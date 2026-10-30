<?php

declare(strict_types=1);

/**
 * Gerencia as chaves da API v1.
 *
 *   php bin/api-key.php add <cliente>     cria ou substitui a chave
 *   php bin/api-key.php remove <cliente>  revoga a chave
 *   php bin/api-key.php list              lista os clientes
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../src/bootstrap.php';

$config = require __DIR__ . '/../config/config.php';
$keys   = new PdfApiKeys($config['api']['keys_file']);

$command = $argv[1] ?? '';
$client  = $argv[2] ?? '';

function usage(): never
{
    fwrite(STDERR, "Uso: php bin/api-key.php add|remove <cliente> | list\n");
    exit(1);
}

switch ($command) {
    case 'add':
        if (!preg_match('/^[a-z0-9_-]{1,64}$/', $client)) {
            fwrite(STDERR, "Nome do cliente: letras minúsculas, números, _ ou -.\n");
            exit(1);
        }

        $key = $keys->create($client);

        echo "Chave de \"{$client}\" (guarde agora — ela não será exibida de novo):\n\n";
        echo "{$key}\n";
        break;

    case 'remove':
        if ($client === '') {
            usage();
        }

        if (!$keys->remove($client)) {
            fwrite(STDERR, "Cliente não encontrado: {$client}\n");
            exit(1);
        }

        echo "Chave de \"{$client}\" revogada.\n";
        break;

    case 'list':
        foreach (array_keys($keys->all()) as $name) {
            echo "{$name}\n";
        }
        break;

    default:
        usage();
}
