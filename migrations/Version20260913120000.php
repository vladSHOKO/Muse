<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Require a block for every task; delete legacy ungrouped tasks and cascade block deletion.';
    }

    public function up(Schema $schema): void
    {
        // The user explicitly approved discarding ungrouped development data.
        $this->addSql('INSERT INTO pending_file_deletion (stored_name) SELECT a.stored_name FROM attachment a JOIN task t ON t.id = a.task_id WHERE t.block_id IS NULL ON CONFLICT (stored_name) DO NOTHING');
        $this->addSql('DELETE FROM task WHERE block_id IS NULL');
        $this->addSql('ALTER TABLE task ALTER block_id SET NOT NULL');
        $this->addSql('ALTER TABLE task DROP CONSTRAINT FK_527EDB25E9ED820C');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_527EDB25E9ED820C FOREIGN KEY (block_id) REFERENCES task_block (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Deleted ungrouped tasks cannot be recovered automatically.');
    }
}
