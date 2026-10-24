<?php

declare(strict_types=1);

namespace App\Blast\Result;

enum HitSort: string
{
    /** Ordine di BLAST (e-value crescente): quello del legacy. */
    case Rank = 'rank';
    case Score = 'score';
    case Identity = 'identity';
}
