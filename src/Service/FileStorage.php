<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class FileStorage
{
    public function __construct(private string $directory) {}

    public function put(UploadedFile $file, string $name): void
    {
        (new Filesystem())->mkdir($this->directory, 0700);
        $file->move($this->directory, $name);
        (new Filesystem())->chmod($this->path($name), 0600);
    }

    public function path(string $name): string
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $name)) {
            throw new \InvalidArgumentException('Invalid storage name.');
        }
        return $this->directory.'/'.$name;
    }

    public function remove(string $name): void { (new Filesystem())->remove($this->path($name)); }
}
