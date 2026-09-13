<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Task;
use App\Entity\TaskBlock;
use App\Entity\TaskShareLink;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class DomainTest extends TestCase
{
    public function testDeadlineBoundaryAndCompletedTasks(): void
    {
        $task = new Task(new User('deadline@example.com'));
        $deadline = new \DateTimeImmutable('2026-09-13T18:30:00+03:00');
        self::assertFalse($task->isOverdue($deadline));
        $task->setDeadline($deadline);
        self::assertSame('2026-09-13 15:30', $task->getDeadline()->format('Y-m-d H:i'));
        self::assertFalse($task->isOverdue($deadline->modify('-1 microsecond')));
        self::assertTrue($task->isOverdue($deadline));
        self::assertTrue($task->isOverdue($deadline->modify('+1 day')));
        $task->setCompleted(true);
        self::assertFalse($task->isOverdue($deadline));
        $task->setCompleted(false);
        self::assertTrue($task->isOverdue($deadline));
        $task->setDeadline(null);
        self::assertFalse($task->isOverdue($deadline));
    }

    public function testEmailNormalization(): void
    {
        self::assertSame('person@example.com', (new User('  Person@EXAMPLE.com '))->getEmail());
    }

    public function testForeignBlockIsRejected(): void
    {
        $task = new Task(new User('one@example.com'));
        $this->expectException(\DomainException::class);
        $task->setBlock(new TaskBlock(new User('other@example.com')));
    }

    public function testShareExpiresExactlyAt24HoursAcrossDst(): void
    {
        $created = new \DateTimeImmutable('2026-10-24 12:00:00', new \DateTimeZone('Europe/Berlin'));
        $link = new TaskShareLink(new Task(new User('one@example.com')), str_repeat('a', 64), $created);
        self::assertSame(86400, $link->getExpiresAt()->getTimestamp() - $created->getTimestamp());
        self::assertTrue($link->isActive($link->getExpiresAt()->modify('-1 microsecond')));
        self::assertFalse($link->isActive($link->getExpiresAt()));
        self::assertFalse($link->isActive($link->getExpiresAt()->modify('+1 second')));
    }

    public function testShareScopeAndRevocation(): void
    {
        $user = new User('one@example.com');
        $task = new Task($user);
        $other = new Task($user);
        $now = new \DateTimeImmutable('2026-09-12T10:00:00Z');
        $link = new TaskShareLink($task, str_repeat('a', 64), $now);
        self::assertTrue($link->allows($task));
        self::assertFalse($link->allows($other));
        $link->revoke($now);
        self::assertFalse($link->isActive($now));
    }

    public function testChecklistCompletionDoesNotChangeTaskState(): void
    {
        $user = new User('one@example.com');
        $task = new Task($user);
        $item = new \App\Entity\ChecklistItem($task);
        $item->setTitle('  Собрать материалы  ');
        self::assertSame('Собрать материалы', $item->getTitle());
        self::assertSame($user, $item->getOwner());
        $item->setCompleted(true);
        self::assertTrue($item->isCompleted());
        self::assertFalse($task->isCompleted());
        $task->setCompleted(true);
        $item->setCompleted(false);
        self::assertFalse($item->isCompleted());
        self::assertTrue($task->isCompleted());
        self::assertNull($task->getPriority());
        $task->setPriority(2);
        self::assertSame(2, $task->getPriority());
    }
}
