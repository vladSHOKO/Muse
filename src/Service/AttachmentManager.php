<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Attachment;
use App\Entity\PendingFileDeletion;
use App\Entity\Task;
use App\Entity\TaskBlock;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class AttachmentManager
{
    public function __construct(
        private EntityManagerInterface $em,
        private FileStorage $storage,
        private ValidatorInterface $validator,
        private LoggerInterface $logger,
    ) {}

    /** @param list<UploadedFile> $files */
    public function upload(Task $task, array $files): void
    {
        $errors = $this->validator->validate($files, [new Assert\Count(min: 1, max: UploadPolicy::MAX_FILES), new Assert\All([UploadPolicy::fileConstraint()])]);
        if (count($errors) > 0) {
            throw new \InvalidArgumentException((string) $errors);
        }
        $stored = [];
        $entities = [];
        try {
            foreach ($files as $file) {
                $name = bin2hex(random_bytes(32));
                $original = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $file->getClientOriginalName()) ?? 'file', 0, 255);
                $attachment = new Attachment($task, $original ?: 'file', $name, $file->getMimeType() ?? 'application/octet-stream', $file->getSize());
                $stored[] = $name;
                $this->storage->put($file, $name);
                $entities[] = $attachment;
                $this->em->persist($attachment);
            }
            $task->touch();
            $this->em->flush();
        } catch (\Throwable $exception) {
            foreach ($entities as $entity) {
                if ($this->em->isOpen()) { $this->em->detach($entity); }
            }
            foreach ($stored as $name) {
                try { $this->storage->remove($name); }
                catch (\Throwable $cleanupError) { $this->logger->error('Failed to clean up interrupted upload.', ['exception' => $cleanupError]); }
            }
            throw $exception;
        }
    }

    public function deleteAttachment(Attachment $attachment): void
    {
        $this->em->persist(new PendingFileDeletion($attachment->getStoredName()));
        $attachment->getTask()->touch();
        $this->em->remove($attachment);
        $this->em->flush();
        $this->cleanup();
    }

    public function deleteTask(Task $task): void
    {
        $attachments = $this->em->createQueryBuilder()->select('a')->from(Attachment::class, 'a')
            ->join('a.task', 't')->where('t = :task')->setParameter('task', $task)->getQuery()->getResult();
        foreach ($attachments as $attachment) {
            $this->em->persist(new PendingFileDeletion($attachment->getStoredName()));
        }
        $this->em->remove($task);
        $this->em->flush(); // PostgreSQL cascades checklist items, attachments and share links atomically.
        // Discard managed objects removed by database cascades before the cleanup flush.
        $this->em->clear();
        $this->cleanup();
    }

    public function deleteBlock(TaskBlock $block): void
    {
        $this->em->wrapInTransaction(function () use ($block): void {
            // Prevent concurrent task creation and uploads while collecting file names.
            $this->em->getConnection()->fetchFirstColumn('SELECT id FROM task_block WHERE id = ? FOR UPDATE', [$block->getId()]);
            $this->em->getConnection()->fetchFirstColumn('SELECT id FROM task WHERE block_id = ? ORDER BY id FOR UPDATE', [$block->getId()]);
            $names = $this->em->getConnection()->fetchFirstColumn('SELECT a.stored_name FROM attachment a JOIN task t ON t.id = a.task_id WHERE t.block_id = ?', [$block->getId()]);
            foreach ($names as $name) { $this->em->persist(new PendingFileDeletion($name)); }
            $this->em->remove($block);
        }); // The queue and cascading deletion commit together, before physical cleanup.
        $this->em->clear();
        $this->cleanup();
    }

    public function cleanup(): int
    {
        $failed = 0;
        foreach ($this->em->getRepository(PendingFileDeletion::class)->findAll() as $pending) {
            try {
                $this->storage->remove($pending->getStoredName());
                $this->em->remove($pending);
            } catch (\Throwable $exception) {
                ++$failed;
                $this->logger->error('File deletion will be retried.', ['exception' => $exception]);
            }
        }
        $this->em->flush();
        return $failed;
    }
}
