<?php

declare(strict_types=1);

final class PdfWorkspace
{
    private string $path;

    public function __construct(
        private readonly string $basePath
    ) {
        if (!is_dir($this->basePath) && !@mkdir($this->basePath, 0700, true) && !is_dir($this->basePath)) {
            throw new RuntimeException(
                "Diretório temporário não existe: {$this->basePath}"
            );
        }

        if (!is_writable($this->basePath)) {
            throw new RuntimeException(
                "Diretório temporário não permite gravação: {$this->basePath}"
            );
        }

        $this->path = '';
    }

    public function create(): string
    {
        if ($this->path !== '') {
            throw new RuntimeException(
                'O workspace já foi criado.'
            );
        }

        $directory = $this->basePath . '/' . bin2hex(random_bytes(16));

        if (!mkdir($directory, 0700)) {
            throw new RuntimeException(
                'Não foi possível criar o workspace temporário.'
            );
        }

        $this->path = $directory;

        return $this->path;
    }

    public function path(): string
    {
        if ($this->path === '') {
            throw new RuntimeException(
                'O workspace ainda não foi criado.'
            );
        }

        return $this->path;
    }

    /**
     * Remove o workspace temporário.
     *
     * Nunca lança exceção — falhas são apenas registradas no error_log.
     * Isso é intencional: cleanup roda em finally, e uma exceção aqui
     * mascararia o erro original da operação.
     */
    public function cleanup(): void
    {
        if ($this->path === '' || !is_dir($this->path)) {
            $this->path = '';
            return;
        }

        try {
            $this->removeDirectory($this->path);
        } catch (Throwable $exception) {
            // Só registra, não propaga.
            error_log(
                'Falha ao limpar workspace: ' . $exception->getMessage()
            );
        } finally {
            $this->path = '';
        }
    }

    /**
     * Remove um diretório recursivamente.
     *
     * Não lança exceção: falhas individuais (arquivo já removido,
     * permissão negada, etc.) são apenas registradas no error_log.
     */
    private function removeDirectory(string $directory): void
    {
        $items = @scandir($directory);

        if ($items === false) {
            // Diretório já não existe ou não pode ser lido.
            error_log("Não foi possível listar diretório: {$directory}");
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;

            // Usa lstat para não seguir symlinks.
            $stat = @lstat($path);

            if ($stat === false) {
                // Sumiu entre scandir e lstat — TOCTOU.
                continue;
            }

            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
                continue;
            }

            if (!@unlink($path)) {
                error_log("Falha ao remover arquivo: {$path}");
            }
        }

        if (!@rmdir($directory)) {
            error_log("Falha ao remover diretório: {$directory}");
        }
    }
}
