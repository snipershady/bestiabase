<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\UserRole;
use App\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use LogicException;
use Override;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'uq_user_email', columns: ['email'])]
#[ORM\UniqueConstraint(name: 'uq_user_username', columns: ['username'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private ?int $id = null;

    /**
     * Ruoli assegnati esplicitamente, senza ROLE_USER implicito. Un utente nasce disabilitato:
     * lo abilita un amministratore.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [UserRole::Disabled->value];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastLoginAt = null;

    /** Quando l'utente ha confermato di possedere l'indirizzo; null finché non segue il link ricevuto. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $emailVerifiedAt = null;

    /**
     * @param string $password hash prodotto dal password hasher di Symfony
     */
    public function __construct(
        #[ORM\Column(type: Types::STRING, length: 180)]
        private string $email,
        #[ORM\Column(type: Types::STRING, length: 180)]
        private string $username,
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $password,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private readonly DateTimeImmutable $createdAt,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    /**
     * @throws LogicException se l'e-mail è vuota: un utente così non può autenticarsi
     */
    #[Override]
    public function getUserIdentifier(): string
    {
        if ($this->email === '') {
            throw new LogicException('A user without an e-mail address cannot be authenticated.');
        }

        return $this->email;
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function getRoles(): array
    {
        // Un account disabilitato non conserva nessun privilegio, nemmeno quelli assegnati in precedenza.
        if (!$this->isActive()) {
            return [UserRole::Disabled->value];
        }

        return array_values(array_unique([...$this->roles, UserRole::User->value]));
    }

    public function grantRole(UserRole $role): void
    {
        if (!$this->hasRole($role)) {
            $this->roles[] = $role->value;
        }
    }

    public function revokeRole(UserRole $role): void
    {
        $this->roles = array_values(array_filter(
            $this->roles,
            static fn (string $granted): bool => $granted !== $role->value,
        ));
    }

    /**
     * Ruolo assegnato esplicitamente, indipendentemente dallo stato dell'account.
     */
    public function hasRole(UserRole $role): bool
    {
        return \in_array($role->value, $this->roles, true);
    }

    public function isAdmin(): bool
    {
        return $this->isActive() && $this->hasRole(UserRole::Admin);
    }

    #[Override]
    public function getPassword(): string
    {
        return $this->password;
    }

    public function changePassword(string $passwordHash): void
    {
        $this->password = $passwordHash;
    }

    public function isActive(): bool
    {
        return !$this->hasRole(UserRole::Disabled);
    }

    public function deactivate(): void
    {
        $this->grantRole(UserRole::Disabled);
    }

    public function activate(): void
    {
        $this->revokeRole(UserRole::Disabled);
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastLoginAt(): ?DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function recordLogin(DateTimeImmutable $at): void
    {
        $this->lastLoginAt = $at;
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerifiedAt instanceof DateTimeImmutable;
    }

    public function getEmailVerifiedAt(): ?DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function verifyEmail(DateTimeImmutable $at): void
    {
        $this->emailVerifiedAt ??= $at;
    }
}
