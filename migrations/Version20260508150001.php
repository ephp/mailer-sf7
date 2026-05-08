<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260508150001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CampaignEmail: add unsubscribe_token (UUID) and missing indices';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE campaign_email ADD COLUMN unsubscribe_token UUID DEFAULT NULL');
        $this->addSql('UPDATE campaign_email SET unsubscribe_token = gen_random_uuid() WHERE unsubscribe_token IS NULL');
        $this->addSql('ALTER TABLE campaign_email ALTER COLUMN unsubscribe_token SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_campaign_email_unsubscribe_token ON campaign_email (unsubscribe_token)');
        $this->addSql('CREATE INDEX idx_campaign_email_status ON campaign_email (status)');
        $this->addSql('CREATE INDEX idx_campaign_email_tracking_open_id ON campaign_email (tracking_open_id)');
        $this->addSql('DROP INDEX IF EXISTS IDX_A997181BF639F774');
        $this->addSql('CREATE INDEX idx_campaign_email_campaign_id ON campaign_email (campaign_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_campaign_email_unsubscribe_token');
        $this->addSql('DROP INDEX IF EXISTS uniq_campaign_email_unsubscribe_token');
        $this->addSql('DROP INDEX IF EXISTS idx_campaign_email_status');
        $this->addSql('DROP INDEX IF EXISTS idx_campaign_email_tracking_open_id');
        $this->addSql('DROP INDEX IF EXISTS idx_campaign_email_campaign_id');
        $this->addSql('CREATE INDEX IDX_A997181BF639F774 ON campaign_email (campaign_id)');
        $this->addSql('ALTER TABLE campaign_email DROP COLUMN IF EXISTS unsubscribe_token');
    }
}
