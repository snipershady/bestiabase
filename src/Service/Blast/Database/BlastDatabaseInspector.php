<?php

declare(strict_types=1);

namespace App\Service\Blast\Database;

use App\Enum\MolType;
use App\Service\Blast\BlastBinaryLocator;
use App\Service\Blast\BlastProcessExecutor;
use App\Service\Blast\BlastTool;
use App\Service\Blast\Exception\BlastExecutionException;
use App\Service\Blast\Exception\DatabaseImportException;
use JsonException;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierServiceInterface;

/**
 * Legge i metadati dei volumi BLAST (`blastdbcmd -metadata`, JSON) e così verifica che il
 * database sia utilizzabile.
 */
final readonly class BlastDatabaseInspector
{
    public function __construct(
        private BlastBinaryLocator $locator,
        private BlastProcessExecutor $executor,
        private EffectivePrimitiveTypeIdentifierServiceInterface $input,
    ) {
    }

    /**
     * @throws BlastExecutionException se blastdbcmd non riesce ad aprire il database
     * @throws DatabaseImportException se l'output non è il JSON atteso
     */
    public function metadata(string $databasePath, MolType $molType): BlastDatabaseMetadata
    {
        $result = $this->executor->execute(BlastTool::BlastDbCmd->value, [
            $this->locator->tool(BlastTool::BlastDbCmd),
            '-db', $databasePath,
            '-dbtype', $molType->value,
            '-metadata',
        ], \dirname($databasePath));

        if (!json_validate($result->output)) {
            throw DatabaseImportException::invalidMetadata($databasePath, 'blastdbcmd did not return JSON');
        }

        try {
            $metadata = json_decode($result->output, true, 16, \JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw DatabaseImportException::invalidMetadata($databasePath, $jsonException->getMessage());
        }

        if (!\is_array($metadata) || !isset($metadata['number-of-sequences'], $metadata['number-of-letters'])) {
            throw DatabaseImportException::invalidMetadata($databasePath, 'missing sequence or letter count');
        }

        return new BlastDatabaseMetadata(
            $this->input->getIntValueFromArray('number-of-sequences', $metadata),
            $this->input->getIntValueFromArray('number-of-letters', $metadata),
            $this->input->getIntValueFromArray('bytes-total', $metadata),
        );
    }
}
