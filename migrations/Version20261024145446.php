<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024145446 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gap openings per HSP, for the tabular export (outfmt 6/7).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blast_hsp ADD gap_opens INT UNSIGNED DEFAULT 0 NOT NULL');
        // Righe già importate: aperture di gap = sequenze di '-' consecutivi in query e soggetto.
        $this->addSql("UPDATE blast_hsp SET gap_opens = (CHAR_LENGTH(REGEXP_REPLACE(query_sequence, '-+', '#')) - CHAR_LENGTH(REPLACE(REGEXP_REPLACE(query_sequence, '-+', '#'), '#', ''))) + (CHAR_LENGTH(REGEXP_REPLACE(hit_sequence, '-+', '#')) - CHAR_LENGTH(REPLACE(REGEXP_REPLACE(hit_sequence, '-+', '#'), '#', '')))");
        $this->addSql('ALTER TABLE blast_hsp ALTER gap_opens DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blast_hsp DROP gap_opens');
    }
}
