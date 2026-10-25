<?php

declare(strict_types=1);

namespace App\Tests\Integration\Blast;

use App\Entity\BlastDatabase;
use App\Entity\BlastDatabaseProgram;
use App\Entity\BlastHit;
use App\Entity\BlastHsp;
use App\Entity\BlastJob;
use App\Entity\BlastQuery;
use App\Enum\BlastProgram;
use App\Enum\JobStatus;
use App\Enum\SearchType;
use App\Repository\BlastDatabaseRepository;
use App\Repository\BlastJobRepository;
use App\Service\Blast\BlastBinaryLocator;
use App\Service\Blast\BlastCommandBuilder;
use App\Service\Blast\BlastProcessExecutor;
use App\Service\Blast\BlastResultImporter;
use App\Service\Blast\BlastRunner;
use App\Service\Blast\BlastVersionReader;
use App\Service\Blast\Database\BlastDatabaseStorage;
use App\Service\Blast\Exception\BlastExecutionException;
use App\Service\Blast\Exception\BlastOutputParseException;
use App\Service\Blast\Exception\DatabaseUnavailableException;
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
use App\Tests\Support\BlastTestKit;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\EntityFactory;
use App\Tests\Support\RecordingProcessRunner;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;

#[CoversClass(BlastJobProcessor::class)]
#[CoversClass(JobNotFoundException::class)]
#[CoversClass(DatabaseUnavailableException::class)]
#[UsesClass(BlastBinaryLocator::class)]
#[UsesClass(BlastCommandBuilder::class)]
#[UsesClass(BlastDatabaseStorage::class)]
#[UsesClass(BlastProcessExecutor::class)]
#[UsesClass(BlastRunner::class)]
#[UsesClass(BlastVersionReader::class)]
#[UsesClass(BlastResultImporter::class)]
#[UsesClass(BlastXmlParser::class)]
#[UsesClass(BlastExecutionException::class)]
#[UsesClass(BlastOutputParseException::class)]
#[UsesClass(ImportSummary::class)]
#[UsesClass(JobWorkspace::class)]
#[UsesClass(ProcessResult::class)]
#[UsesClass(SearchParametersNormalizer::class)]
#[UsesClass(SequenceNames::class)]
#[UsesClass(ParsedIteration::class)]
#[UsesClass(ParsedHit::class)]
#[UsesClass(ParsedHsp::class)]
#[UsesClass(BlastDatabase::class)]
#[UsesClass(BlastDatabaseProgram::class)]
#[UsesClass(BlastJob::class)]
#[UsesClass(BlastQuery::class)]
#[UsesClass(BlastHit::class)]
#[UsesClass(BlastHsp::class)]
#[UsesClass(BlastDatabaseRepository::class)]
#[UsesClass(BlastJobRepository::class)]
#[UsesClass(BlastProgram::class)]
final class BlastJobProcessorTest extends DatabaseTestCase
{
    private const string FIXTURES = __DIR__ . '/../../Fixtures/blast/';

    private BlastTestKit $kit;

    private MockClock $clock;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->kit = new BlastTestKit();
        $this->clock = new MockClock(EntityFactory::NOW);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->kit->remove();
        parent::tearDown();
    }

    public function testPendingJobRunsAndFinishesWithItsResults(): void
    {
        $job = $this->pendingJob();
        $runner = BlastTestKit::blastWritingOutput('blastn_legacy_volumes.xml');

        $processed = $this->processor($runner)->process($job->getId());

        self::assertSame(JobStatus::Finished, $processed->getStatus());
        self::assertSame([1, 2], [$processed->getNumQueries(), $processed->getNumHits()]);
        self::assertSame('2.17.0+', $processed->getBlastVersion());
        self::assertSame(EntityFactory::NOW, $processed->getStartedAt()?->format('Y-m-d H:i:s'));
        self::assertNotNull($processed->getFinishedAt());
        self::assertStringContainsString('/srv/blastdb/test_na_db', implode(' ', $runner->commands()[0]));
        self::assertSame(3, $this->rowsIn('blast_hsp'));
    }

    public function testBlastFailureMarksTheJobFailedWithTheBlastMessage(): void
    {
        $job = $this->pendingJob();
        $runner = new RecordingProcessRunner(new ProcessResult(2, '', 'BLAST Database error: No alias or index file found'));

        $processed = $this->processor($runner)->process($job->getId());

        self::assertSame(JobStatus::Failed, $processed->getStatus());
        self::assertSame('blastn exited with code 2: BLAST Database error: No alias or index file found', $processed->getErrorMessage());
        self::assertSame(0, $this->rowsIn('blast_query'));
    }

    public function testPartialResultsAreRemovedWhenTheOutputBreaksHalfway(): void
    {
        $job = $this->pendingJob();
        $valid = (string) file_get_contents(self::FIXTURES . 'blastn_parse_seqids.xml');
        $start = (int) strpos($valid, '<Iteration>');
        $emptyIteration = substr($valid, $start, (int) strpos($valid, '</Iteration>') + \strlen('</Iteration>') - $start) . "\n";
        // 600 query senza hit (l'importer ne salva già 500 con il primo flush), poi una query troncata.
        $broken = substr($valid, 0, $start) . str_repeat($emptyIteration, 600) . substr($valid, (int) strpos($valid, '<Iteration>', $start + 1), 200);
        $runner = new RecordingProcessRunner()->onRun(static function (array $command) use ($broken): void {
            new Filesystem()->dumpFile(BlastTestKit::outputPath($command), $broken);
        });

        $processed = $this->processor($runner)->process($job->getId());

        self::assertSame(JobStatus::Failed, $processed->getStatus());
        self::assertStringContainsString('is malformed', (string) $processed->getErrorMessage());
        self::assertSame(0, $this->rowsIn('blast_query'), 'the 500 queries already flushed are removed too');
    }

    public function testRemovedOrInactiveDatabaseFailsTheJob(): void
    {
        $job = $this->pendingJob();
        $database = $this->entityManager->getRepository(BlastDatabase::class)->findOneBy(['code' => 'test_na_db']);
        self::assertInstanceOf(BlastDatabase::class, $database);
        $database->deactivate();
        $this->entityManager->flush();

        $processed = $this->processor(new RecordingProcessRunner())->process($job->getId());

        self::assertSame(JobStatus::Failed, $processed->getStatus());
        self::assertSame('The selected BLAST database(s) are no longer available: test_na_db.', $processed->getErrorMessage());
    }

    public function testUnexpectedErrorsAreNotShownToTheUser(): void
    {
        $job = $this->pendingJob();
        $runner = new RecordingProcessRunner()->onRun(static function (): never {
            throw new RuntimeException('SQLSTATE[HY000]: secret internal detail at /srv/app');
        });

        $processed = $this->processor($runner)->process($job->getId());

        self::assertSame(JobStatus::Failed, $processed->getStatus());
        self::assertSame(BlastJobProcessor::INTERNAL_ERROR, $processed->getErrorMessage());
    }

    public function testAlreadyProcessedJobIsNotRunAgain(): void
    {
        $job = $this->pendingJob();
        $job->cancel(EntityFactory::now());

        $this->entityManager->flush();
        $runner = new RecordingProcessRunner();

        $processed = $this->processor($runner)->process($job->getId());

        self::assertSame(JobStatus::Cancelled, $processed->getStatus());
        self::assertSame([], $runner->calls);
    }

    public function testUnknownJobIsReported(): void
    {
        $this->expectException(JobNotFoundException::class);

        $this->processor(new RecordingProcessRunner())->process(Uuid::v7());
    }

    private function pendingJob(): BlastJob
    {
        $database = EntityFactory::database('test_na_db');
        $database->enableAllCompatiblePrograms();

        $this->entityManager->persist($database);
        $this->entityManager->flush();

        $job = new BlastJob(
            Uuid::v7(),
            BlastProgram::Blastn,
            SearchType::Basic,
            [],
            [['id' => (int) $database->getId(), 'code' => 'test_na_db', 'title' => 'Nucleotide test database']],
            ">q1 test query\nACGT\n",
            EntityFactory::now(),
        );
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $job;
    }

    private function processor(RecordingProcessRunner $processRunner): BlastJobProcessor
    {
        $container = self::getContainer();

        return $this->kit->processor($processRunner, $container->get(BlastJobRepository::class), $container->get(BlastDatabaseRepository::class), $this->entityManager, $this->clock);
    }

    private function rowsIn(string $table): mixed
    {
        return $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }
}
