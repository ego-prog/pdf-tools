<?php

declare(strict_types=1);

/**
 * Falha de um processo externo (qpdf/gs). Carrega a saída bruta para
 * diagnóstico — nunca deve ser exibida diretamente ao usuário.
 */
final class PdfProcessException extends RuntimeException
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $output
    ) {
        parent::__construct(
            "Falha ao executar PDF Tools (exit {$exitCode}).\n{$output}"
        );
    }
}
