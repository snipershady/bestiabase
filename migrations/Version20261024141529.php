<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024141529 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BLAST+ version used by each job.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blast_job ADD blast_version VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blast_job DROP blast_version');
    }
}
