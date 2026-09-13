<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Task;
use App\Entity\TaskBlock;
use App\Entity\TaskShareLink;
use App\Form\TaskType;
use App\Form\UploadType;
use App\Service\AttachmentManager;
use App\Service\ChecklistForms;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TaskController extends AppController
{
    #[Route('/', name: 'app_tasks', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $em, ChecklistForms $checklistForms, ClockInterface $clock): Response
    {
        $blocks = $em->getRepository(TaskBlock::class)->findBy(['owner' => $this->user()], ['title' => 'ASC']);
        $choices = [];
        foreach ($blocks as $block) { $choices[(string) $block->getId()] = $block; }
        $selected = $request->query->has('block') ? $request->query->getString('block') : null;
        if ($selected !== null && !array_key_exists($selected, $choices)) {
            throw $this->createNotFoundException();
        }
        $selectedBlock = $selected !== null ? $choices[$selected] : null;

        $status = $request->query->getString('status', 'open');
        if (!in_array($status, ['open', 'completed'], true)) { throw $this->createNotFoundException(); }
        $completedCounts = [];
        $counts = [];
        $rows = $em->createQueryBuilder()->select('IDENTITY(t.block) AS blockId, COUNT(t.id) AS taskCount, SUM(CASE WHEN t.completed = true THEN 1 ELSE 0 END) AS completedCount')
            ->from(Task::class, 't')->where('t.owner = :owner')
            ->setParameter('owner', $this->user())->groupBy('t.block')->getQuery()->getArrayResult();
        foreach ($rows as $row) {
            $counts[$row['blockId']] = (int) $row['taskCount'];
            $completedCounts[$row['blockId']] = (int) $row['completedCount'];
        }

        $query = $em->createQueryBuilder()->select('t', 'c')->from(Task::class, 't')
            ->leftJoin('t.checklist', 'c')
            ->where('t.owner = :owner')->setParameter('owner', $this->user())
            ->addSelect('CASE WHEN t.priority IS NULL THEN 1 ELSE 0 END AS HIDDEN noPriority')
            ->orderBy('noPriority', 'ASC')->addOrderBy('t.priority', 'ASC')->addOrderBy('t.createdAt', 'DESC')->addOrderBy('t.id', 'DESC')
            ->addOrderBy('c.createdAt', 'ASC')->addOrderBy('c.id', 'ASC');
        if ($selectedBlock !== null) {
            $query->andWhere('t.block = :block')->setParameter('block', $selectedBlock)
                ->andWhere('t.completed = :completed')->setParameter('completed', $status === 'completed');
            $tasks = $query->getQuery()->getResult();
        } else {
            // Limit tasks per block before joining checklists: every selected task keeps all its items.
            $ids = $em->getConnection()->fetchFirstColumn(<<<'SQL'
SELECT id FROM (
    SELECT id, ROW_NUMBER() OVER (PARTITION BY block_id ORDER BY priority ASC NULLS LAST, created_at DESC, id DESC) AS position
    FROM task WHERE owner_id = :owner AND completed = false AND block_id IS NOT NULL
) ranked WHERE position <= 3
SQL, ['owner' => $this->user()->getId()]);
            $tasks = $ids === [] ? [] : $query->andWhere('t.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult();
        }
        $previews = [];
        foreach ($tasks as $task) { $previews[$task->getBlock()->getId()][] = $task; }
        $context = $selected === null ? 'overview' : 'block';
        return $this->render($selected === null ? 'task/overview.html.twig' : 'task/index.html.twig', [
            'blocks' => $blocks, 'counts' => $counts, 'selected' => $selected, 'selected_block' => $selectedBlock,
            'previews' => $previews, 'tasks' => $tasks, 'total' => $counts[$selected] ?? 0, 'status' => $status, 'now' => $clock->now(),
            'checklist_forms' => array_combine(
                array_map(static fn (Task $task) => $task->getId(), $tasks),
                array_map(static fn (Task $task) => $checklistForms->create($task, $context)->createView(), $tasks),
            ),
            'completed' => $completedCounts[$selected] ?? 0, 'completed_counts' => $completedCounts,
        ]);
    }

    #[Route('/tasks/new', name: 'app_task_new', methods: ['GET', 'POST'])]
    public function create(Request $request, EntityManagerInterface $em): Response
    {
        $task = new Task($this->user());
        if ($request->query->has('block')) {
            $blockId = $request->query->getString('block');
            if (!preg_match('/\A[1-9][0-9]*\z/', $blockId)
                || filter_var($blockId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]) === false) {
                throw $this->createNotFoundException();
            }
            $block = $em->getRepository(TaskBlock::class)->findOneBy(['id' => $blockId, 'owner' => $this->user()]);
            if ($block === null) { throw $this->createNotFoundException(); }
            $task->setBlock($block);
        } elseif ($em->getRepository(TaskBlock::class)->count(['owner' => $this->user()]) === 0) {
            $this->addFlash('success', 'Сначала создайте блок для задач.');
            return $this->redirectToRoute('app_block_new', status: Response::HTTP_SEE_OTHER);
        }
        return $this->editForm($task, $request, $em, 'Новая задача');
    }

    #[Route('/tasks/{id}/edit', name: 'app_task_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Task $task, Request $request, EntityManagerInterface $em): Response
    {
        $this->requireOwner($task);
        return $this->editForm($task, $request, $em, 'Редактирование задачи');
    }

    private function editForm(Task $task, Request $request, EntityManagerInterface $em, string $title): Response
    {
        $form = $this->createForm(TaskType::class, $task);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($task);
            $em->flush();
            $this->addFlash('success', 'Задача сохранена.');
            return $this->redirectToRoute('app_task_show', ['id' => $task->getId()], Response::HTTP_SEE_OTHER);
        }
        return $this->render('task/form.html.twig', ['form' => $form, 'task' => $task, 'title' => $title]);
    }

    #[Route('/tasks/{id}', name: 'app_task_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Task $task, EntityManagerInterface $em, ClockInterface $clock, ChecklistForms $checklistForms): Response
    {
        $this->requireOwner($task);
        return $this->render('task/show.html.twig', [
            'task' => $task,
            'checklist_form' => $checklistForms->create($task)->createView(),
            'upload_form' => $this->createForm(UploadType::class, null, ['action' => $this->generateUrl('app_attachment_upload', ['id' => $task->getId()])]),
            'shares' => $em->getRepository(TaskShareLink::class)->findBy(['task' => $task], ['createdAt' => 'DESC']),
            'now' => $clock->now(),
        ]);
    }

    #[Route('/tasks/{id}/toggle', name: 'app_task_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(Task $task, Request $request, EntityManagerInterface $em): Response
    {
        $this->requireOwner($task);
        $this->requireCsrf($request, 'toggle'.$task->getId());
        $completed = $request->request->getString('completed');
        if ($request->request->has('completed') && !in_array($completed, ['0', '1'], true)) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Invalid completion state.');
        }
        $task->setCompleted($request->request->has('completed') ? $completed === '1' : !$task->isCompleted());
        $em->flush();
        $context = $request->request->getString('context');
        if ($context === 'overview' || $context === 'block') {
            return $this->redirectToRoute('app_tasks', $context === 'block' ? ['block' => $task->getBlock()->getId()] : [], Response::HTTP_SEE_OTHER);
        }
        return $this->redirectToRoute('app_task_show', ['id' => $task->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/tasks/{id}/delete', name: 'app_task_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Task $task, Request $request, AttachmentManager $attachments): Response
    {
        $this->requireOwner($task);
        $this->requireCsrf($request, 'delete-task'.$task->getId());
        $blockId = $task->getBlock()->getId();
        $attachments->deleteTask($task);
        $this->addFlash('success', 'Задача удалена вместе с её вложениями и ссылками.');
        return $this->redirectToRoute('app_tasks', ['block' => $blockId], Response::HTTP_SEE_OTHER);
    }
}
