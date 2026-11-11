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
    /**
     * Cabeçalhos informativos (não alteram o PDF):
     *   X-Pdf-Signed-Documents: 0,2   arquivos assinados que receberam aviso
     *   X-Pdf-Compression-Dpi: 100    resolução usada para caber no limite
     */
    public static function pdf(PdfResult $result, string $filename = 'resultado.pdf'): void
    {
        clearstatcache(true, $result->path);

        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($result->path));
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        header('X-Pdf-Signed-Documents: ' . implode(',', $result->signedIndices));

        if ($result->compressionDpi !== null) {
            header('X-Pdf-Compression-Dpi: ' . $result->compressionDpi);
        }

        readfile($result->path);
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
    public static function json(int $status, array $data): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store');

            if ($status === 401) {
                header('WWW-Authenticate: Bearer');
            }
        }

        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
