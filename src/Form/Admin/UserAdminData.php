<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Command\CreateUserCommand;
use App\Entity\BlastDatabase;
use App\Entity\User;
use App\Enum\UserRole;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Creazione e modifica di un utente dall'amministrazione. I database assegnati sono solo quelli
 * riservati: i pubblici sono già di tutti.
 */
final class UserAdminData
{
    #[Assert\NotBlank(groups: ['create'])]
    #[Assert\Email(groups: ['create'])]
    #[Assert\Length(max: 180, groups: ['create'])]
    public ?string $email = null;

    #[Assert\NotBlank(groups: ['create'])]
    #[Assert\Length(max: 180, groups: ['create'])]
    public ?string $username = null;

    /** Obbligatoria alla creazione; in modifica vuota = invariata. */
    #[Assert\NotBlank(groups: ['create'])]
    #[Assert\Length(min: CreateUserCommand::MIN_PASSWORD_LENGTH, max: 4096)]
    public ?string $password = null;

    public bool $admin = false;

    public bool $active = true;

    /** @var Collection<int, BlastDatabase> */
    public Collection $databases;

    public function __construct()
    {
        $this->databases = new ArrayCollection();
    }

    /**
     * @param list<BlastDatabase> $restrictedDatabases
     */
    public static function fromUser(User $user, array $restrictedDatabases): self
    {
        $data = new self();
        $data->email = $user->getEmail();
        $data->username = $user->getUsername();
        $data->admin = $user->hasRole(UserRole::Admin);
        $data->active = $user->isActive();
        $data->databases = new ArrayCollection(array_values(array_filter(
            $restrictedDatabases,
            static fn (BlastDatabase $database): bool => \in_array($user, $database->getAuthorizedUsers(), true),
        )));

        return $data;
    }

    /**
     * Ruolo, stato e database riservati (la password la gestisce il controller, che ha l'hasher).
     *
     * @param list<BlastDatabase> $restrictedDatabases tutti i database riservati
     */
    public function applyTo(User $user, array $restrictedDatabases): void
    {
        if ($this->admin) {
            $user->grantRole(UserRole::Admin);
        } else {
            $user->revokeRole(UserRole::Admin);
        }

        if ($this->active) {
            $user->activate();
        } else {
            $user->deactivate();
        }

        foreach ($restrictedDatabases as $database) {
            if ($this->databases->contains($database)) {
                $database->authorize($user);
            } else {
                $database->revoke($user);
            }
        }
    }
}
