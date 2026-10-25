<?php

declare(strict_types=1);

namespace App\Service\Altcha;

/**
 * Dove si usa una sfida. Il valore finisce nei dati firmati della sfida: una soluzione emessa
 * per uno scopo non vale per un altro (nemmeno se più economica).
 */
enum AltchaPurpose: string
{
    case Login = 'login';
    case Registration = 'registration';
    case SecurityCheck = 'security_check';
    case Trap = 'trap';
}
