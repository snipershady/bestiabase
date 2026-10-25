<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\BlastDatabase;
use App\Repository\BlastDatabaseRepository;
use Doctrine\ORM\QueryBuilder;
use Override;
use SortDirection;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<UserAdminData>
 */
final class UserAdminType extends AbstractType
{
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $creating = $options['creating'] === true;

        $builder
            // Email e username identificano l'utente: si scelgono solo alla creazione.
            ->add('email', EmailType::class, ['disabled' => !$creating])
            ->add('username', TextType::class, ['disabled' => !$creating])
            ->add('password', RepeatedType::class, [
                'type' => PasswordType::class,
                'required' => $creating,
                'invalid_message' => 'The passwords do not match.',
                'first_options' => ['label' => $creating ? 'Password' : 'New password', 'help' => $creating ? null : 'Leave empty to keep the current password.', 'attr' => ['autocomplete' => 'new-password']],
                'second_options' => ['label' => 'Repeat the password', 'attr' => ['autocomplete' => 'new-password']],
            ])
            ->add('admin', CheckboxType::class, ['required' => false, 'label' => 'Administrator', 'help' => 'Administrators can search every database and manage databases, users and jobs.'])
            ->add('active', CheckboxType::class, ['required' => false, 'label' => 'Enabled', 'help' => 'Disabled users (including new registrations) can sign in but cannot run searches. Enabling sends them an email.'])
            ->add('databases', EntityType::class, [
                'class' => BlastDatabase::class,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'label' => 'Restricted databases this user can search',
                'choice_label' => static fn (BlastDatabase $database): string => \sprintf('%s (%s)', $database->getTitle(), $database->getCode()),
                'query_builder' => static fn (BlastDatabaseRepository $databases): QueryBuilder => $databases->createQueryBuilder('d')
                    ->andWhere('d.public = false')
                    ->orderBy('d.title', SortDirection::Ascending),
            ]);
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => UserAdminData::class,
            'creating' => false,
            'validation_groups' => static fn (FormInterface $form): array => $form->getConfig()->getOption('creating') === true ? ['Default', 'create'] : ['Default'],
        ]);
        $resolver->setAllowedTypes('creating', 'bool');
    }
}
