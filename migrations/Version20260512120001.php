<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260512120001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Account.api_rate_limit — per-account API rate limit (default 60 req/min)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account ADD COLUMN api_rate_limit INT NOT NULL DEFAULT 60');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP COLUMN IF EXISTS api_rate_limit');
    }
}
