<?php

declare(strict_types=1);

namespace App\Form;

use App\Blast\Search\GapCostPresets;
use App\Blast\Search\GapCosts;
use App\Blast\Search\NucleotideScoring;
use App\Blast\Search\ScoringMatrix;
use App\Blast\Search\SearchParameters;
use App\Enum\BlastProgram;

/**
 * Regole del form passate al controller Stimulus `search-form` (ex changeParameters e
 * changeGapcostsOptions di viroblast.js). Generate da PHP: un'unica fonte di verità, il
 * JavaScript non duplica tabelle né default.
 *
 * @phpstan-type ProgramConfig array{wordSizes: list<int>, defaults: array<string, bool|int|string|null>, fields: list<string>}
 */
final class SearchFormClientConfig
{
    /** Campi avanzati che esistono solo per alcuni programmi. */
    private const array FIELDS = [
        'nucleotideScoring' => [BlastProgram::Blastn],
        'matrix' => [BlastProgram::Blastp, BlastProgram::Blastx, BlastProgram::Tblastn, BlastProgram::Tblastx],
        'gapCosts' => [BlastProgram::Blastn, BlastProgram::Blastp, BlastProgram::Blastx, BlastProgram::Tblastn],
        'ungapped' => [BlastProgram::Blastn, BlastProgram::Blastp, BlastProgram::Blastx, BlastProgram::Tblastn],
        'queryGeneticCode' => [BlastProgram::Blastx, BlastProgram::Tblastx],
        'databaseGeneticCode' => [BlastProgram::Tblastn, BlastProgram::Tblastx],
    ];

    /**
     * @return array<string, ProgramConfig>
     */
    public static function programs(): array
    {
        $config = [];

        foreach (BlastProgram::cases() as $program) {
            $defaults = SearchParameters::defaultsFor($program);
            $config[$program->value] = [
                'wordSizes' => SearchFormData::WORD_SIZES[$program === BlastProgram::Blastn ? $program->value : 'other'],
                'defaults' => [
                    'wordSize' => $defaults->wordSize,
                    'nucleotideScoring' => $defaults->nucleotideScoring?->value,
                    'matrix' => $defaults->matrix?->value,
                    'gapCosts' => $defaults->gapCosts instanceof GapCosts ? self::gapCostValue($defaults->gapCosts) : null,
                    'lowComplexityFilter' => $defaults->lowComplexityFilter,
                    'softMasking' => $defaults->softMasking,
                ],
                'fields' => array_keys(array_filter(self::FIELDS, static fn (array $programs): bool => \in_array($program, $programs, true))),
            ];
        }

        return $config;
    }

    /**
     * Gap cost ammessi per ogni schema di punteggio, nell'ordine legacy (il primo è il default).
     *
     * @return array<string, list<string>>
     */
    public static function gapCosts(): array
    {
        $presets = [];

        foreach ([...NucleotideScoring::cases(), ...ScoringMatrix::cases()] as $scoring) {
            $presets[$scoring->value] = array_map(self::gapCostValue(...), GapCostPresets::for($scoring));
        }

        return $presets;
    }

    /**
     * Tutti i valori di gap cost esistenti: le scelte ammesse dal campo lato server.
     *
     * @return array<string, string> etichetta => valore
     */
    public static function gapCostChoices(): array
    {
        $choices = [];

        foreach (self::gapCosts() as $values) {
            foreach ($values as $value) {
                [$existence, $extension] = explode(',', $value);
                $choices[new GapCosts((int) $existence, (int) $extension)->label()] = $value;
            }
        }

        return $choices;
    }

    public static function gapCostValue(GapCosts $gapCosts): string
    {
        return $gapCosts->existence . ',' . $gapCosts->extension;
    }
}
