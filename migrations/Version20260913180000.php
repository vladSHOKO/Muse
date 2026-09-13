<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913180000 extends AbstractMigration
{
    public function getDescription(): string { return 'Use optional priorities 1–5; clear legacy values outside this range without deleting tasks.'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task DROP CONSTRAINT task_priority_nonnegative');
        $this->addSql('ALTER TABLE task ALTER priority DROP NOT NULL');
        $this->addSql('UPDATE task SET priority = NULL WHERE priority NOT BETWEEN 1 AND 5');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT task_priority_range CHECK (priority BETWEEN 1 AND 5)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task DROP CONSTRAINT task_priority_range');
        $this->addSql('UPDATE task SET priority = 0 WHERE priority IS NULL');
        $this->addSql('ALTER TABLE task ALTER priority SET NOT NULL');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT task_priority_nonnegative CHECK (priority >= 0)');
    }
}
