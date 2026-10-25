<?php

declare(strict_types=1);

namespace App\Service\Blast\Search;

/**
 * Matrici di sostituzione offerte dalla UI (programmi diversi da blastn).
 */
enum ScoringMatrix: string
{
    case Pam30 = 'PAM30';
    case Pam70 = 'PAM70';
    case Blosum80 = 'BLOSUM80';
    case Blosum62 = 'BLOSUM62';
    case Blosum45 = 'BLOSUM45';
}
