<?php

declare(strict_types=1);

/**
 * Respostas HTTP do PDF Tools.
 *
 * Contrato: sucesso devolve o PDF em binário; qualquer erro devolve JSON
 * no formato {"error": "<código>", "message": "<texto>", ...detalhes}.
 */
final class PdfResponse
{
    public static function pdf(string $path, string $filename = 'resultado.pdf'): void
    {
        clearstatcache(true, $path);

        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');

        readfile($path);
    }

    public static function error(PdfToolException $exception): void
    {
        self::json($exception->httpStatus, [
            'error'   => $exception->errorCode,
            'message' => $exception->getMessage(),
        ] + $exception->details);
    }

    /**
     * Erro inesperado: registra os detalhes no log e devolve mensagem
     * genérica, sem expor caminhos ou saída das ferramentas.
     */
    public static function internalError(Throwable $exception): void
    {
        error_log('PDF Tools — erro interno: ' . $exception);

        self::error(new PdfToolException(
            'internal_error',
            'Erro interno ao processar o PDF.',
            500
        ));
    }

    /** @param array<string, mixed> $data */
    private static function json(int $status, array $data): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store');
        }

        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
