<?php

declare(strict_types=1);

namespace App\Tests\Integration\Blast;

use App\Entity\BlastDatabase;
use App\Entity\BlastHit;
use App\Entity\BlastJob;
use App\Enum\BlastProgram;
use App\Enum\DownloadSequenceType;
use App\Enum\JobStatus;
use App\Enum\MolType;
use App\Enum\SearchType;
use App\Repository\BlastDatabaseRepository;
use App\Service\Blast\BlastBinaryLocator;
use App\Service\Blast\BlastCommandBuilder;
use App\Service\Blast\BlastProcessExecutor;
use App\Service\Blast\BlastResultImporter;
use App\Service\Blast\BlastRunner;
use App\Service\Blast\BlastTool;
use App\Service\Blast\Database\BlastDatabaseStorage;
use App\Service\Blast\Fasta\FastaDocument;
use App\Service\Blast\Fasta\FastaNormalizer;
use App\Service\Blast\Fasta\FastaRecord;
use App\Service\Blast\ImportSummary;
use App\Service\Blast\JobWorkspace;
use App\Service\Blast\Process\SymfonyProcessRunner;
use App\Service\Blast\Result\SequenceExtractor;
use App\Service\Blast\Search\SearchParameters;
use App\Service\Blast\Search\SearchParametersNormalizer;
use App\Service\Blast\Xml\BlastXmlParser;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\EntityFactory;
use App\Tests\Support\TemporaryDirectory;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;

/**
 * Esegue i binari BLAST+ veri sui database demo del legacy, ricostruiti con -parse_seqids.
 * Verifica che ogni argv prodotto dal builder sia accettato da BLAST+ e che l'intera
 * pipeline (runner → parser → importer) produca i risultati attesi.
 *
 * Saltato se BLAST_BIN_DIR non contiene i binari. Non conta per la coverage: la coverage
 * dei singoli componenti è garantita dai test unitari, deterministici.
 */
#[Group('blast')]
#[CoversNothing]
final class BlastPipelineTest extends DatabaseTestCase
{
    private const string FIXTURES = __DIR__ . '/../../../fixtures/blast-demo/';

    private static ?TemporaryDirectory $databases = null;

    private TemporaryDirectory $var;

    private BlastBinaryLocator $locator;

    private SearchParametersNormalizer $parametersNormalizer;

    #[Override]
    public static function tearDownAfterClass(): void
    {
        self::$databases?->remove();
        self::$databases = null;
        parent::tearDownAfterClass();
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $binDir = new EffectivePrimitiveTypeIdentifierService()->getStringValueFromArray('BLAST_BIN_DIR', $_SERVER, trim: true);

        if ($binDir === '' || !is_executable($binDir . '/blastn')) {
            self::markTestSkipped('BLAST+ binaries not available in BLAST_BIN_DIR.');
        }

        $this->locator = new BlastBinaryLocator($binDir);
        $this->parametersNormalizer = new SearchParametersNormalizer(new EffectivePrimitiveTypeIdentifierService());
        $this->var = new TemporaryDirectory();
        $this->buildDemoDatabases();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->var->remove();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{BlastProgram, bool}>
     */
    public static function searches(): iterable
    {
        foreach (BlastProgram::cases() as $program) {
            yield $program->value . ' basic' => [$program, false];
            yield $program->value . ' advanced defaults' => [$program, true];
        }
    }

    #[DataProvider('searches')]
    public function testEveryProgramAcceptsTheGeneratedCommand(BlastProgram $program, bool $advanced): void
    {
        $query = $program->queryMolType() === MolType::Nucleotide ? $this->nucleotideQuery() : $this->proteinQuery();
        $job = $this->job($program, $query, $advanced ? SearchParameters::defaultsFor($program) : null);

        $summary = $this->search($job, [$this->demoDatabase($program->databaseMolType())]);

        self::assertSame(JobStatus::Finished, $job->getStatus());
        self::assertSame(1, $summary->queries);
        self::assertGreaterThan(0, $summary->hits, 'the demo query has known homologues in the demo database');
    }

    public function testBlastnFindsTheSourceSectionOfTheQuery(): void
    {
        $job = $this->job(BlastProgram::Blastn, $this->nucleotideQuery(), null);
        $this->search($job, [$this->demoDatabase(MolType::Nucleotide)]);

        $best = $this->bestHit($job);
        self::assertSame('gi|1786181|gb|AE000111|ECAE000111', $best->getSubjectName());
        self::assertSame('AE000111', $best->getSubjectAccession());
        self::assertEqualsWithDelta(100.0, $best->getIdentityPercentage() ?? 0.0, 1e-9);
        self::assertSame(350, $best->getHsps()[0]->getAlignLength());
    }

    public function testBlastpFindsTheQueryProteinItself(): void
    {
        $job = $this->job(BlastProgram::Blastp, $this->proteinQuery(), null);
        $this->search($job, [$this->demoDatabase(MolType::Protein)]);

        $best = $this->bestHit($job);
        self::assertSame('gi|6693798|gb|AAF24986.1|AF116242_1', $best->getSubjectName());
        self::assertSame('AAF24986.1', $best->getSubjectAccession(), 'versioned, as BLAST subject acc.ver');
        self::assertEqualsWithDelta(100.0, $best->getIdentityPercentage() ?? 0.0, 1e-9);
    }

    public function testEntireSubjectSequenceIsExtractedWithBlastdbcmd(): void
    {
        $database = $this->demoDatabase(MolType::Nucleotide);
        $this->entityManager->persist($database);
        $this->entityManager->flush();

        $job = new BlastJob(Uuid::v7(), BlastProgram::Blastn, SearchType::Basic, [], [['id' => (int) $database->getId(), 'code' => 'test_na_db', 'title' => 'test_na_db']], $this->nucleotideQuery()->toFasta(), EntityFactory::now());
        $this->entityManager->persist($job);
        $this->entityManager->flush();
        $this->search($job, [$database]);

        $extractor = new SequenceExtractor(
            $this->locator,
            new BlastProcessExecutor(new SymfonyProcessRunner(), 60.0),
            new BlastDatabaseStorage('/unused'),
            self::getContainer()->get(BlastDatabaseRepository::class),
            new JobWorkspace($this->var->path, new Filesystem()),
            new NullLogger(),
        );
        $fasta = implode('', iterator_to_array($extractor->extract($job, [$this->bestHit($job)], DownloadSequenceType::Entire), false));

        self::assertStringStartsWith('>AE000111 Escherichia coli', $fasta);
        $lines = explode("\n", rtrim($fasta));
        self::assertSame(10596, \strlen(implode('', \array_slice($lines, 1))), 'the whole 10596 bp section');
        self::assertSame(80, \strlen($lines[1]));
    }

    public function testUploadedSubjectIsSearchedWithoutRegisteredDatabases(): void
    {
        $subject = (string) file_get_contents(self::FIXTURES . 'test_na_db.fa');
        $job = $this->job(BlastProgram::Blastn, $this->nucleotideQuery(), null, $subject);

        $this->search($job, []);

        $best = $this->bestHit($job);
        self::assertSame('gi|1786181|gb|AE000111|ECAE000111', $best->getSubjectName(), 'name taken from Hit_def, uploads have no seqids');
        self::assertSame('AE000111', $best->getSubjectAccession());
    }

    /**
     * @param list<BlastDatabase> $databases
     */
    private function search(BlastJob $job, array $databases): ImportSummary
    {
        $runner = new BlastRunner(
            $this->locator,
            new BlastCommandBuilder($this->locator),
            $this->parametersNormalizer,
            new BlastProcessExecutor(new SymfonyProcessRunner(), 120.0),
            new JobWorkspace($this->var->path, new Filesystem()),
            new BlastDatabaseStorage('/srv/blastdb'),
        );

        $job->start(EntityFactory::now());
        $output = $runner->run($job, $databases);
        $summary = new BlastResultImporter($this->entityManager)->import($job, new BlastXmlParser()->parse($output));
        $job->finish(EntityFactory::now(), $summary->queries, $summary->hits);
        $this->entityManager->flush();

        return $summary;
    }

    private function job(BlastProgram $program, FastaDocument $query, ?SearchParameters $parameters, ?string $subjectFasta = null): BlastJob
    {
        $job = new BlastJob(
            Uuid::v7(),
            $program,
            $parameters instanceof SearchParameters ? SearchType::Advanced : SearchType::Basic,
            $parameters instanceof SearchParameters ? $this->parametersNormalizer->normalize($parameters) : [],
            [],
            $query->toFasta(),
            EntityFactory::now(),
            $subjectFasta,
        );
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $job;
    }

    private function bestHit(BlastJob $job): BlastHit
    {
        $hit = $this->entityManager->getRepository(BlastHit::class)->findOneBy(['job' => $job, 'rank' => 1]);
        self::assertInstanceOf(BlastHit::class, $hit);

        return $hit;
    }

    /**
     * I primi 350 bp della prima sezione di E. coli (AE000111).
     */
    private function nucleotideQuery(): FastaDocument
    {
        $first = $this->fixture('test_na_db.fa', MolType::Nucleotide)->records[0];

        return new FastaDocument([new FastaRecord('q1 E. coli section 1', substr($first->sequence, 0, 350))]);
    }

    /**
     * Il cotrasportatore KCC3 (AAF24986.1), terza proteina del database demo.
     */
    private function proteinQuery(): FastaDocument
    {
        return new FastaDocument([$this->fixture('test_aa_db.fa', MolType::Protein)->records[2]]);
    }

    private function fixture(string $file, MolType $molType): FastaDocument
    {
        return new FastaNormalizer(1_000_000)->normalize((string) file_get_contents(self::FIXTURES . $file), $molType);
    }

    private function demoDatabase(MolType $molType): BlastDatabase
    {
        $code = $molType === MolType::Nucleotide ? 'test_na_db' : 'test_aa_db';
        $database = new BlastDatabase($code, $code, $molType, $this->demoDatabasePath($code), EntityFactory::now());
        $database->enableAllCompatiblePrograms();

        return $database;
    }

    private function demoDatabasePath(string $code): string
    {
        self::assertInstanceOf(TemporaryDirectory::class, self::$databases);

        return self::$databases->path . '/' . $code;
    }

    private function buildDemoDatabases(): void
    {
        if (self::$databases instanceof TemporaryDirectory) {
            return;
        }

        self::$databases = new TemporaryDirectory();

        foreach (['test_na_db' => 'nucl', 'test_aa_db' => 'prot'] as $code => $type) {
            $result = new SymfonyProcessRunner()->run([
                $this->locator->tool(BlastTool::MakeBlastDb),
                '-in', self::FIXTURES . $code . '.fa',
                '-dbtype', $type,
                '-parse_seqids',
                '-out', self::$databases->path . '/' . $code,
            ], self::$databases->path, 60.0, BlastProcessExecutor::ENVIRONMENT);

            self::assertSame(0, $result->exitCode, $result->errorOutput);
        }
    }
}
