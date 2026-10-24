<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\BlastDatabase;
use App\Entity\User;
use App\Enum\BlastProgram;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Modifica di un database registrato. Codice, tipo di molecola e volumi non si cambiano dal web:
 * dipendono dal FASTA importato da CLI.
 */
final class DatabaseAdminData
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public ?string $title = null;

    public ?string $description = null;

    public bool $active = true;

    public bool $public = true;

    /** @var list<BlastProgram> */
    #[Assert\Count(min: 1, minMessage: 'Enable at least one program.')]
    public array $programs = [];

    /** @var Collection<int, User> utenti autorizzati, rilevanti solo per un database riservato */
    public Collection $authorizedUsers;

    public function __construct()
    {
        $this->authorizedUsers = new ArrayCollection();
    }

    public static function fromDatabase(BlastDatabase $database): self
    {
        $data = new self();
        $data->title = $database->getTitle();
        $data->description = $database->getDescription();
        $data->active = $database->isActive();
        $data->public = $database->isPublic();
        $data->programs = $database->getPrograms();
        $data->authorizedUsers = new ArrayCollection($database->getAuthorizedUsers());

        return $data;
    }

    public function applyTo(BlastDatabase $database): void
    {
        $title = trim($this->title ?? '');
        $description = trim($this->description ?? '');

        if ($title !== '') {
            $database->rename($title);
        }

        $database->describe($description === '' ? null : $description);

        if ($this->active) {
            $database->activate();
        } else {
            $database->deactivate();
        }

        if ($this->public) {
            $database->publish();
        } else {
            $database->restrict();
        }

        foreach (BlastProgram::forDatabaseMolType($database->getMolType()) as $program) {
            if (\in_array($program, $this->programs, true)) {
                $database->enableProgram($program);
            } else {
                $database->disableProgram($program);
            }
        }

        foreach ($database->getAuthorizedUsers() as $user) {
            if (!$this->authorizedUsers->contains($user)) {
                $database->revoke($user);
            }
        }

        foreach ($this->authorizedUsers as $user) {
            $database->authorize($user);
        }
    }
}
