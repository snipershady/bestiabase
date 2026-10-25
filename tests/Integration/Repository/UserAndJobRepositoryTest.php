<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\BlastDatabase;
use App\Entity\BlastJob;
use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\BlastDatabaseRepository;
use App\Repository\BlastJobRepository;
use App\Repository\UserRepository;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\EntityFactory;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\InMemoryUser;

#[CoversClass(UserRepository::class)]
#[CoversClass(BlastJobRepository::class)]
#[UsesClass(BlastDatabase::class)]
#[UsesClass(BlastJob::class)]
#[UsesClass(User::class)]
#[UsesClass(BlastDatabaseRepository::class)]
final class UserAndJobRepositoryTest extends DatabaseTestCase
{
    public function testUsersAreListedByEmailAndPasswordsUpgraded(): void
    {
        $zed = EntityFactory::user('zed@example.org', 'zed');
        $ada = EntityFactory::user('ada@example.org', 'ada');
        $this->persistAndReload($zed, $ada);
        $users = self::getContainer()->get(UserRepository::class);

        self::assertSame(['ada@example.org', 'zed@example.org'], array_map(static fn (User $user): string => $user->getEmail(), $users->findAllByEmail()));

        $loaded = $users->findOneBy(['email' => 'ada@example.org']) ?? self::fail('user not saved');
        $users->upgradePassword($loaded, 'new-hash');
        $this->entityManager->clear();

        self::assertSame('new-hash', $users->findOneBy(['email' => 'ada@example.org'])?->getPassword());
    }

    public function testAdministratorsAreOnlyTheEnabledOnes(): void
    {
        $admin = EntityFactory::user('admin@example.org', 'admin');
        $admin->grantRole(UserRole::Admin);

        $suspended = EntityFactory::user('suspended@example.org', 'suspended');
        $suspended->grantRole(UserRole::Admin);
        $suspended->deactivate();
        $this->persistAndReload($admin, $suspended, EntityFactory::user('plain@example.org', 'plain'));

        $emails = array_map(static fn (User $user): string => $user->getEmail(), self::getContainer()->get(UserRepository::class)->findAdministrators());

        self::assertSame(['admin@example.org'], $emails);
    }

    public function testOnlyApplicationUsersCanBeUpgraded(): void
    {
        $this->expectException(UnsupportedUserException::class);

        self::getContainer()->get(UserRepository::class)->upgradePassword(new InMemoryUser('memory', null), 'hash');
    }

    public function testJobsOfAUserAndRecentJobsAreNewestFirst(): void
    {
        $user = EntityFactory::user();
        $database = EntityFactory::database();
        $this->persistAndReload($user, $database);
        $user = $this->entityManager->getRepository(User::class)->findOneBy([]) ?? self::fail('user not saved');
        $database = $this->entityManager->getRepository(BlastDatabase::class)->findOneBy([]) ?? self::fail('database not saved');

        $old = $this->job($database, $user, '2026-01-01');
        $new = $this->job($database, $user, '2026-02-01');
        $anonymous = $this->job($database, null, '2026-03-01');
        $jobs = self::getContainer()->get(BlastJobRepository::class);

        self::assertSame([$new, $old], $jobs->findForUser($user, 0, 10));
        self::assertSame([$old], $jobs->findForUser($user, 1, 10));
        self::assertSame([$anonymous, $new], $jobs->findRecent(0, 2));
    }

    private function job(BlastDatabase $database, ?User $user, string $createdOn): BlastJob
    {
        $job = EntityFactory::jobOn($database, new DateTimeImmutable($createdOn), $user);
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $job;
    }
}
