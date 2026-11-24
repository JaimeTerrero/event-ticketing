<?php
declare(strict_types=1);

namespace Ticketing\Application;

enum ErrorKind
{
    case Unauthenticated;
    case Forbidden;
    case NotFound;
    case Conflict;
    case Validation;
}
