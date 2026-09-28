<?php

declare(strict_types=1);

final class PdfProcess
{
    public function __construct(
        private readonly string $qpdf,
        private readonly string $ghostscript
    ) {
        $this->validateBinary($this->qpdf, 'qpdf');
        $this->validateBinary($this->ghostscript, 'ghostscript');
    }

    public function run(array $command): void
    {
        if ($command === []) {
            throw new RuntimeException('Comando vazio.');
        }

        $escapedCommand = array_map(
            static fn(string $argument): string => escapeshellarg($argument),
            $command
        );

        $commandLine = implode(' ', $escapedCommand);

        exec(
            $commandLine . ' 2>&1',
            $output,
            $exitCode
        );

        if ($exitCode !== 0 && $exitCode !== 3) {
            throw new RuntimeException(
                "Falha ao executar PDF Tools.\n" .
                    implode("\n", $output)
            );
        }
    }

    public function qpdf(): string
    {
        return $this->qpdf;
    }

    public function ghostscript(): string
    {
        return $this->ghostscript;
    }

    private function validateBinary(string $binary, string $name): void
    {
        if (!is_file($binary) || !is_executable($binary)) {
            throw new RuntimeException(
                "Executável do {$name} não encontrado: {$binary}"
            );
        }
    }
}
