<?php

declare(strict_types=1);

namespace App\Blast\Fasta;

use App\Blast\Exception\InvalidFastaException;
use App\Enum\MolType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Porting della normalizzazione di blastresult.php (L114–221), resa più severa:
 * - tutti i fine riga (CRLF, CR) diventano LF (il legacy convertiva solo i file con soli CR);
 * - con almeno un '>' si scarta tutto ciò che lo precede, come il legacy;
 * - senza '>' l'input è una sequenza nuda (accettata dalla textarea legacy) con header vuoto;
 * - cifre e spazi nelle sequenze vengono eliminati (BLAST+ li ignora comunque);
 * - i residui vengono validati per tipo di molecola.
 */
final readonly class FastaNormalizer
{
    private const string NUCLEOTIDE_RESIDUES = 'ACGTURYKMSWBDHVN-';

    private const string PROTEIN_RESIDUES = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ*-';

    public function __construct(
        #[Autowire('%env(int:BLAST_FASTA_MAX_BYTES)%')]
        private int $maxBytes,
    ) {
    }

    /**
     * @throws InvalidFastaException
     */
    public function normalize(string $input, MolType $molType): FastaDocument
    {
        if (\strlen($input) > $this->maxBytes) {
            throw InvalidFastaException::tooLarge($this->maxBytes);
        }

        $text = trim(str_replace(["\r\n", "\r"], "\n", $this->withoutByteOrderMark($input)));

        if ($text === '') {
            throw InvalidFastaException::empty();
        }

        $headerStart = strpos($text, '>');
        // Senza '>' l'intero testo è la sequenza di un record con header vuoto.
        $blocks = $headerStart === false ? ["\n" . $text] : explode("\n>", substr($text, $headerStart + 1));

        $records = [];

        foreach ($blocks as $index => $block) {
            $records[] = $this->record($block, $index + 1, $molType);
        }

        return new FastaDocument($records);
    }

    private function record(string $block, int $position, MolType $molType): FastaRecord
    {
        $lines = explode("\n", $block);
        $definition = trim(array_shift($lines) ?? '');
        $sequence = preg_replace('/[\s\d]+/', '', implode('', $lines)) ?? '';

        if ($sequence === '') {
            throw InvalidFastaException::emptySequence($position);
        }

        $allowed = $molType === MolType::Nucleotide ? self::NUCLEOTIDE_RESIDUES : self::PROTEIN_RESIDUES;
        $invalid = str_replace(str_split($allowed), '', strtoupper($sequence));

        if ($invalid !== '') {
            throw InvalidFastaException::invalidResidues($position, implode('', array_unique(str_split($invalid))));
        }

        return new FastaRecord($definition, $sequence);
    }

    private function withoutByteOrderMark(string $input): string
    {
        return str_starts_with($input, "\u{FEFF}") ? substr($input, 3) : $input;
    }
}
