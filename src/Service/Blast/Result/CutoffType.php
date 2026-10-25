<?php

declare(strict_types=1);

namespace App\Service\Blast\Result;

/**
 * Criterio del "Re-parse" legacy (blastresult.php, cutoffType pct|blst).
 */
enum CutoffType: string
{
    case None = 'none';

    /** Percentuale di identità del miglior HSP, intera come la mostra BLAST. */
    case Identity = 'identity';

    /** Bit score del miglior HSP. */
    case Score = 'score';
}
