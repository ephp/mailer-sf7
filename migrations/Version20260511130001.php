<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511130001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add link_click_event table to audit every hit on /t/click endpoint (IP, UA, counted/skipped reason)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE link_click_event_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE link_click_event (
            id INT NOT NULL,
            link_stat_id INT NOT NULL,
            clicked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent TEXT DEFAULT NULL,
            counted BOOLEAN DEFAULT true NOT NULL,
            skip_reason VARCHAR(64) DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX idx_link_click_event_link_stat_id ON link_click_event (link_stat_id)');
        $this->addSql('CREATE INDEX idx_link_click_event_clicked_at ON link_click_event (clicked_at)');
        $this->addSql('ALTER TABLE link_click_event ADD CONSTRAINT FK_link_click_event_link_stat
            FOREIGN KEY (link_stat_id) REFERENCES link_stat (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS link_click_event');
        $this->addSql('DROP SEQUENCE IF EXISTS link_click_event_id_seq');
    }
}
