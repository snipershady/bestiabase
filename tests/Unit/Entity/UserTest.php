<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use App\Enum\UserRole;
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
        self::assertSame([UserRole::User->value], $user->getRoles());
        self::assertFalse($user->isAdmin());
        self::assertTrue($user->isActive());
        self::assertSame(EntityFactory::NOW, $user->getCreatedAt()->format('Y-m-d H:i:s'));
        self::assertNull($user->getLastLoginAt());
    }

    public function testNewUserIsDisabledUntilAnAdministratorEnablesIt(): void
    {
        $user = new User('new@example.org', 'new', 'hashed-password', EntityFactory::now());

        self::assertFalse($user->isActive());
        self::assertSame([UserRole::Disabled->value], $user->getRoles());
        self::assertTrue($user->hasRole(UserRole::Disabled));

        $user->activate();

        self::assertTrue($user->isActive());
        self::assertSame([UserRole::User->value], $user->getRoles());
    }

    public function testDisabledAdministratorKeepsTheRoleButHasNoPrivileges(): void
    {
        $user = EntityFactory::user();
        $user->grantRole(UserRole::Admin);
        $user->deactivate();

        self::assertTrue($user->hasRole(UserRole::Admin));
        self::assertFalse($user->isAdmin());
        self::assertSame([UserRole::Disabled->value], $user->getRoles());

        $user->activate();

        self::assertTrue($user->isAdmin());
    }

    public function testTheEmailIsVerifiedOnceAndTheFirstDateIsKept(): void
    {
        $user = new User('new@example.org', 'new', 'hashed-password', EntityFactory::now());

        self::assertFalse($user->isEmailVerified());
        self::assertNotInstanceOf(DateTimeImmutable::class, $user->getEmailVerifiedAt());

        $first = new DateTimeImmutable('2026-10-25 09:00:00');
        $user->verifyEmail($first);
        $user->verifyEmail(new DateTimeImmutable('2026-10-26 09:00:00'));

        self::assertTrue($user->isEmailVerified());
        self::assertSame($first, $user->getEmailVerifiedAt());
    }

    public function testRolesAreGrantedOnceAndCanBeRevoked(): void
    {
        $user = EntityFactory::user();

        $user->grantRole(UserRole::Admin);
        $user->grantRole(UserRole::Admin);

        self::assertSame([UserRole::Admin->value, UserRole::User->value], $user->getRoles());
        self::assertTrue($user->isAdmin());

        $user->revokeRole(UserRole::Admin);

        self::assertSame([UserRole::User->value], $user->getRoles());
        self::assertFalse($user->isAdmin());
    }

    public function testExplicitUserRoleIsNotDuplicated(): void
    {
        $user = EntityFactory::user();
        $user->grantRole(UserRole::User);

        self::assertSame([UserRole::User->value], $user->getRoles());
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
