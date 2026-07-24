<?php

declare(strict_types=1);

namespace Database\Factories\Identity;

use App\Modules\Identity\Models\RememberedDevice;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RememberedDevice>
 */
class RememberedDeviceFactory extends Factory
{
    protected $model = RememberedDevice::class;

    /**
     * Sem @return próprio: herda o do Factory, que o Larastan lê como
     * "chaves = propriedades reais do model" (checkModelProperties).
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'device_id' => (string) Str::ulid(),
            'token_hash' => hash('sha256', Str::random(60)),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'expires_at' => now()->addDays(30),
        ];
    }

    /**
     * Vencido — para provar que a validade é conferida, e não presumida
     * pela simples presença do cookie.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }
}
