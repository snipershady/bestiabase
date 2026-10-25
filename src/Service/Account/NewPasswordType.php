<?php

declare(strict_types=1);

namespace App\Service\Account;

use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<NewPasswordData>
 */
final class NewPasswordType extends AbstractType
{
    /**
     * Opzioni del campo "password + conferma", condivise da tutti i form che ne chiedono una nuova.
     *
     * @return array<string, mixed>
     */
    public static function repeatedOptions(string $label = 'Password'): array
    {
        return [
            'type' => PasswordType::class,
            'invalid_message' => 'The passwords do not match.',
            'first_options' => ['label' => $label, 'help' => 'At least 12 characters.', 'attr' => ['autocomplete' => 'new-password']],
            'second_options' => ['label' => 'Repeat the password', 'attr' => ['autocomplete' => 'new-password']],
        ];
    }

    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('password', RepeatedType::class, self::repeatedOptions('New password'));
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => NewPasswordData::class]);
    }
}
