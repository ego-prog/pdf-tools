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

    /**
     * Executa um comando externo sem passar por shell.
     *
     * @param string[] $command      Array de argumentos. O primeiro é o binário.
     * @param bool     $allowWarnings Se true, exit code 3 é tratado como sucesso
     *                                (usado pelo qpdf, que retorna 3 para warnings).
     */
    public function run(array $command, bool $allowWarnings = false): void
    {
        if ($command === []) {
            throw new RuntimeException('Comando vazio.');
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException(
                'Não foi possível iniciar o processo.'
            );
        }

        // Os binários não leem stdin — fechamos imediatamente.
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $ok = $exitCode === 0
            || ($allowWarnings && $exitCode === 3);

        if (!$ok) {
            $output = trim($stdout . "\n" . $stderr);

            throw new RuntimeException(
                "Falha ao executar PDF Tools (exit {$exitCode}).\n" . $output
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
    /**
     * Verifica se o PDF exige senha para abrir.
     *
     * Usa `qpdf --requires-password`, que retorna:
     *   exit 0 → senha obrigatória
     *   exit 2 → não precisa de senha
     *   exit 3 → erro (arquivo inválido, corrompido, etc.)
     */
    public function passwordRequired(string $file): bool
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [$this->qpdf, '--requires-password', $file],
            $descriptors,
            $pipes
        );

        if (!is_resource($process)) {
            throw new RuntimeException(
                'Não foi possível inspecionar o PDF.'
            );
        }

        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode === 0) {
            return true;
        }

        if ($exitCode === 2) {
            return false;
        }

        throw new RuntimeException(
            "Não foi possível inspecionar o PDF (código {$exitCode})."
        );
    }
}
