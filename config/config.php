<?php

return [
    'module_name' => 'PDF Tools',

    'storage' => [
        // Em memória (tmpfs): uploads e resultados nunca vão para o disco.
        // Criado sob demanda (o /dev/shm é esvaziado a cada boot).
        // PDF_TOOLS_TMP: só para os testes (tests/run.php).
        'temporary_path' => getenv('PDF_TOOLS_TMP') ?: '/dev/shm/pdf-tools',
    ],

    'tools' => [
        'qpdf' => '/usr/bin/qpdf',
        'ghostscript' => '/usr/bin/gs',
        'timeout' => '/usr/bin/timeout',
    ],

    // Tempo máximo (segundos) de cada execução do qpdf/gs.
    'process_timeout' => 120,

    'api' => [
        // Hashes das chaves de API (fora do git). Gerencie com:
        // php bin/api-key.php add <cliente>
        'keys_file' => __DIR__ . '/api-keys.json',
    ],
];
