<?php

declare(strict_types=1);

namespace Database\Factories\Identity;

use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * Sem @return próprio: herda o do Factory, que o Larastan lê como
     * "chaves = propriedades reais de User" (checkModelProperties).
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'status' => UserStatus::Active,
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function invited(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserStatus::Invited,
            'email_verified_at' => null,
            'must_change_password' => true,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserStatus::Suspended,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserStatus::Disabled,
        ]);
    }

    /**
     * Cria o usuário já com o papel atribuído.
     *
     * Depende dos papéis existirem — em teste, rodar o
     * RolePermissionSeeder antes (o Pest.php do projeto já faz isso).
     */
    public function withRole(Role ...$roles): static
    {
        return $this->afterCreating(
            static fn (User $user) => $user->assignRoleEnum(...$roles)
        );
    }
}
