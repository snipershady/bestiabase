<?php

declare(strict_types=1);

namespace App\Service\Blast\Job;

use App\Entity\BlastJob;
use App\Enum\SearchType;
use App\Exception\IncompatibleProgramException;
use App\Service\Blast\BlastCommandBuilder;
use App\Service\Blast\Exception\DatabaseUnavailableException;
use App\Service\Blast\Exception\InvalidFastaException;
use App\Service\Blast\Exception\InvalidSearchParametersException;
use App\Service\Blast\Fasta\FastaNormalizer;
use App\Service\Blast\Search\SearchParameters;
use App\Service\Blast\Search\SearchParametersNormalizer;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Factory\TimeBasedUuidFactory;

/**
 * Crea un job pronto da eseguire: input FASTA normalizzati, parametri verificati per il
 * programma, database coerenti. Tutto ciò che può essere rifiutato lo è qui, all'invio.
 */
final readonly class BlastJobFactory
{
    public function __construct(
        private FastaNormalizer $fastaNormalizer,
        private SearchParametersNormalizer $parametersNormalizer,
        private BlastCommandBuilder $commandBuilder,
        private TimeBasedUuidFactory $uuidFactory,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws InvalidFastaException            query o soggetto non validi
     * @throws InvalidSearchParametersException parametri non supportati o nessun target
     * @throws IncompatibleProgramException     database non abilitato per il programma
     * @throws DatabaseUnavailableException     database non salvato, disattivato o non accessibile all'utente
     */
    public function create(BlastSearchRequest $request): BlastJob
    {
        $program = $request->program;

        if ($request->databases === [] && $request->subjectFasta === null) {
            throw InvalidSearchParametersException::noTarget();
        }

        $snapshot = [];

        foreach ($request->databases as $database) {
            if (!$request->trusted && !$database->isSearchableBy($request->user)) {
                throw DatabaseUnavailableException::notSearchable($database->getCode());
            }

            if (!$database->supports($program)) {
                throw IncompatibleProgramException::forDatabase($program, $database->getMolType());
            }

            $id = $database->getId() ?? throw DatabaseUnavailableException::notPersisted($database->getCode());
            $snapshot[] = ['id' => $id, 'code' => $database->getCode(), 'title' => $database->getTitle()];
        }

        if ($request->parameters instanceof SearchParameters) {
            $this->commandBuilder->assertSupported($program, $request->parameters);
        }

        $subjectFasta = $request->subjectFasta === null
            ? null
            : $this->fastaNormalizer->normalize($request->subjectFasta, $program->databaseMolType())->toFasta();

        return new BlastJob(
            $this->uuidFactory->create(),
            $program,
            $request->parameters instanceof SearchParameters ? SearchType::Advanced : SearchType::Basic,
            $request->parameters instanceof SearchParameters ? $this->parametersNormalizer->normalize($request->parameters) : [],
            $snapshot,
            $this->fastaNormalizer->normalize($request->queryFasta, $program->queryMolType())->toFasta(),
            $this->clock->now(),
            $subjectFasta,
            $request->user,
        );
    }
}
