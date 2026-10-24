<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Una sequenza del FASTA di query (una `<Iteration>` dell'output XML).
 */
#[ORM\Entity]
#[ORM\Table(name: 'blast_query')]
#[ORM\UniqueConstraint(name: 'uq_bq_job_pos', columns: ['job_id', 'position'])]
class BlastQuery
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private ?int $id = null;

    /**
     * @param int    $position ordine nel FASTA, a partire da 1
     * @param string $name     nome breve (primo token del defline, o Query<N>)
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: BlastJob::class)]
        #[ORM\JoinColumn(name: 'job_id', nullable: false, onDelete: 'CASCADE')]
        private readonly BlastJob $job,
        #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
        private readonly int $position,
        #[ORM\Column(type: Types::STRING, length: 512)]
        private readonly string $name,
        #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
        private readonly int $length,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private readonly ?string $definition = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJob(): BlastJob
    {
        return $this->job;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLength(): int
    {
        return $this->length;
    }

    public function getDefinition(): ?string
    {
        return $this->definition;
    }
}
