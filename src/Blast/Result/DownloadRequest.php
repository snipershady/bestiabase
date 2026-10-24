<?php

declare(strict_types=1);

namespace App\Blast\Result;

use App\Enum\DownloadSequenceType;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Richiesta di download delle sequenze dalla tabella risultati (ex form di blastresult.php:
 * seqtype entire|mapping e target[] selezionati).
 */
final readonly class DownloadRequest
{
    public const int MAX_HITS = 500;

    /**
     * @param list<int> $hits id degli hit selezionati (devono appartenere al job)
     */
    public function __construct(
        public DownloadSequenceType $type = DownloadSequenceType::Entire,
        #[Assert\Count(min: 1, max: self::MAX_HITS, minMessage: 'Select at least one hit to download.', maxMessage: 'Select at most {{ limit }} hits per download.')]
        #[Assert\All([new Assert\Positive()])]
        public array $hits = [],
    ) {
    }
}
