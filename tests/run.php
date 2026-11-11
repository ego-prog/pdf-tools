<?php

declare(strict_types=1);

/**
 * Testes de ponta a ponta da interface (POST /), com PDFs sintéticos.
 *
 *   php tests/run.php
 *
 * Sobe o servidor embutido do PHP numa porta livre, com workspace
 * próprio em /dev/shm, e apaga tudo ao terminar. Precisa de qpdf, gs,
 * pdftotext e das extensões curl, gd e openssl.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/Fixtures.php';

$root = dirname(__DIR__);
$tmp  = '/dev/shm/pdf-tools-test-' . bin2hex(random_bytes(4));
$work = $tmp . '/workspace';
$data = $tmp . '/fixtures';

mkdir($work, 0700, true);
mkdir($data, 0700, true);

$port   = random_int(20000, 40000);
$server = proc_open(
    [PHP_BINARY, '-c', $root . '/php-dev.ini', '-S', "127.0.0.1:{$port}", '-t', $root . '/public'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
    null,
    ['PDF_TOOLS_TMP' => $work] + getenv()
);

$failures = 0;
$total    = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $total;

    $total++;

    if (!$ok) {
        $failures++;
    }

    echo ($ok ? '  ok    ' : '  FALHA ') . $name . ($ok || $detail === '' ? '' : " — {$detail}") . "\n";
}

/** @return array{status: int, headers: array<string, string>, body: string} */
function post(int $port, array $fields): array
{
    $headers = [];
    $ch      = curl_init("http://127.0.0.1:{$port}/");

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$headers): int {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }

            return strlen($line);
        },
    ]);

    $body   = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $status, 'headers' => $headers, 'body' => $body];
}

function merge(int $port, array $files, array $passwords = [], ?string $maxBytes = null): array
{
    $fields = ['operation' => 'merge'];

    foreach ($files as $i => $file) {
        $fields["pdf[{$i}]"]      = new CURLFile($file, 'application/pdf');
        $fields["password[{$i}]"] = $passwords[$i] ?? '';
    }

    if ($maxBytes !== null) {
        $fields['max_bytes'] = $maxBytes;
    }

    return post($port, $fields);
}

function saveResult(string $directory, array $response): string
{
    $file = $directory . '/resultado-' . bin2hex(random_bytes(3)) . '.pdf';
    file_put_contents($file, $response['body']);

    return $file;
}

function pdfText(string $file): string
{
    return (string) shell_exec('pdftotext ' . escapeshellarg($file) . ' - 2>/dev/null');
}

function signatureFieldCount(string $file): int
{
    $json = json_decode((string) shell_exec('qpdf --json --json-key=acroform ' . escapeshellarg($file) . ' 2>/dev/null'), true);

    return count(array_filter(
        $json['acroform']['fields'] ?? [],
        static fn (array $field) => ($field['fieldtype'] ?? '') === '/Sig'
    ));
}

try {
    // Espera o servidor subir.
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(100000);
    }

    $fixtures = new Fixtures($data);
    $date     = "D:20260312101500-03'00'";

    $plain   = $fixtures->pdf('simples', [['text' => 'Documento sem assinatura']]);
    $visible = $fixtures->pdf('assinado-visivel', [
        ['signatures' => [['rect' => [330, 60, 560, 130], 'name' => 'Fulano de Tal', 'date' => $date, 'cn' => 'FULANO DE TAL:98765432100']]],
        ['text' => 'Segunda pagina'],
    ]);
    $hidden = $fixtures->pdf('assinado-invisivel', [
        ['signatures' => [['cn' => 'BELTRANA DA SILVA:12345678901', 'kid' => true]]],
        ['rotate' => 90],
    ]);
    $icp = $fixtures->pdf('assinado-icp', [
        ['signatures' => [
            ['cn' => 'CICLANA DE SOUZA', 'date' => $date, 'icp' => [1 => '15041980' . '11122233344' . str_repeat('0', 32)]],
            ['cn' => 'EMPRESA EXEMPLO LTDA', 'date' => "D:20260313", 'icp' => [3 => '12345678000199']],
        ]],
    ]);

    $encrypted = $data . '/assinado-com-senha.pdf';
    exec('qpdf --encrypt --user-password=abc --owner-password=xyz --bits=256 -- '
        . escapeshellarg($visible) . ' ' . escapeshellarg($encrypted));

    $originalHash = md5_file($visible);

    echo "Merge sem assinatura\n";
    $r = merge($port, [$plain, $plain]);
    check('status 200', $r['status'] === 200, (string) $r['status'] . ' ' . $r['body']);
    check('nenhum assinado', ($r['headers']['x-pdf-signed-documents'] ?? null) === '');
    check('sem compactação', !isset($r['headers']['x-pdf-compression-dpi']));

    echo "Merge com assinaturas\n";
    $r = merge($port, [$plain, $visible, $hidden]);
    check('status 200', $r['status'] === 200, (string) $r['status'] . ' ' . $r['body']);
    check('assinados = 1,2', ($r['headers']['x-pdf-signed-documents'] ?? null) === '1,2');

    $result = saveResult($data, $r);
    $text   = preg_replace('/[\s\x{A0}]+/u', ' ', pdfText($result));
    $fulano = 'Assinado digitalmente no original em 12/03/2026 por Fulano de Tal (CPF 987.654.321-00).';

    check('nenhum campo de assinatura no resultado', signatureFieldCount($result) === 0);
    check('aviso sobre a assinatura visível e na faixa', substr_count($text, $fulano) === 2, $text);
    check('nome e CPF do certificado', str_contains($text, 'Assinado digitalmente no original por BELTRANA DA SILVA (CPF 123.456.789-01).'), $text);
    check('sem a frase antiga', !str_contains($text, 'não válida'));

    $r    = merge($port, [$icp, $plain]);
    $text = preg_replace('/[\s\x{A0}]+/u', ' ', pdfText(saveResult($data, $r)));
    check(
        'e-CPF e e-CNPJ (campos ICP-Brasil), duas assinaturas',
        str_contains($text, 'Assinado digitalmente no original: em 12/03/2026 por CICLANA DE SOUZA (CPF 111.222.333-44); '
            . 'em 13/03/2026 por EMPRESA EXEMPLO LTDA (CNPJ 12.345.678/0001-99).'),
        $text
    );
    check('upload original intacto', md5_file($visible) === $originalHash);

    echo "Merge com PDF assinado e com senha\n";
    $r = merge($port, [$encrypted, $plain], ['abc', '']);
    check('status 200', $r['status'] === 200, (string) $r['status'] . ' ' . $r['body']);
    check('assinado = 0', ($r['headers']['x-pdf-signed-documents'] ?? null) === '0');

    $r = merge($port, [$plain, $encrypted], ['', 'errada']);
    $error = json_decode($r['body'], true) ?? [];
    check('senha errada → 422 invalid_password [1]', $r['status'] === 422 && ($error['error'] ?? '') === 'invalid_password' && ($error['indices'] ?? []) === [1], $r['body']);

    $r = merge($port, [$plain, $encrypted]);
    $error = json_decode($r['body'], true) ?? [];
    check('sem senha → 422 password_required [1]', $r['status'] === 422 && ($error['error'] ?? '') === 'password_required' && ($error['indices'] ?? []) === [1], $r['body']);

    echo "Tamanho máximo\n";
    $scan     = $fixtures->scannedPdf('digitalizado', 3, 200);
    $scanSize = filesize($scan);
    $limit    = 1024 * 1024;

    $r = merge($port, [$scan, $visible], [], (string) $limit);
    check('status 200', $r['status'] === 200, (string) $r['status'] . ' ' . $r['body']);
    check('compactado (cabeçalho com dpi)', isset($r['headers']['x-pdf-compression-dpi']), json_encode($r['headers']));
    check('dentro do limite', strlen($r['body']) <= $limit, (string) strlen($r['body']));
    check('aviso preservado após compactar', str_contains(pdfText(saveResult($data, $r)), 'Assinado digitalmente no original'));
    echo '        ' . number_format($scanSize / 1048576, 2) . ' MB → ' . number_format(strlen($r['body']) / 1048576, 2)
        . ' MB a ' . ($r['headers']['x-pdf-compression-dpi'] ?? '?') . " dpi\n";

    $r = merge($port, [$scan, $plain], [], (string) ($scanSize * 10));
    check('já cabe → sem compactação', $r['status'] === 200 && !isset($r['headers']['x-pdf-compression-dpi']));

    $r = merge($port, [$scan, $plain], [], '20000');
    $error = json_decode($r['body'], true) ?? [];
    check('não cabe nem a 72 dpi → 422 size_limit_exceeded', $r['status'] === 422 && ($error['error'] ?? '') === 'size_limit_exceeded' && ($error['smallest_bytes'] ?? 0) > 20000, $r['body']);

    $r = merge($port, [$plain, $plain], [], 'abc');
    check('max_bytes inválido → 400', $r['status'] === 400, $r['body']);

    echo "Outras operações\n";
    $r = post($port, ['operation' => 'compress', 'level' => 'alta', 'pdf' => new CURLFile($scan, 'application/pdf')]);
    check('compactar → 200 e menor', $r['status'] === 200 && strlen($r['body']) < $scanSize, (string) $r['status']);

    $r = post($port, ['operation' => 'remove_password', 'password' => 'abc', 'pdf' => new CURLFile($encrypted, 'application/pdf')]);
    check('remover senha → 200', $r['status'] === 200, (string) $r['status'] . ' ' . $r['body']);

    check('workspace limpo', glob($work . '/*') === []);
} finally {
    proc_terminate($server);
    proc_close($server);
    exec('rm -rf ' . escapeshellarg($tmp));
}

echo "\n" . ($total - $failures) . "/{$total} verificações passaram.\n";
exit($failures === 0 ? 0 : 1);
