<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260505000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Account: add mail_from and mail_from_name (sender email/name)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE account ADD COLUMN mail_from VARCHAR(255) NOT NULL DEFAULT ''");
        $this->addSql("ALTER TABLE account ADD COLUMN mail_from_name VARCHAR(255) NOT NULL DEFAULT ''");
        $this->addSql('UPDATE account SET mail_from = email_contatto WHERE mail_from = \'\'');
        $this->addSql('UPDATE account SET mail_from_name = ragione_sociale WHERE mail_from_name = \'\'');
        $this->addSql('ALTER TABLE account ALTER COLUMN mail_from DROP DEFAULT');
        $this->addSql('ALTER TABLE account ALTER COLUMN mail_from_name DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP COLUMN IF EXISTS mail_from');
        $this->addSql('ALTER TABLE account DROP COLUMN IF EXISTS mail_from_name');
    }
}
