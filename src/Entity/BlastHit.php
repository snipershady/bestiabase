<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BlastHitRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use SortDirection;

/**
 * Soggetto trovato per una query (`<Hit>`). I valori "best" sono quelli del primo HSP,
 * come nel riepilogo del legacy; gli aggregati servono ai filtri SQL senza join sugli HSP.
 */
#[ORM\Entity(repositoryClass: BlastHitRepository::class)]
#[ORM\Table(name: 'blast_hit')]
#[ORM\Index(name: 'idx_bh_job_query', columns: ['job_id', 'query_id'])]
#[ORM\Index(name: 'idx_bh_job_subject', columns: ['job_id', 'subject_name'])]
#[ORM\Index(name: 'idx_bh_filter', columns: ['job_id', 'query_id', 'best_bit_score'])]
class BlastHit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $hspCount = 0;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $bestBitScore = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $bestEValue = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $identityPercentage = null;

    /** @var Collection<int, BlastHsp> */
    #[ORM\OneToMany(targetEntity: BlastHsp::class, mappedBy: 'hit', cascade: ['persist'])]
    #[ORM\OrderBy(['rank' => SortDirection::Ascending])]
    private Collection $hsps;

    /**
     * @param int    $rank        Hit_num: posizione nell'ordine di BLAST per la query
     * @param string $subjectName nome breve del soggetto (primo token)
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: BlastJob::class)]
        #[ORM\JoinColumn(name: 'job_id', nullable: false, onDelete: 'CASCADE')]
        private readonly BlastJob $job,
        #[ORM\ManyToOne(targetEntity: BlastQuery::class)]
        #[ORM\JoinColumn(name: 'query_id', nullable: false, onDelete: 'CASCADE')]
        private readonly BlastQuery $query,
        #[ORM\Column(name: 'hit_rank', type: Types::INTEGER, options: ['unsigned' => true])]
        private readonly int $rank,
        #[ORM\Column(type: Types::STRING, length: 512)]
        private readonly string $subjectName,
        #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
        private readonly int $subjectLength,
        #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
        private readonly ?string $subjectAccession = null,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private readonly ?string $subjectTitle = null,
    ) {
        $this->hsps = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJob(): BlastJob
    {
        return $this->job;
    }

    public function getQuery(): BlastQuery
    {
        return $this->query;
    }

    public function getRank(): int
    {
        return $this->rank;
    }

    public function getSubjectName(): string
    {
        return $this->subjectName;
    }

    public function getSubjectLength(): int
    {
        return $this->subjectLength;
    }

    public function getSubjectAccession(): ?string
    {
        return $this->subjectAccession;
    }

    public function getSubjectTitle(): ?string
    {
        return $this->subjectTitle;
    }

    /**
     * Defline completo del soggetto senza ripetere il nome: per i database senza seqid (e i FASTA
     * caricati) il nome è già il primo token del titolo.
     */
    public function getSubjectDefinition(): string
    {
        $title = trim($this->subjectTitle ?? '');

        if ($title === '' || str_starts_with($title, $this->subjectName)) {
            return $title === '' ? $this->subjectName : $title;
        }

        return $this->subjectName . ' ' . $title;
    }

    /**
     * Aggiunge un HSP aggiornando gli aggregati; il primo registrato è il migliore.
     *
     * @throws InvalidArgumentException se l'HSP appartiene a un altro hit
     */
    public function addHsp(BlastHsp $hsp): void
    {
        if ($hsp->getHit() !== $this) {
            throw new InvalidArgumentException('The HSP belongs to a different hit.');
        }

        if ($this->hspCount === 0) {
            $this->bestBitScore = $hsp->getBitScore();
            $this->bestEValue = $hsp->getEValue();
            $this->identityPercentage = $hsp->getIdentityPercentage();
        }

        $this->hsps->add($hsp);
        ++$this->hspCount;
    }

    /**
     * @return list<BlastHsp>
     */
    public function getHsps(): array
    {
        return array_values($this->hsps->toArray());
    }

    public function getHspCount(): int
    {
        return $this->hspCount;
    }

    public function getBestBitScore(): ?float
    {
        return $this->bestBitScore;
    }

    public function getBestEValue(): ?string
    {
        return $this->bestEValue;
    }

    public function getIdentityPercentage(): ?float
    {
        return $this->identityPercentage;
    }
}
