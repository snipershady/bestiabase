<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\BlastProgram;
use App\Enum\JobStatus;
use App\Enum\SearchType;
use App\Exception\InvalidJobTransitionException;
use App\Repository\BlastJobRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Ricerca BLAST. Lo stato vive qui (non in file marker): pending → running → finished|failed,
 * con cancellazione possibile finché il job non è terminato.
 *
 * @phpstan-type DatabaseSnapshot array{id: int, code: string, title: string}
 * @phpstan-type Parameters array<string, bool|float|int|string|null>
 */
#[ORM\Entity(repositoryClass: BlastJobRepository::class)]
#[ORM\Table(name: 'blast_job')]
#[ORM\Index(name: 'idx_job_status', columns: ['status', 'created_at'])]
#[ORM\Index(name: 'idx_job_user', columns: ['user_id', 'created_at'])]
class BlastJob
{
    #[ORM\Column(type: Types::STRING, length: 16, enumType: JobStatus::class)]
    private JobStatus $status = JobStatus::Pending;

    #[ORM\Column(type: Types::INTEGER, nullable: true, options: ['unsigned' => true])]
    private ?int $numQueries = null;

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private int $numHits = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    private ?string $blastVersion = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $finishedAt = null;

    /**
     * @param Parameters             $parameters snapshot dei parametri di ricerca, per riproducibilità
     * @param list<DatabaseSnapshot> $databases  database selezionati al momento della ricerca
     * @param string                 $queryFasta FASTA della query già normalizzato
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private readonly Uuid $id,
        #[ORM\Column(type: Types::STRING, length: 16, enumType: BlastProgram::class)]
        private readonly BlastProgram $program,
        #[ORM\Column(type: Types::STRING, length: 16, enumType: SearchType::class)]
        private readonly SearchType $searchType,
        #[ORM\Column(type: Types::JSON)]
        private readonly array $parameters,
        // "databases" è una parola riservata in MySQL
        #[ORM\Column(name: 'database_snapshot', type: Types::JSON)]
        private readonly array $databases,
        #[ORM\Column(type: Types::TEXT, length: 16_777_215)]
        private readonly string $queryFasta,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private readonly DateTimeImmutable $createdAt,
        #[ORM\Column(type: Types::TEXT, length: 16_777_215, nullable: true)]
        private readonly ?string $subjectFasta = null,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: true, onDelete: 'SET NULL')]
        private readonly ?User $user = null,
    ) {
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProgram(): BlastProgram
    {
        return $this->program;
    }

    public function getSearchType(): SearchType
    {
        return $this->searchType;
    }

    /**
     * @return Parameters
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * @return list<DatabaseSnapshot>
     */
    public function getDatabases(): array
    {
        return $this->databases;
    }

    public function getQueryFasta(): string
    {
        return $this->queryFasta;
    }

    public function getSubjectFasta(): ?string
    {
        return $this->subjectFasta;
    }

    public function hasUploadedSubject(): bool
    {
        return $this->subjectFasta !== null;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getStatus(): JobStatus
    {
        return $this->status;
    }

    public function getNumQueries(): ?int
    {
        return $this->numQueries;
    }

    public function getNumHits(): int
    {
        return $this->numHits;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * Versione di BLAST+ che ha eseguito la ricerca (es. "2.17.0+").
     */
    public function getBlastVersion(): ?string
    {
        return $this->blastVersion;
    }

    public function recordBlastVersion(?string $version): void
    {
        $this->blastVersion = $version;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStartedAt(): ?DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?DateTimeImmutable
    {
        return $this->finishedAt;
    }

    /**
     * @throws InvalidJobTransitionException
     */
    public function start(DateTimeImmutable $at): void
    {
        $this->transitionTo(JobStatus::Running, JobStatus::Pending);
        $this->startedAt = $at;
    }

    /**
     * @throws InvalidJobTransitionException
     */
    public function finish(DateTimeImmutable $at, int $numQueries, int $numHits): void
    {
        $this->transitionTo(JobStatus::Finished, JobStatus::Running);
        $this->finishedAt = $at;
        $this->numQueries = $numQueries;
        $this->numHits = $numHits;
    }

    /**
     * @throws InvalidJobTransitionException
     */
    public function fail(DateTimeImmutable $at, string $errorMessage): void
    {
        $this->transitionTo(JobStatus::Failed, JobStatus::Pending, JobStatus::Running);
        $this->finishedAt = $at;
        $this->errorMessage = $errorMessage;
    }

    /**
     * @throws InvalidJobTransitionException
     */
    public function cancel(DateTimeImmutable $at): void
    {
        $this->transitionTo(JobStatus::Cancelled, JobStatus::Pending, JobStatus::Running);
        $this->finishedAt = $at;
    }

    private function transitionTo(JobStatus $target, JobStatus ...$allowedFrom): void
    {
        if (!\in_array($this->status, $allowedFrom, true)) {
            throw InvalidJobTransitionException::create($this->status, $target);
        }

        $this->status = $target;
    }
}
