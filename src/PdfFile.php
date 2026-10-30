<?php

declare(strict_types=1);

/**
 * Verificações de arquivo PDF compartilhadas pelo upload e pelas operações.
 */
final class PdfFile
{
    // Leitores de PDF (Acrobat, qpdf, gs) aceitam o cabeçalho %PDF- em
    // qualquer posição dentro do primeiro 1 KB; alguns geradores gravam
    // bytes antes dele.
    private const HEADER_SEARCH_BYTES = 1024;

    /**
     * Verifica pelos bytes iniciais (%PDF-). É mais confiável do que
     * mime_content_type(), que pode retornar application/x-pdf ou
     * application/octet-stream em PDFs válidos.
     */
    public static function hasPdfHeader(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $start = fread($handle, self::HEADER_SEARCH_BYTES);
        fclose($handle);

        return is_string($start) && str_contains($start, '%PDF-');
    }

    /**
     * Garante que um arquivo de entrada existe, pode ser lido e é PDF.
     *
     * Os arquivos chegam aqui já validados pelo PdfUpload; uma falha
     * indica erro interno, não do usuário.
     */
    public static function assertValidInput(string $path): void
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException(
                "Arquivo não encontrado: {$path}"
            );
        }

        if (!is_readable($path)) {
            throw new InvalidArgumentException(
                "Arquivo não pode ser lido: {$path}"
            );
        }

        if (!self::hasPdfHeader($path)) {
            throw new InvalidArgumentException(
                "O arquivo não é um PDF válido: {$path}"
            );
        }
    }
}
