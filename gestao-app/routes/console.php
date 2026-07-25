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
| O `schedule:run` já roda a cada minuto pelo cron do hPanel
| (`~/scheduler.sh`, com caminho absoluto por causa da P-13).
|
*/

Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=3')
    ->everyMinute()
    // `--stop-when-empty` sai assim que a fila esvazia: na maioria dos
    // minutos o processo nasce, não encontra nada e morre. Segurar um
    // worker ocioso por 55 s a cada minuto desperdiçaria um dos processos
    // que o plano divide com o WordPress (P-11).
    //
    // `withoutOverlapping` porque, sem ele, um job travado acumularia um
    // worker por minuto até esbarrar no limite do plano — e derrubaria o
    // site junto, que roda sob o mesmo usuário.
    ->withoutOverlapping()
    ->runInBackground();

/*
| `failed_jobs` só cresce. Duas semanas é tempo de sobra para investigar
| uma falha; o que passa disso é arqueologia, não operação.
*/
Schedule::command('queue:prune-failed --hours=336')->weekly();
