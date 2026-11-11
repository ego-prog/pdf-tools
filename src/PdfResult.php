<?php

declare(strict_types=1);

/**
 * PDF gerado por uma operação e o que foi feito com ele.
 */
final class PdfResult
{
    /**
     * @param list<int> $signedIndices   arquivos de entrada (base 0) que
     *                                   tinham assinatura digital e
     *                                   receberam o aviso
     * @param ?int      $compressionDpi  resolução usada para caber no
     *                                   limite; null = sem compactação
     */
    public function __construct(
        public readonly string $path,
        public readonly array $signedIndices = [],
        public readonly ?int $compressionDpi = null
    ) {}
}
