<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260509120001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CampaignEmail: add html column for audit/debug of the rendered email body';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE campaign_email ADD COLUMN html TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE campaign_email DROP COLUMN IF EXISTS html');
    }
}
