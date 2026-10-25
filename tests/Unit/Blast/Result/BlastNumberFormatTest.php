<?php

declare(strict_types=1);

namespace App\Tests\Unit\Blast\Result;

use App\Service\Blast\Result\BlastNumberFormat;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Valori presi dall'output di BLAST+ 2.17.0 (le regole sono verificate anche dai test di parità).
 */
#[CoversClass(BlastNumberFormat::class)]
final class BlastNumberFormatTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function eValues(): iterable
    {
        // [valore XML, pairwise, tabulare]
        yield 'zero' => ['0', '0.0', '0.0'];
        yield 'below 1e-180' => ['1e-200', '0.0', '0.0'];
        yield 'very small' => ['2.31812e-165', '2e-165', '2.32e-165'];
        yield 'small' => ['3.07929e-06', '3e-06', '3.08e-06'];
        yield 'below 0.1' => ['0.0523', '0.052', '0.052'];
        yield 'below 1' => ['0.5371', '0.54', '0.54'];
        yield 'below 10' => ['2.88403', '2.9', '2.9'];
        yield 'large' => ['25.3', '25', '25'];
    }

    #[DataProvider('eValues')]
    public function testEValues(string $xml, string $pairwise, string $tabular): void
    {
        self::assertSame($pairwise, BlastNumberFormat::eValue($xml));
        self::assertSame($tabular, BlastNumberFormat::tabularEValue($xml));
    }

    /**
     * @return iterable<string, array{float, string}>
     */
    public static function bitScores(): iterable
    {
        yield 'large' => [19109.4, '19109'];
        yield 'hundreds, truncated' => [632.965, '632'];
        yield 'one decimal' => [43.7246, '43.7'];
        yield 'just below 99.9' => [99.9, '99.9'];
    }

    #[DataProvider('bitScores')]
    public function testBitScores(float $bitScore, string $expected): void
    {
        self::assertSame($expected, BlastNumberFormat::bitScore($bitScore));
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function percentages(): iterable
    {
        yield 'complete' => [350, 350, 100];
        yield 'never rounded up to 100' => [232, 233, 99];
        yield 'zero' => [0, 233, 0];
        yield 'never rounded down to 0' => [1, 1000, 1];
        yield 'rounded' => [32, 35, 91];
        yield 'empty alignment' => [0, 0, 100];
    }

    #[DataProvider('percentages')]
    public function testPercentagesFollowGetPercentMatch(int $part, int $total, int $expected): void
    {
        self::assertSame($expected, BlastNumberFormat::percentage($part, $total));
    }

    public function testPercentageRuleIsAvailableInSql(): void
    {
        self::assertSame(
            'CASE WHEN s.identity = s.align_length THEN 100 WHEN s.identity = 0 OR s.align_length = 0 THEN 0 ELSE GREATEST(1, LEAST(99, ROUND(s.identity * 100 / s.align_length))) END',
            BlastNumberFormat::percentageSql('s.identity', 's.align_length'),
        );
    }
}
