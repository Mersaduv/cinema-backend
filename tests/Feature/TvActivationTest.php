<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\TvActivationCode;
use App\Models\User;
use App\Services\Cinema\TvActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TvActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    public function test_tv_can_request_and_poll_activation_code(): void
    {
        $response = $this->postJson('/api/v1/tv/activate/request', []);

        $response->assertOk()->assertJsonPath('success', true);
        $code = $response->json('data.code');
        $this->assertNotEmpty($code);

        $poll = $this->getJson('/api/v1/tv/activate/poll?code=' . urlencode($code));
        $poll->assertOk()->assertJsonPath('data.status', 'pending');
    }

    public function test_authenticated_user_can_confirm_code_for_tv(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach(Role::where('slug', 'user')->first());

        $service = app(TvActivationService::class);
        $record = $service->createCode('test-tv');

        $token = $user->createToken('test')->plainTextToken;

        $confirm = $this->withToken($token)->postJson('/api/v1/tv/activate/confirm', [
            'code' => $record->code,
        ]);

        $confirm->assertOk()->assertJsonPath('success', true);

        $poll = $this->getJson('/api/v1/tv/activate/poll?code=' . urlencode($record->code));
        $poll->assertOk()
            ->assertJsonPath('data.status', 'activated');
        $this->assertNotEmpty($poll->json('data.token'));
        $this->assertDatabaseMissing('tv_activation_codes', ['code' => $record->code]);
    }

    public function test_expired_code_returns_error_on_confirm(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach(Role::where('slug', 'user')->first());

        TvActivationCode::create([
            'code' => 'EXPI-REDC',
            'expires_at' => now()->subMinute(),
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/v1/tv/activate/confirm', [
            'code' => 'EXPI-REDC',
        ]);

        $response->assertStatus(410);
    }
}
