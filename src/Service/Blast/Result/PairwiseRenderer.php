<?php

declare(strict_types=1);

namespace App\Service\Blast\Result;

use App\Entity\BlastHsp;
use App\Enum\BlastProgram;

/**
 * Ricostruisce dai dati di un HSP l'allineamento pairwise di BLAST (`-outfmt 0`), che il legacy
 * otteneva dall'HTML di BLAST e paginava in blastN.html.
 * Le coordinate seguono {@see AlignmentCoordinates}.
 *
 * Differenze note dal testo di BLAST (l'XML non contiene l'informazione): nessun "Expect(N)" per
 * gli e-value da sum statistics, nessuna riga "Method:", regioni mascherate non in minuscolo (con
 * SEG compaiono come X, come nell'XML).
 * Il resto (intestazioni, percentuali, strand/frame, righe e coordinate) è verificato identico da
 * PairwiseParityTest per tutti i programmi.
 */
final class PairwiseRenderer
{
    public const int WIDTH = 60;

    public function render(BlastHsp $hsp, BlastProgram $program): HspAlignment
    {
        $length = $hsp->getAlignLength();

        return new HspAlignment(
            score: \sprintf('%s bits (%d),  Expect = %s', BlastNumberFormat::bitScore($hsp->getBitScore()), (int) $hsp->getRawScore(), BlastNumberFormat::eValue($hsp->getEValue())),
            identities: $this->ratio($hsp->getIdentity(), $length),
            positives: $program === BlastProgram::Blastn ? null : $this->ratio($hsp->getPositive(), $length),
            gaps: $this->ratio($hsp->getGaps(), $length),
            strand: $program === BlastProgram::Blastn ? $this->strand($hsp->getQueryFrame()) . '/' . $this->strand($hsp->getHitFrame()) : null,
            frame: $this->frame($program, $hsp->getQueryFrame(), $hsp->getHitFrame()),
            blocks: $this->blocks(
                $hsp,
                AlignmentCoordinates::query($program, $hsp->getQueryFrom(), $hsp->getQueryTo(), $hsp->getQueryFrame()),
                AlignmentCoordinates::subject($program, $hsp->getHitFrom(), $hsp->getHitTo(), $hsp->getHitFrame()),
            ),
        );
    }

    /**
     * @return list<AlignmentBlock>
     */
    private function blocks(BlastHsp $hsp, AlignmentCoordinates $query, AlignmentCoordinates $subject): array
    {
        $blocks = [];
        $queryPosition = $query->start;
        $subjectPosition = $subject->start;

        foreach (str_split($hsp->getQuerySequence(), self::WIDTH) as $index => $querySegment) {
            $offset = $index * self::WIDTH;
            $subjectSegment = substr($hsp->getHitSequence(), $offset, self::WIDTH);

            $queryRow = $this->row($queryPosition, $querySegment, $query->direction, $query->step);
            $subjectRow = $this->row($subjectPosition, $subjectSegment, $subject->direction, $subject->step);
            $queryPosition = $queryRow->end + $query->direction;
            $subjectPosition = $subjectRow->end + $subject->direction;

            $blocks[] = new AlignmentBlock($queryRow, substr($hsp->getMidline(), $offset, self::WIDTH), $subjectRow);
        }

        return $blocks;
    }

    private function row(int $start, string $segment, int $direction, int $step): AlignmentRow
    {
        $residues = \strlen($segment) - substr_count($segment, '-');

        // Riga di soli gap: BLAST stampa la posizione precedente come fine.
        $end = $residues === 0 ? $start - $direction : $start + $direction * ($residues * $step - 1);

        return new AlignmentRow($start, $segment, $end);
    }

    private function ratio(int $part, int $total): string
    {
        return \sprintf('%d/%d (%d%%)', $part, $total, BlastNumberFormat::percentage($part, $total));
    }

    private function strand(int $frame): string
    {
        return $frame < 0 ? 'Minus' : 'Plus';
    }

    private function frame(BlastProgram $program, int $queryFrame, int $hitFrame): ?string
    {
        return match ($program) {
            BlastProgram::Blastx => \sprintf('%+d', $queryFrame),
            BlastProgram::Tblastn => \sprintf('%+d', $hitFrame),
            BlastProgram::Tblastx => \sprintf('%+d/%+d', $queryFrame, $hitFrame),
            BlastProgram::Blastn, BlastProgram::Blastp => null,
        };
    }
}
