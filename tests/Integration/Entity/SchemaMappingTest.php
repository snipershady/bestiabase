<?php

declare(strict_types=1);

namespace App\Tests\Integration\Entity;

use App\Entity\BlastDatabase;
use App\Entity\BlastDatabaseProgram;
use App\Entity\BlastDownload;
use App\Entity\BlastHit;
use App\Entity\BlastHsp;
use App\Entity\BlastJob;
use App\Entity\BlastQuery;
use App\Entity\User;
use App\Enum\BlastProgram;
use App\Enum\DownloadSequenceType;
use App\Enum\JobStatus;
use App\Enum\MolType;
use App\Repository\BlastDatabaseRepository;
use App\Repository\BlastHitRepository;
use App\Repository\BlastJobRepository;
use App\Repository\UserRepository;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\EntityFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(User::class)]
#[CoversClass(BlastDatabase::class)]
#[CoversClass(BlastDatabaseProgram::class)]
#[CoversClass(BlastJob::class)]
#[CoversClass(BlastQuery::class)]
#[CoversClass(BlastHit::class)]
#[CoversClass(BlastHsp::class)]
#[CoversClass(BlastDownload::class)]
#[CoversClass(UserRepository::class)]
#[CoversClass(BlastJobRepository::class)]
#[CoversClass(BlastHitRepository::class)]
#[UsesClass(BlastProgram::class)]
#[UsesClass(BlastDatabaseRepository::class)]
final class SchemaMappingTest extends DatabaseTestCase
{
    public function testFullResultGraphRoundTrips(): void
    {
        $user = EntityFactory::user();
        $job = EntityFactory::job(BlastProgram::Blastn, $user, ">s1\nACGT\n");
        $job->start(EntityFactory::now());
        $job->finish(EntityFactory::now(), 1, 1);

        $query = EntityFactory::query($job);
        $hit = EntityFactory::hit($query);
        $hit->addHsp(EntityFactory::hsp($hit, 1));
        $hit->addHsp(EntityFactory::hsp($hit, 2, 43.7, 32, 35));

        $download = new BlastDownload($job, DownloadSequenceType::Entire, [$hit], EntityFactory::now(), $user);

        $this->persistAndReload($user, $job, $query, $hit, $download);

        $blastJobRepository = $this->entityManager->getRepository(BlastJob::class);
        self::assertInstanceOf(BlastJobRepository::class, $blastJobRepository);
        $loaded = $blastJobRepository->find($job->getId());
        self::assertInstanceOf(BlastJob::class, $loaded);
        self::assertSame(JobStatus::Finished, $loaded->getStatus());
        self::assertSame(BlastProgram::Blastn, $loaded->getProgram());
        self::assertSame(['expect' => 10.0, 'maxTargetSequences' => 50], $loaded->getParameters());
        self::assertSame('user@example.org', $loaded->getUser()?->getEmail());

        $blastHitRepository = $this->entityManager->getRepository(BlastHit::class);
        self::assertInstanceOf(BlastHitRepository::class, $blastHitRepository);
        $loadedHit = $blastHitRepository->findOneBy(['job' => $loaded]);
        self::assertInstanceOf(BlastHit::class, $loadedHit);
        self::assertSame(2, $loadedHit->getHspCount());
        self::assertSame([1, 2], array_map(static fn (BlastHsp $hsp): int => $hsp->getRank(), $loadedHit->getHsps()));
        self::assertEqualsWithDelta(632.0, $loadedHit->getBestBitScore(), \PHP_FLOAT_EPSILON);

        $userRepository = $this->entityManager->getRepository(User::class);
        self::assertInstanceOf(UserRepository::class, $userRepository);
        self::assertInstanceOf(User::class, $userRepository->findOneBy(['email' => 'user@example.org']));
    }

    public function testDeletingAJobCascadesToItsResults(): void
    {
        $job = EntityFactory::job();
        $query = EntityFactory::query($job);
        $hit = EntityFactory::hit($query);
        $hit->addHsp(EntityFactory::hsp($hit));
        $this->persistAndReload($job, $query, $hit);

        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DELETE FROM blast_job');

        foreach (['blast_query', 'blast_hit', 'blast_hsp'] as $table) {
            self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM ' . $table), $table);
        }
    }

    public function testDatabaseProgramsAreStoredAsEnumKeys(): void
    {
        $database = EntityFactory::database('test_aa_db', MolType::Protein);
        $database->enableAllCompatiblePrograms();
        $this->persistAndReload($database);

        $loaded = $this->entityManager->getRepository(BlastDatabase::class)->findOneBy(['code' => 'test_aa_db']);

        self::assertInstanceOf(BlastDatabase::class, $loaded);
        self::assertEqualsCanonicalizing([BlastProgram::Blastp, BlastProgram::Blastx], $loaded->getPrograms());
    }
}
