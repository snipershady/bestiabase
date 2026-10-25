<?php

declare(strict_types=1);

namespace App\Service\Blast\Fasta;

final readonly class FastaDocument
{
    public const int LINE_WIDTH = 80;

    /**
     * @param non-empty-list<FastaRecord> $records
     */
    public function __construct(
        public array $records,
    ) {
    }

    /**
     * @return non-empty-string FASTA con sequenze a capo ogni 80 caratteri e newline finale
     */
    public function toFasta(): string
    {
        $fasta = '';

        foreach ($this->records as $record) {
            $fasta .= '>' . $record->definition . "\n" . chunk_split($record->sequence, self::LINE_WIDTH, "\n");
        }

        return $fasta;
    }
}
