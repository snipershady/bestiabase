<?php

declare(strict_types=1);

namespace App\Blast;

use App\Blast\Xml\ParsedHit;
use App\Blast\Xml\ParsedHsp;
use App\Blast\Xml\ParsedIteration;
use App\Entity\BlastHit;
use App\Entity\BlastHsp;
use App\Entity\BlastJob;
use App\Entity\BlastQuery;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Salva i risultati parsati come BlastQuery / BlastHit / BlastHsp.
 *
 * Le entity create vengono scaricate dall'EntityManager a blocchi (flush + detach), così la
 * memoria resta limitata anche con decine di migliaia di HSP; il job resta gestito.
 */
final readonly class BlastResultImporter
{
    public const int BATCH_SIZE = 500;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param iterable<ParsedIteration> $iterations
     */
    public function import(BlastJob $job, iterable $iterations): ImportSummary
    {
        $queries = 0;
        $hits = 0;
        $hsps = 0;
        $pending = [];

        foreach ($iterations as $iteration) {
            $position = $queries + 1;
            $query = new BlastQuery(
                $job,
                $position,
                SequenceNames::query($iteration->queryDefinition, $position),
                $iteration->queryLength,
                trim($iteration->queryDefinition) === '' ? null : $iteration->queryDefinition,
            );
            $this->entityManager->persist($query);
            $pending[] = $query;
            ++$queries;

            foreach ($iteration->hits as $parsedHit) {
                $hit = $this->hit($job, $query, $parsedHit);
                $this->entityManager->persist($hit);
                $pending[] = $hit;
                array_push($pending, ...$hit->getHsps());
                ++$hits;
                $hsps += $hit->getHspCount();
            }

            if (\count($pending) >= self::BATCH_SIZE) {
                $this->flush($pending);
                $pending = [];
            }
        }

        $this->flush($pending);

        return new ImportSummary($queries, $hits, $hsps);
    }

    private function hit(BlastJob $job, BlastQuery $query, ParsedHit $parsed): BlastHit
    {
        $hit = new BlastHit(
            $job,
            $query,
            $parsed->number,
            SequenceNames::subject($parsed),
            $parsed->length,
            SequenceNames::subjectAccession($parsed),
            $parsed->definition === '' ? null : $parsed->definition,
        );

        foreach ($parsed->hsps as $parsedHsp) {
            $hit->addHsp($this->hsp($hit, $parsedHsp));
        }

        return $hit;
    }

    private function hsp(BlastHit $hit, ParsedHsp $parsed): BlastHsp
    {
        return new BlastHsp(
            hit: $hit,
            rank: $parsed->number,
            bitScore: $parsed->bitScore,
            rawScore: $parsed->score,
            eValue: $parsed->eValue,
            identity: $parsed->identity,
            positive: $parsed->positive,
            gaps: $parsed->gaps,
            alignLength: $parsed->alignLength,
            queryFrom: $parsed->queryFrom,
            queryTo: $parsed->queryTo,
            hitFrom: $parsed->hitFrom,
            hitTo: $parsed->hitTo,
            queryFrame: $parsed->queryFrame,
            hitFrame: $parsed->hitFrame,
            querySequence: $parsed->querySequence,
            midline: $parsed->midline,
            hitSequence: $parsed->hitSequence,
        );
    }

    /**
     * @param list<object> $entities
     */
    private function flush(array $entities): void
    {
        $this->entityManager->flush();

        foreach ($entities as $entity) {
            $this->entityManager->detach($entity);
        }
    }
}
