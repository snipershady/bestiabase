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
use App\Enum\DownloadSequenceType;
use App\Enum\MolType;
use App\Enum\SearchType;
use App\Repository\BlastDatabaseRepository;
use App\Service\Blast\BlastBinaryLocator;
use App\Service\Blast\BlastProcessExecutor;
use App\Service\Blast\Database\BlastDatabaseStorage;
use App\Service\Blast\Exception\BlastExecutionException;
use App\Service\Blast\JobWorkspace;
use App\Service\Blast\Process\ProcessResult;
use App\Service\Blast\Result\AlignmentCoordinates;
use App\Service\Blast\Result\SequenceExtractor;
use App\Service\Blast\SequenceNames;
use App\Tests\Support\BlastTestKit;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\EntityFactory;
use App\Tests\Support\RecordingProcessRunner;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;

#[CoversClass(SequenceExtractor::class)]
#[UsesClass(BlastBinaryLocator::class)]
#[UsesClass(BlastProcessExecutor::class)]
#[UsesClass(BlastDatabaseStorage::class)]
#[UsesClass(BlastExecutionException::class)]
#[UsesClass(JobWorkspace::class)]
#[UsesClass(ProcessResult::class)]
#[UsesClass(AlignmentCoordinates::class)]
#[UsesClass(SequenceNames::class)]
#[UsesClass(BlastDatabase::class)]
#[UsesClass(BlastDatabaseProgram::class)]
#[UsesClass(BlastHit::class)]
#[UsesClass(BlastHsp::class)]
#[UsesClass(BlastJob::class)]
#[UsesClass(BlastQuery::class)]
#[UsesClass(BlastProgram::class)]
#[UsesClass(BlastDatabaseRepository::class)]
final class SequenceExtractorTest extends DatabaseTestCase
{
    private BlastTestKit $kit;

    /** @var list<string> */
    private array $warnings = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->kit = new BlastTestKit();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->kit->remove();
        parent::tearDown();
    }

    public function testMappedRegionsUseBlastCoordinatesWithoutGaps(): void
    {
        $job = EntityFactory::job(BlastProgram::Blastn);
        $query = EntityFactory::query($job);
        $hit = new BlastHit($job, $query, 1, 'gi|1786181|gb|AE000111|ECAE000111', 10596, 'AE000111', 'Escherichia coli section 1');
        $hit->addHsp(new BlastHsp($hit, 1, 43.7, 47.0, '3e-06', 4, 4, 1, 5, 274, 277, 307, 304, 1, -1, 'AC-GT', '|| ||', 'ACAGT'));

        $fasta = $this->extract($job, [$hit], DownloadSequenceType::Mapping);

        self::assertSame(">gi|1786181|gb|AE000111|ECAE000111 Escherichia coli section 1 (Subject: AE000111, 307..304; Query: q1, 274..277)\nACAGT\n", $fasta);
    }

    public function testEntireSequencesComeFromBlastdbcmdThenFromTheFastaFiles(): void
    {
        $sourceDirectory = $this->kit->var->path . '/blastdb/nucleotide';
        new Filesystem()->dumpFile($sourceDirectory . '/demo.fa', ">gi|9|gb|X00001|ONLY_IN_SOURCE local copy\nac gt-\nAC\n>other\nTTTT\n");
        $database = $this->database('demo', 'nucleotide/demo.fa');
        $job = EntityFactory::jobOn($database);
        $query = EntityFactory::query($job);
        $hits = [
            $this->hit($job, $query, 1, 'gi|1786181|gb|AE000111|ECAE000111', 'AE000111'),
            $this->hit($job, $query, 2, 'gi|9|gb|X00001|ONLY_IN_SOURCE', 'X00001'),
            $this->hit($job, $query, 3, 'missing_subject', null),
            $this->hit($job, $query, 4, 'gi|1786181|gb|AE000111|ECAE000111', 'AE000111'),
        ];
        // blastdbcmd trova AE000111 e salta X00001 (exit code 1).
        $runner = new RecordingProcessRunner(new ProcessResult(1, ">AE000111 Escherichia coli\nACGTACGT\n", "Error: [blastdbcmd] Skipped X00001\n"));

        $fasta = $this->extract($job, $hits, DownloadSequenceType::Entire, $runner);

        self::assertSame(">AE000111 Escherichia coli\nACGTACGT\n>gi|9|gb|X00001|ONLY_IN_SOURCE local copy\nACGTAC\n", $fasta, 'each subject once, in selection order');
        self::assertSame(['-entry_batch', '-outfmt', '%f'], [$runner->calls[0]['command'][5], $runner->calls[0]['command'][7], $runner->calls[0]['command'][8]]);
        self::assertSame($this->kit->var->path . '/blastdb/nucleotide/demo', $runner->calls[0]['command'][2]);
        self::assertStringEqualsFile((string) $runner->calls[0]['command'][6], "AE000111\nX00001\n");
        self::assertSame(['Sequence of subject missing_subject not found for job ' . $job->getId()->toRfc4122() . '.'], $this->warnings);
    }

    public function testUploadedSubjectsAreReadFromTheJobFasta(): void
    {
        $job = new BlastJob(Uuid::v7(), BlastProgram::Blastn, SearchType::Basic, [], [], ">q\nACGT\n", EntityFactory::now(), ">patient_12, clone 4\nacgtn\n>patient_13\nGGGG\n");
        $query = EntityFactory::query($job);
        $runner = new RecordingProcessRunner();

        $fasta = $this->extract($job, [$this->hit($job, $query, 1, 'patient_12', null)], DownloadSequenceType::Entire, $runner);

        self::assertSame(">patient_12, clone 4\nACGTN\n", $fasta);
        self::assertSame([], $runner->calls, 'no blastdbcmd without registered databases');
    }

    public function testBlastdbcmdFailureFallsBackToTheSourceFasta(): void
    {
        new Filesystem()->dumpFile($this->kit->var->path . '/blastdb/nucleotide/demo.fa', ">AE000111 from source\nACGT\n");
        $database = $this->database('demo', 'nucleotide/demo.fa');
        $job = EntityFactory::jobOn($database);
        $runner = new RecordingProcessRunner(new ProcessResult(2, '', 'BLAST Database error: No alias or index file found'));

        $fasta = $this->extract($job, [$this->hit($job, EntityFactory::query($job), 1, 'AE000111', 'AE000111')], DownloadSequenceType::Entire, $runner);

        self::assertSame(">AE000111 from source\nACGT\n", $fasta);
        self::assertStringContainsString('blastdbcmd could not extract sequences', $this->warnings[0] ?? '');
    }

    public function testRemovedDatabasesAndMissingSourceFilesAreSkipped(): void
    {
        $database = $this->database('demo', 'nucleotide/not-there.fa');
        $job = new BlastJob(Uuid::v7(), BlastProgram::Blastn, SearchType::Basic, [], [['id' => (int) $database->getId(), 'code' => 'demo', 'title' => 'Demo'], ['id' => 999999, 'code' => 'gone', 'title' => 'Gone']], ">q\nACGT\n", EntityFactory::now());

        $fasta = $this->extract($job, [$this->hit($job, EntityFactory::query($job), 1, 'subject_without_accession', null)], DownloadSequenceType::Entire);

        self::assertSame('', $fasta);
    }

    /**
     * @internal usato dal logger di test
     */
    public function recordWarning(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function testUnreadableSourceFastaIsLoggedAndSkipped(): void
    {
        $path = $this->kit->var->path . '/blastdb/nucleotide/locked.fa';
        new Filesystem()->dumpFile($path, ">AE000111\nACGT\n");
        chmod($path, 0o000);
        $database = $this->database('locked', 'nucleotide/locked.fa');
        $job = EntityFactory::jobOn($database);

        try {
            $fasta = $this->extract($job, [$this->hit($job, EntityFactory::query($job), 1, 'AE000111', null)], DownloadSequenceType::Entire);
        } finally {
            chmod($path, 0o644);
        }

        self::assertSame('', $fasta);
        self::assertSame('FASTA file ' . $path . ' cannot be read.', $this->warnings[0] ?? null);
    }

    /**
     * @param list<BlastHit> $hits
     */
    private function extract(BlastJob $job, array $hits, DownloadSequenceType $type, ?RecordingProcessRunner $runner = null): string
    {
        $test = $this;
        $logger = new class($test) extends AbstractLogger {
            public function __construct(private readonly SequenceExtractorTest $test)
            {
            }

            #[Override]
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $this->test->recordWarning(strtr((string) $message, array_map(static fn (mixed $value): string => \is_scalar($value) ? (string) $value : '', array_combine(array_map(static fn (string $key): string => '{' . $key . '}', array_keys($context)), $context))));
            }
        };
        $extractor = new SequenceExtractor(
            $this->kit->blast->locator(),
            new BlastProcessExecutor($runner ?? new RecordingProcessRunner(), 60.0),
            new BlastDatabaseStorage($this->kit->var->path . '/blastdb'),
            self::getContainer()->get(BlastDatabaseRepository::class),
            $this->kit->workspace(),
            $logger,
        );

        $fasta = '';

        foreach ($extractor->extract($job, $hits, $type) as $record) {
            $fasta .= $record;
        }

        return str_replace($this->kit->var->path . '/blastdb', '/srv/test-blastdb', $fasta);
    }

    private function database(string $code, string $sourceFasta): BlastDatabase
    {
        $database = new BlastDatabase($code, 'Demo', MolType::Nucleotide, 'nucleotide/' . $code, EntityFactory::now());
        $database->enableAllCompatiblePrograms();
        $database->attachSourceFasta($sourceFasta);

        $this->entityManager->persist($database);
        $this->entityManager->flush();

        return $database;
    }

    private function hit(BlastJob $job, BlastQuery $query, int $rank, string $name, ?string $accession): BlastHit
    {
        return new BlastHit($job, $query, $rank, $name, 100, $accession, $name . ' description');
    }
}
