<?php

declare(strict_types=1);

namespace App\Tests\Unit\Blast\Search;

use App\Blast\Exception\InvalidSearchParametersException;
use App\Blast\Search\GapCostPresets;
use App\Blast\Search\GapCosts;
use App\Blast\Search\GeneticCode;
use App\Blast\Search\NucleotideScoring;
use App\Blast\Search\ScoringMatrix;
use App\Blast\Search\SearchParameters;
use App\Enum\BlastProgram;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SearchParameters::class)]
#[CoversClass(GapCosts::class)]
#[CoversClass(GapCostPresets::class)]
#[CoversClass(NucleotideScoring::class)]
#[CoversClass(GeneticCode::class)]
#[CoversClass(InvalidSearchParametersException::class)]
#[UsesClass(BlastProgram::class)]
final class SearchParametersTest extends TestCase
{
    public function testBlastnDefaultsOfTheLegacyForm(): void
    {
        $defaults = SearchParameters::defaultsFor(BlastProgram::Blastn);

        self::assertEqualsWithDelta(10.0, $defaults->expect, \PHP_FLOAT_EPSILON);
        self::assertSame(11, $defaults->wordSize);
        self::assertSame(50, $defaults->maxTargetSequences);
        self::assertSame(NucleotideScoring::Reward2Penalty3, $defaults->nucleotideScoring);
        self::assertNull($defaults->matrix);
        self::assertSame('Existence: 5, Extension: 2', $defaults->gapCosts?->label());
        self::assertTrue($defaults->lowComplexityFilter);
        self::assertTrue($defaults->softMasking);
        self::assertFalse($defaults->lowercaseMasking);
        self::assertFalse($defaults->ungapped);
        self::assertNull($defaults->queryGeneticCode);
        self::assertNull($defaults->databaseGeneticCode);
    }

    public function testBlastpDefaultsDisableTheFilter(): void
    {
        $defaults = SearchParameters::defaultsFor(BlastProgram::Blastp);

        self::assertSame(3, $defaults->wordSize);
        self::assertSame(ScoringMatrix::Blosum62, $defaults->matrix);
        self::assertSame('Existence: 11, Extension: 1', $defaults->gapCosts?->label());
        self::assertFalse($defaults->lowComplexityFilter);
        self::assertFalse($defaults->softMasking);
    }

    public function testTblastxDefaultsHaveNoGapCostsAndBothGeneticCodes(): void
    {
        $defaults = SearchParameters::defaultsFor(BlastProgram::Tblastx);

        self::assertNull($defaults->gapCosts);
        self::assertSame(GeneticCode::Standard, $defaults->queryGeneticCode);
        self::assertSame(GeneticCode::Standard, $defaults->databaseGeneticCode);
    }

    /**
     * @return iterable<string, array{float, int, int, string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'zero expect' => [0.0, 11, 50, 'expect'];
        yield 'infinite expect' => [\INF, 11, 50, 'expect'];
        yield 'word size 1' => [10.0, 1, 50, 'wordSize'];
        yield 'no targets' => [10.0, 11, 0, 'maxTargetSequences'];
    }

    #[DataProvider('invalidValues')]
    public function testOutOfRangeValuesAreRejected(float $expect, int $wordSize, int $maxTargets, string $option): void
    {
        $this->expectException(InvalidSearchParametersException::class);
        $this->expectExceptionMessageIsOrContains(\sprintf('Option "%s"', $option));

        new SearchParameters($expect, $wordSize, $maxTargets);
    }

    public function testNegativeGapCostIsRejected(): void
    {
        $this->expectException(InvalidSearchParametersException::class);

        new GapCosts(-1, 1);
    }

    public function testGapCostEquality(): void
    {
        self::assertTrue(new GapCosts(11, 1)->equals(new GapCosts(11, 1)));
        self::assertFalse(new GapCosts(11, 1)->equals(new GapCosts(11, 2)));
        self::assertFalse(new GapCosts(11, 1)->equals(new GapCosts(10, 1)));
    }

    public function testGapCostPresetsFollowTheLegacyOrder(): void
    {
        $labels = static fn (array $costs): array => array_map(static fn (GapCosts $gap): string => $gap->existence . ',' . $gap->extension, $costs);

        self::assertSame(['12,8', '6,5', '5,5', '4,5', '3,5'], $labels(GapCostPresets::for(NucleotideScoring::Reward4Penalty5)));
        self::assertSame(['9,1', '7,2', '6,2', '5,2', '10,1', '8,1'], $labels(GapCostPresets::for(ScoringMatrix::Pam30)));
        self::assertCount(12, GapCostPresets::for(ScoringMatrix::Blosum45));
        self::assertSame($labels(GapCostPresets::for(ScoringMatrix::Pam70)), $labels(GapCostPresets::for(ScoringMatrix::Blosum80)));
        self::assertSame('Existence: 15, Extension: 2', GapCostPresets::defaultFor(ScoringMatrix::Blosum45)->label());
    }

    public function testGapCostsAreAllowedOnlyForTheirScoring(): void
    {
        self::assertTrue(GapCostPresets::isAllowed(NucleotideScoring::Reward1Penalty1, new GapCosts(4, 1)));
        self::assertFalse(GapCostPresets::isAllowed(NucleotideScoring::Reward1Penalty4, new GapCosts(4, 1)));
    }

    /**
     * @return iterable<string, array{NucleotideScoring, int, int}>
     */
    public static function scorings(): iterable
    {
        yield '1,-1' => [NucleotideScoring::Reward1Penalty1, 1, -1];
        yield '1,-3' => [NucleotideScoring::Reward1Penalty3, 1, -3];
        yield '4,-5' => [NucleotideScoring::Reward4Penalty5, 4, -5];
    }

    #[DataProvider('scorings')]
    public function testNucleotideScoringSplitsRewardAndPenalty(NucleotideScoring $scoring, int $reward, int $penalty): void
    {
        self::assertSame($reward, $scoring->reward());
        self::assertSame($penalty, $scoring->penalty());
    }

    public function testEveryGeneticCodeHasALabel(): void
    {
        $labels = array_map(static fn (GeneticCode $code): string => $code->label(), GeneticCode::cases());

        self::assertCount(13, array_unique($labels));
        self::assertSame('Bacterial', GeneticCode::from(11)->label());
    }
}
