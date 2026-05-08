<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260508160001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Contact: add data_disiscrizione column (US-001)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact ADD COLUMN data_disiscrizione TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact DROP COLUMN IF EXISTS data_disiscrizione');
    }
}
