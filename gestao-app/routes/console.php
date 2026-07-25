<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Fila por cron — ADR-0014
|--------------------------------------------------------------------------
|
| O ADR-0014 escolheu o driver `database` e deixou o modo de execução dos
| workers dependendo do ADR-0016; como aquele fechou no plano Business
| (compartilhado, sem supervisor), sobra o cron. É este agendamento que
| fecha a pendência — sem ele o driver de fila estaria configurado e
| nenhum job jamais rodaria: ficariam empilhando na tabela `jobs` em
| silêncio, e o primeiro sintoma seria alguém perguntando por que o
| e-mail de convite nunca chegou.
|
| ## Por que `Schedule::call()` e não `Schedule::command()`
|
| **`Schedule::command()` não funciona neste servidor.** Ele dispara o
| comando por `Symfony\Process` — em background E em foreground —, e
| `proc_open` está em `disable_functions` no plano Business (P-15). O
| sintoma é traiçoeiro: o `schedule:run` roda, o batimento do cron é
| gravado normalmente, e a tarefa simplesmente não acontece; o erro só
| aparece no `laravel.log`. Descoberto em 2026-07-25, e só foi visível
| porque o log da produção passou a funcionar no dia anterior (P-16).
|
| `Schedule::call()` executa no próprio processo do `schedule:run`, sem
| subprocesso — logo, sem `proc_open`. Vale para QUALQUER tarefa agendada
| que este projeto vier a ter, não só para a fila.
|
| O `schedule:run` já roda a cada minuto pelo cron do hPanel
| (`~/scheduler.sh`, com caminho absoluto por causa da P-13).
|
*/

Schedule::call(function (): void {
    // `--stop-when-empty` sai assim que a fila esvazia: na maioria dos
    // minutos isto retorna de imediato. Sem ele, o `schedule:run` ficaria
    // preso os 55 s inteiros todo minuto, segurando um dos processos que
    // o plano divide com o WordPress (P-11).
    Artisan::call('queue:work --stop-when-empty --max-time=55 --tries=3');
})
    ->name('fila-worker')
    ->everyMinute()
    // Sem isto, um job travado acumularia execuções sobrepostas até
    // esbarrar no limite de processos do plano — e derrubaria o site
    // junto, que roda sob o mesmo usuário.
    ->withoutOverlapping();

/*
| `failed_jobs` só cresce. Duas semanas é tempo de sobra para investigar
| uma falha; o que passa disso é arqueologia, não operação.
*/
Schedule::call(function (): void {
    Artisan::call('queue:prune-failed --hours=336');
})->name('fila-limpeza')->weekly();
