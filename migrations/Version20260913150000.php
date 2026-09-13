<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913150000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add optional task deadline with time zone.'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task ADD deadline TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task DROP deadline');
    }
}
