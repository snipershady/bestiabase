<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261025091936 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Password reset requests; the "active" flag of users becomes the ROLE_DISABLED role.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE reset_password_request (id INT UNSIGNED AUTO_INCREMENT NOT NULL, selector VARCHAR(20) NOT NULL, hashed_token VARCHAR(100) NOT NULL, requested_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, user_id INT UNSIGNED NOT NULL, INDEX IDX_7CE748AA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('ALTER TABLE reset_password_request ADD CONSTRAINT FK_7CE748AA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        // Gli utenti già disattivati diventano ROLE_DISABLED prima di perdere la colonna.
        $this->addSql("UPDATE `user` SET roles = JSON_ARRAY_APPEND(roles, '$', 'ROLE_DISABLED') WHERE active = 0");
        $this->addSql('ALTER TABLE `user` DROP active');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reset_password_request DROP FOREIGN KEY FK_7CE748AA76ED395');
        $this->addSql('DROP TABLE reset_password_request');
        $this->addSql('ALTER TABLE `user` ADD active TINYINT DEFAULT 1 NOT NULL');
        $this->addSql("UPDATE `user` SET active = 0 WHERE JSON_CONTAINS(roles, '\"ROLE_DISABLED\"')");
        $this->addSql("UPDATE `user` SET roles = JSON_REMOVE(roles, JSON_UNQUOTE(JSON_SEARCH(roles, 'one', 'ROLE_DISABLED'))) WHERE active = 0");
    }
}
