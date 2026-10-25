<?php

declare(strict_types=1);

namespace App\Service\Blast\Search;

use App\Service\Blast\Exception\InvalidSearchParametersException;

/**
 * Costi di apertura ed estensione di un gap (`-gapopen` / `-gapextend`).
 */
final readonly class GapCosts
{
    /**
     * @throws InvalidSearchParametersException se un costo è negativo
     */
    public function __construct(
        public int $existence,
        public int $extension,
    ) {
        if ($existence < 0 || $extension < 0) {
            throw InvalidSearchParametersException::outOfRange('gapCosts', 'zero or positive');
        }
    }

    public function equals(self $other): bool
    {
        return $this->existence === $other->existence && $this->extension === $other->extension;
    }

    /**
     * Etichetta come nella UI legacy ("Existence: 11, Extension: 1").
     */
    public function label(): string
    {
        return \sprintf('Existence: %d, Extension: %d', $this->existence, $this->extension);
    }
}
