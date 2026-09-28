<?php

declare(strict_types=1);

final class PdfApplication
{
    public function __construct(
        private readonly PdfWorkspace $workspace
    ) {
    }

    /**
     * Executa uma operação dentro de um workspace temporário.
     *
     * @param callable(string): string $operation
     */
    public function run(callable $operation): string
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

            $content = file_get_contents($result);

            if ($content === false) {
                throw new RuntimeException(
                    'Não foi possível ler o arquivo de resultado.'
                );
            }

            return $content;
        } finally {
            $this->workspace->cleanup();
        }
    }
}
