<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CashDisbursement;
use App\Models\CashCheckVoucher;
use App\Models\JournalEntry;
use App\Services\JournalEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashDisbursementController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => CashDisbursement::with(['account', 'paymentAccount'])
                ->orderByDesc('disbursement_date')
                ->get(),
        ]);

    }

    public function store(
        Request $request,
        JournalEntryService $journalEntryService
    ): JsonResponse {
        $validated = $request->validate([
            'disbursement_date' => ['required', 'date'],
            'voucher_number' => [
                'required',
                'string',
                'max:30',
                'unique:cash_disbursements,voucher_number',
            ],
            'payee' => ['required', 'string', 'max:150'],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'payment_account_id' => [
                'required',
                'integer',
                'different:account_id',
                'exists:accounts,id',
            ],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'payment_method' => [
                'required',
                'string',
                'in:Cash,Check,Bank Transfer',
            ],
            'check_number' => ['nullable', 'string', 'max:50', 'required_if:payment_method,Check', 'prohibited_unless:payment_method,Check'],
            'remarks' => ['nullable', 'string'],
            'post_now' => ['sometimes', 'boolean'],
        ]);

        $postNow = (bool) ($validated['post_now'] ?? false);
        unset($validated['post_now']);
        if ($postNow) {
            abort_unless($request->user()->can('cash_disbursements.approve') &&
                $request->user()->can('cash_disbursements.post'), 403);
        }

        if (CashCheckVoucher::where('voucher_number', $validated['voucher_number'])->exists()) {
            throw ValidationException::withMessages(['voucher_number' => 'Release this cash/check voucher through its approved voucher workflow.']);
        }

        $expenseAccount = Account::query()
            ->whereKey($validated['account_id'])
            ->where('is_active', true)
            ->first();

        $paymentAccount = Account::query()
            ->whereKey($validated['payment_account_id'])
            ->where('is_active', true)
            ->first();

        if (! $expenseAccount || ! $paymentAccount) {
            throw ValidationException::withMessages([
                'account_id' => 'Both accounts must exist and be active.',
            ]);
        }

        if (! in_array(
            $paymentAccount->account_type,
            ['Asset'],
            true
        )) {
            throw ValidationException::withMessages([
                'payment_account_id' =>
                    'The payment account must be an asset account, such as cash or bank.',
            ]);
        }

        if ($expenseAccount->id === $paymentAccount->id) {
            throw ValidationException::withMessages([
                'payment_account_id' =>
                    'The account being debited and payment account must be different.',
            ]);
        }

        $disbursement = DB::transaction(function () use (
            $validated,
            $journalEntryService,
            $postNow
        ) {
            $disbursement = CashDisbursement::create(array_merge($validated, ['status' => 'Draft']));

            $entry = JournalEntry::create([
                'journal_number' => 'CD-' . $disbursement->id,
                'journal_date' => $disbursement->disbursement_date,
                'description' => 'Cash disbursement to '
                    . $disbursement->payee
                    . ' (Voucher '
                    . $disbursement->voucher_number
                    . ')',
                'reference_type' => 'CashDisbursement',
                'reference_id' => $disbursement->id,
                'status' => 'Draft',
                'created_by' => auth()->id(),
            ]);

            $entry->lines()->createMany([
                [
                    'account_id' => $disbursement->account_id,
                    'description' => 'Disbursement expense/payable',
                    'debit_amount' => $disbursement->amount,
                    'credit_amount' => 0,
                ],
                [
                    'account_id' => $disbursement->payment_account_id,
                    'description' => 'Cash/bank payment',
                    'debit_amount' => 0,
                    'credit_amount' => $disbursement->amount,
                ],
            ]);

            if ($postNow) {
                $journalEntryService->post($entry);
                $disbursement->update(['status' => 'Posted']);
            }

            return $disbursement;
        });

        return response()->json([
            'success' => true,
            'message' =>
                $postNow ? 'Cash disbursement created and journal entry posted successfully.' : 'Cash disbursement saved as Draft.',
            'data' => $disbursement->load([
                'account',
                'paymentAccount',
            ]),
        ], 201);
    }

    public function post(CashDisbursement $cashDisbursement, JournalEntryService $journalEntryService): JsonResponse
    {
        abort_unless(auth()->user()->can('cash_disbursements.approve'), 403);

        return DB::transaction(function () use ($cashDisbursement, $journalEntryService) {
            $disbursement = CashDisbursement::whereKey($cashDisbursement->id)->lockForUpdate()->firstOrFail();
            if ($disbursement->status !== 'Draft') {
                throw ValidationException::withMessages(['status' => 'Only Draft disbursements can be posted.']);
            }
            $entries = JournalEntry::where('reference_type', 'CashDisbursement')
                ->where('reference_id', $disbursement->id)->lockForUpdate()->get();
            if ($entries->count() !== 1 || $entries->first()->status !== 'Draft') {
                throw ValidationException::withMessages(['journal_entry' => 'This disbursement requires exactly one Draft journal entry.']);
            }
            $journalEntryService->post($entries->first());
            $disbursement->update(['status' => 'Posted']);
            return response()->json(['success' => true, 'data' => $disbursement->load(['account', 'paymentAccount'])]);
        });
    }

    public function show(
        CashDisbursement $cashDisbursement
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'data' => $cashDisbursement->load([
                'account',
                'paymentAccount',
            ]),
        ]);
    }

    public function update(
        Request $request,
        CashDisbursement $cashDisbursement
    ): JsonResponse {
        $validated = $request->validate([
            'disbursement_date' => ['required', 'date'],
            'voucher_number' => [
                'required',
                'string',
                'max:30',
                'unique:cash_disbursements,voucher_number,'
                    . $cashDisbursement->id,
            ],
            'payee' => ['required', 'string', 'max:150'],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'payment_account_id' => [
                'required',
                'integer',
                'different:account_id',
                'exists:accounts,id',
            ],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'payment_method' => [
                'required',
                'string',
                'in:Cash,Check,Bank Transfer',
            ],
            'check_number' => ['nullable', 'string', 'max:50', 'required_if:payment_method,Check', 'prohibited_unless:payment_method,Check'],
            'remarks' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($cashDisbursement, $validated) {
            $disbursement = CashDisbursement::whereKey($cashDisbursement->id)->lockForUpdate()->firstOrFail();
            if ($disbursement->status !== 'Draft') {
                throw ValidationException::withMessages(['status' => 'Only Draft disbursements can be edited.']);
            }
            if (CashCheckVoucher::where('voucher_number', $validated['voucher_number'])->exists()) {
                throw ValidationException::withMessages(['voucher_number' => 'Release this cash/check voucher through its approved voucher workflow.']);
            }
            $debitAccount = Account::whereKey($validated['account_id'])->where('is_active', true)->first();
            $paymentAccount = Account::whereKey($validated['payment_account_id'])->where('is_active', true)->first();
            if (! $debitAccount || ! $paymentAccount || $paymentAccount->account_type !== 'Asset' || $debitAccount->id === $paymentAccount->id) {
                throw ValidationException::withMessages(['account_id' => 'Select distinct active debit and Asset payment accounts.']);
            }
            $entries = JournalEntry::where('reference_type', 'CashDisbursement')
                ->where('reference_id', $disbursement->id)->lockForUpdate()->get();
            if ($entries->count() !== 1 || $entries->first()->status !== 'Draft') {
                throw ValidationException::withMessages(['journal_entry' => 'This disbursement requires exactly one Draft journal entry.']);
            }
            $entry = $entries->first();
            $lines = $entry->lines()->orderBy('id')->get();
            if ($lines->count() !== 2) {
                throw ValidationException::withMessages(['journal_entry' => 'Expected two journal lines for this disbursement.']);
            }
            $disbursement->update($validated);
            $entry->update([
                'journal_date' => $validated['disbursement_date'],
                'description' => 'Cash disbursement to ' . $validated['payee'] . ' (Voucher ' . $validated['voucher_number'] . ')',
            ]);
            $amount = bcadd((string) $validated['amount'], '0', 2);
            $lines[0]->update(['account_id' => $debitAccount->id, 'debit_amount' => $amount, 'credit_amount' => '0.00']);
            $lines[1]->update(['account_id' => $paymentAccount->id, 'debit_amount' => '0.00', 'credit_amount' => $amount]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Cash disbursement updated successfully.',
            'data' => $cashDisbursement->fresh()->load([
                'account',
                'paymentAccount',
            ]),
        ]);
    }

    public function destroy(
        CashDisbursement $cashDisbursement
    ): JsonResponse {
        DB::transaction(function () use ($cashDisbursement) {
            $disbursement = CashDisbursement::whereKey($cashDisbursement->id)->lockForUpdate()->firstOrFail();
            if ($disbursement->status !== 'Draft') {
                throw ValidationException::withMessages(['status' => 'Only Draft disbursements can be deleted.']);
            }
            $entries = JournalEntry::where('reference_type', 'CashDisbursement')
                ->where('reference_id', $disbursement->id)->lockForUpdate()->get();
            if ($entries->count() !== 1 || $entries->first()->status !== 'Draft') {
                throw ValidationException::withMessages(['journal_entry' => 'A posted or missing journal entry cannot be deleted.']);
            }
            $entries->first()->lines()->delete();
            $entries->first()->delete();
            $disbursement->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Cash disbursement deleted successfully.',
        ]);
    }
}
