<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\User;
use App\Enum\BlastProgram;
use App\Enum\MolType;
use App\Repository\UserRepository;
use Doctrine\ORM\QueryBuilder;
use Override;
use SortDirection;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<DatabaseAdminData>
 */
final class DatabaseAdminType extends AbstractType
{
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Il tipo è già garantito da setAllowedTypes(); qui serve solo a restringerlo per l'analisi statica.
        $molType = $options['mol_type'] instanceof MolType ? $options['mol_type'] : MolType::Nucleotide;

        $builder
            ->add('title', TextType::class)
            ->add('description', TextareaType::class, ['required' => false, 'attr' => ['rows' => 3]])
            ->add('active', CheckboxType::class, ['required' => false, 'help' => 'Inactive databases cannot be searched; existing results stay available.'])
            ->add('public', CheckboxType::class, ['required' => false, 'label' => 'Public', 'help' => 'Public: every visitor can search it. Otherwise only the authorized users and the administrators.'])
            ->add('programs', EnumType::class, [
                'class' => BlastProgram::class,
                'choices' => BlastProgram::forDatabaseMolType($molType),
                'choice_label' => static fn (BlastProgram $program): string => $program->value,
                'multiple' => true,
                'expanded' => true,
            ])
            ->add('authorizedUsers', EntityType::class, [
                'class' => User::class,
                'multiple' => true,
                'required' => false,
                'label' => 'Authorized users (restricted database)',
                'choice_label' => static fn (User $user): string => \sprintf('%s (%s)', $user->getUsername(), $user->getEmail()),
                'query_builder' => static fn (UserRepository $users): QueryBuilder => $users->createQueryBuilder('u')->orderBy('u.email', SortDirection::Ascending),
                'attr' => ['size' => 8],
            ]);
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DatabaseAdminData::class]);
        $resolver->setRequired('mol_type');
        $resolver->setAllowedTypes('mol_type', MolType::class);
    }
}
