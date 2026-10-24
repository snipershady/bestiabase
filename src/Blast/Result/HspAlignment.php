<?php

declare(strict_types=1);

namespace App\Blast\Result;

/**
 * Un HSP pronto per il template pairwise, con le righe di intestazione formattate come BLAST.
 */
final readonly class HspAlignment
{
    /**
     * @param list<AlignmentBlock> $blocks
     */
    public function __construct(
        public string $score,
        public string $identities,
        public ?string $positives,
        public string $gaps,
        public ?string $strand,
        public ?string $frame,
        public array $blocks,
    ) {
    }

    /**
     * Righe di testo dell'allineamento come in BLAST: "Query  <inizio> <sequenza>  <fine>", la
     * riga di match allineata sotto la sequenza, "Sbjct …" e una riga vuota tra i blocchi.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        // Le coordinate di inizio sono allineate sulla più lunga, come in BLAST.
        $width = 1;

        foreach ($this->blocks as $block) {
            $width = max($width, \strlen((string) $block->query->start), \strlen((string) $block->subject->start));
        }

        $lines = [];

        foreach ($this->blocks as $block) {
            $lines[] = 'Query  ' . str_pad((string) $block->query->start, $width) . '  ' . $block->query->sequence . '  ' . $block->query->end;
            $lines[] = str_repeat(' ', 7 + $width + 2) . $block->midline;
            $lines[] = 'Sbjct  ' . str_pad((string) $block->subject->start, $width) . '  ' . $block->subject->sequence . '  ' . $block->subject->end;
            $lines[] = '';
        }

        return $lines;
    }
}
