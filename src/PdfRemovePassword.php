<?php

declare(strict_types=1);

final class PdfRemovePassword
{
    public function __construct(
        private readonly PdfProcess $process
    ) {}

    public function removePassword(
        string $inputFile,
        string $outputFile,
        string $password = ''
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

        // Senha vazia: PDF só com senha de proprietário, que o qpdf
        // descriptografa sem precisar dela.
        $arguments = $password !== ''
            ? ['--password=' . $password]
            : [];

        $arguments[] = '--decrypt';
        $arguments[] = $inputFile;
        $arguments[] = $outputFile;

        // allowWarnings: true — qpdf pode retornar exit 3 para warnings
        // que não impedem a descriptografia.
        $this->process->runQpdf($arguments, $outputDirectory, allowWarnings: true);

        if (!is_file($outputFile) || filesize($outputFile) === 0) {
            throw new RuntimeException(
                'O PDF sem senha não foi criado corretamente.'
            );
        }
    }
}
