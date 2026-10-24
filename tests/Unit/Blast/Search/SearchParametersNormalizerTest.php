<?php

declare(strict_types=1);

namespace App\Tests\Unit\Blast\Search;

use App\Blast\Exception\InvalidSearchParametersException;
use App\Blast\Search\GapCostPresets;
use App\Blast\Search\GapCosts;
use App\Blast\Search\GeneticCode;
use App\Blast\Search\NucleotideScoring;
use App\Blast\Search\SearchParameters;
use App\Blast\Search\SearchParametersNormalizer;
use App\Enum\BlastProgram;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;

#[CoversClass(SearchParametersNormalizer::class)]
#[UsesClass(SearchParameters::class)]
#[UsesClass(GapCosts::class)]
#[UsesClass(GapCostPresets::class)]
#[UsesClass(InvalidSearchParametersException::class)]
#[UsesClass(BlastProgram::class)]
final class SearchParametersNormalizerTest extends TestCase
{
    private SearchParametersNormalizer $normalizer;

    #[Override]
    protected function setUp(): void
    {
        $this->normalizer = new SearchParametersNormalizer(new EffectivePrimitiveTypeIdentifierService());
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
    public function testDefaultsSurviveTheJsonSnapshot(BlastProgram $program): void
    {
        $parameters = SearchParameters::defaultsFor($program);
        $snapshot = json_decode(json_encode($this->normalizer->normalize($parameters), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($snapshot);

        /** @var array<string, mixed> $snapshot */
        $restored = $this->normalizer->denormalize($snapshot);

        self::assertSame($this->normalizer->normalize($parameters), $this->normalizer->normalize($restored));
    }

    public function testSnapshotLayout(): void
    {
        $snapshot = $this->normalizer->normalize(new SearchParameters(
            expect: 0.5,
            wordSize: 15,
            maxTargetSequences: 10,
            nucleotideScoring: NucleotideScoring::Reward1Penalty1,
            gapCosts: new GapCosts(3, 2),
            lowercaseMasking: true,
            queryGeneticCode: GeneticCode::Bacterial,
        ));

        self::assertSame([
            'expect' => 0.5,
            'wordSize' => 15,
            'maxTargetSequences' => 10,
            'nucleotideScoring' => '1,-1',
            'matrix' => null,
            'gapExistence' => 3,
            'gapExtension' => 2,
            'lowComplexityFilter' => true,
            'softMasking' => false,
            'lowercaseMasking' => true,
            'ungapped' => false,
            'queryGeneticCode' => 11,
            'databaseGeneticCode' => null,
        ], $snapshot);
    }

    public function testUnknownMatrixIsRejected(): void
    {
        $this->expectException(InvalidSearchParametersException::class);

        $this->normalizer->denormalize(['expect' => 10, 'wordSize' => 3, 'maxTargetSequences' => 50, 'matrix' => 'BLOSUM999']);
    }

    public function testUnknownGeneticCodeIsRejected(): void
    {
        $this->expectException(InvalidSearchParametersException::class);

        $this->normalizer->denormalize(['expect' => 10, 'wordSize' => 3, 'maxTargetSequences' => 50, 'queryGeneticCode' => 7]);
    }
}
