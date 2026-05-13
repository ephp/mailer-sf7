<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260513120001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CampaignAttachment: campaign-level email file attachments';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE campaign_attachment_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE campaign_attachment (
            id INT NOT NULL,
            campaign_id INT NOT NULL,
            upload_id INT NOT NULL,
            filename VARCHAR(255) NOT NULL,
            size INT NOT NULL,
            mimetype VARCHAR(100) DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX idx_campaign_attachment_campaign_id ON campaign_attachment (campaign_id)');
        $this->addSql('ALTER TABLE campaign_attachment ADD CONSTRAINT FK_campaign_attachment_campaign
            FOREIGN KEY (campaign_id) REFERENCES campaign (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE campaign_attachment ADD CONSTRAINT FK_campaign_attachment_upload
            FOREIGN KEY (upload_id) REFERENCES upload (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS campaign_attachment');
        $this->addSql('DROP SEQUENCE IF EXISTS campaign_attachment_id_seq');
    }
}
