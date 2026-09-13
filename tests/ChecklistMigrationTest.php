<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20260912134243;
use DoctrineMigrations\Version20260912141000;
use DoctrineMigrations\Version20260912150000;
use DoctrineMigrations\Version20260913090000;
use DoctrineMigrations\Version20260913120000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ChecklistMigrationTest extends KernelTestCase
{
    public function testMigrationPreservesContentWithoutExpandingLegacyShareAccess(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertStringEndsWith('_test', $db->fetchOne('SELECT current_database()'));
        $db->beginTransaction();
        try {
            // Build the old schema in isolation; the transaction removes all fixtures and DDL afterwards.
            $schema = 'migration_check_'.bin2hex(random_bytes(6));
            $db->executeStatement('CREATE SCHEMA '.$schema);
            $db->executeStatement('SET LOCAL search_path TO '.$schema);
            foreach ([Version20260912134243::class, Version20260912141000::class, Version20260912150000::class] as $class) {
                $this->migrate(new $class($db, new NullLogger()), $db);
            }
            $db->executeStatement("INSERT INTO app_user (id, email, password, created_at) VALUES (1, 'migration@example.com', 'hash', now())");
            $db->executeStatement("INSERT INTO task (id, owner_id, title, description, completed, created_at, updated_at, priority) VALUES (1, 1, 'Project', 'Keep original notes', false, now(), now(), 15)");
            $db->executeStatement("INSERT INTO task (id, owner_id, parent_id, title, description, completed, created_at, updated_at) VALUES (2, 1, 1, 'Prepare', 'Keep these details', true, '2026-01-01', now()), (3, 1, 1, 'Review', '', false, '2026-01-02', now())");
            $db->executeStatement('INSERT INTO attachment (id, task_id, original_name, stored_name, mime_type, size, created_at) VALUES (1, 2, ?, ?, ?, 5, now())', ['notes.txt', str_repeat('a', 64), 'text/plain']);
            foreach ([1, 2] as $id) {
                $db->executeStatement("INSERT INTO task_share_link (id, task_id, token_hash, created_at, expires_at) VALUES (?, ?, ?, now(), now() + interval '24 hours')", [$id, $id, str_repeat((string) $id, 64)]);
            }
            $this->migrate(new Version20260913090000($db, new NullLogger()), $db);
            self::assertSame(1, (int) $db->fetchOne('SELECT count(*) FROM task'));
            self::assertSame(['Prepare', 'Review'], $db->fetchFirstColumn('SELECT title FROM checklist_item ORDER BY created_at, id'));
            self::assertSame([true, false], $db->fetchFirstColumn('SELECT completed FROM checklist_item ORDER BY created_at, id'));
            self::assertSame([1, 1], array_map('intval', $db->fetchFirstColumn('SELECT task_id FROM checklist_item ORDER BY id')));
            self::assertSame("Keep original notes\n\nPrepare\nKeep these details", $db->fetchOne('SELECT description FROM task WHERE id = 1'));
            self::assertSame(15, (int) $db->fetchOne('SELECT priority FROM task WHERE id = 1'));
            self::assertSame(1, (int) $db->fetchOne('SELECT task_id FROM attachment WHERE id = 1'));
            self::assertSame(str_repeat('a', 64), $db->fetchOne('SELECT stored_name FROM attachment WHERE id = 1'));
            self::assertSame([1], array_map('intval', $db->fetchFirstColumn('SELECT task_id FROM task_share_link')));
            self::assertSame(0, (int) $db->fetchOne("SELECT count(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = 'task' AND column_name = 'parent_id'", [$schema]));
            $db->executeStatement('DELETE FROM task WHERE id = 1');
            self::assertSame(0, (int) $db->fetchOne('SELECT count(*) FROM checklist_item'));
        } finally { $db->rollBack(); }
    }

    public function testRequiredBlockMigrationDeletesOnlyUngroupedTasksAndQueuesFiles(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertStringEndsWith('_test', $db->fetchOne('SELECT current_database()'));
        $db->beginTransaction();
        try {
            $schema = 'migration_blocks_'.bin2hex(random_bytes(6));
            $db->executeStatement('CREATE SCHEMA '.$schema);
            $db->executeStatement('SET LOCAL search_path TO '.$schema);
            foreach ([Version20260912134243::class, Version20260912141000::class, Version20260912150000::class, Version20260913090000::class] as $class) {
                $this->migrate(new $class($db, new NullLogger()), $db);
            }
            $db->executeStatement("INSERT INTO app_user (id, email, password, created_at) VALUES (1, 'migration@example.com', 'hash', now())");
            $db->executeStatement("INSERT INTO task_block (id, owner_id, title, created_at, updated_at) VALUES (1, 1, 'Keep block', now(), now())");
            $db->executeStatement("INSERT INTO task (id, owner_id, block_id, title, description, completed, created_at, updated_at, priority) VALUES (1, 1, NULL, 'Remove', '', false, now(), now(), 0), (2, 1, 1, 'Keep', 'Details', true, now(), now(), 12)");
            foreach ([1, 2] as $id) {
                $db->executeStatement("INSERT INTO checklist_item (task_id, title, completed, created_at) VALUES (?, 'Item', false, now())", [$id]);
                $db->executeStatement("INSERT INTO attachment (task_id, original_name, stored_name, mime_type, size, created_at) VALUES (?, 'file.txt', ?, 'text/plain', 5, now())", [$id, str_repeat((string) $id, 64)]);
                $db->executeStatement("INSERT INTO task_share_link (task_id, token_hash, created_at, expires_at) VALUES (?, ?, now(), now() + interval '24 hours')", [$id, str_repeat((string) $id, 64)]);
            }
            $this->migrate(new Version20260913120000($db, new NullLogger()), $db);
            self::assertSame([2], array_map('intval', $db->fetchFirstColumn('SELECT id FROM task')));
            self::assertSame('Details', $db->fetchOne('SELECT description FROM task'));
            self::assertSame(12, (int) $db->fetchOne('SELECT priority FROM task'));
            self::assertSame([str_repeat('1', 64)], $db->fetchFirstColumn('SELECT stored_name FROM pending_file_deletion'));
            foreach (['checklist_item', 'attachment', 'task_share_link'] as $table) {
                self::assertSame([2], array_map('intval', $db->fetchFirstColumn('SELECT task_id FROM '.$table)));
            }
            $db->executeStatement('SAVEPOINT required_block');
            try {
                $db->executeStatement('UPDATE task SET block_id = NULL WHERE id = 2');
                self::fail('Database accepted a task without a block.');
            } catch (\Doctrine\DBAL\Exception\NotNullConstraintViolationException) {
                $db->executeStatement('ROLLBACK TO SAVEPOINT required_block');
            }
            $db->executeStatement('DELETE FROM task_block WHERE id = 1');
            foreach (['task', 'checklist_item', 'attachment', 'task_share_link'] as $table) {
                self::assertSame(0, (int) $db->fetchOne('SELECT count(*) FROM '.$table));
            }
        } finally { $db->rollBack(); }
    }

    public function testPriorityMigrationKeepsTasksAndClearsOnlyOutOfRangeValues(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertStringEndsWith('_test', $db->fetchOne('SELECT current_database()'));
        $db->beginTransaction();
        try {
            $schema = 'migration_priority_'.bin2hex(random_bytes(6));
            $db->executeStatement('CREATE SCHEMA '.$schema);
            $db->executeStatement('SET LOCAL search_path TO '.$schema);
            foreach ([Version20260912134243::class, Version20260912141000::class, Version20260912150000::class, Version20260913090000::class, Version20260913120000::class] as $class) {
                $this->migrate(new $class($db, new NullLogger()), $db);
            }
            $db->executeStatement("INSERT INTO app_user (id, email, password, created_at) VALUES (1, 'migration@example.com', 'hash', now())");
            $db->executeStatement("INSERT INTO task_block (id, owner_id, title, created_at, updated_at) VALUES (1, 1, 'Block', now(), now())");
            foreach ([0, 1, 2, 3, 4, 5, 6, 2147483647] as $id => $priority) {
                $db->executeStatement("INSERT INTO task (id, owner_id, block_id, title, description, completed, created_at, updated_at, priority) VALUES (?, 1, 1, 'Keep', 'Details', false, now(), now(), ?)", [$id + 1, $priority]);
            }
            $this->migrate(new \DoctrineMigrations\Version20260913180000($db, new NullLogger()), $db);
            self::assertSame([null, 1, 2, 3, 4, 5, null, null], $db->fetchFirstColumn('SELECT priority FROM task ORDER BY id'));
            self::assertSame(8, (int) $db->fetchOne("SELECT count(*) FROM task WHERE title = 'Keep' AND description = 'Details'"));
            $db->executeStatement('UPDATE task SET priority = NULL WHERE id = 2');
            self::assertNull($db->fetchOne('SELECT priority FROM task WHERE id = 2'));
        } finally { $db->rollBack(); }
    }

    private function migrate(AbstractMigration $migration, Connection $db): void
    {
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
