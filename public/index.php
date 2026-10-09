<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$app = require __DIR__ . '/../src/application.php';

/* ============================================================
 * GET — renderiza a interface
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
?>
    <!DOCTYPE html>
    <html lang="pt-BR">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>PDF Tools</title>

        <link rel="stylesheet" href="css/app.css">
    </head>

    <body>

        <main class="container">
            <h1>PDF Tools</h1>
            <p class="subtitle">Ferramentas locais e temporárias para PDFs.</p>

            <section class="panel">

                <div class="tools">
                    <button
                        type="button"
                        class="tool-button active"
                        id="mergeTool">
                        Unir PDFs
                    </button>

                    <button
                        type="button"
                        class="tool-button"
                        id="compressTool">
                        Compactar PDF
                    </button>

                    <button
                        type="button"
                        class="tool-button"
                        id="removeTool">
                        Remover senha
                    </button>
                </div>

                <label class="dropzone" id="dropzone">
                    <input
                        type="file"
                        id="fileInput"
                        accept="application/pdf,.pdf"
                        multiple>

                    <strong id="dropzoneTitle">Selecione os arquivos PDF</strong>
                    <span id="dropzoneText">
                        Clique aqui ou arraste os arquivos para esta área.
                    </span>
                </label>

                <div
                    class="compression-options"
                    id="compressionOptions"
                    hidden>

                    <label for="compressionLevel">
                        Nível de compactação
                    </label>

                    <select id="compressionLevel">
                        <option value="baixa">Baixa</option>
                        <option value="media" selected>Média</option>
                        <option value="alta">Alta</option>
                    </select>

                </div>

                <div
                    class="single-password-options"
                    id="singlePasswordOptions"
                    hidden>

                    <label for="singlePassword">
                        Senha do PDF (se houver)
                    </label>

                    <input
                        type="password"
                        id="singlePassword"
                        placeholder="Senha">

                </div>

                <div class="file-list" id="fileList">
                    <div class="empty">
                        Nenhum arquivo selecionado.
                    </div>
                </div>

                <div class="actions">

                    <button
                        type="button"
                        class="primary"
                        id="mergeButton"
                        disabled>
                        Unir PDFs
                    </button>

                    <button
                        type="button"
                        class="secondary"
                        id="clearButton">
                        Limpar
                    </button>

                </div>

                <div id="message" class="message"></div>

            </section>
        </main>

        <script src="js/app.js"></script>

    </body>

    </html>
<?php

    exit;
}

/* ============================================================
 * Validação básica do POST
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Método não permitido.';
    exit;
}

$operation = $_POST['operation'] ?? '';

$validOperations = ['merge', 'compress', 'remove_password'];

if (!in_array($operation, $validOperations, true)) {
    http_response_code(400);
    echo 'Operação inválida.';
    exit;
}

if (!isset($_FILES['pdf'])) {
    http_response_code(400);
    echo 'Nenhum PDF enviado.';
    exit;
}

/* ============================================================
 * Processamento
 * ============================================================ */
try {
    $app['application']->run(
        function (string $path) use ($app, $operation): string {
            $upload  = new PdfUpload();
            $process = $app['process'];

            return match ($operation) {
                'merge'           => handleMerge($upload, $process, $path),
                'compress'        => handleCompress($upload, $process, $path),
                'remove_password' => handleRemovePassword($upload, $process, $path),
            };
        },
        function (string $resultPath): void {
            header('Content-Type: application/pdf');
            header('Content-Length: ' . filesize($resultPath));
            header('Content-Disposition: attachment; filename="resultado.pdf"');
            header('Cache-Control: no-store');

            readfile($resultPath);
        }
    );
} catch (PasswordRequiredException $exception) {
    if (!headers_sent()) {
        http_response_code(422);
        header('Content-Type: application/json; charset=UTF-8');
    }

    echo json_encode([
        'error'   => 'password_required',
        'indices' => $exception->indices,
        'message' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    if (!headers_sent()) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
    }

    echo $exception->getMessage();
}

/* ============================================================
 * Handlers
 * ============================================================ */

/**
 * Une vários PDFs. Senhas por arquivo em $_POST['password'][index].
 */
function handleMerge(PdfUpload $upload, PdfProcess $process, string $path): string
{
    if (!is_array($_FILES['pdf']['name'])) {
        throw new InvalidArgumentException(
            'Envie dois ou mais arquivos PDF.'
        );
    }

    $inputFiles = [];
    $passwords  = [];

    foreach ($_FILES['pdf']['name'] as $index => $name) {
        $file = [
            'name'     => $_FILES['pdf']['name'][$index],
            'type'     => $_FILES['pdf']['type'][$index],
            'tmp_name' => $_FILES['pdf']['tmp_name'][$index],
            'error'    => $_FILES['pdf']['error'][$index],
            'size'     => $_FILES['pdf']['size'][$index],
        ];

        $inputFile = $upload->save(
            $file,
            $path,
            'entrada-' . $index . '.pdf'
        );

        $inputFiles[] = $inputFile;

        $password = $_POST['password'][$index] ?? '';

        if ($password !== '') {
            $passwords[$inputFile] = $password;
        }
    }

    if (count($inputFiles) < 2) {
        throw new InvalidArgumentException(
            'É necessário enviar pelo menos dois arquivos PDF.'
        );
    }

    // Detecta arquivos que exigem senha e não receberam nenhuma.
    $missing = detectMissingPasswords($process, $inputFiles, $passwords);

    if ($missing !== []) {
        throw new PasswordRequiredException($missing);
    }

    $outputFile = $path . '/resultado.pdf';

    (new PdfMerge($process))->merge(
        $inputFiles,
        $outputFile,
        $passwords
    );

    return $outputFile;
}

/**
 * Compacta um único PDF. Senha opcional em $_POST['password'].
 */
function handleCompress(PdfUpload $upload, PdfProcess $process, string $path): string
{
    if (is_array($_FILES['pdf']['name'])) {
        throw new InvalidArgumentException(
            'Envie apenas um PDF para compactação.'
        );
    }

    $inputFile = $upload->save($_FILES['pdf'], $path, 'entrada.pdf');

    $password = $_POST['password'] ?? '';

    // Se o PDF exige senha e nenhuma foi enviada → pede.
    if ($password === '' && $process->passwordRequired($inputFile)) {
        throw new PasswordRequiredException([0]);
    }

    $outputFile = $path . '/resultado.pdf';
    $level      = $_POST['level'] ?? 'media';

    (new PdfCompress($process))->compress(
        $inputFile,
        $outputFile,
        $level,
        $password
    );

    return $outputFile;
}

/**
 * Remove a senha de um PDF. Senha obrigatória em $_POST['password'].
 */
function handleRemovePassword(PdfUpload $upload, PdfProcess $process, string $path): string
{
    if (is_array($_FILES['pdf']['name'])) {
        throw new InvalidArgumentException(
            'Envie apenas um PDF para remover a senha.'
        );
    }

    $inputFile = $upload->save($_FILES['pdf'], $path, 'entrada.pdf');

    // Se o PDF não tem senha, não há nada a remover.
    if (!$process->passwordRequired($inputFile)) {
        throw new InvalidArgumentException(
            'Este PDF não possui senha para remover.'
        );
    }

    $password = $_POST['password'] ?? '';

    if ($password === '') {
        throw new PasswordRequiredException([0]);
    }

    $outputFile = $path . '/resultado.pdf';

    (new PdfRemovePassword($process))->removePassword(
        $inputFile,
        $outputFile,
        $password
    );

    return $outputFile;
}

/**
 * Retorna os índices dos arquivos que exigem senha e não receberam nenhuma.
 *
 * @param string[]              $inputFiles
 * @param array<string, string> $passwords
 * @return int[]
 */
function detectMissingPasswords(
    PdfProcess $process,
    array $inputFiles,
    array $passwords
): array {
    $missing = [];

    foreach ($inputFiles as $index => $inputFile) {
        if (isset($passwords[$inputFile])) {
            continue;   // senha já fornecida — deixa o qpdf validar
        }

        if ($process->passwordRequired($inputFile)) {
            $missing[] = $index;
        }
    }

    return $missing;
}
