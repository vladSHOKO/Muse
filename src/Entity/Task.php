<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity, ORM\HasLifecycleCallbacks]
class Task implements OwnedResource
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank(message: 'Введите название задачи.'), Assert\Length(max: 200)]
    private string $title = '';

    #[ORM\Column(type: 'text')]
    #[Assert\Length(max: 50000)]
    private string $description = '';

    #[ORM\Column]
    private bool $completed = false;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1, max: 5, notInRangeMessage: 'Приоритет должен быть целым числом от {{ min }} до {{ max }}.')]
    private ?int $priority = null;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $deadline = null;

    /** @var Collection<int, ChecklistItem> */
    #[ORM\OneToMany(targetEntity: ChecklistItem::class, mappedBy: 'task')]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $checklist;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Выберите блок задач.')]
    private ?TaskBlock $block = null;

    /** @var Collection<int, Attachment> */
    #[ORM\OneToMany(targetEntity: Attachment::class, mappedBy: 'task')]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $attachments;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $owner)
    {
        $this->owner = $owner;
        $this->checklist = new ArrayCollection();
        $this->attachments = new ArrayCollection();
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int { return $this->id; }
    public function getOwner(): User { return $this->owner; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): void { $this->title = trim($title); }
    public function getDescription(): string { return $this->description; }
    public function setDescription(?string $description): void { $this->description = $description ?? ''; }
    public function isCompleted(): bool { return $this->completed; }
    public function setCompleted(bool $completed): void { $this->completed = $completed; }
    public function getPriority(): ?int { return $this->priority; }
    public function setPriority(?int $priority): void { $this->priority = $priority; }
    public function getDeadline(): ?\DateTimeImmutable { return $this->deadline?->setTimezone(new \DateTimeZone('UTC')); }
    public function setDeadline(?\DateTimeImmutable $deadline): void
    {
        $this->deadline = $deadline?->setTimezone(new \DateTimeZone('UTC'));
    }
    public function isOverdue(\DateTimeImmutable $now): bool
    {
        return !$this->completed && $this->deadline !== null && $now >= $this->deadline;
    }
    public function getChecklist(): Collection { return $this->checklist; }
    public function getChecklistCompletedCount(): int
    {
        return $this->checklist->filter(static fn (ChecklistItem $item) => $item->isCompleted())->count();
    }

    public function getAttachments(): Collection { return $this->attachments; }
    public function getBlock(): ?TaskBlock { return $this->block; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function setBlock(?TaskBlock $block): void
    {
        if ($block !== null && !$this->owner->isSame($block->getOwner())) {
            throw new \DomainException('Нельзя назначить чужой блок.');
        }
        $this->block = $block;
    }

    #[ORM\PreUpdate]
    public function touch(): void { $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); }
}
