<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511190001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MailList: add subscribe_token UUID (public token used for QR subscribe links)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mail_list ADD COLUMN subscribe_token UUID DEFAULT NULL');
        // Backfill existing rows with a v4 UUID — gen_random_uuid() is provided
        // by the pgcrypto extension (commonly already enabled).
        $this->addSql("CREATE EXTENSION IF NOT EXISTS pgcrypto");
        $this->addSql('UPDATE mail_list SET subscribe_token = gen_random_uuid() WHERE subscribe_token IS NULL');
        $this->addSql('ALTER TABLE mail_list ALTER COLUMN subscribe_token SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_mail_list_subscribe_token ON mail_list (subscribe_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS UNIQ_mail_list_subscribe_token');
        $this->addSql('ALTER TABLE mail_list DROP COLUMN IF EXISTS subscribe_token');
    }
}
