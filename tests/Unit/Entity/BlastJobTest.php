<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\BlastJob;
use App\Entity\User;
use App\Enum\BlastProgram;
use App\Enum\JobStatus;
use App\Enum\SearchType;
use App\Exception\InvalidJobTransitionException;
use App\Tests\Support\EntityFactory;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

#[CoversClass(BlastJob::class)]
#[CoversClass(InvalidJobTransitionException::class)]
#[UsesClass(User::class)]
final class BlastJobTest extends TestCase
{
    public function testNewJobIsPendingAndKeepsItsSnapshot(): void
    {
        $user = EntityFactory::user();
        $job = EntityFactory::job(BlastProgram::Tblastn, $user, ">s1\nACGT\n");

        self::assertSame(BlastProgram::Tblastn, $job->getProgram());
        self::assertSame(SearchType::Basic, $job->getSearchType());
        self::assertSame(['expect' => 10.0, 'maxTargetSequences' => 50], $job->getParameters());
        self::assertSame([['id' => 1, 'code' => 'test_na_db', 'title' => 'Nucleotide test database']], $job->getDatabases());
        self::assertSame(">q1\nACGT\n", $job->getQueryFasta());
        self::assertSame(">s1\nACGT\n", $job->getSubjectFasta());
        self::assertTrue($job->hasUploadedSubject());
        self::assertSame($user, $job->getUser());
        self::assertSame(JobStatus::Pending, $job->getStatus());
        self::assertNull($job->getNumQueries());
        self::assertSame(0, $job->getNumHits());
        self::assertNull($job->getErrorMessage());
        self::assertSame(EntityFactory::NOW, $job->getCreatedAt()->format('Y-m-d H:i:s'));
        self::assertNull($job->getStartedAt());
        self::assertNull($job->getBlastVersion());
        self::assertNull($job->getFinishedAt());
        self::assertInstanceOf(UuidV7::class, $job->getId());
    }

    public function testJobWithoutUploadAndAnonymous(): void
    {
        $job = EntityFactory::job();

        self::assertNull($job->getSubjectFasta());
        self::assertFalse($job->hasUploadedSubject());
        self::assertNull($job->getUser());
    }

    public function testSuccessfulLifecycle(): void
    {
        $job = EntityFactory::job();
        $startedAt = new DateTimeImmutable('2026-10-24 12:00:05');
        $finishedAt = new DateTimeImmutable('2026-10-24 12:00:42');

        $job->start($startedAt);

        self::assertSame(JobStatus::Running, $job->getStatus());
        self::assertSame($startedAt, $job->getStartedAt());

        $job->finish($finishedAt, 3, 27);

        self::assertSame(JobStatus::Finished, $job->getStatus());
        self::assertSame($finishedAt, $job->getFinishedAt());
        self::assertSame(3, $job->getNumQueries());
        self::assertSame(27, $job->getNumHits());
    }

    public function testBlastVersionIsRecorded(): void
    {
        $job = EntityFactory::job();

        $job->recordBlastVersion('2.17.0+');

        self::assertSame('2.17.0+', $job->getBlastVersion());
    }

    public function testRunningJobCanFailWithTheBlastError(): void
    {
        $job = EntityFactory::job();
        $job->start(EntityFactory::now());

        $job->fail(EntityFactory::now(), 'BLAST Database error: No alias or index file found');

        self::assertSame(JobStatus::Failed, $job->getStatus());
        self::assertSame('BLAST Database error: No alias or index file found', $job->getErrorMessage());
        self::assertInstanceOf(DateTimeImmutable::class, $job->getFinishedAt());
    }

    public function testPendingJobCanFailBeforeStarting(): void
    {
        $job = EntityFactory::job();

        $job->fail(EntityFactory::now(), 'makeblastdb failed');

        self::assertSame(JobStatus::Failed, $job->getStatus());
    }

    public function testPendingJobCanBeCancelled(): void
    {
        $job = EntityFactory::job();

        $job->cancel(EntityFactory::now());

        self::assertSame(JobStatus::Cancelled, $job->getStatus());
        self::assertInstanceOf(DateTimeImmutable::class, $job->getFinishedAt());
    }

    public function testPendingJobCannotFinish(): void
    {
        $job = EntityFactory::job();

        $this->expectException(InvalidJobTransitionException::class);
        $this->expectExceptionMessageIsOrContains('A BLAST job cannot move from "pending" to "finished".');

        $job->finish(EntityFactory::now(), 1, 1);
    }

    public function testTerminalJobCannotBeRestarted(): void
    {
        $job = EntityFactory::job();
        $job->cancel(EntityFactory::now());

        $this->expectException(InvalidJobTransitionException::class);

        $job->start(EntityFactory::now());
    }

    public function testFinishedJobCannotBeCancelled(): void
    {
        $job = EntityFactory::job();
        $job->start(EntityFactory::now());
        $job->finish(EntityFactory::now(), 1, 0);

        $this->expectException(InvalidJobTransitionException::class);

        $job->cancel(EntityFactory::now());
    }

    public function testConstructorAcceptsAdvancedSearchWithAnotherFormat(): void
    {
        $job = new BlastJob(
            EntityFactory::job()->getId(),
            BlastProgram::Blastp,
            SearchType::Advanced,
            [],
            [],
            ">q\nMKV\n",
            EntityFactory::now(),
        );

        self::assertSame(SearchType::Advanced, $job->getSearchType());
    }
}
