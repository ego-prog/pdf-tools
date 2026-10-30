<?php

declare(strict_types=1);

final class PasswordRequiredException extends PdfToolException
{
    /** @param int[] $indices */
    public function __construct(
        public readonly array $indices,
        string $message = 'Alguns arquivos precisam de senha.'
    ) {
        parent::__construct(
            'password_required',
            $message,
            422,
            ['indices' => $indices]
        );
    }
}
