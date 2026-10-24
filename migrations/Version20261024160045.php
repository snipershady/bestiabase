<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024160045 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Users authorized to search restricted BLAST databases.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE blast_database_access (blast_database_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, INDEX IDX_E1F00EFE32110BF6 (blast_database_id), INDEX IDX_E1F00EFEA76ED395 (user_id), PRIMARY KEY (blast_database_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE blast_database_access ADD CONSTRAINT FK_E1F00EFE32110BF6 FOREIGN KEY (blast_database_id) REFERENCES blast_database (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE blast_database_access ADD CONSTRAINT FK_E1F00EFEA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blast_database_access DROP FOREIGN KEY FK_E1F00EFE32110BF6');
        $this->addSql('ALTER TABLE blast_database_access DROP FOREIGN KEY FK_E1F00EFEA76ED395');
        $this->addSql('DROP TABLE blast_database_access');
    }
}
