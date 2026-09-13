<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Task;
use App\Entity\TaskShareLink;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final class ShareService
{
    public function __construct(private EntityManagerInterface $em, private ClockInterface $clock) {}

    /** @return array{link: TaskShareLink, token: string} */
    public function create(Task $task): array
    {
        $token = bin2hex(random_bytes(32));
        $link = new TaskShareLink($task, hash('sha256', $token), $this->clock->now());
        $this->em->persist($link);
        $this->em->flush();
        return ['link' => $link, 'token' => $token];
    }

    public function resolve(string $token): ?TaskShareLink
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) { return null; }
        $link = $this->em->getRepository(TaskShareLink::class)->findOneBy(['tokenHash' => hash('sha256', $token)]);
        return $link !== null && $link->isActive($this->clock->now()) ? $link : null;
    }

    public function revoke(TaskShareLink $link): void
    {
        $link->revoke($this->clock->now());
        $this->em->flush();
    }
}
