<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->resolverDeFactoriesModulares();
    }

    /**
     * Ensina o Laravel a achar as factories dos módulos.
     *
     * O resolvedor padrão só entende `App\Models\X` → `Database\Factories\XFactory`.
     * Com a estrutura modular do ADR-0020, `App\Modules\Identity\Models\User`
     * viraria `Database\Factories\Modules\Identity\Models\UserFactory` — que
     * não existe, porque a pasta 05 §2 põe as factories em
     * `database/factories/<Módulo>/`.
     *
     * Registrar a regra aqui, uma vez, evita que cada model novo precise
     * de um `newFactory()` próprio — e que a falta dele só apareça no
     * primeiro teste que usar aquela factory.
     */
    private function resolverDeFactoriesModulares(): void
    {
        Factory::guessFactoryNamesUsing(static function (string $model): string {
            // App\Modules\Identity\Models\User → Identity\User
            if (Str::startsWith($model, 'App\\Modules\\')) {
                $modulo = Str::before(Str::after($model, 'App\\Modules\\'), '\\');
                $classe = class_basename($model);

                return "Database\\Factories\\{$modulo}\\{$classe}Factory";
            }

            // Fora dos módulos, o comportamento padrão do Laravel.
            $semNamespace = Str::startsWith($model, 'App\\Models\\')
                ? Str::after($model, 'App\\Models\\')
                : Str::after($model, 'App\\');

            return "Database\\Factories\\{$semNamespace}Factory";
        });
    }
}
