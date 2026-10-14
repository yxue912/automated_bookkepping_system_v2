<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use App\Services\JournalEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JournalEntryController extends Controller
{
    private function isSystemSource(?string $referenceType): bool
    {
        $parts = explode('/', str_replace('\\', '/', strtolower(trim((string) $referenceType))));
        $name = preg_replace('/[^a-z0-9]/', '', end($parts));

        return in_array($name, ['cashreceipt', 'cashdisbursement'], true);
    }

    public function index(): JsonResponse
    {
        return response()->json(
            JournalEntry::with('lines.account')
                ->orderByDesc('journal_date')
                ->orderByDesc('id')
                ->paginate(15)
        );
    }

    public function show(JournalEntry $journalEntry): JsonResponse
    {
        return response()->json(
            $journalEntry->load('lines.account', 'creator')
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'journal_number' => [
                'required',
                'string',
                'max:50',
                'unique:journal_entries,journal_number',
            ],
            'journal_date' => [
                'required',
                'date',
            ],
            'description' => [
                'required',
                'string',
                'max:255',
            ],
            'reference_type' => [
                'nullable',
                'string',
                'max:50',
            ],
            'reference_id' => [
                'nullable',
                'integer',
                'min:1',
            ],
            'lines' => [
                'required',
                'array',
                'min:2',
            ],
            'lines.*.account_id' => [
                'required',
                'integer',
                'exists:accounts,id',
            ],
            'lines.*.description' => [
                'nullable',
                'string',
                'max:255',
            ],
            'lines.*.debit_amount' => [
                'required',
                'numeric',
                'decimal:0,2',
                'min:0',
            ],
            'lines.*.credit_amount' => [
                'required',
                'numeric',
                'decimal:0,2',
                'min:0',
            ],
        ]);

        if ($this->isSystemSource($validated['reference_type'] ?? null) ||
            (isset($validated['reference_type']) !== isset($validated['reference_id']))) {
            throw ValidationException::withMessages(['reference_type' => 'Source journal references are managed by their source document.']);
        }

        // Validate line structure and balance before saving.
        $totalDebits = '0.00';
        $totalCredits = '0.00';

        foreach ($validated['lines'] as $index => $line) {
            $debit = (string) $line['debit_amount'];
            $credit = (string) $line['credit_amount'];

            if ((bccomp($debit, '0', 2) > 0 && bccomp($credit, '0', 2) > 0) ||
                (bccomp($debit, '0', 2) === 0 && bccomp($credit, '0', 2) === 0)) {
                throw ValidationException::withMessages([
                    "lines.$index" =>
                        'Each journal line must contain either a debit or a credit, not both or neither.',
                ]);
            }

            $totalDebits = bcadd($totalDebits, $debit, 2);
            $totalCredits = bcadd($totalCredits, $credit, 2);
        }

        if ($totalDebits !== $totalCredits) {
            throw ValidationException::withMessages([
                'lines' =>
                    'Total debits must equal total credits before saving the journal entry.',
            ]);
        }

        $entry = DB::transaction(function () use ($validated, $request) {
            $entry = JournalEntry::create([
                'journal_number' => $validated['journal_number'],
                'journal_date' => $validated['journal_date'],
                'description' => $validated['description'],
                'reference_type' => $validated['reference_type'] ?? null,
                'reference_id' => $validated['reference_id'] ?? null,
                'status' => 'Draft',
                'created_by' => $request->user()?->id,
            ]);

            $entry->lines()->createMany($validated['lines']);

            return $entry;
        });

        return response()->json(
            $entry->load('lines.account'),
            201
        );
    }

    public function post(
        JournalEntry $journalEntry,
        JournalEntryService $service
    ): JsonResponse {
        if ($this->isSystemSource($journalEntry->reference_type)) {
            throw ValidationException::withMessages(['reference_type' => 'Post this journal through its source document.']);
        }
        // The service checks status, line structure, and balance
        // inside its database transaction before posting.
        $service->post($journalEntry);

        return response()->json(
            $journalEntry->fresh()->load('lines.account', 'creator')
        );
    }
}
