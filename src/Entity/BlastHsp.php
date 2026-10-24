<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Segmento di allineamento (`<Hsp>`): serve al rendering pairwise e al download "mapping".
 */
#[ORM\Entity]
#[ORM\Table(name: 'blast_hsp')]
#[ORM\Index(name: 'idx_bhsp_hit', columns: ['hit_id', 'hsp_rank'])]
class BlastHsp
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private ?int $id = null;

    /** Aperture di gap (colonna "gap opens" del formato tabulare), calcolate una volta sola. */
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private readonly int $gapOpens;

    /**
     * @param int    $rank   Hsp_num (1 = migliore)
     * @param string $eValue conservato come testo per non perdere precisione (es. "1e-180", "0.0")
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: BlastHit::class, inversedBy: 'hsps')]
        #[ORM\JoinColumn(name: 'hit_id', nullable: false, onDelete: 'CASCADE')]
        private readonly BlastHit $hit,
        #[ORM\Column(name: 'hsp_rank', type: Types::SMALLINT, options: ['unsigned' => true])]
        private readonly int $rank,
        #[ORM\Column(type: Types::FLOAT)]
        private readonly float $bitScore,
        #[ORM\Column(type: Types::FLOAT)]
        private readonly float $rawScore,
        #[ORM\Column(type: Types::STRING, length: 64)]
        private readonly string $eValue,
        #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
        private readonly int $identity,
        #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
        private readonly int $positive,
        #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
        private readonly int $gaps,
        #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
        private readonly int $alignLength,
        #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
        private readonly int $queryFrom,
        #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
        private readonly int $queryTo,
        #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
        private readonly int $hitFrom,
        #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
        private readonly int $hitTo,
        #[ORM\Column(type: Types::SMALLINT)]
        private readonly int $queryFrame,
        #[ORM\Column(type: Types::SMALLINT)]
        private readonly int $hitFrame,
        #[ORM\Column(type: Types::TEXT, length: 16_777_215)]
        private readonly string $querySequence,
        #[ORM\Column(type: Types::TEXT, length: 16_777_215)]
        private readonly string $midline,
        #[ORM\Column(type: Types::TEXT, length: 16_777_215)]
        private readonly string $hitSequence,
    ) {
        $this->gapOpens = substr_count((string) preg_replace('/-+/', '-', $querySequence . ' ' . $hitSequence), '-');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHit(): BlastHit
    {
        return $this->hit;
    }

    public function getRank(): int
    {
        return $this->rank;
    }

    public function getBitScore(): float
    {
        return $this->bitScore;
    }

    public function getRawScore(): float
    {
        return $this->rawScore;
    }

    public function getEValue(): string
    {
        return $this->eValue;
    }

    public function getIdentity(): int
    {
        return $this->identity;
    }

    public function getPositive(): int
    {
        return $this->positive;
    }

    public function getGaps(): int
    {
        return $this->gaps;
    }

    public function getAlignLength(): int
    {
        return $this->alignLength;
    }

    /**
     * Percentuale di identità sull'allineamento (come la riga "Identities = X/Y (Z%)").
     */
    public function getIdentityPercentage(): float
    {
        return $this->identity * 100 / $this->alignLength;
    }

    public function getQueryFrom(): int
    {
        return $this->queryFrom;
    }

    public function getQueryTo(): int
    {
        return $this->queryTo;
    }

    public function getHitFrom(): int
    {
        return $this->hitFrom;
    }

    public function getHitTo(): int
    {
        return $this->hitTo;
    }

    public function getQueryFrame(): int
    {
        return $this->queryFrame;
    }

    public function getHitFrame(): int
    {
        return $this->hitFrame;
    }

    public function getGapOpens(): int
    {
        return $this->gapOpens;
    }

    public function getQuerySequence(): string
    {
        return $this->querySequence;
    }

    public function getMidline(): string
    {
        return $this->midline;
    }

    public function getHitSequence(): string
    {
        return $this->hitSequence;
    }
}
