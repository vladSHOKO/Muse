<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ChecklistItem;
use App\Entity\Task;
use App\Form\ChecklistItemType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ChecklistForms
{
    public function __construct(private FormFactoryInterface $forms, private UrlGeneratorInterface $urls) {}

    public function create(Task $task, string $context = 'task'): FormInterface
    {
        return $this->forms->createNamed('checklist_'.$task->getId(), ChecklistItemType::class, new ChecklistItem($task), [
            'action' => $this->urls->generate('app_checklist_add', ['id' => $task->getId()]),
            'context' => $context,
        ]);
    }
}
