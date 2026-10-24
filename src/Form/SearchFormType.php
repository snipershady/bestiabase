<?php

declare(strict_types=1);

namespace App\Form;

use App\Blast\Search\GeneticCode;
use App\Blast\Search\NucleotideScoring;
use App\Blast\Search\ScoringMatrix;
use App\Entity\BlastDatabase;
use App\Entity\User;
use App\Enum\BlastProgram;
use App\Repository\BlastDatabaseRepository;
use Doctrine\ORM\QueryBuilder;
use Override;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Form di ricerca: query incollata (nessun upload di file), programma, database e parametri
 * avanzati. Il pulsante premuto decide tra ricerca "basic" e "advanced", come nel legacy.
 *
 * @extends AbstractType<SearchFormData>
 */
final class SearchFormType extends AbstractType
{
    public const string BASIC = 'basic';

    public const string ADVANCED = 'advanced';

    public function __construct(
        #[Autowire('%env(int:BLAST_QUERY_MAX_BYTES)%')]
        private readonly int $queryMaxBytes,
        private readonly Security $security,
    ) {
    }

    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('program', EnumType::class, [
                'class' => BlastProgram::class,
                'choice_label' => static fn (BlastProgram $program): string => $program->value,
            ])
            ->add('query', TextareaType::class, [
                'label' => 'Query sequence(s)',
                'help' => \sprintf('FASTA (one or more sequences) or plain residues, up to %d MB. Large files are imported from the command line.', intdiv($this->queryMaxBytes, 1_048_576)),
                'attr' => ['rows' => 8, 'spellcheck' => 'false', 'class' => 'font-monospace'],
                'constraints' => [new Callback($this->validateQuerySize(...))],
            ])
            ->add('databases', EntityType::class, [
                'class' => BlastDatabase::class,
                'multiple' => true,
                'choice_label' => static fn (BlastDatabase $database): string => $database->getTitle(),
                'choice_attr' => static fn (BlastDatabase $database): array => [
                    'data-programs' => implode(' ', array_map(static fn (BlastProgram $program): string => $program->value, $database->getPrograms())),
                ],
                // Solo i database che l'utente corrente può usare (pubblici, assegnati, tutti se admin).
                'query_builder' => fn (BlastDatabaseRepository $repository): QueryBuilder => $repository->searchableBy($this->currentUser()),
                'attr' => ['size' => 6],
                'help' => 'Hold Ctrl (Cmd on macOS) to select more than one database.',
            ])
            ->add('expect', TextType::class, ['label' => 'Expect threshold'])
            ->add('wordSize', ChoiceType::class, [
                'choices' => array_combine([2, 3, 7, 11, 15], [2, 3, 7, 11, 15]),
            ])
            ->add('maxTargetSequences', ChoiceType::class, [
                'label' => 'Max target sequences',
                'choices' => array_combine(SearchFormData::MAX_TARGET_SEQUENCES, SearchFormData::MAX_TARGET_SEQUENCES),
            ])
            ->add('nucleotideScoring', EnumType::class, [
                'class' => NucleotideScoring::class,
                'label' => 'Match/mismatch scores',
                'choice_label' => static fn (NucleotideScoring $scoring): string => $scoring->value,
            ])
            ->add('matrix', EnumType::class, [
                'class' => ScoringMatrix::class,
                'choice_label' => static fn (ScoringMatrix $matrix): string => $matrix->value,
            ])
            ->add('gapCosts', ChoiceType::class, ['choices' => SearchFormClientConfig::gapCostChoices()])
            ->add('lowComplexityFilter', CheckboxType::class, ['label' => 'Filter low complexity regions', 'required' => false])
            ->add('softMasking', CheckboxType::class, ['label' => 'Mask for lookup table only', 'required' => false])
            ->add('lowercaseMasking', CheckboxType::class, ['label' => 'Mask lower case letters', 'required' => false])
            ->add('ungapped', CheckboxType::class, ['label' => 'Perform ungapped alignment', 'required' => false])
            ->add('queryGeneticCode', EnumType::class, [
                'class' => GeneticCode::class,
                'label' => 'Query genetic code',
                'choice_label' => static fn (GeneticCode $code): string => $code->label(),
            ])
            ->add('databaseGeneticCode', EnumType::class, [
                'class' => GeneticCode::class,
                'label' => 'Database genetic code',
                'choice_label' => static fn (GeneticCode $code): string => $code->label(),
            ])
            ->add(self::BASIC, SubmitType::class, ['label' => 'Basic search', 'attr' => ['class' => 'btn btn-primary']])
            ->add(self::ADVANCED, SubmitType::class, ['label' => 'Advanced search', 'attr' => ['class' => 'btn btn-outline-primary']]);
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SearchFormData::class,
            // I campi avanzati si validano solo se è stato premuto "Advanced search".
            'validation_groups' => static fn (FormInterface $form): array => self::isAdvanced($form) ? ['Default', 'advanced'] : ['Default'],
            // Mostrato quando il body supera post_max_size e PHP scarta l'intero POST.
            'upload_max_size_message' => static fn (): string => 'The query is too large for this server. Paste fewer sequences or import them from the command line.',
        ]);
    }

    /**
     * @template T
     *
     * @param FormInterface<T> $form
     */
    public static function isAdvanced(FormInterface $form): bool
    {
        $root = $form->getRoot();
        $button = $root->has(self::ADVANCED) ? $root->get(self::ADVANCED) : null;

        return $button instanceof ClickableInterface && $button->isClicked();
    }

    private function currentUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    private function validateQuerySize(mixed $query, ExecutionContextInterface $context): void
    {
        // In byte, non in caratteri: è il limite che conta per memoria, colonna DB e body HTTP.
        if (\is_string($query) && \strlen($query) > $this->queryMaxBytes) {
            $context->buildViolation('The query exceeds {{ limit }} MB. Import large FASTA files from the command line.')
                ->setParameter('{{ limit }}', (string) intdiv($this->queryMaxBytes, 1_048_576))
                ->addViolation();
        }
    }
}
