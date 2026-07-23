<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $alvo */
        $alvo = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'lowercase', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($alvo->id),
            ],
            'roles' => ['array'],
            'roles.*' => ['string', Rule::enum(Role::class)],
            'status' => ['required', Rule::enum(UserStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'email' => 'e-mail',
            'roles' => 'papéis',
            'status' => 'situação',
        ];
    }

    /**
     * @return list<Role>
     */
    public function papeis(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $v): ?Role => Role::tryFrom($v),
            $this->array('roles'),
        )));
    }

    public function situacao(): UserStatus
    {
        return UserStatus::from($this->string('status')->value());
    }
}
