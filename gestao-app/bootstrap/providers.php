<?php

declare(strict_types=1);

use App\Modules\Identity\Providers\IdentityServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,

    // Um provider por módulo (pasta 05 §2). A ordem aqui é a ordem de
    // boot: Identity vem primeiro porque define o Gate que os demais
    // módulos consultam.
    IdentityServiceProvider::class,
];
