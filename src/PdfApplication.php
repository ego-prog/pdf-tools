<?php

declare(strict_types=1);

final class PdfApplication
{
    public function __construct(
        private readonly PdfWorkspace $workspace
    ) {}

    /**
     * Executa uma operação dentro de um workspace temporário.
     *
     * @param callable(string): string $operation
     */
    public function run(callable $operation, callable $consumer): void
    {
        $workspacePath = $this->workspace->create();

        try {
            $result = $operation($workspacePath);

            if (!is_string($result) || $result === '') {
                throw new RuntimeException(
                    'A operação não retornou um arquivo de resultado válido.'
                );
            }

            if (!is_file($result) || filesize($result) === 0) {
                throw new RuntimeException(
                    'O arquivo de resultado não existe ou está vazio.'
                );
            }

            // Entrega o caminho para quem sabe o que fazer com ele.
            // O streaming acontece aqui dentro; ao sair, o workspace é limpo.
            $consumer($result);
        } finally {
            $this->workspace->cleanup();
        }
    }
}
