<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Index(name: 'idx_checklist_task', columns: ['task_id'])]
class ChecklistItem implements OwnedResource
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'checklist'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Task $task;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank(message: 'Введите текст пункта.'), Assert\Length(max: 200)]
    private string $title = '';

    #[ORM\Column]
    private bool $completed = false;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Task $task)
    {
        $this->task = $task;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int { return $this->id; }
    public function getTask(): Task { return $this->task; }
    public function getOwner(): User { return $this->task->getOwner(); }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): void { $this->title = trim($title); }
    public function isCompleted(): bool { return $this->completed; }
    public function setCompleted(bool $completed): void { $this->completed = $completed; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
