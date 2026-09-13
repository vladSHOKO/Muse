<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Validator\Constraints as Assert;

final class UploadPolicy
{
    public const MAX_BYTES = 50_000_000;
    public const MAX_FILES = 5;
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'mp4', 'webm', 'mov'];

    public static function fileConstraint(): Assert\File
    {
        return new Assert\File(
            maxSize: self::MAX_BYTES,
            extensions: self::EXTENSIONS,
            maxSizeMessage: 'Файл превышает ограничение 50 МБ.',
            extensionsMessage: 'Формат или содержимое файла не поддерживается.',
            uploadIniSizeErrorMessage: 'Файл превышает допустимый размер загрузки.',
        );
    }
}
