<?php

declare(strict_types=1);

namespace App\Blast;

use App\Blast\Xml\ParsedHit;

/**
 * Nomi brevi di query e soggetti come li mostrava il legacy (blast.pl L178, L229–273),
 * ricavati dai valori strutturati dell'XML invece che dall'HTML.
 */
final class SequenceNames
{
    /** Hit_id assegnato da BLAST+ ai database costruiti senza -parse_seqids. */
    private const string ORDINAL_ID_PREFIX = 'gnl|BL_ORD_ID|';

    private const string LOCAL_ID_PREFIX = 'lcl|';

    /** Defline assegnato da BLAST+ a una query senza header. */
    private const string MISSING_DEFINITION = 'No definition line';

    /** Riconoscimento delle accessioni, unificato dai due rami di blast.pl. */
    private const array ACCESSION_PATTERNS = [
        '/^gi\|\d+\|\w+\|([A-Z]{1,5}\d+(?:\.\d+)?)/',
        '/^gi\|\d+\|\w+\|([A-Z]{2}_\d+(?:\.\d+)?)/',
        '/^gnl\|\w+\|([A-Z]{1,5}\d+(?:\.\d+)?)/',
        '/^([A-Z]{1,5}\d+(?:\.\d+)?)$/',
        '/^([A-Z]{2}_\d+(?:\.\d+)?)$/',
    ];

    /**
     * @return non-empty-string primo token del defline, oppure "Query<N>" se manca
     */
    public static function query(string $definition, int $position): string
    {
        $definition = trim($definition);

        if ($definition === '' || $definition === self::MISSING_DEFINITION) {
            return 'Query' . $position;
        }

        return self::firstToken($definition);
    }

    /**
     * @return non-empty-string
     */
    public static function subject(ParsedHit $hit): string
    {
        $source = self::isOrdinalId($hit->id) ? $hit->definition : $hit->id;
        $name = self::firstToken($source);

        if (str_starts_with($name, self::LOCAL_ID_PREFIX)) {
            $local = substr($name, \strlen(self::LOCAL_ID_PREFIX));

            if ($local !== '') {
                return $local;
            }
        }

        return $name;
    }

    /**
     * Accessione del soggetto, con la versione quando il seqid la contiene ("AAF24984.1"): è quella
     * che BLAST scrive come "subject acc.ver" e che identifica la sequenza esatta su NCBI.
     * `Hit_accession` (senza versione) è solo il ripiego per seqid non riconosciuti.
     */
    public static function subjectAccession(ParsedHit $hit): ?string
    {
        $name = self::subject($hit);

        foreach (self::ACCESSION_PATTERNS as $pattern) {
            if (preg_match($pattern, $name, $match) === 1) {
                return $match[1];
            }
        }

        return !self::isOrdinalId($hit->id) && preg_match('/^[A-Z]/', $hit->accession) === 1 ? $hit->accession : null;
    }

    /**
     * Primo token di un defline, come il legacy: termina al primo tra ',', ';', spazio e '+'.
     *
     * @return non-empty-string
     */
    public static function firstToken(string $text): string
    {
        $token = preg_split('/[,;\s+]/', trim($text), 2);
        $first = $token === false ? '' : $token[0];

        return $first === '' ? 'Unnamed' : $first;
    }

    private static function isOrdinalId(string $id): bool
    {
        return str_starts_with($id, self::ORDINAL_ID_PREFIX);
    }
}
