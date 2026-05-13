<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260508120001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MailList: add default colors and font family for new campaigns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mail_list ADD COLUMN default_primary_color VARCHAR(7) DEFAULT NULL');
        $this->addSql('ALTER TABLE mail_list ADD COLUMN default_text_color VARCHAR(7) DEFAULT NULL');
        $this->addSql('ALTER TABLE mail_list ADD COLUMN default_heading_font VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE mail_list ADD COLUMN default_body_font VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mail_list DROP COLUMN IF EXISTS default_primary_color');
        $this->addSql('ALTER TABLE mail_list DROP COLUMN IF EXISTS default_text_color');
        $this->addSql('ALTER TABLE mail_list DROP COLUMN IF EXISTS default_heading_font');
        $this->addSql('ALTER TABLE mail_list DROP COLUMN IF EXISTS default_body_font');
    }
}
