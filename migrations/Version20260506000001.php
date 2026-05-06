<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260506000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MailList: add unsubscribe_text, deleted_at, timestamps, unique_name_account constraint';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mail_list ADD COLUMN unsubscribe_text TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE mail_list ADD COLUMN deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("ALTER TABLE mail_list ADD COLUMN created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()");
        $this->addSql("ALTER TABLE mail_list ADD COLUMN updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()");
        $this->addSql('ALTER TABLE mail_list ALTER COLUMN created_at DROP DEFAULT');
        $this->addSql('ALTER TABLE mail_list ALTER COLUMN updated_at DROP DEFAULT');
        $this->addSql('ALTER TABLE mail_list ADD CONSTRAINT unique_name_account UNIQUE (name, account_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mail_list DROP CONSTRAINT IF EXISTS unique_name_account');
        $this->addSql('ALTER TABLE mail_list DROP COLUMN IF EXISTS unsubscribe_text');
        $this->addSql('ALTER TABLE mail_list DROP COLUMN IF EXISTS deleted_at');
        $this->addSql('ALTER TABLE mail_list DROP COLUMN IF EXISTS created_at');
        $this->addSql('ALTER TABLE mail_list DROP COLUMN IF EXISTS updated_at');
    }
}
