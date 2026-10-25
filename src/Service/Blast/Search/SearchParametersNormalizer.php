<?php

declare(strict_types=1);

namespace App\Service\Blast\Search;

use App\Service\Blast\Exception\InvalidSearchParametersException;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierServiceInterface;

/**
 * Conversione da/verso lo snapshot JSON salvato in `blast_job.parameters`.
 *
 * @phpstan-type Snapshot array<string, bool|float|int|string|null>
 */
final readonly class SearchParametersNormalizer
{
    public function __construct(
        private EffectivePrimitiveTypeIdentifierServiceInterface $input,
    ) {
    }

    /**
     * @return Snapshot
     */
    public function normalize(SearchParameters $parameters): array
    {
        return [
            'expect' => $parameters->expect,
            'wordSize' => $parameters->wordSize,
            'maxTargetSequences' => $parameters->maxTargetSequences,
            'nucleotideScoring' => $parameters->nucleotideScoring?->value,
            'matrix' => $parameters->matrix?->value,
            'gapExistence' => $parameters->gapCosts?->existence,
            'gapExtension' => $parameters->gapCosts?->extension,
            'lowComplexityFilter' => $parameters->lowComplexityFilter,
            'softMasking' => $parameters->softMasking,
            'lowercaseMasking' => $parameters->lowercaseMasking,
            'ungapped' => $parameters->ungapped,
            'queryGeneticCode' => $parameters->queryGeneticCode?->value,
            'databaseGeneticCode' => $parameters->databaseGeneticCode?->value,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     *
     * @throws InvalidSearchParametersException se lo snapshot contiene valori non validi
     */
    public function denormalize(array $snapshot): SearchParameters
    {
        $gapExistence = $this->input->getTypedValueFromArray('gapExistence', $snapshot);
        $gapExtension = $this->input->getTypedValueFromArray('gapExtension', $snapshot);

        return new SearchParameters(
            expect: $this->input->getFloatValueFromArray('expect', $snapshot),
            wordSize: $this->input->getIntValueFromArray('wordSize', $snapshot),
            maxTargetSequences: $this->input->getIntValueFromArray('maxTargetSequences', $snapshot),
            nucleotideScoring: $this->enumOrNull(NucleotideScoring::class, $this->input->getStringValueFromArray('nucleotideScoring', $snapshot, forceString: true)),
            matrix: $this->enumOrNull(ScoringMatrix::class, $this->input->getStringValueFromArray('matrix', $snapshot, forceString: true)),
            gapCosts: \is_int($gapExistence) && \is_int($gapExtension) ? new GapCosts($gapExistence, $gapExtension) : null,
            lowComplexityFilter: $this->input->getBoolValueFromArray('lowComplexityFilter', $snapshot),
            softMasking: $this->input->getBoolValueFromArray('softMasking', $snapshot),
            lowercaseMasking: $this->input->getBoolValueFromArray('lowercaseMasking', $snapshot),
            ungapped: $this->input->getBoolValueFromArray('ungapped', $snapshot),
            queryGeneticCode: $this->geneticCodeOrNull($this->input->getIntValueFromArray('queryGeneticCode', $snapshot)),
            databaseGeneticCode: $this->geneticCodeOrNull($this->input->getIntValueFromArray('databaseGeneticCode', $snapshot)),
        );
    }

    /**
     * @template T of NucleotideScoring|ScoringMatrix
     *
     * @param class-string<T> $enum
     *
     * @return T|null
     */
    private function enumOrNull(string $enum, string $value): NucleotideScoring|ScoringMatrix|null
    {
        if ($value === '') {
            return null;
        }

        return $enum::tryFrom($value) ?? throw InvalidSearchParametersException::outOfRange($enum, 'a known value, got "' . $value . '"');
    }

    private function geneticCodeOrNull(int $code): ?GeneticCode
    {
        if ($code === 0) {
            return null;
        }

        return GeneticCode::tryFrom($code) ?? throw InvalidSearchParametersException::outOfRange('geneticCode', 'a known NCBI genetic code, got ' . $code);
    }
}
