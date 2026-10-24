<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DownloadSequenceType;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

/**
 * Richiesta di download delle sequenze soggetto: audit e rigenerazione dello stream FASTA.
 */
#[ORM\Entity]
#[ORM\Table(name: 'blast_download')]
class BlastDownload
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private ?int $id = null;

    /** @var Collection<int, BlastHit> */
    #[ORM\ManyToMany(targetEntity: BlastHit::class)]
    #[ORM\JoinTable(name: 'blast_download_item')]
    #[ORM\JoinColumn(name: 'download_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'hit_id', onDelete: 'CASCADE')]
    private Collection $hits;

    /**
     * @param list<BlastHit> $hits
     *
     * @throws InvalidArgumentException se un hit non appartiene al job
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: BlastJob::class)]
        #[ORM\JoinColumn(name: 'job_id', nullable: false, onDelete: 'CASCADE')]
        private readonly BlastJob $job,
        #[ORM\Column(type: Types::STRING, length: 16, enumType: DownloadSequenceType::class)]
        private readonly DownloadSequenceType $sequenceType,
        array $hits,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private readonly DateTimeImmutable $createdAt,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: true, onDelete: 'SET NULL')]
        private readonly ?User $user = null,
    ) {
        foreach ($hits as $hit) {
            if ($hit->getJob() !== $job) {
                throw new InvalidArgumentException('Every downloaded hit must belong to the download job.');
            }
        }

        $this->hits = new ArrayCollection($hits);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJob(): BlastJob
    {
        return $this->job;
    }

    public function getSequenceType(): DownloadSequenceType
    {
        return $this->sequenceType;
    }

    /**
     * @return list<BlastHit>
     */
    public function getHits(): array
    {
        return array_values($this->hits->toArray());
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }
}
