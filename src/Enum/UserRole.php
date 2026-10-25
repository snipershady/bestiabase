<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Ruoli di sicurezza di un utente. ROLE_USER è implicito per ogni utente abilitato; ROLE_DISABLED
 * è lo stato di un account appena registrato o sospeso: può accedere ma non ha nessun altro ruolo.
 */
enum UserRole: string
{
    case User = 'ROLE_USER';
    case Admin = 'ROLE_ADMIN';
    case Disabled = 'ROLE_DISABLED';
}
