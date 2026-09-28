<?php

declare(strict_types=1);

final class PdfWorkspace
{
    private string $path;

    public function __construct(
        private readonly string $basePath
    ) {
        if (!is_dir($this->basePath)) {
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

    public function cleanup(): void
    {
        if ($this->path === '' || !is_dir($this->path)) {
            return;
        }

        $this->removeDirectory($this->path);

        $this->path = '';
    }

    private function removeDirectory(string $directory): void
    {
        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
