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
        if (!isset(self::LEVELS[$level])) {
            throw new PdfToolException(
                'invalid_request',
                'Nível de compactação inválido. Use: '
                    . implode(', ', array_keys(self::LEVELS)) . '.'
            );
        }

        $this->compressToResolution($inputFile, $outputFile, self::LEVELS[$level]);
    }

    /**
     * Reduz as imagens para no máximo $resolution dpi.
     *
     * @param ?int $timeoutSeconds limite desta execução (menor que o padrão)
     */
    public function compressToResolution(
        string $inputFile,
        string $outputFile,
        int $resolution,
        ?int $timeoutSeconds = null
    ): void {
        PdfFile::assertValidInput($inputFile);

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

        $command = [
            $this->process->ghostscript(),
            '-q',
            '-dBATCH',
            '-dNOPAUSE',
            '-dSAFER',
            '-sDEVICE=pdfwrite',
            '-dCompatibilityLevel=1.5',
            '-dDetectDuplicateImages=true',
            '-dCompressFonts=true',

            // Por padrão o Ghostscript só reduz imagens acima de 1,5× a
            // resolução pedida e copia JPEGs sem recomprimir: um PDF
            // digitalizado a 100 dpi não encolhia em nenhum nível.
            '-dPassThroughJPEGImages=false',

            // JPEG sempre: o filtro automático às vezes escolhe compressão
            // sem perdas e o arquivo fica maior que o original.
            '-dAutoFilterColorImages=false',
            '-dColorImageFilter=/DCTEncode',
            '-dAutoFilterGrayImages=false',
            '-dGrayImageFilter=/DCTEncode',
        ];

        foreach (['Color', 'Gray', 'Mono'] as $type) {
            $command[] = "-dDownsample{$type}Images=true";
            $command[] = "-d{$type}ImageResolution={$resolution}";
            $command[] = "-d{$type}ImageDownsampleThreshold=1.0";
        }

        $command[] = '-sOutputFile=' . $outputFile;
        $command[] = $inputFile;

        $output = $this->process->run($command, timeoutSeconds: $timeoutSeconds);

        $this->assertInputWasRead($output);

        if (!is_file($outputFile) || filesize($outputFile) === 0) {
            throw new RuntimeException(
                'O PDF compactado não foi criado corretamente.'
            );
        }
    }

    /**
     * O Ghostscript termina com exit 0 mesmo quando não consegue abrir o
     * arquivo (PDF protegido, PDF corrompido), gerando um PDF em branco.
     * Por isso a saída precisa ser inspecionada.
     */
    private function assertInputWasRead(string $output): void
    {
        if (str_contains($output, 'requires a password')) {
            throw new PasswordRequiredException([0]);
        }

        if (
            str_contains($output, "Couldn't initialise file")
            || str_contains($output, 'No pages will be processed')
        ) {
            error_log('Ghostscript não leu o PDF: ' . $output);

            throw new PdfToolException(
                'invalid_pdf',
                'Não foi possível ler o PDF. Verifique se o arquivo não está corrompido.',
                422,
                ['indices' => [0]]
            );
        }
    }
}
