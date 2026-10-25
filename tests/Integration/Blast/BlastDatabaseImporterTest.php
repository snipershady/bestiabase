<?php

declare(strict_types=1);

namespace App\Tests\Integration\Blast;

use App\Entity\BlastDatabase;
use App\Entity\BlastDatabaseProgram;
use App\Entity\User;
use App\Enum\BlastProgram;
use App\Enum\MolType;
use App\Exception\IncompatibleProgramException;
use App\Repository\BlastDatabaseRepository;
use App\Service\Blast\BlastBinaryLocator;
use App\Service\Blast\BlastProcessExecutor;
use App\Service\Blast\Database\BlastDatabaseImporter;
use App\Service\Blast\Database\BlastDatabaseInspector;
use App\Service\Blast\Database\BlastDatabaseMetadata;
use App\Service\Blast\Database\BlastDatabaseStorage;
use App\Service\Blast\Database\DatabaseImportRequest;
use App\Service\Blast\Exception\BlastExecutionException;
use App\Service\Blast\Exception\DatabaseImportException;
use App\Service\Blast\Process\ProcessResult;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\EntityFactory;
use App\Tests\Support\FakeBlastInstallation;
use App\Tests\Support\RecordingProcessRunner;
use App\Tests\Support\TemporaryDirectory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;

#[CoversClass(BlastDatabaseImporter::class)]
#[CoversClass(DatabaseImportRequest::class)]
#[CoversClass(DatabaseImportException::class)]
#[UsesClass(BlastBinaryLocator::class)]
#[UsesClass(BlastProcessExecutor::class)]
#[UsesClass(BlastDatabaseInspector::class)]
#[UsesClass(BlastDatabaseStorage::class)]
#[UsesClass(BlastDatabaseMetadata::class)]
#[UsesClass(BlastExecutionException::class)]
#[UsesClass(ProcessResult::class)]
#[UsesClass(BlastDatabase::class)]
#[UsesClass(BlastDatabaseProgram::class)]
#[UsesClass(BlastDatabaseRepository::class)]
#[UsesClass(User::class)]
#[UsesClass(BlastProgram::class)]
#[UsesClass(IncompatibleProgramException::class)]
final class BlastDatabaseImporterTest extends DatabaseTestCase
{
    private const string METADATA = '{"number-of-sequences":6,"number-of-letters":69370,"bytes-total":69011}';

    private FakeBlastInstallation $blast;

    private TemporaryDirectory $storage;

    private string $fasta;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->blast = new FakeBlastInstallation();
        $this->storage = new TemporaryDirectory();
        $this->fasta = $this->storage->path . '/upload.fa';
        new Filesystem()->dumpFile($this->fasta, ">AE000111.1 E. coli\nACGT\n");
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->blast->remove();
        $this->storage->remove();
        parent::tearDown();
    }

    public function testFastaIsFormattedInspectedAndRegistered(): void
    {
        $creator = EntityFactory::user();
        $this->entityManager->persist($creator);
        $runner = $this->runner();

        $database = $this->importer($runner)->import(new DatabaseImportRequest(
            sourceFasta: $this->fasta,
            code: 'ecoli_sections',
            title: 'E. coli sections',
            molType: MolType::Nucleotide,
            programs: [BlastProgram::Blastn],
            description: 'Six sections',
            createdBy: $creator,
        ));

        $path = $this->storage->path . '/blastdb/nucleotide/ecoli_sections';
        self::assertSame('nucleotide/ecoli_sections', $database->getPath(), 'stored relative to BLAST_DB_DIR');
        self::assertSame('nucleotide/ecoli_sections.fa', $database->getSourceFasta());
        self::assertFileEquals($this->fasta, $path . '.fa');
        self::assertSame([BlastProgram::Blastn], $database->getPrograms());
        self::assertSame('Six sections', $database->getDescription());
        self::assertSame($creator, $database->getCreatedBy());
        self::assertSame(6, $database->getSequenceCount());
        self::assertSame(69370, $database->getTotalLength());
        self::assertSame(EntityFactory::NOW, $database->getCreatedAt()->format('Y-m-d H:i:s'));
        self::assertNotNull($database->getId(), 'persisted');
        self::assertSame(
            [$this->blast->binary('makeblastdb'), '-in', $path . '.fa', '-dbtype', 'nucl', '-title', 'E. coli sections', '-out', $path, '-blastdb_version', '5', '-parse_seqids'],
            $runner->commands()[0],
        );
        self::assertSame(['BLAST_USAGE_REPORT' => 'false'], $runner->calls[0]['environment']);
    }

    public function testAllCompatibleProgramsAndNoSeqidsOnRequest(): void
    {
        $runner = $this->runner();

        $database = $this->importer($runner)->import(new DatabaseImportRequest($this->fasta, 'proteins', 'Proteins', MolType::Protein, parseSeqids: false, restricted: true));

        self::assertFalse($database->isPublic(), 'imported as restricted');

        self::assertSame([BlastProgram::Blastp, BlastProgram::Blastx], $database->getPrograms());
        self::assertSame('protein/proteins', $database->getPath());
        self::assertNotContains('-parse_seqids', $runner->commands()[0]);
    }

    public function testInvalidCodeIsRejected(): void
    {
        $this->expectException(DatabaseImportException::class);
        $this->expectExceptionMessageIsOrContains('code "test.v2" is invalid');

        $this->importer($this->runner())->import(new DatabaseImportRequest($this->fasta, 'test.v2', 'T', MolType::Nucleotide));
    }

    public function testDuplicateCodeIsRejected(): void
    {
        $this->entityManager->persist(EntityFactory::database('taken'));
        $this->entityManager->flush();

        $this->expectException(DatabaseImportException::class);
        $this->expectExceptionMessageIsOrContains('"taken" is already registered');

        $this->importer($this->runner())->import(new DatabaseImportRequest($this->fasta, 'taken', 'T', MolType::Nucleotide));
    }

    public function testMissingFastaIsRejected(): void
    {
        $this->expectException(DatabaseImportException::class);
        $this->expectExceptionMessageIsOrContains('does not exist or is not readable');

        $this->importer($this->runner())->import(new DatabaseImportRequest($this->storage->path . '/missing.fa', 'missing', 'T', MolType::Nucleotide));
    }

    public function testStorageDirectoryWithWhitespaceIsRejected(): void
    {
        $this->expectException(DatabaseImportException::class);
        $this->expectExceptionMessageIsOrContains('must not contain whitespace');

        $this->importer($this->runner(), $this->storage->path . '/my blastdb')->import(new DatabaseImportRequest($this->fasta, 'db', 'T', MolType::Nucleotide));
    }

    public function testIncompatibleProgramIsRejected(): void
    {
        $this->expectException(IncompatibleProgramException::class);

        $this->importer($this->runner())->import(new DatabaseImportRequest($this->fasta, 'db', 'T', MolType::Protein, [BlastProgram::Blastn]));
    }

    public function testFailedFormattingRemovesOnlyTheVolumesOfThisDatabase(): void
    {
        $directory = $this->storage->path . '/blastdb/nucleotide';
        $filesystem = new Filesystem();
        $filesystem->dumpFile($directory . '/other.nsq', 'volume of another database');

        $runner = new RecordingProcessRunner(new ProcessResult(1, '', 'Error: Duplicate seq_ids are found'))
            ->onRun(static function () use ($filesystem, $directory): void {
                $filesystem->dumpFile($directory . '/broken.nhr', 'partial volume');
            });

        try {
            $this->importer($runner)->import(new DatabaseImportRequest($this->fasta, 'broken', 'T', MolType::Nucleotide));
            self::fail('makeblastdb failure was not reported');
        } catch (BlastExecutionException $blastExecutionException) {
            self::assertStringContainsString('Duplicate seq_ids', $blastExecutionException->getMessage());
        }

        self::assertFileDoesNotExist($directory . '/broken.nhr');
        self::assertFileDoesNotExist($directory . '/broken.fa');
        self::assertFileExists($directory . '/other.nsq');
        self::assertSame(0, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM blast_database'));
    }

    private function runner(): RecordingProcessRunner
    {
        return new RecordingProcessRunner(new ProcessResult(0, '', ''), new ProcessResult(0, self::METADATA, ''));
    }

    private function importer(RecordingProcessRunner $runner, ?string $storage = null): BlastDatabaseImporter
    {
        $locator = $this->blast->locator();
        $executor = new BlastProcessExecutor($runner, 60.0);

        return new BlastDatabaseImporter(
            $locator,
            $executor,
            new BlastDatabaseInspector($locator, $executor, new EffectivePrimitiveTypeIdentifierService()),
            self::getContainer()->get(BlastDatabaseRepository::class),
            $this->entityManager,
            new Filesystem(),
            new MockClock(EntityFactory::NOW),
            new BlastDatabaseStorage($storage ?? $this->storage->path . '/blastdb/'),
        );
    }
}
