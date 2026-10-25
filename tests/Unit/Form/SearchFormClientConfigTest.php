<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Form\SearchFormClientConfig;
use App\Service\Blast\Search\GapCostPresets;
use App\Service\Blast\Search\GapCosts;
use App\Service\Blast\Search\SearchParameters;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SearchFormClientConfig::class)]
#[UsesClass(GapCostPresets::class)]
#[UsesClass(GapCosts::class)]
#[UsesClass(SearchParameters::class)]
final class SearchFormClientConfigTest extends TestCase
{
    public function testProgramRulesMirrorTheLegacyForm(): void
    {
        $programs = SearchFormClientConfig::programs();

        self::assertSame(['blastn', 'blastp', 'blastx', 'tblastn', 'tblastx'], array_keys($programs));
        self::assertSame([7, 11, 15], $programs['blastn']['wordSizes']);
        self::assertSame(['wordSize' => 11, 'nucleotideScoring' => '2,-3', 'matrix' => null, 'gapCosts' => '5,2', 'lowComplexityFilter' => true, 'softMasking' => true], $programs['blastn']['defaults']);
        self::assertFalse($programs['blastp']['defaults']['lowComplexityFilter']);
        self::assertNull($programs['tblastx']['defaults']['gapCosts']);
        self::assertSame(['matrix', 'queryGeneticCode', 'databaseGeneticCode'], $programs['tblastx']['fields']);
    }

    public function testGapCostPresetsAndChoices(): void
    {
        $presets = SearchFormClientConfig::gapCosts();

        self::assertSame(['12,8', '6,5', '5,5', '4,5', '3,5'], $presets['4,-5']);
        self::assertSame('15,2', $presets['BLOSUM45'][0]);
        self::assertSame('11,1', SearchFormClientConfig::gapCostChoices()['Existence: 11, Extension: 1']);
        self::assertCount(\count(array_unique(array_merge(...array_values($presets)))), SearchFormClientConfig::gapCostChoices());
    }
}
