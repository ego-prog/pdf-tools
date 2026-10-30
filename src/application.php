<?php

declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

$process = new PdfProcess(
    $config['tools']['qpdf'],
    $config['tools']['ghostscript'],
    $config['tools']['timeout'],
    $config['process_timeout']
);

$workspace = new PdfWorkspace(
    $config['storage']['temporary_path']
);

$pdfApplication = new PdfApplication(
    $workspace
);

return [
    'config' => $config,
    'process' => $process,
    'workspace' => $workspace,
    'application' => $pdfApplication,
];
