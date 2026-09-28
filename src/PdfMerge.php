<?php

declare(strict_types=1);

final class PdfMerge
{
    public function __construct(
        private readonly PdfProcess $process
    ) {
    }

    /**
     * @param string[] $inputFiles
     */
    public function merge(array $inputFiles, string $outputFile): void
    {
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

        $command = [
            $this->process->qpdf(),
            '--empty',
            '--pages',
            ...$inputFiles,
            '--',
            $outputFile,
        ];

        $this->process->run($command);

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
