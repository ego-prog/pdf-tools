<?php

declare(strict_types=1);

final class PdfCompress
{
    private const LEVELS = [
        'baixa' => 150,
        'media' => 100,
        'alta'  => 72,
    ];

    public function __construct(
        private readonly PdfProcess $process
    ) {
    }

    public function compress(
        string $inputFile,
        string $outputFile,
        string $level = 'media'
    ): void {
        $this->validateInputFile($inputFile);

        if (!isset(self::LEVELS[$level])) {
            throw new InvalidArgumentException(
                "Nível de compactação inválido: {$level}"
            );
        }

        $outputDirectory = dirname($outputFile);

        if (!is_dir($outputDirectory)) {
            throw new RuntimeException(
                "Diretório de saída não existe: {$outputDirectory}"
            );
        }

        if (realpath($inputFile) === realpath($outputFile)) {
            throw new InvalidArgumentException(
                'O arquivo de entrada e o arquivo de saída devem ser diferentes.'
            );
        }

        $resolution = self::LEVELS[$level];

        $command = [
            $this->process->ghostscript(),
            '-q',
            '-dBATCH',
            '-dNOPAUSE',
            '-dSAFER',
            '-sDEVICE=pdfwrite',

            '-dDownsampleColorImages=true',
            '-dColorImageResolution=' . $resolution,

            '-dDownsampleGrayImages=true',
            '-dGrayImageResolution=' . $resolution,

            '-dDownsampleMonoImages=true',
            '-dMonoImageResolution=' . $resolution,

            '-sOutputFile=' . $outputFile,
            $inputFile,
        ];

        $this->process->run($command);

        if (!is_file($outputFile) || filesize($outputFile) === 0) {
            throw new RuntimeException(
                'O PDF compactado não foi criado corretamente.'
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