<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');
header('Access-Control-Allow-Headers: Content-Type');

$dir_resultados  = __DIR__ . '/resultados';
$arquivo_dados   = $dir_resultados . '/dados.json';  // JSON, 1 linha por WhatsApp
$arquivo_bloqueios = $dir_resultados . '/bloqueios.json';

if (!is_dir($dir_resultados)) mkdir($dir_resultados, 0755, true);

$tz   = new DateTimeZone('America/Sao_Paulo');
$agora = new DateTime('now', $tz);

// ── helpers ──────────────────────────────────────────────────────
function lerJson($arquivo) {
    if (!file_exists($arquivo)) return [];
    $c = file_get_contents($arquivo);
    return json_decode($c, true) ?? [];
}
function salvarJson($arquivo, $dados) {
    file_put_contents($arquivo, json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}
function telLimpo($tel) { return preg_replace('/\D/', '', $tel); }
function diaSemana($ts) {
    $dias = ['domingo','segunda-feira','terça-feira','quarta-feira','quinta-feira','sexta-feira','sábado'];
    $tz   = new DateTimeZone('America/Sao_Paulo');
    $d    = new DateTime("@$ts"); $d->setTimezone($tz);
    $sem  = $dias[(int)$d->format('w')];
    return mb_strtoupper(mb_substr($sem,0,1)) . mb_substr($sem,1) . ', ' . $d->format('d/m');
}

$acao = $_GET['acao'] ?? '';

// ── VERIFICAR BLOQUEIO ────────────────────────────────────────────
if ($acao === 'verificar') {
    $tel = telLimpo($_GET['tel'] ?? '');
    if (!$tel) { echo json_encode(['bloqueado'=>false]); exit; }

    $bloqueios = lerJson($arquivo_bloqueios);

    if (isset($bloqueios[$tel])) {
        $libera = $bloqueios[$tel]['libera_ts'];
        if (time() < $libera) {
            echo json_encode([
                'bloqueado'  => true,
                'nome'       => $bloqueios[$tel]['nome'] ?? '',
                'libera_fmt' => diaSemana($libera)
            ]);
            exit;
        }
        // Expirou — limpa
        unset($bloqueios[$tel]);
        salvarJson($arquivo_bloqueios, $bloqueios);
    }
    echo json_encode(['bloqueado'=>false]);
    exit;
}

// ── APAGAR REGISTRO (painel admin) ───────────────────────────────
if ($acao === 'apagar' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $tel = telLimpo($_GET['tel'] ?? '');
    if (!$tel) { echo json_encode(['ok'=>false]); exit; }

    // Remove de dados
    $dados = lerJson($arquivo_dados);
    unset($dados[$tel]);
    salvarJson($arquivo_dados, $dados);

    // Remove bloqueio também
    $bloqueios = lerJson($arquivo_bloqueios);
    unset($bloqueios[$tel]);
    salvarJson($arquivo_bloqueios, $bloqueios);

    echo json_encode(['ok'=>true]);
    exit;
}

// ── LISTAR (painel admin) ─────────────────────────────────────────
if ($acao === 'listar') {
    $dados = lerJson($arquivo_dados);
    echo json_encode(array_values($dados));
    exit;
}

// ── SALVAR RESULTADO ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body     = json_decode(file_get_contents('php://input'), true);
    $nome     = trim($body['nome']     ?? '');
    $tel_fmt  = trim($body['tel']      ?? '');
    $tel      = telLimpo($tel_fmt);
    $email    = trim($body['email']    ?? '');
    $acertos  = (int)($body['acertos'] ?? 0);
    $total    = (int)($body['total']   ?? 10);
    $aprovado = (bool)($body['aprovado'] ?? false);

    if (!$nome || !$tel) { echo json_encode(['ok'=>false,'erro'=>'Dados inválidos']); exit; }

    $data_fmt  = $agora->format('d/m/Y H:i');
    $status    = $aprovado ? 'APROVADO' : 'REPROVADO';

    // Upsert: 1 entrada por WhatsApp (substitui a anterior)
    $dados = lerJson($arquivo_dados);
    $dados[$tel] = [
        'tel'      => $tel_fmt,
        'nome'     => $nome,
        'email'    => $email,
        'acertos'  => $acertos,
        'total'    => $total,
        'status'   => $status,
        'data'     => $data_fmt,
        'tentativas' => ($dados[$tel]['tentativas'] ?? 0) + 1
    ];
    salvarJson($arquivo_dados, $dados);

    // Bloqueio no servidor se reprovado
    if (!$aprovado) {
        $bloqueios = lerJson($arquivo_bloqueios);
        $bloqueios[$tel] = [
            'nome'      => $nome,
            'libera_ts' => $agora->getTimestamp() + (3 * 24 * 60 * 60)
        ];
        salvarJson($arquivo_bloqueios, $bloqueios);
    } else {
        // Aprovado: remove bloqueio caso exista
        $bloqueios = lerJson($arquivo_bloqueios);
        unset($bloqueios[$tel]);
        salvarJson($arquivo_bloqueios, $bloqueios);
    }

    echo json_encode(['ok'=>true]);
    exit;
}

echo json_encode(['ok'=>false,'erro'=>'Requisição inválida']);
