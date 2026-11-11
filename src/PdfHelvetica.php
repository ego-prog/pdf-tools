<?php

declare(strict_types=1);

/**
 * Texto em Helvetica (fonte padrão do PDF, não precisa ser embutida)
 * com codificação WinAnsi: conversão, medida e quebra de linhas.
 */
final class PdfHelvetica
{
    /**
     * Larguras (1/1000 do tamanho da fonte) dos códigos 32–255.
     * Geradas com o Ghostscript; 0 = código sem caractere (127–159),
     * que encode() nunca produz.
     */
    private const WIDTHS = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 584, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584, 0,
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        278, 333, 556, 556, 556, 556, 260, 556, 333, 737, 370, 556, 584, 333, 737, 333,
        400, 584, 333, 333, 333, 556, 537, 278, 333, 333, 365, 556, 834, 834, 834, 611,
        667, 667, 667, 667, 667, 667, 1000, 722, 667, 667, 667, 667, 278, 278, 278, 278,
        722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611,
        556, 556, 556, 556, 556, 556, 889, 500, 556, 556, 556, 556, 278, 278, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 584, 611, 556, 556, 556, 556, 500, 556, 500,
    ];

    /**
     * Converte UTF-8 para WinAnsi, trocando o que não existe na
     * codificação por um equivalente ou "?".
     */
    public static function encode(string $text): string
    {
        // Só espaços comuns: o inseparável (U+00A0) segura palavras juntas.
        $text = preg_replace('/[ \t\r\n]+/', ' ', $text) ?? '';

        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);

        if ($converted === false) {
            $converted = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '';
        }

        // Só os códigos com largura conhecida (32–126 e 160–255).
        return preg_replace('/[^\x20-\x7E\xA0-\xFF]/', '?', $converted) ?? '';
    }

    /** Largura do texto (já em WinAnsi), em pontos. */
    public static function width(string $encoded, float $fontSize): float
    {
        $units = 0;

        foreach (str_split($encoded) as $char) {
            $units += self::WIDTHS[ord($char) - 32] ?? 556;
        }

        return $units * $fontSize / 1000;
    }

    /**
     * Quebra o texto (já em WinAnsi) em linhas que cabem na largura.
     * Palavras maiores que a linha são cortadas.
     *
     * @return list<string>
     */
    public static function wrap(string $encoded, float $fontSize, float $maxWidth): array
    {
        $lines   = [];
        $current = '';

        foreach (explode(' ', $encoded) as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;

            if (self::width($candidate, $fontSize) <= $maxWidth) {
                $current = $candidate;
                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
            }

            // Palavra sozinha maior que a linha: corta em pedaços.
            while (self::width($word, $fontSize) > $maxWidth && strlen($word) > 1) {
                $cut = strlen($word) - 1;

                while ($cut > 1 && self::width(substr($word, 0, $cut), $fontSize) > $maxWidth) {
                    $cut--;
                }

                $lines[] = substr($word, 0, $cut);
                $word    = substr($word, $cut);
            }

            $current = $word;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    /** Texto em WinAnsi como string literal de PDF. */
    public static function literal(string $encoded): string
    {
        return '(' . strtr($encoded, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ')';
    }
}
