<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Durable cleanup queue: database deletion and filesystem deletion cannot share a transaction. */
#[ORM\Entity]
class PendingFileDeletion
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $storedName;

    public function __construct(string $storedName) { $this->storedName = $storedName; }
    public function getStoredName(): string { return $this->storedName; }
}
