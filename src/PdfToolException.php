<?php

declare(strict_types=1);

/**
 * Erro previsto, com código estável para os clientes (interface e API).
 *
 * A mensagem é sempre segura para exibir ao usuário: nunca contém
 * caminhos do servidor nem saída bruta das ferramentas.
 */
class PdfToolException extends RuntimeException
{
    /** @param array<string, mixed> $details Campos extras da resposta JSON */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 400,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }
}
