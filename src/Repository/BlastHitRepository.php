<?php

declare(strict_types=1);

namespace App\Repository;

use App\Blast\Result\BlastNumberFormat;
use App\Blast\Result\CutoffType;
use App\Blast\Result\HitFilter;
use App\Blast\Result\HitRow;
use App\Blast\Result\HitSort;
use App\Entity\BlastHit;
use App\Entity\BlastJob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierServiceInterface;

/**
 * @extends ServiceEntityRepository<BlastHit>
 */
final class BlastHitRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly EffectivePrimitiveTypeIdentifierServiceInterface $input,
    ) {
        parent::__construct($registry, BlastHit::class);
    }

    /**
     * Righe della tabella risultati per le query in [$firstQuery, $lastQuery].
     *
     * Il cutoff si applica prima del "top N" (come il legacy, che filtrava `out.par` già
     * rifiltrato da "Parse again"); il top N segue l'ordine di BLAST, poi si ordina per $filter->sort.
     *
     * @return list<HitRow>
     */
    public function findRows(BlastJob $job, HitFilter $filter, int $firstQuery, int $lastQuery): array
    {
        $cutoff = match ($filter->cutoff) {
            CutoffType::None => '',
            // Percentuale intera mostrata da BLAST (e letta dal legacy), non quella decimale.
            CutoffType::Identity => 'AND ' . BlastNumberFormat::percentageSql('s.identity', 's.align_length') . ' >= :value',
            CutoffType::Score => 'AND h.best_bit_score >= :value',
        };
        $order = match ($filter->sort) {
            HitSort::Rank => 'r.hit_rank ASC',
            HitSort::Score => 'r.best_bit_score DESC, r.hit_rank ASC',
            HitSort::Identity => 'r.identity_percentage DESC, r.hit_rank ASC',
        };

        $sql = <<<SQL
            SELECT r.* FROM (
                SELECT h.id, q.position, q.name, q.length, h.hit_rank, h.subject_name, h.subject_accession,
                       h.subject_title, h.best_bit_score, h.identity_percentage, h.best_evalue,
                       s.identity, s.align_length,
                       ROW_NUMBER() OVER (PARTITION BY h.query_id ORDER BY h.hit_rank) AS position_in_query
                FROM blast_hit h
                INNER JOIN blast_query q ON q.id = h.query_id
                INNER JOIN blast_hsp s ON s.hit_id = h.id AND s.hsp_rank = 1
                WHERE h.job_id = :job AND q.position BETWEEN :first AND :last {$cutoff}
            ) r
            WHERE :top = 0 OR r.position_in_query <= :top
            ORDER BY r.position ASC, {$order}
            SQL;

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, [
            'job' => $job->getId()->toBinary(),
            'first' => $firstQuery,
            'last' => $lastQuery,
            'top' => $filter->top,
            'value' => $filter->value,
        ], [
            'job' => ParameterType::BINARY,
            'first' => ParameterType::INTEGER,
            'last' => ParameterType::INTEGER,
            'top' => ParameterType::INTEGER,
        ]);

        return array_map($this->hitRow(...), $rows);
    }

    /**
     * Un hit solo se appartiene al job: gli id degli hit sono sequenziali e non devono permettere
     * di leggere i risultati di un altro job.
     */
    public function findOneInJob(BlastJob $job, int $hitId): ?BlastHit
    {
        return $this->findOneBy(['id' => $hitId, 'job' => $job]);
    }

    /**
     * Hit richiesti per id, solo se del job, nell'ordine di BLAST (query, poi rank).
     *
     * @param list<int> $hitIds
     *
     * @return list<BlastHit>
     */
    public function findSelectedInJob(BlastJob $job, array $hitIds): array
    {
        /** @var list<BlastHit> $hits */
        $hits = $this->createQueryBuilder('h')
            ->innerJoin('h.query', 'q')
            ->addSelect('q')
            ->andWhere('h.job = :job')
            ->andWhere('h.id IN (:ids)')
            ->setParameter('job', $job->getId(), 'uuid')
            ->setParameter('ids', $hitIds)
            ->orderBy('q.position', SortDirection::Ascending)
            ->addOrderBy('h.rank', SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $hits;
    }

    /**
     * Hit di una query in ordine di BLAST, per la vista pairwise paginata.
     *
     * @return list<BlastHit>
     */
    public function findForQuery(BlastJob $job, int $queryPosition, int $offset, int $limit): array
    {
        /** @var list<BlastHit> $hits */
        $hits = $this->createQueryBuilder('h')
            ->innerJoin('h.query', 'q')
            ->addSelect('q')
            ->andWhere('h.job = :job')
            ->andWhere('q.position = :position')
            ->setParameter('job', $job->getId(), 'uuid')
            ->setParameter('position', $queryPosition)
            ->orderBy('h.rank', SortDirection::Ascending)
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $hits;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hitRow(array $row): HitRow
    {
        return new HitRow(
            hitId: $this->input->getIntValueFromArray('id', $row),
            queryPosition: $this->input->getIntValueFromArray('position', $row),
            queryName: $this->input->getStringValueFromArray('name', $row, forceString: true),
            queryLength: $this->input->getIntValueFromArray('length', $row),
            rank: $this->input->getIntValueFromArray('hit_rank', $row),
            subjectName: $this->input->getStringValueFromArray('subject_name', $row, forceString: true),
            subjectAccession: $this->nullableString($row, 'subject_accession'),
            subjectTitle: $this->nullableString($row, 'subject_title'),
            bitScore: $this->input->getFloatValueFromArray('best_bit_score', $row),
            identity: $this->input->getIntValueFromArray('identity', $row),
            alignLength: $this->input->getIntValueFromArray('align_length', $row),
            eValue: $this->input->getStringValueFromArray('best_evalue', $row, forceString: true),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nullableString(array $row, string $column): ?string
    {
        $value = $this->input->getTypedValueFromArray($column, $row, forceString: true);

        return \is_string($value) ? $value : null;
    }
}
