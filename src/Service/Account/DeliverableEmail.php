<?php

declare(strict_types=1);

namespace App\Service\Account;

use Attribute;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

/**
 * L'indirizzo deve essere ben formato e il suo dominio deve poter ricevere posta (record MX),
 * secondo snipershady/emailvalidator.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class DeliverableEmail extends Constraint
{
    /** Segnaposto {{ reason }}: il motivo restituito dalla libreria. */
    public string $message = 'This email address cannot be used: {{ reason }}.';

    /**
     * @param list<string>|null $groups
     */
    #[HasNamedArguments]
    public function __construct(
        ?string $message = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);

        $this->message = $message ?? $this->message;
    }
}
