<?php
/**
 * Teste de granularidade do cron — ERP Dona Arteira
 *
 * Painéis de hospedagem compartilhada costumam OFERECER a opção "a cada
 * minuto" e, na prática, executar com intervalo maior. A única forma
 * confiável de saber é medir.
 *
 * USO:
 *   1. Enviar este arquivo para o servidor, ex.: ~/cron-test.php
 *   2. Criar um cron job de 1 em 1 minuto (ver 02-verificar-cron-e-docroot.md):
 *        * * * * * /usr/bin/php /home/SEU_USUARIO/cron-test.php
 *   3. Esperar ~15 minutos.
 *   4. Rodar o relatório:  php cron-test.php --relatorio
 *   5. Remover o cron job e apagar os arquivos.
 */

$log = __DIR__ . '/cron-test.log';

// ------------------------------------------------------- Relatório
if (in_array('--relatorio', $argv ?? [], true)) {
    if (!is_readable($log)) {
        exit("Nenhum registro em {$log}. O cron não executou nenhuma vez.\n");
    }

    $marcas = array_values(array_filter(array_map('trim', file($log))));
    $total  = count($marcas);

    if ($total < 2) {
        exit("Apenas {$total} execução registrada. Esperar mais tempo antes de concluir.\n");
    }

    $intervalos = [];
    for ($i = 1; $i < $total; $i++) {
        $intervalos[] = strtotime($marcas[$i]) - strtotime($marcas[$i - 1]);
    }

    $menor = min($intervalos);
    $maior = max($intervalos);
    $media = array_sum($intervalos) / count($intervalos);

    echo str_repeat('=', 60), "\n";
    echo " TESTE DE GRANULARIDADE DO CRON\n";
    echo str_repeat('=', 60), "\n";
    echo " Execuções registradas : {$total}\n";
    echo " Primeira              : {$marcas[0]}\n";
    echo " Última                : {$marcas[$total - 1]}\n";
    echo ' Intervalo mínimo      : ', $menor, " s\n";
    echo ' Intervalo máximo      : ', $maior, " s\n";
    echo ' Intervalo médio       : ', round($media), " s\n";
    echo str_repeat('-', 60), "\n";

    if ($media <= 90) {
        echo " VEREDITO: cron de 1 MINUTO honrado.\n";
        echo " A fila roda como planejado (ADR-0014). Nada a mudar.\n";
    } elseif ($media <= 330) {
        echo " VEREDITO: intervalo real de ~", round($media / 60), " minuto(s).\n";
        echo " O plano NAO honra 1 minuto. A sincronizacao com o Woo fica\n";
        echo " mais lenta que o NFR de 2 min. Ver mitigacao no doc 23/02.\n";
    } else {
        echo " VEREDITO: intervalo de ~", round($media / 60), " minutos — MUITO alto.\n";
        echo " Gatilho de reabertura do ADR-0016.\n";
    }

    echo str_repeat('-', 60), "\n";
    echo " Intervalos observados (s): ", implode(', ', $intervalos), "\n";
    echo str_repeat('=', 60), "\n";
    exit(0);
}

// ------------------------------------------------------- Marcação
file_put_contents($log, date('Y-m-d H:i:s') . PHP_EOL, FILE_APPEND | LOCK_EX);
