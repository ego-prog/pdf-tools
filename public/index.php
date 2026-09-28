<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$app = require __DIR__ . '/../src/application.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo 'PDF Tools - aguardando upload';
    exit;
}

if (!isset($_FILES['pdf'])) {
    http_response_code(400);
    echo 'Nenhum PDF enviado.';
    exit;
}

if (!is_array($_FILES['pdf']['name'])) {
    http_response_code(400);
    echo 'Envie dois ou mais arquivos PDF.';
    exit;
}

try {
    $result = $app['application']->run(
        function (string $path) use ($app): string {
            $upload = new PdfUpload();

            $inputFiles = [];

            foreach ($_FILES['pdf']['name'] as $index => $name) {
                $file = [
                    'name' => $_FILES['pdf']['name'][$index],
                    'type' => $_FILES['pdf']['type'][$index],
                    'tmp_name' => $_FILES['pdf']['tmp_name'][$index],
                    'error' => $_FILES['pdf']['error'][$index],
                    'size' => $_FILES['pdf']['size'][$index],
                ];

                $inputFiles[] = $upload->save(
                    $file,
                    $path,
                    'entrada-' . $index . '.pdf'
                );
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
                $outputFile
            );

            return $outputFile;
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