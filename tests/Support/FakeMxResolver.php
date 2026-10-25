<?php

declare(strict_types=1);

namespace App\Tests\Support;

use EmailValidator\Enum\EmailError;
use EmailValidator\Service\MxResolver;
use Override;

/**
 * Resolver MX senza rete: ogni dominio ha un server di posta, tranne quelli di prova.
 */
final readonly class FakeMxResolver implements MxResolver
{
    public const string NO_MX_DOMAIN = 'no-mail.invalid';

    public const string NULL_MX_DOMAIN = 'null-mx.example';

    public const string DNS_DOWN_DOMAIN = 'dns-down.example';

    #[Override]
    public function resolve(string $domain): array|EmailError
    {
        return match ($domain) {
            self::NO_MX_DOMAIN => EmailError::NO_MX_RECORD,
            self::NULL_MX_DOMAIN => EmailError::NULL_MX,
            self::DNS_DOWN_DOMAIN => EmailError::DNS_FAILURE,
            default => ['mx.' . $domain],
        };
    }
}
