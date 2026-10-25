<?php

declare(strict_types=1);

namespace App\Tests\Unit\Blast;

use App\Entity\BlastDatabase;
use App\Entity\BlastDatabaseProgram;
use App\Entity\BlastJob;
use App\Enum\BlastProgram;
use App\Enum\MolType;
use App\Enum\SearchType;
use App\Exception\IncompatibleProgramException;
use App\Service\Blast\BlastBinaryLocator;
use App\Service\Blast\BlastCommandBuilder;
use App\Service\Blast\BlastProcessExecutor;
use App\Service\Blast\BlastRunner;
use App\Service\Blast\Database\BlastDatabaseStorage;
use App\Service\Blast\Exception\BlastExecutionException;
use App\Service\Blast\JobWorkspace;
use App\Service\Blast\Process\ProcessResult;
use App\Service\Blast\Search\GapCostPresets;
use App\Service\Blast\Search\GapCosts;
use App\Service\Blast\Search\SearchParameters;
use App\Service\Blast\Search\SearchParametersNormalizer;
use App\Tests\Support\EntityFactory;
use App\Tests\Support\FakeBlastInstallation;
use App\Tests\Support\RecordingProcessRunner;
use App\Tests\Support\TemporaryDirectory;
use InvalidArgumentException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;

#[CoversClass(BlastRunner::class)]
#[CoversClass(JobWorkspace::class)]
#[CoversClass(ProcessResult::class)]
#[CoversClass(BlastExecutionException::class)]
#[CoversClass(BlastProcessExecutor::class)]
#[UsesClass(BlastBinaryLocator::class)]
#[UsesClass(BlastCommandBuilder::class)]
#[UsesClass(BlastDatabaseStorage::class)]
#[UsesClass(SearchParameters::class)]
#[UsesClass(SearchParametersNormalizer::class)]
#[UsesClass(GapCosts::class)]
#[UsesClass(GapCostPresets::class)]
#[UsesClass(BlastDatabase::class)]
#[UsesClass(BlastDatabaseProgram::class)]
#[UsesClass(BlastJob::class)]
#[UsesClass(BlastProgram::class)]
#[UsesClass(IncompatibleProgramException::class)]
final class BlastRunnerTest extends TestCase
{
    private FakeBlastInstallation $blast;

    private TemporaryDirectory $var;

    private JobWorkspace $workspace;

    #[Override]
    protected function setUp(): void
    {
        $this->blast = new FakeBlastInstallation();
        $this->var = new TemporaryDirectory();
        $this->workspace = new JobWorkspace($this->var->path . '/', new Filesystem());
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->blast->remove();
        $this->var->remove();
    }

    public function testBasicSearchOnRegisteredDatabases(): void
    {
        $runner = new RecordingProcessRunner();
        $job = EntityFactory::job();

        $output = $this->runner($runner)->run($job, [$this->database('na1'), $this->database('na2')]);

        $directory = $this->var->path . '/jobs/' . $job->getId()->toRfc4122();
        self::assertSame($directory . '/output.xml', $output);
        self::assertSame($directory, $this->workspace->directory($job->getId()));
        self::assertStringEqualsFile($directory . '/query.fa', ">q1\nACGT\n");
        self::assertCount(1, $runner->calls);
        self::assertSame($directory, $runner->calls[0]['workingDirectory']);
        self::assertEqualsWithDelta(120.0, $runner->calls[0]['timeout'], \PHP_FLOAT_EPSILON);
        self::assertSame(['BLAST_USAGE_REPORT' => 'false'], $runner->calls[0]['environment'], 'no usage report is sent to NCBI');
        self::assertSame(
            [$this->blast->binary('blastn'), '-task', 'blastn', '-db', '/srv/blastdb/na1 /srv/blastdb/na2', '-query', $directory . '/query.fa', '-out', $output, '-outfmt', '5', '-max_target_seqs', '50'],
            $runner->calls[0]['command'],
        );
    }

    public function testUploadedSubjectIsFormattedWithMakeblastdbFirst(): void
    {
        $runner = new RecordingProcessRunner();
        $job = EntityFactory::job(BlastProgram::Blastx, subjectFasta: ">s1\nMKV\n");

        $this->runner($runner)->run($job, []);

        $directory = $this->workspace->directory($job->getId());
        self::assertStringEqualsFile($directory . '/subject.fa', ">s1\nMKV\n");
        self::assertSame(
            [$this->blast->binary('makeblastdb'), '-in', $directory . '/subject.fa', '-dbtype', 'prot', '-out', $directory . '/subject'],
            $runner->commands()[0],
        );
        self::assertContains($directory . '/subject', $runner->commands()[1]);
    }

    public function testAdvancedParametersAreReadFromTheJobSnapshot(): void
    {
        $runner = new RecordingProcessRunner();
        $normalizer = new SearchParametersNormalizer(new EffectivePrimitiveTypeIdentifierService());
        $job = new BlastJob(
            Uuid::v7(),
            BlastProgram::Blastp,
            SearchType::Advanced,
            $normalizer->normalize(new SearchParameters(0.01, 2, 10)),
            [],
            ">q\nMKV\n",
            EntityFactory::now(),
        );

        $this->runner($runner)->run($job, [$this->database('aa', MolType::Protein)]);

        $command = $runner->commands()[0];
        self::assertSame(['-evalue', '0.01', '-word_size', '2'], \array_slice($command, 9, 4));
        self::assertSame('10', $command[\count($command) - 1]);
    }

    public function testDatabaseNotEnabledForTheProgramIsRejected(): void
    {
        $database = $this->database('na');
        $database->disableProgram(BlastProgram::Tblastn);

        $this->expectException(IncompatibleProgramException::class);

        $this->runner(new RecordingProcessRunner())->run(EntityFactory::job(BlastProgram::Tblastn), [$database]);
    }

    public function testJobWithoutAnyTargetIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->runner(new RecordingProcessRunner())->run(EntityFactory::job(), []);
    }

    public function testBlastFailureCarriesTheErrorOutput(): void
    {
        $runner = new RecordingProcessRunner(new ProcessResult(2, '', "BLAST Database error: No alias or index file found\n"));

        $this->expectException(BlastExecutionException::class);
        $this->expectExceptionMessageIsOrContains('blastn exited with code 2: BLAST Database error: No alias or index file found');

        $this->runner($runner)->run(EntityFactory::job(), [$this->database('na')]);
    }

    public function testFailureWithoutErrorOutputIsStillReported(): void
    {
        $runner = new RecordingProcessRunner(new ProcessResult(137, '', ''));

        $this->expectException(BlastExecutionException::class);
        $this->expectExceptionMessageIsOrContains('blastn exited with code 137 and no error output.');

        $this->runner($runner)->run(EntityFactory::job(), [$this->database('na')]);
    }

    public function testMakeblastdbFailureStopsTheSearch(): void
    {
        $runner = new RecordingProcessRunner(new ProcessResult(1, '', 'FASTA-Reader: Ignoring invalid residues'));

        try {
            $this->runner($runner)->run(EntityFactory::job(subjectFasta: ">s\nACGT\n"), []);
            self::fail('makeblastdb failure was not reported');
        } catch (BlastExecutionException $blastExecutionException) {
            self::assertStringStartsWith('makeblastdb exited with code 1', $blastExecutionException->getMessage());
        }

        self::assertCount(1, $runner->calls);
    }

    public function testTimeoutIsReported(): void
    {
        $runner = new RecordingProcessRunner(new ProcessResult(-1, '', '', true));

        $this->expectException(BlastExecutionException::class);
        $this->expectExceptionMessageIsOrContains('blastn did not finish within 120 seconds.');

        $this->runner($runner)->run(EntityFactory::job(), [$this->database('na')]);
    }

    public function testWorkspaceCanBeRemoved(): void
    {
        $job = EntityFactory::job();
        $this->runner(new RecordingProcessRunner())->run($job, [$this->database('na')]);

        $this->workspace->remove($job->getId());

        self::assertDirectoryDoesNotExist($this->workspace->directory($job->getId()));
    }

    public function testExecutorAcceptsTheExitCodesItIsToldTo(): void
    {
        $executor = new BlastProcessExecutor(new RecordingProcessRunner(new ProcessResult(1, 'partial', 'Skipped X'), new ProcessResult(1, '', 'Skipped X')), 10.0);

        self::assertSame('partial', $executor->execute('blastdbcmd', ['/bin/blastdbcmd'], '/tmp', [0, 1])->output);

        $this->expectException(BlastExecutionException::class);

        $executor->execute('blastdbcmd', ['/bin/blastdbcmd'], '/tmp');
    }

    private function runner(RecordingProcessRunner $processRunner): BlastRunner
    {
        $locator = $this->blast->locator();

        return new BlastRunner(
            $locator,
            new BlastCommandBuilder($locator),
            new SearchParametersNormalizer(new EffectivePrimitiveTypeIdentifierService()),
            new BlastProcessExecutor($processRunner, 120.0),
            $this->workspace,
            new BlastDatabaseStorage('/srv/blastdb'),
        );
    }

    /**
     * @param non-empty-string $code
     */
    private function database(string $code, MolType $molType = MolType::Nucleotide): BlastDatabase
    {
        $database = EntityFactory::database($code, $molType);
        $database->enableAllCompatiblePrograms();

        return $database;
    }
}
