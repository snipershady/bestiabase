<?php

declare(strict_types=1);

namespace App\Tests\Integration\Blast;

use App\Blast\BlastProcessExecutor;
use App\Blast\BlastResultImporter;
use App\Blast\Fasta\FastaDocument;
use App\Blast\Fasta\FastaNormalizer;
use App\Blast\Process\SymfonyProcessRunner;
use App\Blast\Result\TabularExporter;
use App\Blast\Xml\BlastXmlParser;
use App\Entity\BlastJob;
use App\Enum\BlastProgram;
use App\Enum\MolType;
use App\Enum\SearchType;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\DemoBlastDatabases;
use App\Tests\Support\EntityFactory;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;

/**
 * Parità con BLAST+: l'export tabulare ricostruito dal database deve coincidere riga per riga
 * con `-outfmt 6` (colonne di default) eseguito sulla stessa ricerca, per tutti i programmi.
 */
#[Group('blast')]
#[CoversNothing]
final class TabularParityTest extends DatabaseTestCase
{
    private static ?DemoBlastDatabases $databases = null;

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
        self::$databases ??= DemoBlastDatabases::build();

        if (!self::$databases instanceof DemoBlastDatabases) {
            self::markTestSkipped('BLAST+ binaries not available in BLAST_BIN_DIR.');
        }
    }

    /**
     * @return iterable<string, array{BlastProgram}>
     */
    public static function programs(): iterable
    {
        foreach (BlastProgram::cases() as $program) {
            yield $program->value => [$program];
        }
    }

    #[DataProvider('programs')]
    public function testExportMatchesTheBlastTabularOutput(BlastProgram $program): void
    {
        $databases = self::$databases ?? self::fail('no databases');
        $work = $databases->directory->path . '/tabular-' . $program->value;
        $query = $this->query($program->queryMolType());
        new Filesystem()->dumpFile($work . '/query.fa', $query->toFasta());

        $base = [
            $databases->locator->program($program),
            ...(\in_array($program, [BlastProgram::Blastn, BlastProgram::Blastp], true) ? ['-task', $program->value] : []),
            '-db', $databases->path($program->databaseMolType()),
            '-query', $work . '/query.fa',
            '-max_target_seqs', '500',
        ];
        $executor = new BlastProcessExecutor(new SymfonyProcessRunner(), 120.0);
        $executor->execute($program->value, [...$base, '-outfmt', '6', '-out', $work . '/blast.tsv'], $work);
        $executor->execute($program->value, [...$base, '-outfmt', '5', '-out', $work . '/output.xml'], $work);

        $job = new BlastJob(Uuid::v7(), $program, SearchType::Basic, [], [], $query->toFasta(), EntityFactory::now());
        $this->entityManager->persist($job);
        $this->entityManager->flush();
        new BlastResultImporter($this->entityManager)->import($job, new BlastXmlParser()->parse($work . '/output.xml'));

        $exported = '';
        new TabularExporter($this->entityManager->getConnection(), new EffectivePrimitiveTypeIdentifierService())
            ->export($job, false, static function (string $text) use (&$exported): void {
                $exported .= $text;
            });

        $expected = explode("\n", rtrim((string) file_get_contents($work . '/blast.tsv')));
        self::assertNotSame([''], $expected, 'BLAST found at least one alignment');
        self::assertSame($expected, explode("\n", rtrim($exported)));
    }

    private function query(MolType $molType): FastaDocument
    {
        $normalizer = new FastaNormalizer(1_000_000);
        $file = $molType === MolType::Protein ? 'test_aa_db.fa' : 'test_na_db.fa';
        $records = $normalizer->normalize((string) file_get_contents(DemoBlastDatabases::FASTA . $file), $molType)->records;

        // Proteine: due query; nucleotidi: tutte e 6 le sezioni di E. coli.
        return $molType === MolType::Protein ? new FastaDocument([$records[0], $records[2]]) : new FastaDocument($records);
    }
}
