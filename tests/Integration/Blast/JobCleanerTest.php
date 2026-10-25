<?php

declare(strict_types=1);

namespace App\Tests\Integration\Blast;

use App\Entity\BlastDatabase;
use App\Entity\BlastHit;
use App\Entity\BlastJob;
use App\Entity\BlastQuery;
use App\Enum\JobStatus;
use App\Repository\BlastJobRepository;
use App\Service\Blast\Job\JobCleaner;
use App\Service\Blast\JobWorkspace;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\EntityFactory;
use App\Tests\Support\TemporaryDirectory;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(JobCleaner::class)]
#[CoversClass(BlastJobRepository::class)]
#[UsesClass(JobWorkspace::class)]
#[UsesClass(BlastDatabase::class)]
#[UsesClass(BlastHit::class)]
#[UsesClass(BlastJob::class)]
#[UsesClass(BlastQuery::class)]
#[UsesClass(JobStatus::class)]
final class JobCleanerTest extends DatabaseTestCase
{
    private TemporaryDirectory $var;

    private JobWorkspace $workspace;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->var = new TemporaryDirectory();
        $this->workspace = new JobWorkspace($this->var->path, new Filesystem());
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->var->remove();
        parent::tearDown();
    }

    public function testOnlyOldTerminatedJobsAreRemovedWithResultsAndFiles(): void
    {
        $oldFinished = $this->job('2026-08-01', 'finish');
        $oldFailed = $this->job('2026-08-02', 'fail');
        $oldPending = $this->job('2026-08-03');
        $oldRunning = $this->job('2026-08-04', 'start');
        $recentFinished = $this->job('2026-10-20', 'finish');
        $query = EntityFactory::query($oldFinished);
        $this->persistAndReload($query, EntityFactory::hit($query));

        $removed = $this->cleaner()->purgeOlderThan(30);

        self::assertSame(2, $removed);
        $remaining = $this->entityManager->getConnection()->fetchFirstColumn('SELECT status FROM blast_job ORDER BY created_at');
        self::assertSame(['pending', 'running', 'finished'], $remaining, 'pending and running jobs never expire');
        self::assertSame(0, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM blast_hit'));
        self::assertDirectoryDoesNotExist($this->workspace->directory($oldFinished->getId()));
        self::assertDirectoryDoesNotExist($this->workspace->directory($oldFailed->getId()));
        self::assertDirectoryExists($this->workspace->directory($oldPending->getId()));
        self::assertDirectoryExists($this->workspace->directory($oldRunning->getId()));
        self::assertDirectoryExists($this->workspace->directory($recentFinished->getId()));
    }

    public function testDryRunOnlyCounts(): void
    {
        $this->job('2026-08-01', 'finish');

        self::assertSame(1, $this->cleaner()->purgeOlderThan(30, dryRun: true));
        self::assertSame(1, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM blast_job'));
    }

    public function testLargeBacklogsAreRemovedInBatches(): void
    {
        for ($i = 0; $i < 205; ++$i) {
            $this->job('2026-01-01', 'cancel');
        }

        self::assertSame(205, $this->cleaner()->purgeOlderThan(30));
        self::assertSame(0, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM blast_job'));
    }

    private function cleaner(): JobCleaner
    {
        return new JobCleaner(
            self::getContainer()->get(BlastJobRepository::class),
            $this->workspace,
            $this->entityManager,
            new MockClock('2026-10-24 12:00:00'),
        );
    }

    /**
     * @param 'start'|'finish'|'fail'|'cancel'|null $transition
     */
    private function job(string $createdOn, ?string $transition = null): BlastJob
    {
        $database = EntityFactory::database('db_' . bin2hex(random_bytes(4)));
        $this->entityManager->persist($database);
        $this->entityManager->flush();

        $job = EntityFactory::jobOn($database, new DateTimeImmutable($createdOn));
        $now = EntityFactory::now();

        if (\in_array($transition, ['start', 'finish'], true)) {
            $job->start($now);
        }

        if ($transition === 'finish') {
            $job->finish($now, 1, 0);
        }

        if ($transition === 'fail') {
            $job->fail($now, 'error');
        }

        if ($transition === 'cancel') {
            $job->cancel($now);
        }

        $this->entityManager->persist($job);
        $this->entityManager->flush();

        $this->workspace->prepare($job->getId());

        return $job;
    }
}
