<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260512100001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Account.privacy_policy + Contact.privacy_accepted_at (GDPR consent tracking)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account ADD COLUMN privacy_policy TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE contact ADD COLUMN privacy_accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP COLUMN IF EXISTS privacy_policy');
        $this->addSql('ALTER TABLE contact DROP COLUMN IF EXISTS privacy_accepted_at');
    }
}
