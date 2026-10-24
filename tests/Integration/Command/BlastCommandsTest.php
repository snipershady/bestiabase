<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Blast\BlastBinaryLocator;
use App\Blast\BlastCommandBuilder;
use App\Blast\BlastProcessExecutor;
use App\Blast\BlastResultImporter;
use App\Blast\BlastRunner;
use App\Blast\BlastVersionReader;
use App\Blast\Database\BlastDatabaseImporter;
use App\Blast\Database\BlastDatabaseInspector;
use App\Blast\Database\BlastDatabaseMetadata;
use App\Blast\Database\BlastDatabaseStorage;
use App\Blast\Database\DatabaseImportRequest;
use App\Blast\Exception\BlastExecutionException;
use App\Blast\Exception\DatabaseImportException;
use App\Blast\Fasta\FastaDocument;
use App\Blast\Fasta\FastaNormalizer;
use App\Blast\Fasta\FastaRecord;
use App\Blast\ImportSummary;
use App\Blast\Job\BlastJobFactory;
use App\Blast\Job\BlastJobProcessor;
use App\Blast\Job\BlastJobSubmitter;
use App\Blast\Job\BlastSearchRequest;
use App\Blast\Job\JobCleaner;
use App\Blast\JobWorkspace;
use App\Blast\Process\ProcessResult;
use App\Blast\Process\SymfonyProcessRunner;
use App\Blast\Result\BlastNumberFormat;
use App\Blast\Result\HitFilter;
use App\Blast\Result\HitRow;
use App\Blast\Search\GapCostPresets;
use App\Blast\Search\GapCosts;
use App\Blast\Search\NucleotideScoring;
use App\Blast\Search\SearchParameters;
use App\Blast\Search\SearchParametersNormalizer;
use App\Blast\SequenceNames;
use App\Blast\Xml\BlastXmlParser;
use App\Blast\Xml\ParsedHit;
use App\Blast\Xml\ParsedHsp;
use App\Blast\Xml\ParsedIteration;
use App\Command\BlastSearchCommand;
use App\Command\CleanupBlastJobsCommand;
use App\Command\ImportBlastDatabaseCommand;
use App\Command\ImportBlastTestDataCommand;
use App\Entity\BlastDatabase;
use App\Entity\BlastDatabaseProgram;
use App\Entity\BlastHit;
use App\Entity\BlastHsp;
use App\Entity\BlastJob;
use App\Entity\BlastQuery;
use App\Enum\BlastProgram;
use App\Enum\JobStatus;
use App\Enum\MolType;
use App\Exception\IncompatibleProgramException;
use App\Message\RunBlastJob;
use App\Repository\BlastDatabaseRepository;
use App\Repository\BlastHitRepository;
use App\Repository\BlastJobRepository;
use App\Repository\UserRepository;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\EntityFactory;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Tester\ExecutionResult;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;

/**
 * Comandi eseguiti con il container reale e i binari BLAST+ veri (BLAST_DB_DIR e
 * BLAST_VAR_DIR puntano a var/test/ in ambiente di test).
 */
#[Group('blast')]
#[CoversClass(ImportBlastDatabaseCommand::class)]
#[CoversClass(ImportBlastTestDataCommand::class)]
#[CoversClass(BlastSearchCommand::class)]
#[CoversClass(CleanupBlastJobsCommand::class)]
#[UsesClass(BlastBinaryLocator::class)]
#[UsesClass(BlastCommandBuilder::class)]
#[UsesClass(BlastProcessExecutor::class)]
#[UsesClass(BlastResultImporter::class)]
#[UsesClass(BlastRunner::class)]
#[UsesClass(BlastDatabaseImporter::class)]
#[UsesClass(BlastDatabaseInspector::class)]
#[UsesClass(BlastDatabaseMetadata::class)]
#[UsesClass(DatabaseImportRequest::class)]
#[UsesClass(BlastExecutionException::class)]
#[UsesClass(DatabaseImportException::class)]
#[UsesClass(FastaDocument::class)]
#[UsesClass(FastaNormalizer::class)]
#[UsesClass(FastaRecord::class)]
#[UsesClass(ImportSummary::class)]
#[UsesClass(JobWorkspace::class)]
#[UsesClass(BlastJobFactory::class)]
#[UsesClass(BlastJobProcessor::class)]
#[UsesClass(BlastSearchRequest::class)]
#[UsesClass(ProcessResult::class)]
#[UsesClass(SymfonyProcessRunner::class)]
#[UsesClass(GapCostPresets::class)]
#[UsesClass(GapCosts::class)]
#[UsesClass(NucleotideScoring::class)]
#[UsesClass(SearchParameters::class)]
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
#[UsesClass(IncompatibleProgramException::class)]
#[UsesClass(BlastDatabaseRepository::class)]
#[UsesClass(BlastHitRepository::class)]
#[UsesClass(BlastJobRepository::class)]
#[UsesClass(BlastDatabaseStorage::class)]
#[UsesClass(BlastVersionReader::class)]
#[UsesClass(BlastJobSubmitter::class)]
#[UsesClass(JobCleaner::class)]
#[UsesClass(JobStatus::class)]
#[UsesClass(RunBlastJob::class)]
#[UsesClass(BlastNumberFormat::class)]
#[UsesClass(HitFilter::class)]
#[UsesClass(HitRow::class)]
#[UsesClass(UserRepository::class)]
final class BlastCommandsTest extends DatabaseTestCase
{
    private const string DEMO = __DIR__ . '/../../../fixtures/blast-demo/';

    private string $nucleotideQuery;

    private string $proteinQuery;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $binDir = new EffectivePrimitiveTypeIdentifierService()->getStringValueFromArray('BLAST_BIN_DIR', $_SERVER, trim: true);

        if ($binDir === '' || !is_executable($binDir . '/blastn')) {
            self::markTestSkipped('BLAST+ binaries not available in BLAST_BIN_DIR.');
        }

        $this->removeTestStorage();
        $this->nucleotideQuery = $this->projectDir() . '/var/test/query.fa';
        $this->proteinQuery = $this->projectDir() . '/var/test/protein.fa';
        $demo = (string) file_get_contents(self::DEMO . 'test_na_db.fa');
        $lines = \array_slice(explode("\n", $demo), 1, 5);
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->nucleotideQuery, ">q1 E. coli section 1\n" . implode("\n", $lines) . "\n");
        $filesystem->dumpFile($this->proteinQuery, ">p1 random\nMKVLAAWQQESTRPLLK\n");
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->removeTestStorage();
        parent::tearDown();
    }

    public function testDemoDataIsImportedOnceAndSearchable(): void
    {
        $first = self::runCommand('app:blast:import-test-data', interactive: false);
        self::assertSame(Command::SUCCESS, $first->statusCode);
        self::assertStringContainsString('test_na_db registered: 6 sequences.', $this->flat($first));
        self::assertStringContainsString('test_aa_db registered: 214 sequences.', $this->flat($first));

        $second = self::runCommand('app:blast:import-test-data', interactive: false);
        self::assertStringContainsString('test_na_db is already registered, skipped.', $this->flat($second));

        $search = self::runCommand('app:blast:search', ['program' => 'blastn', 'query' => $this->nucleotideQuery, '--database' => ['test_na_db']], interactive: false);
        self::assertSame(Command::SUCCESS, $search->statusCode, $this->flat($search));
        self::assertMatchesRegularExpression('/q1 gi\|1786181\|gb\|AE000111\|ECAE000111 \d+ 350\/350 \(350\) 100 0\.0 /', $this->flat($search));
    }

    public function testAtMostTenRowsPerQueryAreShown(): void
    {
        self::runCommand('app:blast:import-test-data', interactive: false);
        $kcc3 = $this->projectDir() . '/var/test/kcc3.fa';
        $proteins = explode('>', (string) file_get_contents(self::DEMO . 'test_aa_db.fa'));
        new Filesystem()->dumpFile($kcc3, '>' . $proteins[3]);

        $search = self::runCommand('app:blast:search', ['program' => 'blastp', 'query' => $kcc3, '--database' => ['test_aa_db'], '--evalue' => '1000'], interactive: false);

        self::assertSame(Command::SUCCESS, $search->statusCode, self::flat($search));
        // Righe della tabella che iniziano con la query (il nome ricompare anche come primo soggetto).
        self::assertSame(10, preg_match_all('/^\s*gi\|6693798\|gb\|AAF24986\.1\|AF116242_1\s/m', $search->getDisplay()), 'ten rows for the query');
        self::assertMatchesRegularExpression('/1 queries, (1[1-9]|[2-9]\d|\d{3,}) hits \(at most 10 shown per query\)/', self::flat($search));
    }

    public function testDemoImportFailureIsReported(): void
    {
        $container = self::getContainer();
        $application = new Application(self::$kernel ?? self::bootKernel());
        $application->addCommand(new ImportBlastTestDataCommand(
            $container->get(BlastDatabaseImporter::class),
            $container->get(BlastDatabaseRepository::class),
            '/nonexistent/demo',
        ));
        $tester = new CommandTester($application->find('app:blast:import-test-data'));

        self::assertSame(Command::FAILURE, $tester->execute([], ['interactive' => false]));
        self::assertStringContainsString('test_na_db: FASTA file "/nonexistent/demo/test_na_db.fa" does not exist', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
    }

    public function testAsyncSearchIsQueuedForAWorker(): void
    {
        self::runCommand('app:blast:import-test-data', interactive: false);

        $search = self::runCommand('app:blast:search', ['program' => 'blastn', 'query' => $this->nucleotideQuery, '--database' => ['test_na_db'], '--async' => true], interactive: false);

        self::assertSame(Command::SUCCESS, $search->statusCode, self::flat($search));
        self::assertStringContainsString('queued. Run "bin/console messenger:consume async" to process it.', self::flat($search));
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertCount(1, $transport->getSent());
        self::assertSame('pending', $this->entityManager->getConnection()->fetchOne('SELECT status FROM blast_job'));
    }

    public function testCleanupHonoursTheRetention(): void
    {
        $database = EntityFactory::database('cleanup_db');
        $this->entityManager->persist($database);
        $this->entityManager->flush();

        $old = EntityFactory::jobOn($database, new DateTimeImmutable('-90 days'));
        $old->cancel(EntityFactory::now());

        $this->entityManager->persist($old);
        $this->entityManager->flush();

        $dryRun = self::runCommand('app:blast:cleanup', ['--days' => '60', '--dry-run' => true], interactive: false);
        self::assertStringContainsString('1 jobs older than 60 days would be deleted.', self::flat($dryRun));

        $cleanup = self::runCommand('app:blast:cleanup', interactive: false);
        self::assertSame(Command::SUCCESS, $cleanup->statusCode);
        self::assertStringContainsString('1 jobs older than 30 days deleted.', self::flat($cleanup), 'default from BLAST_JOB_RETENTION_DAYS');

        $invalid = self::runCommand('app:blast:cleanup', ['--days' => '0'], interactive: false);
        self::assertSame(Command::INVALID, $invalid->statusCode);
    }

    public function testImportDatabaseWithSelectedPrograms(): void
    {
        $tester = self::runCommand('app:blast:import-db', [
            'fasta' => self::DEMO . 'test_aa_db.fa',
            'code' => 'hepa_proteins',
            'mol-type' => 'prot',
            'title' => 'HepA-related proteins',
            '--program' => ['blastp'],
            '--description' => 'Imported by the test suite',
            '--restricted' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->statusCode, $this->flat($tester));
        self::assertStringContainsString('Registered "hepa_proteins" (prot, restricted): 214 sequences, 49279 letters, programs: blastp.', $this->flat($tester));
        $database = $this->entityManager->getRepository(BlastDatabase::class)->findOneBy(['code' => 'hepa_proteins']);
        self::assertInstanceOf(BlastDatabase::class, $database);
        self::assertFileExists($this->projectDir() . '/var/test/blastdb/' . $database->getPath() . '.psq');
    }

    public function testImportDatabaseRejectsUnknownProgramsAndReportsDomainErrors(): void
    {
        $unknown = self::runCommand('app:blast:import-db', ['fasta' => self::DEMO . 'test_aa_db.fa', 'code' => 'x', 'mol-type' => 'prot', 'title' => 'X', '--program' => ['blastz']], interactive: false);
        self::assertSame(Command::INVALID, $unknown->statusCode);
        self::assertStringContainsString('Unknown program "blastz"', $this->flat($unknown));

        $missing = self::runCommand('app:blast:import-db', ['fasta' => '/nonexistent.fa', 'code' => 'x', 'mol-type' => 'prot', 'title' => 'X', '--no-parse-seqids' => true], interactive: false);
        self::assertSame(Command::FAILURE, $missing->statusCode);
        self::assertStringContainsString('does not exist or is not readable', $this->flat($missing));
    }

    public function testAdvancedSearchAgainstAnUploadedSubjectWithoutHits(): void
    {
        $tester = self::runCommand('app:blast:search', ['program' => 'tblastn', 'query' => $this->proteinQuery, '--subject' => self::DEMO . 'test_na_db.fa', '--evalue' => '1e-30', '--max-target-seqs' => '5'], interactive: false);

        self::assertSame(Command::SUCCESS, $tester->statusCode, $this->flat($tester));
        self::assertStringContainsString('No comparison meets the cutoff criterion', $this->flat($tester));
    }

    public function testSearchInputErrors(): void
    {
        $this->entityManager->persist(EntityFactory::database('only_nucl'));
        $this->entityManager->flush();

        $cases = [
            'unknown database' => [['program' => 'blastn', 'query' => $this->nucleotideQuery, '--database' => ['nope']], 'No BLAST database is registered with code "nope".'],
            'missing query' => [['program' => 'blastn', 'query' => '/nonexistent.fa', '--database' => ['only_nucl']], 'File "/nonexistent.fa" does not exist'],
            'unknown owner' => [['program' => 'blastn', 'query' => $this->nucleotideQuery, '--database' => ['only_nucl'], '--user' => 'ghost@example.org'], 'No user is registered with email "ghost@example.org".'],
            'missing subject' => [['program' => 'blastn', 'query' => $this->nucleotideQuery, '--subject' => '/nonexistent.fa'], 'File "/nonexistent.fa" does not exist'],
            'program not enabled' => [['program' => 'blastn', 'query' => $this->nucleotideQuery, '--database' => ['only_nucl']], 'Program "blastn" searches nucl databases and cannot use a nucl database.'],
        ];

        foreach ($cases as $case => [$input, $message]) {
            $tester = self::runCommand('app:blast:search', $input, interactive: false);
            self::assertSame(Command::INVALID, $tester->statusCode, $case);
            self::assertStringContainsString($message, $this->flat($tester), $case);
        }
    }

    public function testFailedSearchReportsTheBlastError(): void
    {
        $database = EntityFactory::database('broken_db', MolType::Nucleotide);
        $database->enableAllCompatiblePrograms();

        $this->entityManager->persist($database);
        $this->entityManager->flush();

        $tester = self::runCommand('app:blast:search', ['program' => 'blastn', 'query' => $this->nucleotideQuery, '--database' => ['broken_db'], '--advanced' => true], interactive: false);

        self::assertSame(Command::FAILURE, $tester->statusCode);
        self::assertStringContainsString('failed: blastn exited with code', $this->flat($tester));
    }

    /**
     * Output con gli spazi normalizzati: SymfonyStyle va a capo alla larghezza del terminale.
     */
    private function flat(ExecutionResult $result): string
    {
        return preg_replace('/\s+/', ' ', $result->getDisplay()) ?? '';
    }

    private function projectDir(): string
    {
        return \dirname(__DIR__, 3);
    }

    private function removeTestStorage(): void
    {
        new Filesystem()->remove([$this->projectDir() . '/var/test/blastdb', $this->projectDir() . '/var/test/blast']);
    }
}
