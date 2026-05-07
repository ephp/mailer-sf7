<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260506000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MailList: add use_custom_dsn flag, smtp fields, mail_from override; widen mailer_dsn_override to TEXT';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mail_list ADD COLUMN use_custom_dsn BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE mail_list ADD COLUMN smtp_host VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE mail_list ADD COLUMN smtp_port INT DEFAULT NULL');
        $this->addSql('ALTER TABLE mail_list ADD COLUMN smtp_user VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE mail_list ADD COLUMN smtp_password VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE mail_list ADD COLUMN smtp_encryption VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE mail_list ADD COLUMN mail_from VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE mail_list ADD COLUMN mail_from_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE mail_list ALTER COLUMN mailer_dsn_override TYPE TEXT');
        $this->addSql('ALTER TABLE mail_list ALTER COLUMN use_custom_dsn DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mail_list DROP COLUMN IF EXISTS use_custom_dsn');
        $this->addSql('ALTER TABLE mail_list DROP COLUMN IF EXISTS smtp_host');
        $this->addSql('ALTER TABLE mail_list DROP COLUMN IF EXISTS smtp_port');
        $this->addSql('ALTER TABLE mail_list DROP COLUMN IF EXISTS smtp_user');
        $this->addSql('ALTER TABLE mail_list DROP COLUMN IF EXISTS smtp_password');
        $this->addSql('ALTER TABLE mail_list DROP COLUMN IF EXISTS smtp_encryption');
        $this->addSql('ALTER TABLE mail_list DROP COLUMN IF EXISTS mail_from');
        $this->addSql('ALTER TABLE mail_list DROP COLUMN IF EXISTS mail_from_name');
        $this->addSql('ALTER TABLE mail_list ALTER COLUMN mailer_dsn_override TYPE VARCHAR(500)');
    }
}
