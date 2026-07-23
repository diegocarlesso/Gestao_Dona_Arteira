<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteUserRequest extends FormRequest
{
    /**
     * A autorização fica na Policy, chamada pelo controller — aqui só o
     * formato (pasta 05 §3).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')],
            'roles' => ['array'],
            'roles.*' => ['string', Rule::enum(Role::class)],
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
}
