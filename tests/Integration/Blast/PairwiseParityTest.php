<?php

declare(strict_types=1);

namespace App\Tests\Integration\Blast;

use App\Blast\BlastProcessExecutor;
use App\Blast\Fasta\FastaDocument;
use App\Blast\Fasta\FastaNormalizer;
use App\Blast\Fasta\FastaRecord;
use App\Blast\Process\SymfonyProcessRunner;
use App\Blast\Result\PairwiseRenderer;
use App\Blast\Xml\BlastXmlParser;
use App\Entity\BlastHit;
use App\Entity\BlastHsp;
use App\Enum\BlastProgram;
use App\Enum\MolType;
use App\Tests\Support\DemoBlastDatabases;
use App\Tests\Support\EntityFactory;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Parità con BLAST+: la stessa ricerca eseguita in pairwise (-outfmt 0) e in XML (-outfmt 5);
 * il PairwiseRenderer, partendo dall'XML, deve produrre le stesse intestazioni e le stesse righe
 * Query/Sbjct con le stesse coordinate, HSP per HSP, per tutti i programmi.
 */
#[Group('blast')]
#[CoversNothing]
final class PairwiseParityTest extends TestCase
{
    private static ?DemoBlastDatabases $databases = null;

    #[Override]
    public static function tearDownAfterClass(): void
    {
        self::$databases?->remove();
        self::$databases = null;
    }

    #[Override]
    protected function setUp(): void
    {
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
    public function testRenderedAlignmentsMatchTheBlastPairwiseOutput(BlastProgram $program): void
    {
        $databases = self::$databases ?? self::fail('no databases');
        $work = $databases->directory->path . '/' . $program->value;
        $filesystem = new Filesystem();
        $filesystem->dumpFile($work . '/query.fa', $this->query($program->queryMolType())->toFasta());

        $base = [
            $databases->locator->program($program),
            ...(\in_array($program, [BlastProgram::Blastn, BlastProgram::Blastp], true) ? ['-task', $program->value] : []),
            '-db', $databases->path($program->databaseMolType()),
            '-query', $work . '/query.fa',
        ];
        $executor = new BlastProcessExecutor(new SymfonyProcessRunner(), 120.0);
        $executor->execute($program->value, [...$base, '-outfmt', '0', '-num_descriptions', '500', '-num_alignments', '500', '-out', $work . '/pairwise.txt'], $work);
        $executor->execute($program->value, [...$base, '-outfmt', '5', '-max_target_seqs', '500', '-out', $work . '/output.xml'], $work);

        $expected = $this->pairwiseHsps((string) file_get_contents($work . '/pairwise.txt'), $program);
        $actual = $this->renderedHsps($work . '/output.xml', $program);

        self::assertGreaterThan(2, \count($expected), 'the demo queries produce several HSPs');
        self::assertCount(\count($expected), $actual);

        foreach ($expected as $index => $hsp) {
            $rendered = $actual[$index]['score'];

            if ($rendered === '') {
                self::fail('empty score line for HSP #' . $index);
            }

            // BLAST può aggiungere ", Method: …" dopo l'e-value: si confronta il prefisso.
            self::assertStringStartsWith($rendered, $hsp['score'], 'score of HSP #' . $index);
            self::assertSame($hsp['lines'], $actual[$index]['lines'], 'header and alignment of HSP #' . $index);
            self::assertSame($hsp['rows'], $actual[$index]['rows'], 'Query/Sbjct rows of HSP #' . $index);
        }
    }

    /**
     * HSP del testo di BLAST: riga Score, righe Identities/Strand/Frame, righe Query/Sbjct.
     *
     * @return list<array{score: string, lines: list<string>, rows: list<string>}>
     */
    private function pairwiseHsps(string $text, BlastProgram $program): array
    {
        $hsps = [];
        $score = null;
        $lines = [];
        $rows = [];

        foreach (explode("\n", $text) as $line) {
            if (str_starts_with($line, ' Score = ')) {
                if ($score !== null) {
                    $hsps[] = ['score' => $score, 'lines' => $lines, 'rows' => $rows];
                }

                // "Expect(2)": e-value da sum statistics su più HSP; il numero non è nell'XML.
                $score = preg_replace('/Expect\(\d+\)/', 'Expect', substr($line, 9)) ?? '';
                $lines = [];
                $rows = [];
            } elseif ($score !== null && preg_match('/^ (Identities|Strand|Frame)\s*=/', $line) === 1) {
                $lines[] = preg_replace('/\s*=\s*/', ' = ', trim($line), 1) ?? '';
            } elseif ($score !== null && preg_match('/^(Query|Sbjct)\s+(\d+)\s+(\S+)\s+(\d+)$/', $line, $match) === 1) {
                // Regioni mascherate: il testo di BLAST le mostra in minuscolo; l'XML in maiuscolo
                // (DUST, blastn) oppure come X (SEG, programmi proteici e tradotti).
                $sequence = $program === BlastProgram::Blastn ? strtoupper($match[3]) : preg_replace('/[a-z]/', 'X', $match[3]);
                $rows[] = $match[1] . ' ' . $match[2] . ' ' . $sequence . ' ' . $match[4];
            }
        }

        if ($score !== null) {
            $hsps[] = ['score' => $score, 'lines' => $lines, 'rows' => $rows];
        }

        return $hsps;
    }

    /**
     * @return list<array{score: string, lines: list<string>, rows: list<string>}>
     */
    private function renderedHsps(string $xml, BlastProgram $program): array
    {
        $renderer = new PairwiseRenderer();
        $rendered = [];
        $query = EntityFactory::query(EntityFactory::job($program));

        foreach (new BlastXmlParser()->parse($xml) as $iteration) {
            foreach ($iteration->hits as $parsedHit) {
                $hit = new BlastHit($query->getJob(), $query, $parsedHit->number, 'subject', $parsedHit->length);

                foreach ($parsedHit->hsps as $parsed) {
                    $alignment = $renderer->render(new BlastHsp($hit, $parsed->number, $parsed->bitScore, $parsed->score, $parsed->eValue, $parsed->identity, $parsed->positive, $parsed->gaps, $parsed->alignLength, $parsed->queryFrom, $parsed->queryTo, $parsed->hitFrom, $parsed->hitTo, $parsed->queryFrame, $parsed->hitFrame, $parsed->querySequence, $parsed->midline, $parsed->hitSequence), $program);

                    $lines = ['Identities = ' . $alignment->identities . ($alignment->positives === null ? '' : ', Positives = ' . $alignment->positives) . ', Gaps = ' . $alignment->gaps];

                    if ($alignment->strand !== null) {
                        $lines[] = 'Strand = ' . $alignment->strand;
                    }

                    if ($alignment->frame !== null) {
                        $lines[] = 'Frame = ' . $alignment->frame;
                    }

                    $rows = [];

                    foreach ($alignment->blocks as $block) {
                        $rows[] = 'Query ' . $block->query->start . ' ' . $block->query->sequence . ' ' . $block->query->end;
                        $rows[] = 'Sbjct ' . $block->subject->start . ' ' . $block->subject->sequence . ' ' . $block->subject->end;
                    }

                    $rendered[] = ['score' => $alignment->score, 'lines' => $lines, 'rows' => $rows];
                }
            }
        }

        return $rendered;
    }

    /**
     * Query con allineamenti su entrambi i filamenti e HSP multipli.
     */
    private function query(MolType $molType): FastaDocument
    {
        $normalizer = new FastaNormalizer(1_000_000);

        if ($molType === MolType::Protein) {
            $proteins = $normalizer->normalize((string) file_get_contents(DemoBlastDatabases::FASTA . 'test_aa_db.fa'), MolType::Protein)->records;

            return new FastaDocument([$proteins[0], $proteins[2], $proteins[40]]);
        }

        $sections = $normalizer->normalize((string) file_get_contents(DemoBlastDatabases::FASTA . 'test_na_db.fa'), MolType::Nucleotide)->records;
        $forward = substr($sections[0]->sequence, 0, 600);
        $reverse = strrev(strtr(substr($sections[3]->sequence, 2000, 700), 'ACGTacgt', 'TGCAtgca'));

        return new FastaDocument([
            new FastaRecord('forward', $this->nonEmpty($forward)),
            new FastaRecord('reverse', $this->nonEmpty($reverse)),
            new FastaRecord('mixed', $this->nonEmpty(substr($sections[1]->sequence, 100, 300) . $reverse)),
        ]);
    }

    /**
     * @return non-empty-string
     */
    private function nonEmpty(string $sequence): string
    {
        return $sequence === '' ? self::fail('empty demo sequence') : $sequence;
    }
}
