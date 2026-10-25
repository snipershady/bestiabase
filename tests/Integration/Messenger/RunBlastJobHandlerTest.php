<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messenger;

use App\Entity\BlastDatabase;
use App\Entity\BlastDatabaseProgram;
use App\Entity\BlastHit;
use App\Entity\BlastHsp;
use App\Entity\BlastJob;
use App\Entity\BlastQuery;
use App\Enum\BlastProgram;
use App\Enum\JobStatus;
use App\Repository\BlastDatabaseRepository;
use App\Repository\BlastJobRepository;
use App\Service\Blast\BlastBinaryLocator;
use App\Service\Blast\BlastCommandBuilder;
use App\Service\Blast\BlastProcessExecutor;
use App\Service\Blast\BlastResultImporter;
use App\Service\Blast\BlastRunner;
use App\Service\Blast\BlastVersionReader;
use App\Service\Blast\Database\BlastDatabaseStorage;
use App\Service\Blast\Exception\JobNotFoundException;
use App\Service\Blast\ImportSummary;
use App\Service\Blast\Job\BlastJobProcessor;
use App\Service\Blast\JobWorkspace;
use App\Service\Blast\Process\ProcessResult;
use App\Service\Blast\Search\SearchParametersNormalizer;
use App\Service\Blast\SequenceNames;
use App\Service\Blast\Xml\BlastXmlParser;
use App\Service\Blast\Xml\ParsedHit;
use App\Service\Blast\Xml\ParsedHsp;
use App\Service\Blast\Xml\ParsedIteration;
use App\Service\Message\RunBlastJob;
use App\Service\MessageHandler\RunBlastJobHandler;
use App\Tests\Support\BlastTestKit;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\EntityFactory;
use App\Tests\Support\RecordingProcessRunner;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use ReflectionClass;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Attribute\AsMessage;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(RunBlastJobHandler::class)]
#[CoversClass(RunBlastJob::class)]
#[UsesClass(BlastBinaryLocator::class)]
#[UsesClass(BlastCommandBuilder::class)]
#[UsesClass(BlastProcessExecutor::class)]
#[UsesClass(BlastResultImporter::class)]
#[UsesClass(BlastRunner::class)]
#[UsesClass(BlastVersionReader::class)]
#[UsesClass(BlastDatabaseStorage::class)]
#[UsesClass(JobNotFoundException::class)]
#[UsesClass(ImportSummary::class)]
#[UsesClass(JobWorkspace::class)]
#[UsesClass(BlastJobProcessor::class)]
#[UsesClass(ProcessResult::class)]
#[UsesClass(SearchParametersNormalizer::class)]
#[UsesClass(SequenceNames::class)]
#[UsesClass(BlastXmlParser::class)]
#[UsesClass(ParsedHit::class)]
#[UsesClass(ParsedHsp::class)]
#[UsesClass(ParsedIteration::class)]
#[UsesClass(BlastDatabase::class)]
#[UsesClass(BlastDatabaseProgram::class)]
#[UsesClass(BlastHit::class)]
#[UsesClass(BlastHsp::class)]
#[UsesClass(BlastJob::class)]
#[UsesClass(BlastQuery::class)]
#[UsesClass(BlastProgram::class)]
#[UsesClass(BlastDatabaseRepository::class)]
#[UsesClass(BlastJobRepository::class)]
final class RunBlastJobHandlerTest extends DatabaseTestCase
{
    private BlastTestKit $kit;

    private LockFactory $locks;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->kit = new BlastTestKit();
        $this->locks = new LockFactory(new InMemoryStore());
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->kit->remove();
        parent::tearDown();
    }

    public function testQueuedJobIsProcessedAndTheLockReleased(): void
    {
        $job = $this->pendingJob();

        $this->handler(BlastTestKit::blastWritingOutput('blastn_legacy_volumes.xml'))(new RunBlastJob($job->getId()->toRfc4122()));

        self::assertSame(JobStatus::Finished, $job->getStatus());
        self::assertTrue($this->locks->createLock('blast-job-' . $job->getId()->toRfc4122())->acquire(), 'lock released');
    }

    public function testJobLockedByAnotherWorkerIsLeftAlone(): void
    {
        $job = $this->pendingJob();
        $runner = new RecordingProcessRunner();
        $otherWorker = $this->locks->createLock('blast-job-' . $job->getId()->toRfc4122());
        $otherWorker->acquire();

        $this->handler($runner)(new RunBlastJob($job->getId()->toRfc4122()));

        self::assertSame(JobStatus::Pending, $job->getStatus());
        self::assertSame([], $runner->calls);
    }

    public function testInvalidIdIsNotRetried(): void
    {
        $this->expectException(UnrecoverableMessageHandlingException::class);

        $this->handler(new RecordingProcessRunner())(new RunBlastJob('not-a-uuid'));
    }

    public function testDeletedJobIsNotRetried(): void
    {
        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessageIsOrContains('does not exist');

        $this->handler(new RecordingProcessRunner())(new RunBlastJob(Uuid::v7()->toRfc4122()));
    }

    public function testMessageIsRoutedToTheAsyncTransport(): void
    {
        $routing = new ReflectionClass(RunBlastJob::class)->getAttributes(AsMessage::class)[0]->newInstance();

        self::assertSame('async', $routing->transport);
    }

    private function handler(RecordingProcessRunner $runner): RunBlastJobHandler
    {
        $container = self::getContainer();

        return new RunBlastJobHandler(
            $this->kit->processor($runner, $container->get(BlastJobRepository::class), $container->get(BlastDatabaseRepository::class), $this->entityManager, new MockClock(EntityFactory::NOW)),
            $this->locks,
            new NullLogger(),
        );
    }

    private function pendingJob(): BlastJob
    {
        $database = EntityFactory::database('test_na_db');
        $database->enableAllCompatiblePrograms();

        $this->entityManager->persist($database);
        $this->entityManager->flush();

        $job = EntityFactory::jobOn($database);
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $job;
    }
}
