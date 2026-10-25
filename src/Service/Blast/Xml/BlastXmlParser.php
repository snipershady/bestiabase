<?php

declare(strict_types=1);

namespace App\Service\Blast\Xml;

use App\Service\Blast\Exception\BlastOutputParseException;
use DOMDocument;
use DOMElement;
use Generator;
use XMLReader;

/**
 * Legge l'output `-outfmt 5` (XML "BlastOutput", DTD NCBI_BlastOutput) in streaming:
 * in memoria c'è al più una query con i suoi hit, qualunque sia la dimensione del file.
 * Sostituisce il post-processing HTML a regex di blast.pl (L166–400).
 */
final readonly class BlastXmlParser
{
    /**
     * @throws BlastOutputParseException se il file non è leggibile o non è XML BLAST valido
     *
     * @return Generator<int, ParsedIteration>
     */
    public function parse(string $path): Generator
    {
        if (!is_file($path) || !is_readable($path)) {
            throw BlastOutputParseException::unreadable($path);
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        // LIBXML_NONET: il DOCTYPE punta al DTD sul sito NCBI, che non va mai scaricato.
        $reader = XMLReader::fromUri($path, null, \LIBXML_NONET);

        try {
            yield from $this->iterations($reader, $path);
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @return Generator<int, ParsedIteration>
     */
    private function iterations(XMLReader $reader, string $path): Generator
    {
        $number = 0;
        $definition = '';
        $length = 0;
        $hits = [];

        $moved = $this->read($reader, $path);

        while ($moved) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'Iteration') {
                yield new ParsedIteration($number, $definition, $length, $hits);
                $hits = [];
            } elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'Hit') {
                $hits[] = $this->hit($this->expand($reader, $path), $path);
                // Il sottoalbero è già stato letto: si passa direttamente al fratello successivo.
                $moved = $this->skipSubtree($reader, $path);

                continue;
            } elseif ($reader->nodeType === XMLReader::ELEMENT) {
                match ($reader->localName) {
                    'Iteration_iter-num' => $number = $this->integer($reader->readString(), 'Iteration_iter-num', $path),
                    'Iteration_query-def' => $definition = $reader->readString(),
                    'Iteration_query-len' => $length = $this->integer($reader->readString(), 'Iteration_query-len', $path),
                    default => null,
                };
            }

            $moved = $this->read($reader, $path);
        }
    }

    private function read(XMLReader $reader, string $path): bool
    {
        return $this->checked($reader->read(), $path);
    }

    private function skipSubtree(XMLReader $reader, string $path): bool
    {
        return $this->checked($reader->next(), $path);
    }

    private function checked(bool $moved, string $path): bool
    {
        $error = libxml_get_last_error();

        if ($error !== false) {
            throw BlastOutputParseException::malformed($path, trim($error->message));
        }

        return $moved;
    }

    private function expand(XMLReader $reader, string $path): DOMElement
    {
        $warning = null;
        // Su XML troncato expand() emette un warning oltre a restituire false: lo si trasforma
        // in eccezione di dominio invece di lasciarlo arrivare all'error handler globale.
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        }, \E_WARNING);

        try {
            $node = $reader->expand(new DOMDocument());
        } finally {
            restore_error_handler();
        }

        if (!$node instanceof DOMElement) {
            throw BlastOutputParseException::malformed($path, \sprintf('cannot expand <%s>%s', $reader->localName, $warning === null ? '' : ': ' . $warning));
        }

        return $node;
    }

    private function hit(DOMElement $hit, string $path): ParsedHit
    {
        $hsps = [];

        foreach ($hit->getElementsByTagName('Hsp') as $hsp) {
            $hsps[] = $this->hsp($hsp, $path);
        }

        return new ParsedHit(
            number: $this->integerChild($hit, 'Hit_num', $path),
            id: $this->text($hit, 'Hit_id', $path),
            definition: $this->text($hit, 'Hit_def', $path),
            accession: $this->text($hit, 'Hit_accession', $path),
            length: $this->integerChild($hit, 'Hit_len', $path),
            hsps: $hsps,
        );
    }

    private function hsp(DOMElement $hsp, string $path): ParsedHsp
    {
        return new ParsedHsp(
            number: $this->integerChild($hsp, 'Hsp_num', $path),
            bitScore: $this->floatChild($hsp, 'Hsp_bit-score', $path),
            score: $this->floatChild($hsp, 'Hsp_score', $path),
            eValue: $this->number($this->text($hsp, 'Hsp_evalue', $path), 'Hsp_evalue', $path),
            queryFrom: $this->integerChild($hsp, 'Hsp_query-from', $path),
            queryTo: $this->integerChild($hsp, 'Hsp_query-to', $path),
            hitFrom: $this->integerChild($hsp, 'Hsp_hit-from', $path),
            hitTo: $this->integerChild($hsp, 'Hsp_hit-to', $path),
            queryFrame: $this->integerChild($hsp, 'Hsp_query-frame', $path),
            hitFrame: $this->integerChild($hsp, 'Hsp_hit-frame', $path),
            identity: $this->integerChild($hsp, 'Hsp_identity', $path),
            positive: $this->integerChild($hsp, 'Hsp_positive', $path),
            gaps: $this->integerChild($hsp, 'Hsp_gaps', $path),
            alignLength: $this->integerChild($hsp, 'Hsp_align-len', $path),
            querySequence: $this->text($hsp, 'Hsp_qseq', $path),
            hitSequence: $this->text($hsp, 'Hsp_hseq', $path),
            midline: $this->text($hsp, 'Hsp_midline', $path),
        );
    }

    private function text(DOMElement $parent, string $name, string $path): string
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $name === $child->localName) {
                return $child->textContent;
            }
        }

        throw BlastOutputParseException::malformed($path, \sprintf('<%s> has no <%s>', $parent->localName, $name));
    }

    private function integerChild(DOMElement $parent, string $name, string $path): int
    {
        return $this->integer($this->text($parent, $name, $path), $name, $path);
    }

    private function floatChild(DOMElement $parent, string $name, string $path): float
    {
        return (float) $this->number($this->text($parent, $name, $path), $name, $path);
    }

    private function integer(string $value, string $name, string $path): int
    {
        if (preg_match('/^-?\d+$/', trim($value)) !== 1) {
            throw BlastOutputParseException::malformed($path, \sprintf('<%s> is not an integer: "%s"', $name, $value));
        }

        return (int) trim($value);
    }

    private function number(string $value, string $name, string $path): string
    {
        $value = trim($value);

        if (!is_numeric($value)) {
            throw BlastOutputParseException::malformed($path, \sprintf('<%s> is not a number: "%s"', $name, $value));
        }

        return $value;
    }
}
