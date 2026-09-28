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

try {
    $result = $app['application']->run(
        function (string $path) use ($app): string {
            $upload = new PdfUpload();

            return $upload->save(
                $_FILES['pdf'],
                $path,
                'entrada.pdf'
            );
        }
    );

    echo 'Upload recebido: ' . strlen($result) . ' bytes';
} catch (Throwable $exception) {
    http_response_code(400);
    echo $exception->getMessage();
}