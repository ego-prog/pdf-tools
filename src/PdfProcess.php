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
     * stderr é redirecionado para stdout para evitar deadlock ao ler
     * dois pipes (o buffer do pipe não lido pode encher e travar o filho).
     *
     * @param string[] $command
     * @param bool     $allowWarnings exit code 3 do qpdf = warning
     * @return string  Saída combinada (stdout + stderr)
     */
    public function run(array $command, bool $allowWarnings = false): string
    {
        if ($command === []) {
            throw new RuntimeException('Comando vazio.');
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['redirect', 1],   // stderr → stdout
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException(
                'Não foi possível iniciar o processo.'
            );
        }

        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $exitCode = proc_close($process);

        $ok = $exitCode === 0
            || ($allowWarnings && $exitCode === 3);

        $output = trim((string) $output);

        if (!$ok) {
            throw new PdfProcessException($exitCode, $output);
        }

        return $output;
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
     * exit 0 → senha obrigatória
     * exit 2 → não precisa de senha
     * exit 3 → erro
     */
    public function passwordRequired(string $file): bool
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['redirect', 1],
        ];

        $process = proc_open(
            [$this->qpdf, '--requires-password', $file],
            $descriptors,
            $pipes
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Não foi possível inspecionar o PDF.');
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $exitCode = proc_close($process);

        // 0 → senha obrigatória
        // 2 → não criptografado
        if ($exitCode === 0) {
            return true;
        }

        if ($exitCode === 2) {
            return false;
        }

        // Código 3: avisos. Pode ser um PDF não criptografado com pequenas
        // inconsistências estruturais (ex.: "reported number of objects").
        if ($exitCode === 3) {
            $normalized = strtolower((string) $output);

            // Se a saída fala explicitamente em criptografia/senha,
            // então o código 3 provavelmente veio do estado de criptografia.
            if (
                str_contains($normalized, 'encrypt')
                || str_contains($normalized, 'password')
            ) {
                // Tenta confirmar com --show-encryption, que também emite
                // "File is not encrypted" para arquivos em claro.
                return $this->isEncrypted((string) $file);
            }

            // Caso contrário, é apenas um aviso estrutural. O arquivo
            // não exige senha para ser aberto.
            return false;
        }

        throw new RuntimeException(
            "Não foi possível inspecionar o PDF (código {$exitCode}).\n"
                . trim((string) $output)
        );
    }

    /**
     * Retorna true se o PDF estiver criptografado (mesmo que a senha
     * não seja obrigatória para abrir).
     */
    private function isEncrypted(string $file): bool
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['redirect', 1],
        ];

        $process = proc_open(
            [$this->qpdf, '--show-encryption', $file],
            $descriptors,
            $pipes
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Não foi possível inspecionar a criptografia do PDF.');
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        proc_close($process);

        // A documentação do qpdf indica que, para arquivos em claro,
        // --show-encryption imprime "File is not encrypted".
        return !str_contains((string) $output, 'File is not encrypted');
    }
}
