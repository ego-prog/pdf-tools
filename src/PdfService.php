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
    public function __construct(
        private readonly PdfProcess $process,
        private readonly PdfUpload $upload
    ) {}

    /**
     * Une vários PDFs, na ordem recebida.
     *
     * @param list<array>        $files
     * @param array<int, string> $passwords Índice do arquivo => senha
     */
    public function merge(string $workspace, array $files, array $passwords): string
    {
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

        return $outputFile;
    }

    /**
     * Compacta um único PDF. A senha só é necessária se o PDF exigir.
     */
    public function compress(
        string $workspace,
        array $file,
        string $level,
        string $password
    ): string {
        $inputFile = $this->upload->save($file, $workspace, 'entrada.pdf');

        // Se o PDF exige senha e nenhuma foi enviada → pede.
        if ($password === '' && $this->process->passwordRequired($inputFile)) {
            throw new PasswordRequiredException([0]);
        }

        $outputFile = $workspace . '/resultado.pdf';

        (new PdfCompress($this->process))->compress(
            $inputFile,
            $outputFile,
            $level,
            $password
        );

        return $outputFile;
    }

    /**
     * Remove a senha de um PDF.
     */
    public function removePassword(
        string $workspace,
        array $file,
        string $password
    ): string {
        $inputFile = $this->upload->save($file, $workspace, 'entrada.pdf');

        // Se o PDF não tem senha, não há nada a remover.
        if (!$this->process->passwordRequired($inputFile)) {
            throw new PdfToolException(
                'no_password',
                'Este PDF não possui senha para remover.'
            );
        }

        if ($password === '') {
            throw new PasswordRequiredException([0]);
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

        return $outputFile;
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
     * @param string[] $inputFiles
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
