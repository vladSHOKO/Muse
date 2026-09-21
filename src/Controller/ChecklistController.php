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
use Symfony\Component\Validator\Validator\ValidatorInterface;

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

    #[Route('/checklist/{id}/edit', name: 'app_checklist_edit', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function edit(ChecklistItem $item, Request $request, EntityManagerInterface $em, ValidatorInterface $validator): Response
    {
        $this->requireOwner($item);
        $this->requireCsrf($request, 'checklist-edit'.$item->getId());
        $context = $request->request->getString('context');
        if (!in_array($context, ['task', 'block', 'overview'], true)) {
            throw new BadRequestHttpException('Invalid checklist context.');
        }
        $originalTitle = $item->getTitle();
        $item->setTitle($request->request->getString('title'));
        $errors = $validator->validate($item);
        if (count($errors) > 0) {
            $item->setTitle($originalTitle);
            $message = $errors->get(0)->getMessage();
            if ($request->isXmlHttpRequest()) {
                return $this->json(['error' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->addFlash('error', $message);
            return $this->back($item->getTask(), $context);
        }
        $item->getTask()->touch();
        $em->flush();
        if ($request->isXmlHttpRequest()) {
            return $this->json(['title' => $item->getTitle()]);
        }
        return $this->back($item->getTask(), $context);
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
        return $this->redirect($this->backUrl($task, $context), Response::HTTP_SEE_OTHER);
    }

    private function backUrl(Task $task, string $context): string
    {
        if ($context === 'overview') {
            return $this->generateUrl('app_tasks').'#checklist-'.$task->getId();
        }
        $url = $context === 'block'
            ? $this->generateUrl('app_tasks', ['block' => $task->getBlock()->getId(), 'status' => $task->isCompleted() ? 'completed' : null])
            : $this->generateUrl('app_task_show', ['id' => $task->getId()]);
        return $url.'#checklist-'.$task->getId();
    }
}
