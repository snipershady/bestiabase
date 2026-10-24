<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024135942 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BLAST database statistics from blastdbcmd -metadata: sequence count and total length.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blast_database ADD sequence_count INT UNSIGNED DEFAULT NULL, ADD total_length BIGINT UNSIGNED DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blast_database DROP sequence_count, DROP total_length');
    }
}
