<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\BlastProgram;
use App\Enum\MolType;
use App\Exception\IncompatibleProgramException;
use App\Repository\BlastDatabaseRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Database BLAST registrato. I volumi (`.nhr/.nin/.nsq`, `.phr/.pin/.psq`) restano su
 * filesystem: qui c'è solo il registro, che sostituisce viroblast.ini.
 */
#[ORM\Entity(repositoryClass: BlastDatabaseRepository::class)]
#[ORM\Table(name: 'blast_database')]
#[ORM\UniqueConstraint(name: 'uq_blast_db_code', columns: ['code'])]
#[ORM\Index(name: 'idx_blast_db_moltype', columns: ['mol_type', 'active'])]
class BlastDatabase
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::STRING, length: 512, nullable: true)]
    private ?string $sourceFasta = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true, options: ['unsigned' => true])]
    private ?int $sequenceCount = null;

    /** Residui totali: per database come nt supera i 2^31, da cui il BIGINT. */
    #[ORM\Column(type: Types::BIGINT, nullable: true, options: ['unsigned' => true])]
    private int|string|null $totalLength = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $active = true;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $public = true;

    /**
     * Utenti autorizzati a un database riservato (non pubblico).
     *
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'blast_database_access')]
    #[ORM\JoinColumn(name: 'blast_database_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'user_id', onDelete: 'CASCADE')]
    private Collection $authorizedUsers;

    /** @var Collection<int, BlastDatabaseProgram> */
    #[ORM\OneToMany(targetEntity: BlastDatabaseProgram::class, mappedBy: 'database', cascade: ['persist'], orphanRemoval: true)]
    private Collection $programs;

    /**
     * @param string $code  basename dei volumi BLAST (es. test_na_db)
     * @param string $title etichetta mostrata nella UI
     * @param string $path  path dei volumi passato a `-db`, risolto solo lato server
     */
    public function __construct(
        #[ORM\Column(type: Types::STRING, length: 128)]
        private readonly string $code,
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $title,
        #[ORM\Column(type: Types::STRING, length: 4, enumType: MolType::class)]
        private readonly MolType $molType,
        #[ORM\Column(type: Types::STRING, length: 512)]
        private string $path,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private readonly DateTimeImmutable $createdAt,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'created_by', nullable: true, onDelete: 'SET NULL')]
        private readonly ?User $user = null,
    ) {
        $this->programs = new ArrayCollection();
        $this->authorizedUsers = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function rename(string $title): void
    {
        $this->title = $title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function describe(?string $description): void
    {
        $this->description = $description;
    }

    public function getMolType(): MolType
    {
        return $this->molType;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getSourceFasta(): ?string
    {
        return $this->sourceFasta;
    }

    public function attachSourceFasta(?string $sourceFasta): void
    {
        $this->sourceFasta = $sourceFasta;
    }

    public function getSequenceCount(): ?int
    {
        return $this->sequenceCount;
    }

    /**
     * Residui totali. Restituito come stringa solo se supera PHP_INT_MAX.
     */
    public function getTotalLength(): int|string|null
    {
        return $this->totalLength;
    }

    /**
     * Statistiche lette dai volumi con `blastdbcmd -metadata`.
     */
    public function recordStatistics(int $sequenceCount, int $totalLength): void
    {
        $this->sequenceCount = $sequenceCount;
        $this->totalLength = $totalLength;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function activate(): void
    {
        $this->active = true;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    public function isPublic(): bool
    {
        return $this->public;
    }

    public function publish(): void
    {
        $this->public = true;
    }

    public function restrict(): void
    {
        $this->public = false;
    }

    /**
     * Permessi sul database (e quindi sui risultati che ne provengono): gli amministratori vedono
     * tutto; un database pubblico è di tutti, anche degli anonimi; uno riservato solo degli utenti
     * autorizzati.
     */
    public function isAccessibleBy(?User $user): bool
    {
        if ($this->public || $user?->isAdmin() === true) {
            return true;
        }

        return $user instanceof User && $this->authorizedUsers->contains($user);
    }

    /**
     * Utilizzabile per una nuova ricerca: accessibile e attivo. Disattivare un database ferma le
     * nuove ricerche ma non nasconde i risultati già ottenuti.
     */
    public function isSearchableBy(?User $user): bool
    {
        return $this->active && $this->isAccessibleBy($user);
    }

    public function authorize(User $user): void
    {
        if (!$this->authorizedUsers->contains($user)) {
            $this->authorizedUsers->add($user);
        }
    }

    public function revoke(User $user): void
    {
        $this->authorizedUsers->removeElement($user);
    }

    /**
     * @return list<User>
     */
    public function getAuthorizedUsers(): array
    {
        return array_values($this->authorizedUsers->toArray());
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCreatedBy(): ?User
    {
        return $this->user;
    }

    /**
     * @throws IncompatibleProgramException se il programma non cerca su questo tipo di molecola
     */
    public function enableProgram(BlastProgram $program): void
    {
        if ($program->databaseMolType() !== $this->molType) {
            throw IncompatibleProgramException::forDatabase($program, $this->molType);
        }

        if (!$this->supports($program)) {
            $this->programs->add(new BlastDatabaseProgram($this, $program));
        }
    }

    public function disableProgram(BlastProgram $program): void
    {
        foreach ($this->programs as $enabled) {
            if ($enabled->getProgram() === $program) {
                $this->programs->removeElement($enabled);
            }
        }
    }

    /**
     * Abilita tutti i programmi compatibili con il tipo di molecola del database.
     */
    public function enableAllCompatiblePrograms(): void
    {
        foreach (BlastProgram::forDatabaseMolType($this->molType) as $program) {
            $this->enableProgram($program);
        }
    }

    public function supports(BlastProgram $program): bool
    {
        return \in_array($program, $this->getPrograms(), true);
    }

    /**
     * @return list<BlastProgram>
     */
    public function getPrograms(): array
    {
        return array_values($this->programs->map(
            static fn (BlastDatabaseProgram $blastDatabaseProgram): BlastProgram => $blastDatabaseProgram->getProgram(),
        )->toArray());
    }
}
