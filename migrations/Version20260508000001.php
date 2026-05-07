<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260508000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Campaign: add status and cloned_from_id columns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE campaign ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'draft'");
        $this->addSql('ALTER TABLE campaign ADD COLUMN cloned_from_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE campaign ADD CONSTRAINT fk_campaign_cloned_from FOREIGN KEY (cloned_from_id) REFERENCES campaign (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_campaign_cloned_from ON campaign (cloned_from_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_campaign_cloned_from');
        $this->addSql('ALTER TABLE campaign DROP CONSTRAINT IF EXISTS fk_campaign_cloned_from');
        $this->addSql('ALTER TABLE campaign DROP COLUMN IF EXISTS cloned_from_id');
        $this->addSql('ALTER TABLE campaign DROP COLUMN IF EXISTS status');
    }
}
