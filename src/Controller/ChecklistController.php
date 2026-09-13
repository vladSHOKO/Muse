<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ChecklistItem;
use App\Entity\Task;
use App\Service\ChecklistForms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ChecklistController extends AppController
{
    #[Route('/tasks/{id}/checklist', name: 'app_checklist_add', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function add(Task $task, Request $request, ChecklistForms $forms, EntityManagerInterface $em): Response
    {
        $this->requireOwner($task);
        $form = $forms->create($task);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($form->getData());
            $task->touch();
            $em->flush();
            return $this->back($task, $form->get('context')->getData());
        }
        return $this->render('task/checklist_form.html.twig', ['task' => $task, 'form' => $form], new Response(status: 422));
    }

    #[Route('/checklist/{id}/complete', name: 'app_checklist_complete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function complete(ChecklistItem $item, Request $request, EntityManagerInterface $em): Response
    {
        $this->requireOwner($item);
        $this->requireCsrf($request, 'checklist-complete'.$item->getId());
        $completed = $request->request->getString('completed');
        if (!in_array($completed, ['0', '1'], true)) { throw new BadRequestHttpException('Invalid completion state.'); }
        $item->setCompleted($completed === '1');
        $task = $item->getTask();
        $task->touch();
        $em->flush();
        if ($request->isXmlHttpRequest()) {
            return $this->json(['completed' => $item->isCompleted(), 'completedCount' => $task->getChecklistCompletedCount(), 'totalCount' => $task->getChecklist()->count()]);
        }
        return $this->back($task, $request->request->getString('context'));
    }

    #[Route('/checklist/{id}/delete', name: 'app_checklist_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(ChecklistItem $item, Request $request, EntityManagerInterface $em): Response
    {
        $this->requireOwner($item);
        $this->requireCsrf($request, 'checklist-delete'.$item->getId());
        $task = $item->getTask();
        $task->touch();
        $em->remove($item);
        $em->flush();
        return $this->back($task, $request->request->getString('context'));
    }

    private function back(Task $task, string $context): Response
    {
        if ($context === 'overview') {
            return $this->redirect($this->generateUrl('app_tasks').'#checklist-'.$task->getId(), Response::HTTP_SEE_OTHER);
        }
        $url = $context === 'block'
            ? $this->generateUrl('app_tasks', ['block' => $task->getBlock()->getId(), 'status' => $task->isCompleted() ? 'completed' : null])
            : $this->generateUrl('app_task_show', ['id' => $task->getId()]);
        return $this->redirect($url.'#checklist-'.$task->getId(), Response::HTTP_SEE_OTHER);
    }
}
