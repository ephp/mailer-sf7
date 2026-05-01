<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260501000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Account entity: add emailContatto, mailerDsn, logo, batchSize, sendInterval, timestamps; change indirizzo to TEXT';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account ADD COLUMN email_contatto VARCHAR(255) NOT NULL DEFAULT \'\'');
        $this->addSql('ALTER TABLE account ADD COLUMN mailer_dsn TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE account ADD COLUMN logo_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE account ADD COLUMN batch_size INT NOT NULL DEFAULT 50');
        $this->addSql('ALTER TABLE account ADD COLUMN send_interval INT NOT NULL DEFAULT 30');
        $this->addSql('ALTER TABLE account ADD COLUMN created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE account ADD COLUMN updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE account ALTER COLUMN indirizzo TYPE TEXT');
        $this->addSql('ALTER TABLE account DROP COLUMN IF EXISTS indirizzo_old');
        $this->addSql('ALTER TABLE account ALTER COLUMN email_contatto DROP DEFAULT');
        $this->addSql('ALTER TABLE account ADD CONSTRAINT fk_account_logo FOREIGN KEY (logo_id) REFERENCES upload (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_account_logo ON account (logo_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP CONSTRAINT IF EXISTS fk_account_logo');
        $this->addSql('DROP INDEX IF EXISTS idx_account_logo');
        $this->addSql('ALTER TABLE account DROP COLUMN IF EXISTS email_contatto');
        $this->addSql('ALTER TABLE account DROP COLUMN IF EXISTS mailer_dsn');
        $this->addSql('ALTER TABLE account DROP COLUMN IF EXISTS logo_id');
        $this->addSql('ALTER TABLE account DROP COLUMN IF EXISTS batch_size');
        $this->addSql('ALTER TABLE account DROP COLUMN IF EXISTS send_interval');
        $this->addSql('ALTER TABLE account DROP COLUMN IF EXISTS created_at');
        $this->addSql('ALTER TABLE account DROP COLUMN IF EXISTS updated_at');
        $this->addSql('ALTER TABLE account ALTER COLUMN indirizzo TYPE VARCHAR(500)');
    }
}
