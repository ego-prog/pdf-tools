<?php

declare(strict_types=1);

final class PasswordRequiredException extends RuntimeException
{
    /** @param int[] $indices */
    public function __construct(
        public readonly array $indices,
        string $message = 'Alguns arquivos precisam de senha.'
    ) {
        parent::__construct($message);
    }
}
