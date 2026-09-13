<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\UploadPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Validation;

final class UploadPolicyTest extends TestCase
{
    public function testFileAtLimitIsAcceptedAndOneByteOverIsRejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'task-limit-');
        $handle = fopen($path, 'wb');
        fwrite($handle, "%PDF-1.4\n");
        ftruncate($handle, UploadPolicy::MAX_BYTES);
        fclose($handle);
        try {
            $validator = Validation::createValidator();
            self::assertCount(0, $validator->validate(new UploadedFile($path, 'document.pdf', test: true), UploadPolicy::fileConstraint()));
            $handle = fopen($path, 'ab');
            fwrite($handle, 'x');
            fclose($handle);
            clearstatcache(true, $path);
            $violations = $validator->validate(new UploadedFile($path, 'document.pdf', test: true), UploadPolicy::fileConstraint());
            self::assertGreaterThan(0, count($violations));
            self::assertSame('Файл превышает ограничение 50 МБ.', $violations[0]->getMessage());
        } finally { unlink($path); }
    }

    public function testRenamedExecutableIsRejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'task-type-');
        file_put_contents($path, '<?php echo "not a photo";');
        try {
            $violations = Validation::createValidator()->validate(new UploadedFile($path, 'photo.jpg', test: true), UploadPolicy::fileConstraint());
            self::assertGreaterThan(0, count($violations));
        } finally { unlink($path); }
    }
}
