<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use App\Tests\Support\EntityFactory;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(User::class)]
final class UserTest extends TestCase
{
    public function testNewUserIsActiveWithOnlyTheImplicitUserRole(): void
    {
        $user = EntityFactory::user('ada@example.org', 'ada');

        self::assertNull($user->getId());
        self::assertSame('ada@example.org', $user->getEmail());
        self::assertSame('ada@example.org', $user->getUserIdentifier());
        self::assertSame('ada', $user->getUsername());
        self::assertSame('hashed-password', $user->getPassword());
        self::assertSame([User::ROLE_USER], $user->getRoles());
        self::assertFalse($user->isAdmin());
        self::assertTrue($user->isActive());
        self::assertSame(EntityFactory::NOW, $user->getCreatedAt()->format('Y-m-d H:i:s'));
        self::assertNull($user->getLastLoginAt());
    }

    public function testRolesAreGrantedOnceAndCanBeRevoked(): void
    {
        $user = EntityFactory::user();

        $user->grantRole(User::ROLE_ADMIN);
        $user->grantRole(User::ROLE_ADMIN);

        self::assertSame([User::ROLE_ADMIN, User::ROLE_USER], $user->getRoles());
        self::assertTrue($user->isAdmin());

        $user->revokeRole(User::ROLE_ADMIN);

        self::assertSame([User::ROLE_USER], $user->getRoles());
        self::assertFalse($user->isAdmin());
    }

    public function testExplicitUserRoleIsNotDuplicated(): void
    {
        $user = EntityFactory::user();
        $user->grantRole(User::ROLE_USER);

        self::assertSame([User::ROLE_USER], $user->getRoles());
    }

    public function testPasswordActivationAndLoginCanChange(): void
    {
        $user = EntityFactory::user();
        $loginAt = new DateTimeImmutable('2026-10-25 08:00:00');

        $user->changePassword('new-hash');
        $user->deactivate();
        $user->recordLogin($loginAt);

        self::assertSame('new-hash', $user->getPassword());
        self::assertFalse($user->isActive());
        self::assertSame($loginAt, $user->getLastLoginAt());

        $user->activate();

        self::assertTrue($user->isActive());
    }

    public function testUserWithoutEmailCannotBeAuthenticated(): void
    {
        $user = new User('', 'ghost', 'hashed-password', EntityFactory::now());

        $this->expectException(LogicException::class);

        $user->getUserIdentifier();
    }
}
