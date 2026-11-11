<?php

declare(strict_types=1);

/**
 * Leitura validada da requisição HTTP ($_POST / $_FILES).
 *
 * Garante tipos corretos antes de chegar ao PdfService: um campo
 * enviado como array onde se espera texto vira erro 400, não TypeError.
 */
final class PdfRequest
{
    /**
     * Se post_max_size for excedido, o PHP descarta $_POST e $_FILES
     * silenciosamente. Damos uma mensagem coerente.
     */
    public static function assertBodyReceived(): void
    {
        if (
            empty($_POST)
            && empty($_FILES)
            && ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0)) > 0
        ) {
            throw new PdfToolException(
                'file_too_large',
                'O upload excede o tamanho máximo permitido pelo servidor.',
                413
            );
        }
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = $_POST[$key] ?? $default;

        if (!is_string($value)) {
            throw new PdfToolException(
                'invalid_request',
                "O campo \"{$key}\" deve ser um texto."
            );
        }

        return $value;
    }

    /**
     * Tamanho máximo em bytes (inteiro positivo). Ausente ou vazio = sem limite.
     */
    public static function maxBytes(string $key): ?int
    {
        $value = trim(self::string($key));

        if ($value === '') {
            return null;
        }

        $bytes = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($bytes === false) {
            throw new PdfToolException(
                'invalid_request',
                "O campo \"{$key}\" deve ser um número inteiro de bytes maior que zero."
            );
        }

        return $bytes;
    }

    /**
     * Campo enviado como lista (ex.: password[]).
     *
     * @return array<int, string>
     */
    public static function stringList(string $key): array
    {
        $values = $_POST[$key] ?? [];

        if (!is_array($values)) {
            throw new PdfToolException(
                'invalid_request',
                "O campo \"{$key}\" deve ser uma lista."
            );
        }

        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new PdfToolException(
                    'invalid_request',
                    "O campo \"{$key}\" deve conter apenas textos."
                );
            }
        }

        return array_values($values);
    }

    /**
     * Uploads de um campo, aceitando tanto "pdf" quanto "pdf[]".
     *
     * @return list<array> Itens no formato de um único $_FILES
     */
    public static function uploads(string $field): array
    {
        if (!isset($_FILES[$field]['name'])) {
            throw new PdfToolException(
                'invalid_request',
                'Nenhum PDF enviado.'
            );
        }

        $raw = $_FILES[$field];

        if (!is_array($raw['name'])) {
            return [$raw];
        }

        $files = [];

        foreach (array_keys($raw['name']) as $index) {
            $files[] = [
                'name'     => $raw['name'][$index],
                'type'     => $raw['type'][$index] ?? '',
                'tmp_name' => $raw['tmp_name'][$index] ?? '',
                'error'    => $raw['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size'     => $raw['size'][$index] ?? 0,
            ];
        }

        return $files;
    }

    public static function singleUpload(string $field): array
    {
        $files = self::uploads($field);

        if (count($files) !== 1) {
            throw new PdfToolException(
                'invalid_request',
                'Envie apenas um arquivo PDF.'
            );
        }

        return $files[0];
    }
}
