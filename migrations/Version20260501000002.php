<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260501000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User-Account: change ManyToOne to OneToOne by adding UNIQUE constraint on user_account.account_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_account ADD CONSTRAINT uq_user_account_account_id UNIQUE (account_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_account DROP CONSTRAINT IF EXISTS uq_user_account_account_id');
    }
}
