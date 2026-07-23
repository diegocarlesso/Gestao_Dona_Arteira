<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Exceptions\TransicaoDeStatusInvalida;
use App\Modules\Identity\Http\Requests\InviteUserRequest;
use App\Modules\Identity\Http\Requests\UpdateUserRequest;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ChangeUserStatusService;
use App\Modules\Identity\Services\InviteUserService;
use App\Modules\Identity\Services\SyncUserRolesService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Gestão de contas (pasta 18) e atribuição de papéis (pasta 19).
 *
 * Controller fino (ADR-0015): autoriza pela Policy, valida pelo
 * FormRequest, delega ao Service, responde com Inertia.
 */
class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $busca = $request->string('busca')->trim()->value();

        $usuarios = User::query()
            ->with('roles:id,name')
            ->when($busca !== '', fn ($q) => $q->where(
                fn ($q) => $q->where('name', 'like', "%{$busca}%")
                    ->orWhere('email', 'like', "%{$busca}%")
            ))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (User $u): array => $this->paraTela($u));

        return Inertia::render('identity/users/index', [
            'usuarios' => $usuarios,
            'filtros' => ['busca' => $busca],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', User::class);

        return Inertia::render('identity/users/create', [
            'papeisDisponiveis' => $this->papeisDisponiveis(),
        ]);
    }

    public function store(InviteUserRequest $request, InviteUserService $service): RedirectResponse
    {
        $this->authorize('create', User::class);

        $usuario = $service->handle(
            $request->string('name')->value(),
            $request->string('email')->value(),
            $request->papeis(),
        );

        return to_route('identity.users.index')
            ->with('sucesso', "Conta de {$usuario->name} criada. A pessoa define a senha pelo link de redefinição.");
    }

    public function edit(User $user): Response
    {
        $this->authorize('view', $user);

        return Inertia::render('identity/users/edit', [
            'usuario' => $this->paraTela($user),
            'papeisDisponiveis' => $this->papeisDisponiveis(),
            'situacoesDisponiveis' => $this->situacoesDisponiveis($user),
            'ehVoce' => $user->is(request()->user()),
        ]);
    }

    public function update(
        UpdateUserRequest $request,
        User $user,
        SyncUserRolesService $papeis,
        ChangeUserStatusService $status,
    ): RedirectResponse {
        $this->authorize('update', $user);

        $user->update([
            'name' => $request->string('name')->value(),
            'email' => $request->string('email')->value(),
        ]);

        if ($request->papeis() !== $user->roleEnums()) {
            $this->authorize('assignRoles', $user);
            $papeis->handle($user, $request->papeis());
        }

        $destino = $request->situacao();

        if ($destino !== $user->status) {
            $this->authorize('changeStatus', [$user, $destino]);

            try {
                $status->handle($user, $destino);
            } catch (TransicaoDeStatusInvalida $e) {
                return back()->withErrors(['status' => $e->getMessage()]);
            }
        }

        return to_route('identity.users.index')
            ->with('sucesso', "Conta de {$user->name} atualizada.");
    }

    /**
     * @return array<string, mixed>
     */
    private function paraTela(User $u): array
    {
        return [
            'public_id' => $u->public_id,
            'name' => $u->name,
            'email' => $u->email,
            'status' => $u->status->value,
            'status_label' => $u->status->label(),
            'roles' => $u->roleEnums(),
            'role_labels' => array_map(static fn (Role $r): string => $r->label(), $u->roleEnums()),
            'requires_two_factor' => $u->requiresTwoFactor(),
            'has_two_factor' => $u->hasTwoFactorEnabled(),
            'last_login_at' => $u->last_login_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array{value: string, label: string, requires_two_factor: bool}>
     */
    private function papeisDisponiveis(): array
    {
        return array_map(static fn (Role $r): array => [
            'value' => $r->value,
            'label' => $r->label(),
            'requires_two_factor' => $r->requiresTwoFactor(),
        ], Role::cases());
    }

    /**
     * Só as transições que o ciclo de vida permite a partir de onde a
     * conta está — a tela não oferece o que o domínio recusaria.
     *
     * @return list<array{value: string, label: string}>
     */
    private function situacoesDisponiveis(User $u): array
    {
        $possiveis = [$u->status, ...$u->status->allowedTransitions()];

        return array_map(static fn (UserStatus $s): array => [
            'value' => $s->value,
            'label' => $s->label(),
        ], $possiveis);
    }
}
