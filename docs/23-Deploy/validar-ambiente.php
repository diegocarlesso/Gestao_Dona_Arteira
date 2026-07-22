<?php
/**
 * Validação do ambiente de hospedagem — ERP Dona Arteira
 *
 * Verifica se o plano contratado suporta os Gates 01 a 06, com atenção
 * especial ao Gate 05 (emissão de NF-e), que é o mais exigente.
 *
 * USO:
 *   1. Editar $TOKEN abaixo para um valor secreto qualquer.
 *   2. Enviar este arquivo para o servidor (fora do site público, se possível).
 *   3. Abrir https://SEU-DOMINIO/validar-ambiente.php?token=SEU_TOKEN
 *      ou rodar via SSH: php validar-ambiente.php
 *   4. **APAGAR O ARQUIVO DO SERVIDOR** logo depois.
 *
 * Ver docs/23-Deploy/01-validacao-ambiente-business.md para a leitura dos resultados.
 */

$TOKEN = 'PeqE0rlpAdEXlQ8YuzawrOV_thacucrotriPhitrIpHi8lpHiboYETr1HEPorAfl';

$viaCli = PHP_SAPI === 'cli';
if (!$viaCli && (($_GET['token'] ?? '') !== $TOKEN || $TOKEN === 'PeqE0rlpAdEXlQ8YuzawrOV_thacucrotriPhitrIpHi8lpHiboYETr1HEPorAfl')) {
    http_response_code(403);
    exit('Token inválido ou não configurado.');
}
if (!$viaCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

$resultados = [];

/** Registra um resultado. $nivel: 'ok' | 'aviso' | 'falha' */
function checar(string $grupo, string $item, bool|string $valor, string $nivel, string $nota = ''): void
{
    global $resultados;
    $resultados[$grupo][] = compact('item', 'valor', 'nivel', 'nota');
}

// ---------------------------------------------------------------- PHP
$phpOk = version_compare(PHP_VERSION, '8.2', '>=');
checar('PHP', 'Versão', PHP_VERSION, $phpOk ? 'ok' : 'falha', $phpOk ? '' : 'Laravel 12 exige PHP >= 8.2');
checar('PHP', 'Arquitetura', PHP_INT_SIZE === 8 ? '64 bits' : '32 bits', PHP_INT_SIZE === 8 ? 'ok' : 'falha', '32 bits quebra cálculos de data/dinheiro');
checar('PHP', 'SAPI', PHP_SAPI, $viaCli ? 'ok' : 'aviso',
    $viaCli ? 'esta é a execução que importa para fila e NF-e' : 'execução WEB: o PHP de CLI costuma ter extensões e limites DIFERENTES. Rode também via SSH — é o CLI que executa fila e emissão de NF-e');

// ------------------------------------------------------- Extensões
$extensoes = [
    // [nome, obrigatória para, nível se ausente]
    ['openssl',  'assinatura do XML da NF-e (Gate 05) + HTTPS', 'falha'],
    ['soap',     'comunicação SOAP com a SEFAZ (Gate 05)',      'falha'],
    ['curl',     'WooCommerce, SEFAZ, banco (Gates 02/04/05)',  'falha'],
    ['dom',      'montagem do XML da NF-e (Gate 05)',           'falha'],
    ['libxml',   'processamento de XML (Gate 05)',              'falha'],
    ['simplexml','leitura de retornos da SEFAZ (Gate 05)',      'falha'],
    ['xml',      'processamento de XML (Gate 05)',              'falha'],
    ['mbstring', 'Laravel (núcleo)',                            'falha'],
    ['pdo_mysql','MariaDB (núcleo)',                            'falha'],
    ['bcmath',   'cálculos de dinheiro sem float (ADR-0013)',   'falha'],
    ['ctype',    'Laravel (núcleo)',                            'falha'],
    ['fileinfo', 'upload de imagens de produto',                'falha'],
    ['json',     'Laravel (núcleo)',                            'falha'],
    ['tokenizer','Laravel (núcleo)',                            'falha'],
    ['zip',      'guarda de XML em lote (Gate 05)',             'falha'],
    ['iconv',    'normalização de texto do legado (Gate 01)',   'aviso'],
    ['intl',     'formatação pt-BR de datas e números',         'aviso'],
    ['gd',       'redimensionamento de imagens',                'aviso'],
    ['zlib',     'compactação',                                 'aviso'],
    ['opcache',  'desempenho',                                  'aviso'],
];
foreach ($extensoes as [$ext, $para, $nivelSeAusente]) {
    $tem = extension_loaded($ext);
    checar('Extensões', $ext, $tem ? 'presente' : 'AUSENTE', $tem ? 'ok' : $nivelSeAusente, $tem ? '' : "necessária para: {$para}");
}

// ---------------------------------------------------------- Limites
$maxExec = (int) ini_get('max_execution_time');
checar('Limites', 'max_execution_time', $maxExec === 0 ? 'ilimitado' : "{$maxExec}s",
    ($maxExec === 0 || $maxExec >= 60) ? 'ok' : 'falha',
    'assinar + transmitir NF-e pode passar de 30s; workers de fila precisam de mais');

$memoria = ini_get('memory_limit');
$memBytes = (int) $memoria * (str_contains(strtoupper($memoria), 'G') ? 1024 : 1);
checar('Limites', 'memory_limit', $memoria, ($memoria === '-1' || $memBytes >= 256) ? 'ok' : 'aviso',
    'ETL da migração (Gate 01) processa dumps grandes');

checar('Limites', 'upload_max_filesize', ini_get('upload_max_filesize'), 'ok');
checar('Limites', 'post_max_size', ini_get('post_max_size'), 'ok');
checar('Limites', 'max_input_vars', ini_get('max_input_vars') ?: 'padrão', 'ok');

// ------------------------------------------- Funções desabilitadas
$desabilitadas = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
$necessarias = [
    'proc_open'      => ['fila e comandos artisan (Gates 01+)', 'falha'],
    'proc_close'     => ['fila e comandos artisan', 'falha'],
    'symlink'        => ['storage:link e deploy atômico', 'aviso'],
    'exec'           => ['tarefas de manutenção', 'aviso'],
    'shell_exec'     => ['tarefas de manutenção', 'aviso'],
    'putenv'         => ['configuração em runtime', 'aviso'],
    'set_time_limit' => ['jobs longos (ETL, NF-e)', 'aviso'],
];
foreach ($necessarias as $fn => [$para, $nivel]) {
    $bloqueada = in_array($fn, $desabilitadas, true) || !function_exists($fn);
    checar('Funções', $fn, $bloqueada ? 'BLOQUEADA' : 'disponível', $bloqueada ? $nivel : 'ok', $bloqueada ? "necessária para: {$para}" : '');
}

// ------------------------------------------------------ CA bundle
// Sem um bundle de CAs configurado, toda conexão HTTPS verificada falha
// com "unable to get local issuer certificate" — o que parece bloqueio de
// rede mas não é. Diagnosticar separadamente evita conclusão errada.
$caIni = ini_get('curl.cainfo') ?: ini_get('openssl.cafile') ?: '';
$caOk  = $caIni !== '' && is_readable($caIni);
checar('TLS', 'curl.cainfo / openssl.cafile', $caOk ? $caIni : ($caIni ?: 'não configurado'),
    $caOk ? 'ok' : 'aviso',
    $caOk ? '' : 'sem CA bundle a verificação de certificado falha; Laravel/Guzzle podem contornar, mas configurar é melhor');

// -------------------------------------------------- Conectividade
// A SEFAZ e o WooCommerce exigem saída HTTPS. Alguns planos compartilhados
// restringem saída para hosts arbitrários.
//
// IMPORTANTE: os endpoints que a emissão realmente usa são os webservices da
// SEFAZ da UF da empresa (ou da SVRS/SVC, conforme o estado). Como a UF ainda
// não foi confirmada pelo contador (item A-02 da pauta fiscal), testamos os
// endpoints nacionais e a SVRS como aproximação. Reconfirmar com a UF real.
$alvos = [
    'https://www.google.com'                                          => 'saída HTTPS genérica',
    'https://www.nfe.fazenda.gov.br'                                  => 'portal nacional da NF-e',
    'https://hom.nfe.fazenda.gov.br'                                  => 'ambiente de homologação NF-e',
    'https://nfe.svrs.rs.gov.br/ws/NfeStatusServico/NfeStatusServico4.asmx' => 'webservice SEFAZ (SVRS) — o que importa',
];
foreach ($alvos as $url => $desc) {
    if (!function_exists('curl_init')) {
        checar('Conectividade', $desc, 'não testado (sem curl)', 'falha');
        continue;
    }

    $tentar = static function (string $url, bool $verificar): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY         => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => $verificar,
            CURLOPT_SSL_VERIFYHOST => $verificar ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        curl_exec($ch);
        $r = ['errno' => curl_errno($ch), 'erro' => curl_error($ch), 'http' => curl_getinfo($ch, CURLINFO_HTTP_CODE)];
        curl_close($ch);
        return $r;
    };

    $r = $tentar($url, true);

    if ($r['errno'] === 0) {
        checar('Conectividade', $desc, "OK (HTTP {$r['http']})", 'ok');
        continue;
    }

    // Erros de CA/certificado: 60 (verificação falhou), 77 (bundle ilegível), 35 (handshake)
    if (in_array($r['errno'], [35, 60, 77], true)) {
        $semVerificar = $tentar($url, false);
        if ($semVerificar['errno'] === 0) {
            checar('Conectividade', $desc, 'ALCANÇÁVEL (falta CA bundle)', 'aviso',
                'a rede NÃO está bloqueada; só falta configurar o bundle de CAs — ver seção TLS');
            continue;
        }
    }

    $rotulo = match ($r['errno']) {
        6       => 'DNS NÃO RESOLVEU',
        7       => 'CONEXÃO RECUSADA',
        28      => 'TIMEOUT',
        default => 'FALHOU',
    };
    checar('Conectividade', $desc, $rotulo, 'falha', "curl({$r['errno']}): {$r['erro']}");
}

// -------------------------------------------------------- Sistema
checar('Sistema', 'Sistema operacional', PHP_OS_FAMILY . ' / ' . php_uname('r'), 'ok');
checar('Sistema', 'Timezone padrão', date_default_timezone_get(), 'ok', 'a aplicação define o seu; só referência');
checar('Sistema', 'Diretório temporário gravável', is_writable(sys_get_temp_dir()) ? 'sim' : 'NÃO', is_writable(sys_get_temp_dir()) ? 'ok' : 'falha');
checar('Sistema', 'Diretório atual gravável', is_writable(__DIR__) ? 'sim' : 'NÃO', is_writable(__DIR__) ? 'ok' : 'aviso');

$livre = @disk_free_space(__DIR__);
checar('Sistema', 'Espaço livre no volume', $livre ? round($livre / 1073741824, 2) . ' GB' : 'indisponível', 'aviso',
    'em hospedagem compartilhada este número é do VOLUME DO HOST, não da sua cota. Conferir a cota real no painel');

// ------------------------------------------------- Banco de dados
// Preencher se quiser testar a conexão; deixar vazio para pular.
$db = ['host' => '127.0.0.1', 'porta' => 3306, 'nome' => 'u917402451_gestao', 'usuario' => 'u917402451_root', 'senha' => 'TUreye@wO43p'];
if ($db['host'] !== '' && extension_loaded('pdo_mysql')) {
    try {
        $pdo = new PDO(
            "mysql:host={$db['host']};port={$db['porta']};dbname={$db['nome']};charset=utf8mb4",
            $db['usuario'],
            $db['senha'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]
        );
        $versao = $pdo->query('SELECT VERSION()')->fetchColumn();
        checar('Banco', 'Conexão', 'OK', 'ok');
        checar('Banco', 'Versão', (string) $versao, 'ok', 'MariaDB >= 10.6 recomendado (ADR-0002)');

        $innodb = $pdo->query("SHOW ENGINES")->fetchAll(PDO::FETCH_ASSOC);
        $temInnoDb = false;
        foreach ($innodb as $linha) {
            if (strtolower($linha['Engine']) === 'innodb' && in_array(strtoupper($linha['Support']), ['YES', 'DEFAULT'], true)) {
                $temInnoDb = true;
            }
        }
        checar('Banco', 'InnoDB', $temInnoDb ? 'disponível' : 'AUSENTE', $temInnoDb ? 'ok' : 'falha', 'transações são obrigatórias (ledger de estoque, ADR-0008)');
    } catch (Throwable $e) {
        checar('Banco', 'Conexão', 'FALHOU', 'falha', $e->getMessage());
    }
} else {
    checar('Banco', 'Conexão', 'não testada', 'aviso', 'preencher $db no topo do script para testar');
}

// ------------------------------------------------------ Relatório
$contagem = ['ok' => 0, 'aviso' => 0, 'falha' => 0];
$linhas = [];
$linhas[] = str_repeat('=', 72);
$linhas[] = ' VALIDAÇÃO DE AMBIENTE — ERP Dona Arteira';
$linhas[] = ' ' . date('Y-m-d H:i:s');
$linhas[] = str_repeat('=', 72);

foreach ($resultados as $grupo => $itens) {
    $linhas[] = '';
    $linhas[] = "── {$grupo} " . str_repeat('─', max(0, 68 - strlen($grupo)));
    foreach ($itens as $r) {
        $contagem[$r['nivel']]++;
        $marca = match ($r['nivel']) { 'ok' => '  OK  ', 'aviso' => ' AVISO', 'falha' => ' FALHA' };
        $valor = is_bool($r['valor']) ? ($r['valor'] ? 'sim' : 'não') : $r['valor'];
        $linha = sprintf('[%s] %-26s %s', $marca, $r['item'], $valor);
        if ($r['nota'] !== '') {
            $linha .= "\n              ↳ {$r['nota']}";
        }
        $linhas[] = $linha;
    }
}

$linhas[] = '';
$linhas[] = str_repeat('=', 72);
$linhas[] = sprintf(' RESUMO: %d OK · %d avisos · %d falhas', $contagem['ok'], $contagem['aviso'], $contagem['falha']);
$linhas[] = str_repeat('=', 72);
$linhas[] = $contagem['falha'] === 0
    ? ' Nenhuma falha bloqueante. Ver os avisos antes do Gate 05.'
    : ' HÁ FALHAS BLOQUEANTES. Levar ao ADR-0016 antes de escrever código.';
$linhas[] = '';
$linhas[] = ' Verificações que este script NÃO faz (checar manualmente):';
$linhas[] = '  - Acesso SSH e Composer disponíveis no plano';
$linhas[] = '  - Granularidade do cron (precisa aceitar execução a cada 1 minuto)';
$linhas[] = '  - Limite de processos simultâneos do plano';
$linhas[] = '  - Possibilidade de apontar um subdomínio para uma pasta própria';
$linhas[] = '  - Política de backup e acesso a dumps automatizados';
$linhas[] = '';
$linhas[] = ' >>> APAGUE ESTE ARQUIVO DO SERVIDOR APÓS O USO. <<<';
$linhas[] = '';

echo implode("\n", $linhas);
