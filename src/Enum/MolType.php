<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Tipo di molecola di una sequenza o di un database BLAST (`makeblastdb -dbtype`).
 */
enum MolType: string
{
    case Nucleotide = 'nucl';
    case Protein = 'prot';
}
