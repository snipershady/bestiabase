<?php

declare(strict_types=1);

namespace App\Blast\Result;

use App\Enum\BlastProgram;

/**
 * Orientamento delle coordinate di un lato dell'allineamento, come le stampa BLAST.
 *
 * Sul lato tradotto (query di blastx/tblastx, soggetto di tblastn/tblastx) ogni residuo vale 3
 * nucleotidi e la direzione dipende dal segno del frame: nell'XML from/to sono crescenti anche per
 * i frame negativi, mentre BLAST li stampa decrescenti. Sul lato non tradotto la direzione è
 * quella di from → to (decrescente per il filamento minus di blastn).
 */
final readonly class AlignmentCoordinates
{
    private function __construct(
        public int $start,
        public int $end,
        public int $direction,
        public int $step,
    ) {
    }

    public static function query(BlastProgram $program, int $from, int $to, int $frame): self
    {
        return self::side($from, $to, $frame, \in_array($program, [BlastProgram::Blastx, BlastProgram::Tblastx], true));
    }

    public static function subject(BlastProgram $program, int $from, int $to, int $frame): self
    {
        return self::side($from, $to, $frame, \in_array($program, [BlastProgram::Tblastn, BlastProgram::Tblastx], true));
    }

    private static function side(int $from, int $to, int $frame, bool $translated): self
    {
        $low = min($from, $to);
        $high = max($from, $to);

        if ($translated) {
            return $frame < 0 ? new self($high, $low, -1, 3) : new self($low, $high, 1, 3);
        }

        return $to >= $from ? new self($from, $to, 1, 1) : new self($from, $to, -1, 1);
    }
}
