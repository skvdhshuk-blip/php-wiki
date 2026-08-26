<?php

namespace App\Exceptions;

use RuntimeException;

class AgentContractException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $responseText = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
