<?php

declare(strict_types=1);

final class PdfProcess
{
    // Exit codes do coreutils timeout: 124 = tempo esgotado (SIGTERM),
    // 137 = processo morto com SIGKILL após --kill-after.
    private const TIMEOUT_EXIT_CODES = [124, 137];

    private const KILL_AFTER_SECONDS = 5;

    public function __construct(
        private readonly string $qpdf,
        private readonly string $ghostscript,
        private readonly string $timeout,
        private readonly int $timeoutSeconds
    ) {
        $this->validateBinary($this->qpdf, 'qpdf');
        $this->validateBinary($this->ghostscript, 'ghostscript');
        $this->validateBinary($this->timeout, 'timeout');

        if ($this->timeoutSeconds < 1) {
            throw new RuntimeException(
                'O tempo limite dos processos deve ser de pelo menos 1 segundo.'
            );
        }
    }

    /**
     * Executa um comando externo sem passar por shell.
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

        [$exitCode, $output] = $this->execute($command);

        $ok = $exitCode === 0
            || ($allowWarnings && $exitCode === 3);

        if (!$ok) {
            throw new PdfProcessException($exitCode, $output);
        }

        return $output;
    }

    /**
     * Executa o qpdf lendo os argumentos de um arquivo (qpdf @arquivo).
     *
     * Assim senhas não aparecem na lista de processos (ps,
     * /proc/<pid>/cmdline), visível a outros usuários do servidor.
     * O arquivo é criado com permissão 0600 dentro do workspace e
     * removido logo após a execução.
     *
     * @param string[] $arguments Um argumento por linha no arquivo
     */
    public function runQpdf(
        array $arguments,
        string $workDirectory,
        bool $allowWarnings = false
    ): string {
        foreach ($arguments as $argument) {
            if (str_contains($argument, "\n") || str_contains($argument, "\r")) {
                throw new PdfToolException(
                    'invalid_request',
                    'A senha não pode conter quebras de linha.'
                );
            }
        }

        $argFile = tempnam($workDirectory, 'qpdf-args-');

        if ($argFile === false) {
            throw new RuntimeException(
                'Não foi possível criar o arquivo de argumentos do qpdf.'
            );
        }

        try {
            chmod($argFile, 0600);

            $written = file_put_contents(
                $argFile,
                implode("\n", $arguments) . "\n"
            );

            if ($written === false) {
                throw new RuntimeException(
                    'Não foi possível gravar o arquivo de argumentos do qpdf.'
                );
            }

            return $this->run([$this->qpdf, '@' . $argFile], $allowWarnings);
        } finally {
            @unlink($argFile);
        }
    }

    public function ghostscript(): string
    {
        return $this->ghostscript;
    }

    /**
     * Retorna true se for preciso uma senha para abrir o PDF.
     *
     * qpdf --requires-password:
     * exit 0 → senha obrigatória
     * exit 2 → não criptografado
     * exit 3 → criptografado, mas abre sem senha (só senha de proprietário)
     */
    public function passwordRequired(string $file): bool
    {
        [$exitCode, $output] = $this->execute(
            [$this->qpdf, '--requires-password', $file]
        );

        return match ($exitCode) {
            0       => true,
            2, 3    => false,
            default => throw new PdfProcessException($exitCode, $output),
        };
    }

    /**
     * Retorna true se o PDF estiver criptografado, mesmo que abra sem
     * senha (PDF só com senha de proprietário, que restringe impressão,
     * cópia etc.).
     *
     * qpdf --is-encrypted: exit 0 → criptografado; exit 2 → não.
     */
    public function isEncrypted(string $file): bool
    {
        [$exitCode, $output] = $this->execute(
            [$this->qpdf, '--is-encrypted', $file]
        );

        return match ($exitCode) {
            0       => true,
            2       => false,
            default => throw new PdfProcessException($exitCode, $output),
        };
    }

    /**
     * Executa o comando com tempo limite (coreutils timeout).
     *
     * O max_execution_time do PHP não conta o tempo de espera por um
     * processo filho; sem o timeout, um PDF malicioso poderia prender o
     * qpdf/gs indefinidamente.
     *
     * stderr é redirecionado para stdout para evitar deadlock ao ler
     * dois pipes (o buffer do pipe não lido pode encher e travar o filho).
     *
     * @param string[] $command
     * @return array{0: int, 1: string} [exit code, saída]
     */
    private function execute(array $command): array
    {
        $command = [
            $this->timeout,
            '--kill-after=' . self::KILL_AFTER_SECONDS,
            (string) $this->timeoutSeconds,
            ...$command,
        ];

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

        if (in_array($exitCode, self::TIMEOUT_EXIT_CODES, true)) {
            throw new PdfToolException(
                'timeout',
                'O processamento do PDF excedeu o tempo limite.',
                504
            );
        }

        return [$exitCode, trim((string) $output)];
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
