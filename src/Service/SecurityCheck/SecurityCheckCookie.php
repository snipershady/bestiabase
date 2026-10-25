<?php

declare(strict_types=1);

namespace App\Service\SecurityCheck;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Prova di aver superato la verifica di sicurezza: un cookie firmato con scadenza e legato
 * all'indirizzo IP, come fa Cloudflare con cf_clearance. Non porta dati dell'utente.
 */
final readonly class SecurityCheckCookie
{
    public const string NAME = 'bb_security_check';

    public function __construct(
        #[Autowire(param: 'kernel.secret')]
        private string $secret,
        private ClockInterface $clock,
        #[Autowire('%env(int:SECURITY_CHECK_COOKIE_LIFETIME)%')]
        private int $lifetime,
    ) {
    }

    public function issue(Request $request): Cookie
    {
        $expires = $this->clock->now()->getTimestamp() + $this->lifetime;

        return Cookie::create(self::NAME, $expires . '.' . $this->signature($expires, $request))
            ->withExpires($expires)
            ->withHttpOnly()
            ->withSameSite(Cookie::SAMESITE_LAX)
            ->withSecure($request->isSecure());
    }

    public function isValid(Request $request): bool
    {
        $value = $request->cookies->getString(self::NAME);
        [$expires, $signature] = array_pad(explode('.', $value, 2), 2, '');

        if (!ctype_digit($expires) || (int) $expires < $this->clock->now()->getTimestamp()) {
            return false;
        }

        return hash_equals($this->signature((int) $expires, $request), $signature);
    }

    private function signature(int $expires, Request $request): string
    {
        return hash_hmac('sha256', \sprintf('%d|%s', $expires, $request->getClientIp() ?? 'unknown'), $this->secret);
    }
}
