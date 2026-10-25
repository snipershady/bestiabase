<?php

declare(strict_types=1);

namespace App\Tests\Unit\SecurityCheck;

use App\Service\SecurityCheck\SecurityCheckCookie;
use App\Service\SecurityCheck\SecurityCheckGate;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\InMemoryUser;

#[CoversClass(SecurityCheckCookie::class)]
#[CoversClass(SecurityCheckGate::class)]
final class SecurityCheckCookieTest extends TestCase
{
    private const int NOW = 1_800_000_000;

    private const int LIFETIME = 3_600;

    private int $now = self::NOW;

    public function testAnIssuedCookieIsValidForTheSameClientUntilItExpires(): void
    {
        $cookie = $this->cookie();
        $issued = $cookie->issue($this->request('203.0.113.7'));

        self::assertSame(SecurityCheckCookie::NAME, $issued->getName());
        self::assertTrue($issued->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_LAX, $issued->getSameSite());
        self::assertSame(self::NOW + self::LIFETIME, $issued->getExpiresTime());
        self::assertTrue($cookie->isValid($this->request('203.0.113.7', (string) $issued->getValue())));

        $this->now = self::NOW + self::LIFETIME + 1;

        self::assertFalse($cookie->isValid($this->request('203.0.113.7', (string) $issued->getValue())), 'expired');
    }

    public function testTheCookieIsBoundToTheClientAddress(): void
    {
        $value = (string) $this->cookie()->issue($this->request('203.0.113.7'))->getValue();

        self::assertFalse($this->cookie()->isValid($this->request('198.51.100.9', $value)));
    }

    public function testForgedMissingAndMalformedCookiesAreRejected(): void
    {
        $cookie = $this->cookie();
        $expires = self::NOW + 100;

        self::assertFalse($cookie->isValid($this->request('203.0.113.7')), 'no cookie');
        self::assertFalse($cookie->isValid($this->request('203.0.113.7', $expires . '.forged')), 'wrong signature');
        self::assertFalse($cookie->isValid($this->request('203.0.113.7', 'garbage')), 'no separator');
        self::assertFalse($cookie->isValid($this->request('203.0.113.7', 'abc.def')), 'non numeric expiry');
    }

    public function testSecureRequestsGetASecureCookie(): void
    {
        $request = Request::create('https://blast.example.org/', server: ['REMOTE_ADDR' => '203.0.113.7']);

        self::assertTrue($this->cookie()->issue($request)->isSecure());
    }

    public function testOnlyAnonymousVisitorsWithoutACookieMustPassTheCheck(): void
    {
        $gate = new SecurityCheckGate($this->cookie());
        $value = (string) $this->cookie()->issue($this->request('203.0.113.7'))->getValue();

        self::assertTrue($gate->isRequired($this->request('203.0.113.7'), null));
        self::assertFalse($gate->isRequired($this->request('203.0.113.7', $value), null));
        self::assertFalse($gate->isRequired($this->request('203.0.113.7'), new InMemoryUser('lab', null)), 'signed-in users never see it');
    }

    public function currentTime(): int
    {
        return $this->now;
    }

    private function cookie(): SecurityCheckCookie
    {
        $clock = new readonly class($this) implements ClockInterface {
            public function __construct(private SecurityCheckCookieTest $test)
            {
            }

            #[Override]
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('@' . $this->test->currentTime());
            }
        };

        return new SecurityCheckCookie('a-secret', $clock, self::LIFETIME);
    }

    private function request(string $ip, ?string $cookie = null): Request
    {
        return Request::create('/', cookies: $cookie === null ? [] : [SecurityCheckCookie::NAME => $cookie], server: ['REMOTE_ADDR' => $ip]);
    }
}
