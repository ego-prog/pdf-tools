<?php

declare(strict_types=1);

final class PdfOperation
{
    public function __construct(
        private readonly PdfApplication $application
    ) {
    }

    /**
     * Executa uma operação PDF em um workspace temporário.
     *
     * @param callable(string): string $operation
     */
    public function execute(callable $operation): string
    {
        return $this->application->run($operation);
    }
}
