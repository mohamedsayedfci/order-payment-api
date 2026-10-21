<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class BusinessRuleViolation extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
