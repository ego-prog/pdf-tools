<?php

return [
    'module_name' => 'PDF Tools',

    'storage' => [
        'temporary_path' => __DIR__ . '/../storage/tmp',
    ],

    'tools' => [
        'qpdf' => '/usr/bin/qpdf',
        'ghostscript' => '/usr/bin/gs',
        'timeout' => '/usr/bin/timeout',
    ],

    // Tempo máximo (segundos) de cada execução do qpdf/gs.
    'process_timeout' => 120,
];
