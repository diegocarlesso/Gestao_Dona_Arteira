<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile settings.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return to_route('profile.edit');
    }

    /*
     * O `destroy()` do starter kit — autoexclusão de conta — foi removido
     * em 2026-07-24. O ciclo de vida da pasta 18 §3 não tem estado
     * "excluído": conta se encerra em `disabled`, decidido por um admin
     * pelo ChangeUserStatusService.
     *
     * A trilha de segurança da pasta 26 tem FK RESTRICT para `users`
     * justamente para que apagar uma conta não apague o registro de que
     * ela existiu — e foi ela que fez esta funcionalidade reprovar, o que
     * é o comportamento desejado.
     */
}
