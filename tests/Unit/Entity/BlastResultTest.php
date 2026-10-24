<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\BlastHit;
use App\Entity\BlastHsp;
use App\Entity\BlastJob;
use App\Entity\BlastQuery;
use App\Tests\Support\EntityFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BlastQuery::class)]
#[CoversClass(BlastHit::class)]
#[CoversClass(BlastHsp::class)]
#[UsesClass(BlastJob::class)]
final class BlastResultTest extends TestCase
{
    public function testQueryExposesItsData(): void
    {
        $job = EntityFactory::job();
        $query = new BlastQuery($job, 2, 'q2', 120, 'q2 second query');

        self::assertNull($query->getId());
        self::assertSame($job, $query->getJob());
        self::assertSame(2, $query->getPosition());
        self::assertSame('q2', $query->getName());
        self::assertSame(120, $query->getLength());
        self::assertSame('q2 second query', $query->getDefinition());
    }

    public function testHitWithoutHspsHasNoBestValues(): void
    {
        $query = EntityFactory::query(EntityFactory::job());
        $hit = EntityFactory::hit($query, 3);

        self::assertNull($hit->getId());
        self::assertSame($query->getJob(), $hit->getJob());
        self::assertSame($query, $hit->getQuery());
        self::assertSame(3, $hit->getRank());
        self::assertSame('gi|1786181|gb|AE000111|ECAE000111', $hit->getSubjectName());
        self::assertSame(10596, $hit->getSubjectLength());
        self::assertSame('AE000111', $hit->getSubjectAccession());
        self::assertSame('Escherichia coli section 1', $hit->getSubjectTitle());
        self::assertSame(0, $hit->getHspCount());
        self::assertSame([], $hit->getHsps());
        self::assertNull($hit->getBestBitScore());
        self::assertNull($hit->getBestEValue());
        self::assertNull($hit->getIdentityPercentage());
    }

    public function testFirstHspIsTheBestAndAggregatesAccumulate(): void
    {
        $hit = EntityFactory::hit(EntityFactory::query(EntityFactory::job()));
        $hsp = EntityFactory::hsp($hit, 1, 632.0, 350, 350);
        $second = EntityFactory::hsp($hit, 2, 43.7, 32, 35);

        $hit->addHsp($hsp);
        $hit->addHsp($second);

        self::assertSame(2, $hit->getHspCount());
        self::assertSame([$hsp, $second], $hit->getHsps());
        self::assertEqualsWithDelta(632.0, $hit->getBestBitScore(), \PHP_FLOAT_EPSILON);
        self::assertSame('0.0', $hit->getBestEValue());
        self::assertEqualsWithDelta(100.0, $hit->getIdentityPercentage(), \PHP_FLOAT_EPSILON);
    }

    public function testHspOfAnotherHitIsRejected(): void
    {
        $query = EntityFactory::query(EntityFactory::job());
        $hit = EntityFactory::hit($query, 1);
        $otherHit = EntityFactory::hit($query, 2);

        $this->expectException(InvalidArgumentException::class);

        $hit->addHsp(EntityFactory::hsp($otherHit));
    }

    public function testHspExposesAlignmentData(): void
    {
        $hit = EntityFactory::hit(EntityFactory::query(EntityFactory::job()));
        $hsp = new BlastHsp(
            hit: $hit,
            rank: 2,
            bitScore: 43.7,
            rawScore: 46.0,
            eValue: '3.08e-06',
            identity: 32,
            positive: 33,
            gaps: 2,
            alignLength: 35,
            queryFrom: 274,
            queryTo: 307,
            hitFrom: 307,
            hitTo: 274,
            queryFrame: 1,
            hitFrame: -1,
            querySequence: 'AC-GT',
            midline: '|| ||',
            hitSequence: 'ACAGT',
        );

        self::assertNull($hsp->getId());
        self::assertSame($hit, $hsp->getHit());
        self::assertSame(2, $hsp->getRank());
        self::assertEqualsWithDelta(43.7, $hsp->getBitScore(), \PHP_FLOAT_EPSILON);
        self::assertEqualsWithDelta(46.0, $hsp->getRawScore(), \PHP_FLOAT_EPSILON);
        self::assertSame('3.08e-06', $hsp->getEValue());
        self::assertSame(32, $hsp->getIdentity());
        self::assertSame(33, $hsp->getPositive());
        self::assertSame(2, $hsp->getGaps());
        self::assertSame(35, $hsp->getAlignLength());
        self::assertEqualsWithDelta(91.428, $hsp->getIdentityPercentage(), 0.001);
        self::assertSame(274, $hsp->getQueryFrom());
        self::assertSame(307, $hsp->getQueryTo());
        self::assertSame(307, $hsp->getHitFrom());
        self::assertSame(274, $hsp->getHitTo());
        self::assertSame(1, $hsp->getQueryFrame());
        self::assertSame(-1, $hsp->getHitFrame());
        self::assertSame('AC-GT', $hsp->getQuerySequence());
        self::assertSame('|| ||', $hsp->getMidline());
        self::assertSame('ACAGT', $hsp->getHitSequence());
        self::assertSame(1, $hsp->getGapOpens());
    }

    public function testSubjectDefinitionDoesNotRepeatTheName(): void
    {
        $query = EntityFactory::query(EntityFactory::job());

        self::assertSame('AE000111 Escherichia coli', new BlastHit($query->getJob(), $query, 1, 'AE000111', 10, 'AE000111', 'Escherichia coli')->getSubjectDefinition());
        self::assertSame('patient_12 clone 4', new BlastHit($query->getJob(), $query, 1, 'patient_12', 10, null, 'patient_12 clone 4')->getSubjectDefinition(), 'uploads: the name is the start of the title');
        self::assertSame('lonely', new BlastHit($query->getJob(), $query, 1, 'lonely', 10)->getSubjectDefinition());
    }
}
