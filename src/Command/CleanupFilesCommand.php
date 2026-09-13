<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AttachmentManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:files:cleanup', description: 'Retry pending physical file deletions.')]
final class CleanupFilesCommand extends Command
{
    public function __construct(private AttachmentManager $attachments) { parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $failed = $this->attachments->cleanup();
        $output->writeln($failed === 0 ? 'Очередь удаления файлов обработана.' : "Не удалось удалить файлов: $failed");
        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
