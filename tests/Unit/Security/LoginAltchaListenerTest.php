<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\LoginAltchaListener;
use App\Service\Altcha\AltchaPurpose;
use App\Service\Altcha\AltchaVerifierInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

#[CoversClass(LoginAltchaListener::class)]
final class LoginAltchaListenerTest extends TestCase
{
    public function testOtherRoutesAreNotChecked(): void
    {
        $verifier = $this->createMock(AltchaVerifierInterface::class);
        $verifier->expects(self::never())->method('verify');

        $this->listener($this->request('app_search'), $verifier)->onCheckPassport();
    }

    public function testNoRequestMeansNothingToCheck(): void
    {
        $verifier = $this->createMock(AltchaVerifierInterface::class);
        $verifier->expects(self::never())->method('verify');

        new LoginAltchaListener(new RequestStack(), $verifier)->onCheckPassport();
    }

    public function testLoginWithAValidSolutionPasses(): void
    {
        $verifier = $this->createMock(AltchaVerifierInterface::class);
        $verifier->expects(self::once())->method('verify')->with('payload', AltchaPurpose::Login)->willReturn(true);

        $this->listener($this->request('app_login', 'payload'), $verifier)->onCheckPassport();
    }

    public function testLoginWithoutAValidSolutionIsRefused(): void
    {
        $verifier = self::createStub(AltchaVerifierInterface::class);
        $verifier->method('verify')->willReturn(false);

        $this->expectException(CustomUserMessageAuthenticationException::class);

        $this->listener($this->request('app_login'), $verifier)->onCheckPassport();
    }

    private function listener(Request $request, AltchaVerifierInterface $verifier): LoginAltchaListener
    {
        $stack = new RequestStack([$request]);

        return new LoginAltchaListener($stack, $verifier);
    }

    private function request(string $route, string $altcha = ''): Request
    {
        $request = Request::create('/login', 'POST', ['altcha' => $altcha]);
        $request->attributes->set('_route', $route);

        return $request;
    }
}
