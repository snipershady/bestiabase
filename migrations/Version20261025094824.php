<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261025094824 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Email verification: user.email_verified_at (existing users are trusted and marked as verified).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD email_verified_at DATETIME DEFAULT NULL');
        // Gli utenti già presenti li ha creati un amministratore: l'indirizzo è fidato.
        $this->addSql('UPDATE `user` SET email_verified_at = created_at');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP email_verified_at');
    }
}
