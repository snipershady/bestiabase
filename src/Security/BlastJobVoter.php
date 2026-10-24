<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\BlastDatabase;
use App\Entity\BlastJob;
use App\Entity\User;
use App\Repository\BlastDatabaseRepository;
use Override;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Stato, risultati e download di un job:
 * - gli amministratori vedono tutto;
 * - un job con proprietario lo vede solo il proprietario; un job anonimo chi ne conosce l'UUID;
 * - in ogni caso servono ancora i permessi su tutti i database del job: chi perde l'accesso a un
 *   database riservato perde anche i risultati (e le sequenze) che ne provengono.
 *
 * @extends Voter<string, BlastJob>
 */
final class BlastJobVoter extends Voter
{
    public const string VIEW = 'BLAST_JOB_VIEW';

    public function __construct(
        private readonly BlastDatabaseRepository $databases,
    ) {
    }

    #[Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::VIEW && $subject instanceof BlastJob;
    }

    #[Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        $user = $user instanceof User ? $user : null;

        if ($user?->isAdmin() === true) {
            return true;
        }

        $owner = $subject->getUser();

        if ($owner instanceof User && $owner !== $user) {
            return false;
        }

        foreach ($subject->getDatabases() as $snapshot) {
            $database = $this->databases->find($snapshot['id']);

            // Un database cancellato non dà più accesso a nessuno, salvo gli amministratori; uno
            // disattivato sì (ferma solo le nuove ricerche).
            if (!$database instanceof BlastDatabase || !$database->isAccessibleBy($user)) {
                return false;
            }
        }

        return true;
    }
}
