<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024162303 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove unused schema: subject_sequence table, blast_job.output_format, blast_hit total_identity/total_align_length.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subject_sequence DROP FOREIGN KEY `FK_6AFB974332110BF6`');
        $this->addSql('ALTER TABLE subject_sequence DROP FOREIGN KEY `FK_6AFB9743BE04EA9`');
        $this->addSql('DROP TABLE subject_sequence');
        $this->addSql('ALTER TABLE blast_hit DROP total_identity, DROP total_align_length');
        $this->addSql('ALTER TABLE blast_job DROP output_format');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("CREATE TABLE subject_sequence (id INT UNSIGNED AUTO_INCREMENT NOT NULL, length INT UNSIGNED NOT NULL, name VARCHAR(512) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`, sequence MEDIUMTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`, definition LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`, blast_database_id INT UNSIGNED DEFAULT NULL, job_id BINARY(16) DEFAULT NULL, INDEX IDX_6AFB974332110BF6 (blast_database_id), INDEX IDX_6AFB9743BE04EA9 (job_id), UNIQUE INDEX uq_ss_database_name (blast_database_id, name), UNIQUE INDEX uq_ss_job_name (job_id, name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = '' ");
        $this->addSql('ALTER TABLE subject_sequence ADD CONSTRAINT `FK_6AFB974332110BF6` FOREIGN KEY (blast_database_id) REFERENCES blast_database (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE subject_sequence ADD CONSTRAINT `FK_6AFB9743BE04EA9` FOREIGN KEY (job_id) REFERENCES blast_job (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        // Rollback: valori validi per le righe esistenti (gli aggregati non erano letti da nessuno).
        $this->addSql('ALTER TABLE blast_hit ADD total_identity INT UNSIGNED DEFAULT 0 NOT NULL, ADD total_align_length INT UNSIGNED DEFAULT 0 NOT NULL');
        $this->addSql("ALTER TABLE blast_job ADD output_format VARCHAR(32) DEFAULT 'pairwise' NOT NULL");
    }
}
