<?php

namespace Tests\Feature\Settings;

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/settings/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    /**
     * O starter kit trazia autoexclusão de conta; ela foi removida em
     * 2026-07-24. O ciclo de vida da pasta 18 §3 não tem estado
     * "excluído" — conta se encerra em `disabled`, por um admin — e
     * apagar a própria conta levaria junto a trilha de segurança da
     * pasta 26, que existe para não perder o registro de que alguém
     * existiu.
     *
     * Este teste substitui os dois que exercitavam a exclusão. Ele prova
     * que a rota não voltou: um `php artisan install:api` ou uma
     * atualização do starter kit poderia trazê-la de volta em silêncio.
     */
    public function test_self_service_account_deletion_does_not_exist()
    {
        $user = User::factory()->create();

        $this->assertFalse(
            Route::has('profile.destroy'),
            'A rota de autoexclusão de conta voltou a existir — ver pasta 18 §3.'
        );

        $this->actingAs($user)
            ->delete('/settings/profile', ['password' => 'password'])
            ->assertMethodNotAllowed();

        $this->assertNotNull($user->fresh());
    }
}
