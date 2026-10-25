<?php

declare(strict_types=1);

namespace App\Tests\Unit\Blast\Result;

use App\Entity\BlastHit;
use App\Entity\BlastHsp;
use App\Entity\BlastJob;
use App\Entity\BlastQuery;
use App\Enum\BlastProgram;
use App\Service\Blast\Result\AlignmentBlock;
use App\Service\Blast\Result\AlignmentCoordinates;
use App\Service\Blast\Result\AlignmentRow;
use App\Service\Blast\Result\BlastNumberFormat;
use App\Service\Blast\Result\HspAlignment;
use App\Service\Blast\Result\PairwiseRenderer;
use App\Tests\Support\EntityFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PairwiseRenderer::class)]
#[CoversClass(AlignmentCoordinates::class)]
#[CoversClass(AlignmentBlock::class)]
#[CoversClass(AlignmentRow::class)]
#[CoversClass(HspAlignment::class)]
#[UsesClass(BlastNumberFormat::class)]
#[UsesClass(BlastHit::class)]
#[UsesClass(BlastHsp::class)]
#[UsesClass(BlastJob::class)]
#[UsesClass(BlastQuery::class)]
final class PairwiseRendererTest extends TestCase
{
    public function testBlastnMinusStrandCountsDownOnTheSubject(): void
    {
        // Dall'output di BLAST: Query 274..307, Sbjct 307..274, Strand=Plus/Minus.
        $alignment = new PairwiseRenderer()->render($this->hsp(
            BlastProgram::Blastn,
            274,
            307,
            307,
            274,
            1,
            -1,
            'AAAAAAGCCCGCACCTGACA-GTGCGGGCTTTTTT',
            '||||||||||||| ||| || ||||||||||||||',
            'AAAAAAGCCCGCA-CTGTCAGGTGCGGGCTTTTTT',
            identity: 32,
            gaps: 2,
        ), BlastProgram::Blastn);

        self::assertSame('43.7 bits (47),  Expect = 3e-06', $alignment->score);
        self::assertSame('32/35 (91%)', $alignment->identities);
        self::assertNull($alignment->positives);
        self::assertSame('2/35 (6%)', $alignment->gaps);
        self::assertSame('Plus/Minus', $alignment->strand);
        self::assertNull($alignment->frame);
        self::assertSame([
            'Query  274  AAAAAAGCCCGCACCTGACA-GTGCGGGCTTTTTT  307',
            '            ||||||||||||| ||| || ||||||||||||||',
            'Sbjct  307  AAAAAAGCCCGCA-CTGTCAGGTGCGGGCTTTTTT  274',
            '',
        ], $alignment->lines());
    }

    public function testTranslatedSubjectOnANegativeFrameIsPrintedDescending(): void
    {
        // tblastn: nell'XML hit 7171..7236, BLAST stampa Sbjct 7236 → 7171 (Frame = -1).
        $alignment = new PairwiseRenderer()->render($this->hsp(
            BlastProgram::Tblastn,
            185,
            202,
            7171,
            7236,
            0,
            -1,
            'MGTFMGVYLP----CLQNIFGV',
            'MGT +G+  P    C+Q +FG+',
            'MGTSLGLATPLVTECMQWLFGI',
            identity: 9,
            gaps: 4,
        ), BlastProgram::Tblastn);

        self::assertSame('-1', $alignment->frame);
        self::assertSame('9/22 (41%)', $alignment->identities);
        self::assertSame('Query  185   MGTFMGVYLP----CLQNIFGV  202', $alignment->lines()[0]);
        self::assertSame('Sbjct  7236  MGTSLGLATPLVTECMQWLFGI  7171', $alignment->lines()[2]);
    }

    public function testTblastxShowsBothFramesAndLongAlignmentsAreSplitIn60Columns(): void
    {
        $sequence = str_repeat('ACDEFGHIKL', 7);
        $alignment = new PairwiseRenderer()->render($this->hsp(BlastProgram::Tblastx, 1, 210, 1, 210, 1, 2, $sequence, $sequence, $sequence, identity: 70, gaps: 0), BlastProgram::Tblastx);

        self::assertSame('+1/+2', $alignment->frame);
        self::assertSame('70/70 (100%)', $alignment->positives);
        self::assertCount(2, $alignment->blocks);
        self::assertSame([1, 180], [$alignment->blocks[0]->query->start, $alignment->blocks[0]->query->end]);
        self::assertSame([181, 210], [$alignment->blocks[1]->query->start, $alignment->blocks[1]->query->end]);
    }

    public function testBlastxQueryOnANegativeFrameAndAGapOnlyRow(): void
    {
        // 60 colonne di soli gap sulla query: BLAST ripete la posizione precedente come fine.
        $query = str_repeat('A', 60) . str_repeat('-', 60) . 'AAAA';
        $subject = str_repeat('A', 124);
        $alignment = new PairwiseRenderer()->render($this->hsp(BlastProgram::Blastx, 1, 192, 10, 133, -2, 0, $query, str_repeat(' ', 124), $subject, identity: 64, gaps: 60), BlastProgram::Blastx);

        self::assertSame('-2', $alignment->frame);
        self::assertSame([192, 13], [$alignment->blocks[0]->query->start, $alignment->blocks[0]->query->end]);
        self::assertSame([12, 13], [$alignment->blocks[1]->query->start, $alignment->blocks[1]->query->end]);
        self::assertSame([12, 1], [$alignment->blocks[2]->query->start, $alignment->blocks[2]->query->end]);
        self::assertSame([10, 69], [$alignment->blocks[0]->subject->start, $alignment->blocks[0]->subject->end]);
    }

    public function testBlastpHasPositivesButNeitherStrandNorFrame(): void
    {
        $alignment = new PairwiseRenderer()->render($this->hsp(BlastProgram::Blastp, 1, 3, 5, 7, 0, 0, 'MKV', 'MK+', 'MKI', identity: 2, gaps: 0), BlastProgram::Blastp);

        self::assertNull($alignment->strand);
        self::assertNull($alignment->frame);
        self::assertSame('3/3 (100%)', $alignment->positives);
    }

    private function hsp(BlastProgram $program, int $queryFrom, int $queryTo, int $hitFrom, int $hitTo, int $queryFrame, int $hitFrame, string $querySequence, string $midline, string $hitSequence, int $identity, int $gaps): BlastHsp
    {
        $query = EntityFactory::query(EntityFactory::job($program));
        $hit = new BlastHit($query->getJob(), $query, 1, 'subject', 10000);
        $length = \strlen($querySequence);

        return new BlastHsp($hit, 1, 43.7246, 47.0, '3.07929e-06', $identity, \strlen($querySequence) - $gaps, $gaps, max(1, $length), $queryFrom, $queryTo, $hitFrom, $hitTo, $queryFrame, $hitFrame, $querySequence, $midline, $hitSequence);
    }
}
