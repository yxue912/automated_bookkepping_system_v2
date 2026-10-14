<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_api_rejects_requests_without_a_token(): void
    {
        $this->getJson('/api/accounts')->assertUnauthorized();
    }

    public function test_valid_login_issues_token_that_can_access_authorized_api(): void
    {
        $user = User::create([
            'name' => 'Test Accountant',
            'email' => 'accountant@example.test',
            'password' => Hash::make('correct-password'),
        ]);

        Permission::create([
            'name' => 'accounts.view',
            'guard_name' => 'web',
        ]);
        $user->givePermissionTo('accounts.view');

        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertOk()
            ->assertJsonStructure(['token', 'token_type', 'user' => ['id', 'email', 'roles', 'permissions']])
            ->assertJsonPath('token_type', 'Bearer');

        $token = $login->json('token');
        $this->assertNotEmpty($token);

        $this->withToken($token)
            ->getJson('/api/accounts')
            ->assertOk()
            ->assertJsonStructure(['success', 'data']);
    }

    public function test_authenticated_user_without_permission_cannot_access_route(): void
    {
        $user = User::create([
            'name' => 'Restricted Accountant',
            'email' => 'restricted@example.test',
            'password' => Hash::make('correct-password'),
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/accounts')
            ->assertForbidden();
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::create([
            'name' => 'Test Accountant',
            'email' => 'logout@example.test',
            'password' => Hash::make('correct-password'),
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/logout')
            ->assertOk();

        $this->assertCount(0, $user->tokens);
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/accounts')->assertUnauthorized();
    }

    public function test_invalid_credentials_and_expired_tokens_are_rejected(): void
    {
        $user = User::create([
            'name' => 'Login User',
            'email' => 'login@example.test',
            'password' => Hash::make('correct-password'),
        ]);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertUnauthorized();
        $this->postJson('/api/login', ['email' => 'invalid', 'password' => 'wrong'])
            ->assertUnprocessable();
        $token = $user->createToken('expired', ['*'], now()->subMinute())->plainTextToken;
        $this->withToken($token)->getJson('/api/accounts')->assertUnauthorized();
    }

    public function test_login_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/login', ['email' => 'missing@example.test', 'password' => 'wrong'])
                ->assertUnauthorized();
        }
        $this->postJson('/api/login', ['email' => 'missing@example.test', 'password' => 'wrong'])
            ->assertStatus(429);
    }
}
