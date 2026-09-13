<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add non-negative numeric priority to root tasks only; preserve existing tasks with priority zero.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task ADD priority INT DEFAULT NULL');
        $this->addSql('UPDATE task SET priority = 0 WHERE parent_id IS NULL');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT root_task_priority CHECK ((parent_id IS NULL AND priority IS NOT NULL AND priority >= 0) OR (parent_id IS NOT NULL AND priority IS NULL))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task DROP CONSTRAINT root_task_priority');
        $this->addSql('ALTER TABLE task DROP priority');
    }
}
