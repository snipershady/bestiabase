<?php

declare(strict_types=1);

namespace App\Blast\Search;

/**
 * Coppie match/mismatch di blastn (`-reward` / `-penalty`) offerte dalla UI.
 */
enum NucleotideScoring: string
{
    case Reward1Penalty1 = '1,-1';
    case Reward1Penalty2 = '1,-2';
    case Reward1Penalty3 = '1,-3';
    case Reward1Penalty4 = '1,-4';
    case Reward2Penalty3 = '2,-3';
    case Reward4Penalty5 = '4,-5';

    public function reward(): int
    {
        return (int) explode(',', $this->value)[0];
    }

    public function penalty(): int
    {
        return (int) explode(',', $this->value)[1];
    }
}
