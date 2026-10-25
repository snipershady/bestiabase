<?php

declare(strict_types=1);

namespace App\Service\Altcha;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Difficoltà configurata per ogni scopo (variabili d'ambiente ALTCHA_*_DIFFICULTY).
 */
final readonly class AltchaDifficulties
{
    public function __construct(
        #[Autowire('%env(enum:App\Service\Altcha\AltchaDifficulty:ALTCHA_LOGIN_DIFFICULTY)%')]
        private AltchaDifficulty $login,
        #[Autowire('%env(enum:App\Service\Altcha\AltchaDifficulty:ALTCHA_REGISTRATION_DIFFICULTY)%')]
        private AltchaDifficulty $registration,
        #[Autowire('%env(enum:App\Service\Altcha\AltchaDifficulty:ALTCHA_SECURITY_CHECK_DIFFICULTY)%')]
        private AltchaDifficulty $securityCheck,
        #[Autowire('%env(enum:App\Service\Altcha\AltchaDifficulty:ALTCHA_TRAP_DIFFICULTY)%')]
        private AltchaDifficulty $trap,
    ) {
    }

    public function forPurpose(AltchaPurpose $purpose): AltchaDifficulty
    {
        return match ($purpose) {
            AltchaPurpose::Login => $this->login,
            AltchaPurpose::Registration => $this->registration,
            AltchaPurpose::SecurityCheck => $this->securityCheck,
            AltchaPurpose::Trap => $this->trap,
        };
    }
}
