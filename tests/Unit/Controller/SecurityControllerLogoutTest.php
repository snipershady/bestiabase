<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\SecurityController;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SecurityController::class)]
final class SecurityControllerLogoutTest extends TestCase
{
    public function testLogoutIsHandledByTheFirewall(): void
    {
        $this->expectException(LogicException::class);

        new SecurityController()->logout();
    }
}
