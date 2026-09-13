<?php

declare(strict_types=1);

namespace App\Entity;

interface OwnedResource
{
    public function getOwner(): User;
}
