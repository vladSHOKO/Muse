<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class TaskShareLink implements OwnedResource
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Task $task;

    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(Task $task, string $tokenHash, \DateTimeImmutable $now)
    {
        $this->task = $task;
        $this->tokenHash = $tokenHash;
        $this->createdAt = $now->setTimezone(new \DateTimeZone('UTC'));
        $this->expiresAt = $this->createdAt->add(new \DateInterval('PT24H'));
    }

    public function getId(): ?int { return $this->id; }
    public function getTask(): Task { return $this->task; }
    public function getOwner(): User { return $this->task->getOwner(); }
    public function getTokenHash(): string { return $this->tokenHash; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getRevokedAt(): ?\DateTimeImmutable { return $this->revokedAt; }
    public function revoke(\DateTimeImmutable $now): void { $this->revokedAt ??= $now; }

    public function isActive(\DateTimeImmutable $now): bool
    {
        return $this->revokedAt === null && $now < $this->expiresAt;
    }

    public function allows(Task $task): bool
    {
        return $task === $this->task || ($task->getId() !== null && $task->getId() === $this->task->getId());
    }
}
