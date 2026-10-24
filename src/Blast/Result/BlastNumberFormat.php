<?php

declare(strict_types=1);

namespace App\Blast\Result;

/**
 * Formattazione dei numeri come nell'output testuale di BLAST+ (CAlignFormatUtil::GetScoreString):
 * l'XML contiene i valori a piena precisione, il pairwise li arrotonda così.
 */
final class BlastNumberFormat
{
    public static function eValue(string|float $eValue): string
    {
        $value = (float) $eValue;

        return match (true) {
            $value < 1.0E-180 => '0.0',
            $value < 0.0009 => self::scientific($value, 0),
            $value < 0.1 => \sprintf('%.3f', $value),
            $value < 1.0 => \sprintf('%.2f', $value),
            $value < 10.0 => \sprintf('%.1f', $value),
            default => \sprintf('%.0f', $value),
        };
    }

    public static function bitScore(float $bitScore): string
    {
        // Oltre 99.9 BLAST stampa l'intero ("19109"), anche sopra 9999 (verificato con 2.17.0).
        return match (true) {
            $bitScore > 99.9 => (string) (int) $bitScore,
            default => \sprintf('%.1f', $bitScore),
        };
    }

    /**
     * E-value nel formato tabulare (`-outfmt 6/7`): come il pairwise ma con due decimali nella
     * notazione esponenziale ("3.08e-06" e "2.32e-165", dove il pairwise scrive "3e-06" e "2e-165").
     */
    public static function tabularEValue(string|float $eValue): string
    {
        $value = (float) $eValue;

        return match (true) {
            $value < 1.0E-180 => '0.0',
            $value < 0.0009 => self::scientific($value, 2),
            default => self::eValue($value),
        };
    }

    /**
     * Percentuale intera come CAlignFormatUtil::GetPercentMatch (Identities, Positives, Gaps):
     * 100 solo se completa, 0 solo se nulla, altrimenti arrotondata ma compresa tra 1 e 99
     * (232/233 → 99, non 100).
     */
    public static function percentage(int $part, int $total): int
    {
        return match (true) {
            $part === $total => 100,
            $part === 0 || $total === 0 => 0,
            default => max(1, min(99, (int) round($part * 100 / $total))),
        };
    }

    /**
     * La stessa regola in SQL, per i filtri sul database.
     *
     * @param non-empty-string $part
     * @param non-empty-string $total
     */
    public static function percentageSql(string $part, string $total): string
    {
        return "CASE WHEN {$part} = {$total} THEN 100 WHEN {$part} = 0 OR {$total} = 0 THEN 0 "
            . "ELSE GREATEST(1, LEAST(99, ROUND({$part} * 100 / {$total}))) END";
    }

    /**
     * Notazione esponenziale con almeno due cifre di esponente, come printf in C ("3e-06");
     * sprintf di PHP scriverebbe "3e-6".
     */
    private static function scientific(float $value, int $decimals): string
    {
        [$mantissa, $exponent] = explode('e', \sprintf('%.' . $decimals . 'e', $value));
        $sign = str_starts_with($exponent, '-') ? '-' : '+';

        return $mantissa . 'e' . $sign . str_pad(ltrim($exponent, '+-'), 2, '0', \STR_PAD_LEFT);
    }
}
