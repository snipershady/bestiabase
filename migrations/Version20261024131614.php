<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024131614 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: users, BLAST database registry, jobs, results (queries, hits, HSPs), subject sequences, downloads, Messenger queue.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE blast_database (id INT UNSIGNED AUTO_INCREMENT NOT NULL, description LONGTEXT DEFAULT NULL, source_fasta VARCHAR(512) DEFAULT NULL, active TINYINT NOT NULL, public TINYINT NOT NULL, code VARCHAR(128) NOT NULL, title VARCHAR(255) NOT NULL, mol_type VARCHAR(4) NOT NULL, path VARCHAR(512) NOT NULL, created_at DATETIME NOT NULL, created_by INT UNSIGNED DEFAULT NULL, INDEX idx_blast_db_moltype (mol_type, active), UNIQUE INDEX uq_blast_db_code (code), INDEX IDX_24E3771DE12AB56 (created_by), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE blast_database_program (program VARCHAR(16) NOT NULL, blast_database_id INT UNSIGNED NOT NULL, INDEX IDX_A409A45732110BF6 (blast_database_id), PRIMARY KEY (blast_database_id, program)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE blast_download (id INT UNSIGNED AUTO_INCREMENT NOT NULL, sequence_type VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL, job_id BINARY(16) NOT NULL, user_id INT UNSIGNED DEFAULT NULL, INDEX IDX_B307B32FBE04EA9 (job_id), INDEX IDX_B307B32FA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE blast_download_item (download_id INT UNSIGNED NOT NULL, hit_id INT UNSIGNED NOT NULL, INDEX IDX_C4CFE75AC667AEAB (download_id), INDEX IDX_C4CFE75ABC1A1241 (hit_id), PRIMARY KEY (download_id, hit_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE blast_hit (id INT UNSIGNED AUTO_INCREMENT NOT NULL, hsp_count SMALLINT UNSIGNED NOT NULL, best_bit_score DOUBLE PRECISION DEFAULT NULL, best_evalue VARCHAR(64) DEFAULT NULL, identity_percentage DOUBLE PRECISION DEFAULT NULL, total_identity INT UNSIGNED NOT NULL, total_align_length INT UNSIGNED NOT NULL, hit_rank INT UNSIGNED NOT NULL, subject_name VARCHAR(512) NOT NULL, subject_length INT UNSIGNED NOT NULL, subject_accession VARCHAR(255) DEFAULT NULL, subject_title LONGTEXT DEFAULT NULL, job_id BINARY(16) NOT NULL, query_id INT UNSIGNED NOT NULL, INDEX idx_bh_job_query (job_id, query_id), INDEX idx_bh_job_subject (job_id, subject_name), INDEX idx_bh_filter (job_id, query_id, best_bit_score), INDEX IDX_890096EEBE04EA9 (job_id), INDEX IDX_890096EEEF946F99 (query_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE blast_hsp (id INT UNSIGNED AUTO_INCREMENT NOT NULL, hsp_rank SMALLINT UNSIGNED NOT NULL, bit_score DOUBLE PRECISION NOT NULL, raw_score DOUBLE PRECISION NOT NULL, e_value VARCHAR(64) NOT NULL, identity INT UNSIGNED NOT NULL, positive INT UNSIGNED NOT NULL, gaps INT UNSIGNED NOT NULL, align_length INT UNSIGNED NOT NULL, query_from INT UNSIGNED NOT NULL, query_to INT UNSIGNED NOT NULL, hit_from INT UNSIGNED NOT NULL, hit_to INT UNSIGNED NOT NULL, query_frame SMALLINT NOT NULL, hit_frame SMALLINT NOT NULL, query_sequence MEDIUMTEXT NOT NULL, midline MEDIUMTEXT NOT NULL, hit_sequence MEDIUMTEXT NOT NULL, hit_id INT UNSIGNED NOT NULL, INDEX idx_bhsp_hit (hit_id, hsp_rank), INDEX IDX_3E40A82CBC1A1241 (hit_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE blast_job (status VARCHAR(16) NOT NULL, num_queries INT UNSIGNED DEFAULT NULL, num_hits INT UNSIGNED NOT NULL, error_message LONGTEXT DEFAULT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, id BINARY(16) NOT NULL, program VARCHAR(16) NOT NULL, search_type VARCHAR(16) NOT NULL, output_format VARCHAR(32) NOT NULL, parameters JSON NOT NULL, database_snapshot JSON NOT NULL, query_fasta MEDIUMTEXT NOT NULL, created_at DATETIME NOT NULL, subject_fasta MEDIUMTEXT DEFAULT NULL, user_id INT UNSIGNED DEFAULT NULL, INDEX idx_job_status (status, created_at), INDEX idx_job_user (user_id, created_at), INDEX IDX_280A5057A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE blast_query (id INT UNSIGNED AUTO_INCREMENT NOT NULL, position SMALLINT UNSIGNED NOT NULL, name VARCHAR(512) NOT NULL, length INT UNSIGNED NOT NULL, definition LONGTEXT DEFAULT NULL, job_id BINARY(16) NOT NULL, UNIQUE INDEX uq_bq_job_pos (job_id, position), INDEX IDX_C6437593BE04EA9 (job_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE subject_sequence (id INT UNSIGNED AUTO_INCREMENT NOT NULL, length INT UNSIGNED NOT NULL, name VARCHAR(512) NOT NULL, sequence MEDIUMTEXT NOT NULL, definition LONGTEXT DEFAULT NULL, blast_database_id INT UNSIGNED DEFAULT NULL, job_id BINARY(16) DEFAULT NULL, UNIQUE INDEX uq_ss_database_name (blast_database_id, name), UNIQUE INDEX uq_ss_job_name (job_id, name), INDEX IDX_6AFB974332110BF6 (blast_database_id), INDEX IDX_6AFB9743BE04EA9 (job_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `user` (id INT UNSIGNED AUTO_INCREMENT NOT NULL, roles JSON NOT NULL, active TINYINT NOT NULL, last_login_at DATETIME DEFAULT NULL, email VARCHAR(180) NOT NULL, username VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uq_user_email (email), UNIQUE INDEX uq_user_username (username), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE blast_database ADD CONSTRAINT FK_24E3771DE12AB56 FOREIGN KEY (created_by) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE blast_database_program ADD CONSTRAINT FK_A409A45732110BF6 FOREIGN KEY (blast_database_id) REFERENCES blast_database (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE blast_download ADD CONSTRAINT FK_B307B32FBE04EA9 FOREIGN KEY (job_id) REFERENCES blast_job (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE blast_download ADD CONSTRAINT FK_B307B32FA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE blast_download_item ADD CONSTRAINT FK_C4CFE75AC667AEAB FOREIGN KEY (download_id) REFERENCES blast_download (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE blast_download_item ADD CONSTRAINT FK_C4CFE75ABC1A1241 FOREIGN KEY (hit_id) REFERENCES blast_hit (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE blast_hit ADD CONSTRAINT FK_890096EEBE04EA9 FOREIGN KEY (job_id) REFERENCES blast_job (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE blast_hit ADD CONSTRAINT FK_890096EEEF946F99 FOREIGN KEY (query_id) REFERENCES blast_query (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE blast_hsp ADD CONSTRAINT FK_3E40A82CBC1A1241 FOREIGN KEY (hit_id) REFERENCES blast_hit (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE blast_job ADD CONSTRAINT FK_280A5057A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE blast_query ADD CONSTRAINT FK_C6437593BE04EA9 FOREIGN KEY (job_id) REFERENCES blast_job (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE subject_sequence ADD CONSTRAINT FK_6AFB974332110BF6 FOREIGN KEY (blast_database_id) REFERENCES blast_database (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE subject_sequence ADD CONSTRAINT FK_6AFB9743BE04EA9 FOREIGN KEY (job_id) REFERENCES blast_job (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blast_database DROP FOREIGN KEY FK_24E3771DE12AB56');
        $this->addSql('ALTER TABLE blast_database_program DROP FOREIGN KEY FK_A409A45732110BF6');
        $this->addSql('ALTER TABLE blast_download DROP FOREIGN KEY FK_B307B32FBE04EA9');
        $this->addSql('ALTER TABLE blast_download DROP FOREIGN KEY FK_B307B32FA76ED395');
        $this->addSql('ALTER TABLE blast_download_item DROP FOREIGN KEY FK_C4CFE75AC667AEAB');
        $this->addSql('ALTER TABLE blast_download_item DROP FOREIGN KEY FK_C4CFE75ABC1A1241');
        $this->addSql('ALTER TABLE blast_hit DROP FOREIGN KEY FK_890096EEBE04EA9');
        $this->addSql('ALTER TABLE blast_hit DROP FOREIGN KEY FK_890096EEEF946F99');
        $this->addSql('ALTER TABLE blast_hsp DROP FOREIGN KEY FK_3E40A82CBC1A1241');
        $this->addSql('ALTER TABLE blast_job DROP FOREIGN KEY FK_280A5057A76ED395');
        $this->addSql('ALTER TABLE blast_query DROP FOREIGN KEY FK_C6437593BE04EA9');
        $this->addSql('ALTER TABLE subject_sequence DROP FOREIGN KEY FK_6AFB974332110BF6');
        $this->addSql('ALTER TABLE subject_sequence DROP FOREIGN KEY FK_6AFB9743BE04EA9');
        $this->addSql('DROP TABLE blast_database');
        $this->addSql('DROP TABLE blast_database_program');
        $this->addSql('DROP TABLE blast_download');
        $this->addSql('DROP TABLE blast_download_item');
        $this->addSql('DROP TABLE blast_hit');
        $this->addSql('DROP TABLE blast_hsp');
        $this->addSql('DROP TABLE blast_job');
        $this->addSql('DROP TABLE blast_query');
        $this->addSql('DROP TABLE subject_sequence');
        $this->addSql('DROP TABLE `user`');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
