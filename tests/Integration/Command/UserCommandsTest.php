<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\BlastDatabaseAccessCommand;
use App\Command\CreateUserCommand;
use App\Entity\BlastDatabase;
use App\Entity\User;
use App\Repository\BlastDatabaseRepository;
use App\Repository\UserRepository;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\EntityFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(CreateUserCommand::class)]
#[CoversClass(BlastDatabaseAccessCommand::class)]
#[UsesClass(BlastDatabase::class)]
#[UsesClass(User::class)]
#[UsesClass(BlastDatabaseRepository::class)]
#[UsesClass(UserRepository::class)]
final class UserCommandsTest extends DatabaseTestCase
{
    private const string PASSWORD = 'correct horse battery';

    public function testAdministratorIsCreatedWithAHashedPassword(): void
    {
        $result = self::runCommand('app:user:create', ['email' => 'admin@example.org', 'username' => 'admin', '--admin' => true], [self::PASSWORD, self::PASSWORD]);

        self::assertSame(Command::SUCCESS, $result->statusCode, $result->getDisplay());
        self::assertStringContainsString('Administrator "admin@example.org" created.', $result->getDisplay());
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'admin@example.org']) ?? self::fail('user not saved');
        self::assertTrue($user->isAdmin());
        self::assertNotSame(self::PASSWORD, $user->getPassword());
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, self::PASSWORD));
        self::assertStringNotContainsString(self::PASSWORD, $result->getDisplay(), 'the password is never echoed');
    }

    public function testInvalidInputIsRejected(): void
    {
        $this->entityManager->persist(EntityFactory::user('taken@example.org', 'taken'));
        $this->entityManager->flush();

        $cases = [
            'invalid email' => [['email' => 'not-an-email', 'username' => 'x'], [], Command::INVALID],
            'empty username' => [['email' => 'x@example.org', 'username' => ' '], [], Command::INVALID],
            'existing email' => [['email' => 'taken@example.org', 'username' => 'new'], [], Command::FAILURE],
            'existing username' => [['email' => 'new@example.org', 'username' => 'taken'], [], Command::FAILURE],
            'short password' => [['email' => 'short@example.org', 'username' => 'short'], ['short', 'short'], Command::INVALID],
            'mismatch' => [['email' => 'mismatch@example.org', 'username' => 'mismatch'], [self::PASSWORD, self::PASSWORD . '!'], Command::INVALID],
        ];

        foreach ($cases as $case => [$input, $answers, $status]) {
            self::assertSame($status, self::runCommand('app:user:create', $input, $answers)->statusCode, $case);
        }

        self::assertSame(1, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM `user`'));
    }

    public function testAccessToARestrictedDatabaseIsGrantedAndRevoked(): void
    {
        $user = EntityFactory::user('lab@example.org', 'lab');
        $database = EntityFactory::database('mouse_db');
        $database->restrict();
        $this->persistAndReload($user, $database);

        $grant = self::runCommand('app:blast:access', ['code' => 'mouse_db', 'email' => 'lab@example.org'], interactive: false);
        self::assertSame(Command::SUCCESS, $grant->statusCode);
        self::assertStringContainsString('lab@example.org can now search "mouse_db".', $grant->getDisplay());
        self::assertSame(1, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM blast_database_access'));

        $revoke = self::runCommand('app:blast:access', ['code' => 'mouse_db', 'email' => 'lab@example.org', '--revoke' => true], interactive: false);
        self::assertStringContainsString('can no longer search', $revoke->getDisplay());
        self::assertSame(0, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM blast_database_access'));
    }

    public function testAccessCommandReportsUnknownNamesAndPublicDatabases(): void
    {
        $user = EntityFactory::user('lab@example.org', 'lab');
        $this->persistAndReload($user, EntityFactory::database('open_db'));

        self::assertStringContainsString('Unknown database "nope".', self::runCommand('app:blast:access', ['code' => 'nope', 'email' => 'lab@example.org'], interactive: false)->getDisplay());
        self::assertStringContainsString('Unknown user "ghost@example.org".', self::runCommand('app:blast:access', ['code' => 'open_db', 'email' => 'ghost@example.org'], interactive: false)->getDisplay());

        $public = self::runCommand('app:blast:access', ['code' => 'open_db', 'email' => 'lab@example.org'], interactive: false);
        self::assertStringContainsString('This database is public', preg_replace('/\s+/', ' ', $public->getDisplay()) ?? '');
        self::assertInstanceOf(BlastDatabase::class, $this->entityManager->getRepository(BlastDatabase::class)->findOneBy(['code' => 'open_db']));
    }
}
