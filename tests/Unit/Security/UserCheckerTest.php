<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Security\UserChecker;
use App\Tests\Support\EntityFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\InMemoryUser;

#[CoversClass(UserChecker::class)]
#[UsesClass(User::class)]
final class UserCheckerTest extends TestCase
{
    public function testActiveUsersAndOtherUserTypesPass(): void
    {
        $checker = new UserChecker();

        $checker->checkPreAuth(EntityFactory::user());
        $checker->checkPreAuth(new InMemoryUser('memory', null));
        $checker->checkPostAuth(EntityFactory::user());

        $this->expectNotToPerformAssertions();
    }

    public function testDisabledUsersCannotSignIn(): void
    {
        $user = EntityFactory::user();
        $user->deactivate();

        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->expectExceptionMessageIsOrContains('Your account has been disabled.');

        new UserChecker()->checkPreAuth($user);
    }
}
