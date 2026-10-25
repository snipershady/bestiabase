<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\Entity\BlastDatabase;
use App\Entity\BlastDatabaseProgram;
use App\Entity\BlastJob;
use App\Entity\User;
use App\Enum\BlastProgram;
use App\Enum\MolType;
use App\Enum\SearchType;
use App\Enum\UserRole;
use App\Repository\BlastDatabaseRepository;
use App\Security\BlastDatabaseVoter;
use App\Security\BlastJobVoter;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\EntityFactory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[CoversClass(BlastJobVoter::class)]
#[CoversClass(BlastDatabaseVoter::class)]
#[CoversClass(BlastDatabaseRepository::class)]
#[UsesClass(BlastDatabase::class)]
#[UsesClass(BlastDatabaseProgram::class)]
#[UsesClass(BlastJob::class)]
#[UsesClass(User::class)]
#[UsesClass(BlastProgram::class)]
final class AccessRulesTest extends DatabaseTestCase
{
    private User $owner;

    private User $other;

    private User $admin;

    private BlastDatabase $public;

    private BlastDatabase $restricted;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->user('owner');
        $this->other = $this->user('other');
        $this->admin = $this->user('admin');
        $this->admin->grantRole(UserRole::Admin);

        $this->public = $this->database('a_public', true);
        $this->restricted = $this->database('b_restricted', false);
        $this->restricted->authorize($this->owner);

        $inactive = $this->database('c_inactive', true);
        $inactive->deactivate();

        $this->entityManager->flush();
    }

    public function testSearchableDatabasesDependOnTheUser(): void
    {
        $repository = self::getContainer()->get(BlastDatabaseRepository::class);
        $codes = static fn (array $databases): array => array_map(static fn (BlastDatabase $database): string => $database->getCode(), $databases);

        self::assertSame(['a_public'], $this->selectable(BlastProgram::Blastn, null));
        self::assertSame(['a_public'], $this->selectable(BlastProgram::Blastn, $this->other));
        self::assertSame(['a_public', 'b_restricted'], $this->selectable(BlastProgram::Blastn, $this->owner));
        self::assertSame(['a_public', 'b_restricted'], $this->selectable(BlastProgram::Blastn, $this->admin), 'administrators: every active database');

        $this->owner->deactivate();
        self::assertSame([], $this->selectable(BlastProgram::Blastn, $this->owner), 'disabled accounts search nothing');
        self::assertSame(['b_restricted'], $codes($repository->findRestricted()));
        self::assertSame(['a_public', 'b_restricted', 'c_inactive'], $codes($repository->findAllByTitle()));
    }

    public function testDisabledAccountsSeeNoJobsAndNoDatabases(): void
    {
        $job = $this->job([$this->public], $this->owner);
        $this->admin->deactivate();
        $this->owner->deactivate();

        self::assertFalse($this->canView($this->owner, $job), 'the owner is disabled');
        self::assertFalse($this->canView($this->admin, $job), 'a disabled administrator has no privileges');
        self::assertFalse($this->public->isAccessibleBy($this->owner));
    }

    public function testDatabaseVoter(): void
    {
        $voter = new BlastDatabaseVoter();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(new NullToken(), $this->public, [BlastDatabaseVoter::SEARCH]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), $this->restricted, [BlastDatabaseVoter::SEARCH]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token($this->owner), $this->restricted, [BlastDatabaseVoter::SEARCH]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(new NullToken(), $this->public, ['OTHER']));
    }

    public function testOwnedJobsAreVisibleToTheirOwnerAndToAdministrators(): void
    {
        $job = $this->job([$this->public], $this->owner);

        self::assertTrue($this->canView($this->owner, $job));
        self::assertTrue($this->canView($this->admin, $job));
        self::assertFalse($this->canView($this->other, $job));
        self::assertFalse($this->canView(null, $job));
    }

    public function testAnonymousJobsAreVisibleByUuidWhileTheirDatabasesAreAccessible(): void
    {
        $job = $this->job([$this->public], null);

        self::assertTrue($this->canView(null, $job));
        self::assertTrue($this->canView($this->other, $job));

        $this->public->restrict();

        self::assertFalse($this->canView(null, $job), 'the database became restricted');
        self::assertTrue($this->canView($this->admin, $job));
    }

    public function testRevokedAccessHidesTheResultsOfARestrictedDatabase(): void
    {
        $job = $this->job([$this->restricted], $this->owner);

        self::assertTrue($this->canView($this->owner, $job));

        $this->restricted->revoke($this->owner);

        self::assertFalse($this->canView($this->owner, $job));
    }

    public function testDeactivatedDatabasesKeepTheirResultsVisible(): void
    {
        $job = $this->job([$this->restricted], $this->owner);
        $this->restricted->deactivate();

        self::assertTrue($this->canView($this->owner, $job));
    }

    public function testJobsOfDeletedDatabasesAreOnlyVisibleToAdministrators(): void
    {
        $job = new BlastJob(
            EntityFactory::job()->getId(),
            BlastProgram::Blastn,
            SearchType::Basic,
            [],
            [['id' => 999999, 'code' => 'deleted', 'title' => 'Deleted']],
            ">q\nACGT\n",
            EntityFactory::now(),
        );

        self::assertFalse($this->canView(null, $job));
        self::assertTrue($this->canView($this->admin, $job));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->jobVoter()->vote(new NullToken(), $job, ['OTHER']));
    }

    /**
     * @param list<BlastDatabase> $databases
     */
    private function job(array $databases, ?User $user): BlastJob
    {
        return new BlastJob(
            EntityFactory::job()->getId(),
            BlastProgram::Blastn,
            SearchType::Basic,
            [],
            array_map(static fn (BlastDatabase $database): array => ['id' => (int) $database->getId(), 'code' => $database->getCode(), 'title' => $database->getTitle()], $databases),
            ">q\nACGT\n",
            EntityFactory::now(),
            null,
            $user,
        );
    }

    private function canView(?User $user, BlastJob $job): bool
    {
        $token = $user instanceof User ? $this->token($user) : new NullToken();

        return $this->jobVoter()->vote($token, $job, [BlastJobVoter::VIEW]) === VoterInterface::ACCESS_GRANTED;
    }

    private function jobVoter(): BlastJobVoter
    {
        return new BlastJobVoter(self::getContainer()->get(BlastDatabaseRepository::class));
    }

    private function token(User $user): TokenInterface
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    /**
     * @param non-empty-string $name
     */
    private function user(string $name): User
    {
        $user = EntityFactory::user($name . '@example.org', $name);
        $this->entityManager->persist($user);

        return $user;
    }

    /**
     * @param non-empty-string $code
     */
    private function database(string $code, bool $public): BlastDatabase
    {
        $database = EntityFactory::database($code, MolType::Nucleotide);
        $database->rename($code);
        $database->enableAllCompatiblePrograms();

        if (!$public) {
            $database->restrict();
        }

        $this->entityManager->persist($database);
        $this->entityManager->flush();

        return $database;
    }

    /**
     * Database utilizzabili con il programma: searchableBy() più il filtro sul programma.
     *
     * @return list<string>
     */
    private function selectable(BlastProgram $program, ?User $user): array
    {
        /** @var list<BlastDatabase> $databases */
        $databases = self::getContainer()->get(BlastDatabaseRepository::class)->searchableBy($user)
            ->innerJoin('d.programs', 'p')
            ->andWhere('p.program = :program')
            ->setParameter('program', $program->value)
            ->getQuery()
            ->getResult();

        return array_map(static fn (BlastDatabase $database): string => $database->getCode(), $databases);
    }
}
