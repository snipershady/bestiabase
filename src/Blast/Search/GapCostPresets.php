<?php

declare(strict_types=1);

namespace App\Blast\Search;

/**
 * Combinazioni di gap cost ammesse per ogni schema di punteggio, nell'ordine della UI legacy
 * (`viroblast.js`, changeGapcostsOptions): il primo elemento è il default.
 */
final class GapCostPresets
{
    private const array NUCLEOTIDE = [
        '1,-1' => [[5, 2], [3, 2], [2, 2], [1, 2], [0, 2], [4, 1], [3, 1], [2, 1]],
        '1,-2' => [[5, 2], [2, 2], [1, 2], [0, 2], [3, 1], [2, 1], [1, 1]],
        '1,-3' => [[5, 2], [2, 2], [1, 2], [0, 2], [2, 1], [1, 1]],
        '1,-4' => [[5, 2], [1, 2], [0, 2], [2, 1], [1, 1]],
        '2,-3' => [[5, 2], [4, 4], [2, 4], [0, 4], [3, 3], [6, 2], [4, 2], [2, 2]],
        '4,-5' => [[12, 8], [6, 5], [5, 5], [4, 5], [3, 5]],
    ];

    private const array MATRIX = [
        'PAM30' => [[9, 1], [7, 2], [6, 2], [5, 2], [10, 1], [8, 1]],
        'PAM70' => [[10, 1], [8, 2], [7, 2], [6, 2], [11, 1], [9, 1]],
        'BLOSUM80' => [[10, 1], [8, 2], [7, 2], [6, 2], [11, 1], [9, 1]],
        'BLOSUM62' => [[11, 1], [9, 2], [8, 2], [7, 2], [12, 1], [10, 1]],
        'BLOSUM45' => [[15, 2], [13, 3], [12, 3], [11, 3], [10, 3], [14, 2], [13, 2], [12, 2], [19, 1], [18, 1], [17, 1], [16, 1]],
    ];

    /**
     * @return non-empty-list<GapCosts>
     */
    public static function for(NucleotideScoring|ScoringMatrix $scoring): array
    {
        $pairs = $scoring instanceof NucleotideScoring ? self::NUCLEOTIDE[$scoring->value] : self::MATRIX[$scoring->value];

        return array_map(static fn (array $pair): GapCosts => new GapCosts($pair[0], $pair[1]), $pairs);
    }

    public static function defaultFor(NucleotideScoring|ScoringMatrix $scoring): GapCosts
    {
        return self::for($scoring)[0];
    }

    public static function isAllowed(NucleotideScoring|ScoringMatrix $scoring, GapCosts $gapCosts): bool
    {
        return array_any(self::for($scoring), static fn ($allowed) => $allowed->equals($gapCosts));
    }
}
