<?php

declare(strict_types=1);

final class PdfMerge
{
    public function __construct(
        private readonly PdfProcess $process
    ) {}

    /**
     * @param string[]              $inputFiles
     * @param array<string, string> $passwords  Mapa caminho => senha
     */
    public function merge(
        array $inputFiles,
        string $outputFile,
        array $passwords = []
    ): void {
        if (count($inputFiles) < 2) {
            throw new InvalidArgumentException(
                'É necessário informar pelo menos dois arquivos PDF.'
            );
        }

        foreach ($inputFiles as $inputFile) {
            $this->validateInputFile($inputFile);
        }

        $outputDirectory = dirname($outputFile);

        if (!is_dir($outputDirectory)) {
            throw new RuntimeException(
                "Diretório de saída não existe: {$outputDirectory}"
            );
        }

        $arguments = [
            '--empty',
            '--pages',
        ];

        foreach ($inputFiles as $inputFile) {
            // Dentro de --pages: arquivo primeiro, depois --password
            $arguments[] = $inputFile;

            if (isset($passwords[$inputFile]) && $passwords[$inputFile] !== '') {
                $arguments[] = '--password=' . $passwords[$inputFile];
            }
        }

        $arguments[] = '--';
        $arguments[] = $outputFile;

        $this->process->runQpdf($arguments, $outputDirectory, allowWarnings: true);

        if (!is_file($outputFile) || filesize($outputFile) === 0) {
            throw new RuntimeException(
                'O PDF resultante não foi criado corretamente.'
            );
        }
    }

    private function validateInputFile(string $inputFile): void
    {
        if (!is_file($inputFile)) {
            throw new InvalidArgumentException(
                "Arquivo não encontrado: {$inputFile}"
            );
        }

        if (!is_readable($inputFile)) {
            throw new InvalidArgumentException(
                "Arquivo não pode ser lido: {$inputFile}"
            );
        }

        $mimeType = mime_content_type($inputFile);

        if ($mimeType !== 'application/pdf') {
            throw new InvalidArgumentException(
                "O arquivo não é um PDF válido: {$inputFile}"
            );
        }
    }
}
