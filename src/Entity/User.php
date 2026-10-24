<?php

declare(strict_types=1);

namespace App\Entity;

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
    public const string ROLE_USER = 'ROLE_USER';

    public const string ROLE_ADMIN = 'ROLE_ADMIN';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private ?int $id = null;

    /** @var list<string> ruoli assegnati esplicitamente, senza ROLE_USER implicito */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastLoginAt = null;

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
        return array_values(array_unique([...$this->roles, self::ROLE_USER]));
    }

    public function grantRole(string $role): void
    {
        if (!\in_array($role, $this->roles, true)) {
            $this->roles[] = $role;
        }
    }

    public function revokeRole(string $role): void
    {
        $this->roles = array_values(array_filter(
            $this->roles,
            static fn (string $granted): bool => $granted !== $role,
        ));
    }

    public function isAdmin(): bool
    {
        return \in_array(self::ROLE_ADMIN, $this->roles, true);
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
        return $this->active;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    public function activate(): void
    {
        $this->active = true;
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
}
