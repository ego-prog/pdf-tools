<?php

declare(strict_types=1);

/**
 * Casos de uso do PDF Tools, independentes de HTTP.
 *
 * Recebe uploads já normalizados (formato de um item de $_FILES) e
 * devolve o caminho do PDF resultante dentro do workspace. Usado tanto
 * pela interface quanto pela API.
 *
 * Erros previstos saem como PdfToolException; qualquer outra exceção
 * é tratada pelo chamador como erro interno.
 */
final class PdfService
{
    /**
     * Resoluções tentadas, em ordem, quando o PDF unido passa do limite
     * de tamanho. 72 dpi é o piso: abaixo disso documentos digitalizados
     * ficam ilegíveis.
     */
    private const COMPRESSION_STEPS = [150, 125, 100, 85, 72];

    /**
     * Tempo total (segundos, desde o início do merge) para as tentativas
     * de compactação. Fica abaixo do request_terminate_timeout do pool
     * (330 s), para responder com erro antes de o PHP-FPM matar o processo.
     */
    private const COMPRESSION_DEADLINE_SECONDS = 270;

    public function __construct(
        private readonly PdfProcess $process,
        private readonly PdfUpload $upload
    ) {}

    /**
     * Une vários PDFs, na ordem recebida.
     *
     * PDFs com assinatura digital entram com o aviso no lugar da
     * assinatura (PdfSignatureNotice). Com $maxBytes, o resultado é
     * compactado progressivamente até caber no limite.
     *
     * @param list<array>        $files
     * @param array<int, string> $passwords Índice do arquivo => senha
     */
    public function merge(
        string $workspace,
        array $files,
        array $passwords,
        ?int $maxBytes = null
    ): PdfResult {
        $startedAt = microtime(true);

        if (count($files) < 2) {
            throw new PdfToolException(
                'invalid_request',
                'É necessário enviar pelo menos dois arquivos PDF.'
            );
        }

        $inputFiles    = [];
        $filePasswords = [];

        foreach ($files as $index => $file) {
            $inputFile = $this->upload->save(
                $file,
                $workspace,
                'entrada-' . $index . '.pdf'
            );

            $inputFiles[] = $inputFile;

            $password = $passwords[$index] ?? '';

            if ($password !== '') {
                $filePasswords[$inputFile] = $password;
            }
        }

        // Detecta arquivos que exigem senha e não receberam nenhuma.
        $missing = $this->detectMissingPasswords($inputFiles, $filePasswords);

        if ($missing !== []) {
            throw new PasswordRequiredException($missing);
        }

        // Assinaturas digitais: cada PDF assinado é trocado por uma cópia
        // (sem senha) com o aviso. O upload original não é alterado.
        $notice        = new PdfSignatureNotice($this->process);
        $signedIndices = [];

        foreach ($inputFiles as $index => $inputFile) {
            $noticeFile = $workspace . '/aviso-' . $index . '.pdf';

            try {
                $signatures = $notice->apply(
                    $inputFile,
                    $noticeFile,
                    $filePasswords[$inputFile] ?? ''
                );
            } catch (PdfProcessException $exception) {
                throw $this->translateQpdfFailure($exception, [$index => $inputFile]);
            }

            if ($signatures > 0) {
                unset($filePasswords[$inputFile]);
                $inputFiles[$index] = $noticeFile;
                $signedIndices[]    = $index;
            }
        }

        $outputFile = $workspace . '/resultado.pdf';

        try {
            (new PdfMerge($this->process))->merge(
                $inputFiles,
                $outputFile,
                $filePasswords
            );
        } catch (PdfProcessException $exception) {
            throw $this->translateQpdfFailure($exception, $inputFiles);
        }

        $compressionDpi = null;

        if ($maxBytes !== null && $this->size($outputFile) > $maxBytes) {
            [$outputFile, $compressionDpi] = $this->fitWithinLimit(
                $workspace,
                $outputFile,
                $maxBytes,
                $startedAt + self::COMPRESSION_DEADLINE_SECONDS
            );
        }

        return new PdfResult($outputFile, $signedIndices, $compressionDpi);
    }

    /**
     * Compacta com resolução cada vez menor até o arquivo caber no limite.
     *
     * @return array{0: string, 1: int} [arquivo, resolução usada]
     */
    private function fitWithinLimit(
        string $workspace,
        string $inputFile,
        int $maxBytes,
        float $deadline
    ): array {
        $compress = new PdfCompress($this->process);
        $smallest = $this->size($inputFile);
        $previous = null;

        foreach (self::COMPRESSION_STEPS as $resolution) {
            $remaining = (int) floor($deadline - microtime(true));

            if ($remaining < 5) {
                throw new PdfToolException(
                    'timeout',
                    'Não houve tempo para compactar o PDF até o tamanho máximo.',
                    504
                );
            }

            $attempt = $workspace . '/compactado-' . $resolution . '.pdf';

            $compress->compressToResolution($inputFile, $attempt, $resolution, $remaining);

            // Só a tentativa atual fica no workspace (em memória).
            if ($previous !== null) {
                @unlink($previous);
            }

            $previous = $attempt;
            $size     = $this->size($attempt);
            $smallest = min($smallest, $size);

            if ($size <= $maxBytes) {
                return [$attempt, $resolution];
            }
        }

        throw new PdfToolException(
            'size_limit_exceeded',
            sprintf(
                'Mesmo com a compactação máxima (72 dpi) o PDF ficou com %s, acima do limite de %s.',
                self::megabytes($smallest),
                self::megabytes($maxBytes)
            ),
            422,
            ['max_bytes' => $maxBytes, 'smallest_bytes' => $smallest]
        );
    }

    private function size(string $file): int
    {
        clearstatcache(true, $file);

        return (int) filesize($file);
    }

    private static function megabytes(int $bytes): string
    {
        return number_format($bytes / 1048576, 2, ',', '.') . ' MB';
    }

    /**
     * Compacta um único PDF. A senha só é necessária se o PDF exigir.
     */
    public function compress(
        string $workspace,
        array $file,
        string $level,
        string $password
    ): PdfResult {
        $inputFile = $this->upload->save($file, $workspace, 'entrada.pdf');

        if ($this->process->passwordRequired($inputFile)) {
            if ($password === '') {
                throw new PasswordRequiredException([0]);
            }

            // Descriptografa antes com o qpdf: a senha vai num arquivo de
            // argumentos, e não na linha de comando do Ghostscript.
            $decryptedFile = $workspace . '/entrada-sem-senha.pdf';

            try {
                (new PdfRemovePassword($this->process))->removePassword(
                    $inputFile,
                    $decryptedFile,
                    $password
                );
            } catch (PdfProcessException $exception) {
                throw $this->translateQpdfFailure($exception, [$inputFile]);
            }

            $inputFile = $decryptedFile;
        }

        $outputFile = $workspace . '/resultado.pdf';

        (new PdfCompress($this->process))->compress(
            $inputFile,
            $outputFile,
            $level
        );

        return new PdfResult($outputFile);
    }

    /**
     * Remove a senha de um PDF.
     */
    public function removePassword(
        string $workspace,
        array $file,
        string $password
    ): PdfResult {
        $inputFile = $this->upload->save($file, $workspace, 'entrada.pdf');

        // Se o PDF não é criptografado, não há nada a remover.
        if (!$this->process->isEncrypted($inputFile)) {
            throw new PdfToolException(
                'no_password',
                'Este PDF não possui senha para remover.'
            );
        }

        if ($this->process->passwordRequired($inputFile)) {
            if ($password === '') {
                throw new PasswordRequiredException([0]);
            }
        } else {
            // Só senha de proprietário (restringe impressão, cópia etc.):
            // o qpdf remove sem senha, e uma senha digitada errada faria
            // a operação falhar à toa.
            $password = '';
        }

        $outputFile = $workspace . '/resultado.pdf';

        try {
            (new PdfRemovePassword($this->process))->removePassword(
                $inputFile,
                $outputFile,
                $password
            );
        } catch (PdfProcessException $exception) {
            throw $this->translateQpdfFailure($exception, [$inputFile]);
        }

        return new PdfResult($outputFile);
    }

    /**
     * Retorna os índices dos arquivos que exigem senha e não receberam nenhuma.
     *
     * @param string[]              $inputFiles
     * @param array<string, string> $passwords
     * @return int[]
     */
    private function detectMissingPasswords(array $inputFiles, array $passwords): array
    {
        $missing = [];

        foreach ($inputFiles as $index => $inputFile) {
            if (isset($passwords[$inputFile])) {
                continue;   // senha já fornecida — deixa o qpdf validar
            }

            if ($this->process->passwordRequired($inputFile)) {
                $missing[] = $index;
            }
        }

        return $missing;
    }

    /**
     * Converte a falha do qpdf num erro com código. O qpdf cita o
     * arquivo problemático na saída ("qpdf: <caminho>: invalid password"),
     * o que permite apontar qual dos arquivos falhou.
     *
     * @param array<int, string> $inputFiles Índice do arquivo => caminho
     */
    private function translateQpdfFailure(
        PdfProcessException $exception,
        array $inputFiles
    ): PdfToolException {
        $indices = [];

        foreach ($inputFiles as $index => $inputFile) {
            if (str_contains($exception->output, $inputFile . ':')) {
                $indices[] = $index;
            }
        }

        if (str_contains($exception->output, 'invalid password')) {
            return new PdfToolException(
                'invalid_password',
                count($inputFiles) > 1
                    ? 'A senha informada para os arquivos destacados está incorreta.'
                    : 'A senha informada está incorreta.',
                422,
                ['indices' => $indices]
            );
        }

        error_log('qpdf falhou: ' . $exception->getMessage());

        return new PdfToolException(
            'invalid_pdf',
            'Não foi possível processar o PDF. Verifique se o arquivo não está corrompido.',
            422,
            ['indices' => $indices]
        );
    }
}
