<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\BlastDatabase;
use App\Entity\User;
use Override;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Può l'utente (anche anonimo) cercare su questo database? Vedi {@see BlastDatabase::isSearchableBy()}.
 *
 * @extends Voter<string, BlastDatabase>
 */
final class BlastDatabaseVoter extends Voter
{
    public const string SEARCH = 'BLAST_DATABASE_SEARCH';

    #[Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::SEARCH && $subject instanceof BlastDatabase;
    }

    #[Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $subject->isSearchableBy($user instanceof User ? $user : null);
    }
}
