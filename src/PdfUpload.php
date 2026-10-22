<?php

declare(strict_types=1);

final class PdfUpload
{
    private const MAX_SIZE = 100 * 1024 * 1024;  // 100 MB

    public function save(
        array $file,
        string $destinationDirectory,
        string $filename
    ): string {
        $this->validateUpload($file);

        if (!is_dir($destinationDirectory)) {
            throw new RuntimeException(
                "Diretório de destino não existe: {$destinationDirectory}"
            );
        }

        if (!is_writable($destinationDirectory)) {
            throw new RuntimeException(
                "Diretório de destino não permite gravação: {$destinationDirectory}"
            );
        }

        // basename: defesa em profundidade contra path traversal.
        $destination = $destinationDirectory . '/' . basename($filename);

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException(
                'Não foi possível mover o arquivo enviado.'
            );
        }

        return $destination;
    }

    private function validateUpload(array $file): void
    {
        if (!isset(
            $file['error'],
            $file['tmp_name'],
            $file['name'],
            $file['size']
        )) {
            throw new InvalidArgumentException(
                'Dados de upload inválidos.'
            );
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException(
                'Falha no upload do arquivo. Código: ' . $file['error']
            );
        }

        if (!is_int($file['size'])) {
            throw new InvalidArgumentException(
                'Tamanho do arquivo inválido.'
            );
        }

        if ($file['size'] > self::MAX_SIZE) {
            throw new InvalidArgumentException(
                'Arquivo excede o limite de 100 MB.'
            );
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            throw new InvalidArgumentException(
                'O arquivo informado não é um upload HTTP válido.'
            );
        }

        if (!is_readable($file['tmp_name'])) {
            throw new InvalidArgumentException(
                'O arquivo enviado não pode ser lido.'
            );
        }

        // Verificação por magic bytes (%PDF-) é mais confiável do que
        // mime_content_type(), que pode retornar application/x-pdf ou
        // application/octet-stream em PDFs válidos.
        $handle = @fopen($file['tmp_name'], 'rb');

        if ($handle === false) {
            throw new InvalidArgumentException(
                'Não foi possível abrir o arquivo enviado.'
            );
        }

        $header = fread($handle, 5);
        fclose($handle);

        if ($header !== '%PDF-') {
            throw new InvalidArgumentException(
                'Somente arquivos PDF são aceitos.'
            );
        }
    }
}
