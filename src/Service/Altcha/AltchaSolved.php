<?php

declare(strict_types=1);

namespace App\Service\Altcha;

use Attribute;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

/**
 * Il valore (il payload del widget ALTCHA) deve essere una soluzione valida per lo scopo indicato.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class AltchaSolved extends Constraint
{
    public string $message = 'The security check failed. Wait for it to complete and try again.';

    /**
     * @param list<string>|null $groups
     */
    #[HasNamedArguments]
    public function __construct(
        public AltchaPurpose $purpose,
        ?string $message = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);

        $this->message = $message ?? $this->message;
    }
}
