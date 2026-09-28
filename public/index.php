<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$app = require __DIR__ . '/../src/application.php';

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
            <p class="subtitle">Una arquivos PDF de forma local e temporária.</p>

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Método não permitido.';
    exit;
}

$operation = $_POST['operation'] ?? '';

if (!isset($_FILES['pdf'])) {
    http_response_code(400);
    echo 'Nenhum PDF enviado.';
    exit;
}

try {
    $result = $app['application']->run(
        function (string $path) use ($app, $operation): string {
            $upload = new PdfUpload();

            if ($operation === 'merge') {
                if (!is_array($_FILES['pdf']['name'])) {
                    throw new InvalidArgumentException(
                        'Envie dois ou mais arquivos PDF.'
                    );
                }

                $inputFiles = [];
                $passwords = [];

                foreach ($_FILES['pdf']['name'] as $index => $name) {
                    $file = [
                        'name' => $_FILES['pdf']['name'][$index],
                        'type' => $_FILES['pdf']['type'][$index],
                        'tmp_name' => $_FILES['pdf']['tmp_name'][$index],
                        'error' => $_FILES['pdf']['error'][$index],
                        'size' => $_FILES['pdf']['size'][$index],
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

                $outputFile = $path . '/resultado.pdf';

                $merge = new PdfMerge($app['process']);

                $merge->merge(
                    $inputFiles,
                    $outputFile,
                    $passwords
                );

                return $outputFile;
            }

            if ($operation === 'compress') {
                if (is_array($_FILES['pdf']['name'])) {
                    throw new InvalidArgumentException(
                        'Envie apenas um PDF para compactação.'
                    );
                }

                $inputFile = $upload->save(
                    $_FILES['pdf'],
                    $path,
                    'entrada.pdf'
                );

                $outputFile = $path . '/resultado.pdf';

                $level = $_POST['level'] ?? 'media';

                $compress = new PdfCompress(
                    $app['process']
                );

                $compress->compress(
                    $inputFile,
                    $outputFile,
                    $level
                );

                return $outputFile;
            }

            throw new InvalidArgumentException(
                'Operação inválida.'
            );
        }
    );

    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($result));
    header('Content-Disposition: attachment; filename="resultado.pdf"');

    echo $result;
} catch (Throwable $exception) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $exception->getMessage();
}
