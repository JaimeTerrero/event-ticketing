<?php
declare(strict_types=1);

namespace Ticketing\Application;

use RuntimeException;

final class ApplicationError extends RuntimeException
{
    public function __construct(public readonly ErrorKind $kind, string $message)
    {
        parent::__construct($message);
    }
}
