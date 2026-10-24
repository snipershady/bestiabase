<?php

declare(strict_types=1);

namespace App\Blast\Result;

/**
 * Riga della tabella riepilogativa (Query | Subject | Score | Identities (Query length) |
 * Percentage | Expect), con i valori del miglior HSP come in blast.pl (L372–392).
 */
final readonly class HitRow
{
    public function __construct(
        public int $hitId,
        public int $queryPosition,
        public string $queryName,
        public int $queryLength,
        public int $rank,
        public string $subjectName,
        public ?string $subjectAccession,
        public ?string $subjectTitle,
        public float $bitScore,
        public int $identity,
        public int $alignLength,
        public string $eValue,
    ) {
    }

    /**
     * "X/Y (Lq)": identità e lunghezza dell'allineamento, poi lunghezza della query.
     */
    public function identities(): string
    {
        return \sprintf('%d/%d (%d)', $this->identity, $this->alignLength, $this->queryLength);
    }

    /**
     * Percentuale intera, come la riga "Identities = X/Y (Z%)" di BLAST.
     */
    public function percentage(): int
    {
        return BlastNumberFormat::percentage($this->identity, $this->alignLength);
    }
}
