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
        string $password
    ): void {
        $this->validateInputFile($inputFile);

        if ($password === '') {
            throw new InvalidArgumentException(
                'A senha é obrigatória.'
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

        $command = [
            $this->process->qpdf(),
            '--password=' . $password,
            '--decrypt',
            $inputFile,
            $outputFile,
        ];

        // allowWarnings: true — qpdf pode retornar exit 3 para warnings
        // que não impedem a descriptografia.
        $this->process->run($command, allowWarnings: true);

        if (!is_file($outputFile) || filesize($outputFile) === 0) {
            throw new RuntimeException(
                'O PDF sem senha não foi criado corretamente.'
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
