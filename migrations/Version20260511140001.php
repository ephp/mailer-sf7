<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511140001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Contact: add unsubscribe_reason column to record why a contact was unsubscribed (manual click vs auto bounce threshold)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact ADD COLUMN unsubscribe_reason TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact DROP COLUMN IF EXISTS unsubscribe_reason');
    }
}
