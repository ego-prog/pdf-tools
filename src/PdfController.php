<?php

declare(strict_types=1);

/**
 * Execução das operações via HTTP, comum à interface e à API.
 */
final class PdfController
{
    /**
     * Executa a ação e converte qualquer exceção em resposta JSON.
     */
    public static function respond(callable $action): void
    {
        try {
            $action();
        } catch (PdfToolException $exception) {
            PdfResponse::error($exception);
        } catch (Throwable $exception) {
            PdfResponse::internalError($exception);
        }
    }

    /**
     * Processa uma operação a partir de $_POST/$_FILES e envia o PDF.
     *
     * @param string $operation merge | compress | remove_password
     */
    public static function process(string $operation): void
    {
        if (!in_array($operation, ['merge', 'compress', 'remove_password'], true)) {
            throw new PdfToolException(
                'invalid_operation',
                'Operação inválida.'
            );
        }

        // Dentro do respond(): falha de configuração (binário ausente,
        // diretório sem permissão) também vira resposta JSON.
        $app = require __DIR__ . '/application.php';

        PdfRequest::assertBodyReceived();

        $service = new PdfService($app['process'], new PdfUpload());

        $app['application']->run(
            fn (string $path): PdfResult => match ($operation) {
                'merge' => $service->merge(
                    $path,
                    PdfRequest::uploads('pdf'),
                    PdfRequest::stringList('password'),
                    PdfRequest::maxBytes('max_bytes')
                ),
                'compress' => $service->compress(
                    $path,
                    PdfRequest::singleUpload('pdf'),
                    PdfRequest::string('level', 'media'),
                    PdfRequest::string('password')
                ),
                'remove_password' => $service->removePassword(
                    $path,
                    PdfRequest::singleUpload('pdf'),
                    PdfRequest::string('password')
                ),
            },
            fn (PdfResult $result) => PdfResponse::pdf($result)
        );
    }

    public static function assertMethod(string $method): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== $method) {
            throw new PdfToolException(
                'method_not_allowed',
                'Método não permitido.',
                405
            );
        }
    }
}
