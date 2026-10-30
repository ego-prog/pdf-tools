<?php

declare(strict_types=1);

/**
 * API v1 — mesmas operações da interface, autenticadas por chave.
 *
 * POST /api/v1/merge
 * POST /api/v1/compress
 * POST /api/v1/remove-password
 * GET  /api/v1/health
 *
 * Documentação: docs/API.md
 */
final class PdfApi
{
    private const PREFIX = '/api/v1/';

    private const ROUTES = [
        'merge'           => 'merge',
        'compress'        => 'compress',
        'remove-password' => 'remove_password',
    ];

    public static function handle(string $path): void
    {
        PdfController::respond(static function () use ($path): void {
            $config = require __DIR__ . '/../config/config.php';

            // Autentica antes de rotear: sem chave, nenhuma rota é revelada.
            $client = (new PdfApiKeys($config['api']['keys_file']))
                ->authenticate();

            $route = str_starts_with($path, self::PREFIX)
                ? trim(substr($path, strlen(self::PREFIX)), '/')
                : '';

            if ($route === 'health') {
                PdfController::assertMethod('GET');

                PdfResponse::json(200, [
                    'status' => 'ok',
                    'client' => $client,
                ]);

                return;
            }

            if (!isset(self::ROUTES[$route])) {
                throw new PdfToolException(
                    'not_found',
                    'Rota não encontrada.',
                    404
                );
            }

            PdfController::assertMethod('POST');
            PdfController::process(self::ROUTES[$route]);
        });
    }
}
