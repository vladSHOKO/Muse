<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\TaskBlock;
use App\Form\BlockType;
use App\Service\AttachmentManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BlockController extends AppController
{
    #[Route('/blocks/new', name: 'app_block_new', methods: ['GET', 'POST'])]
    public function create(Request $request, EntityManagerInterface $em): Response
    {
        return $this->editForm(new TaskBlock($this->user()), $request, $em);
    }

    #[Route('/blocks/{id}/edit', name: 'app_block_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(TaskBlock $block, Request $request, EntityManagerInterface $em): Response
    {
        $this->requireOwner($block);
        return $this->editForm($block, $request, $em);
    }

    private function editForm(TaskBlock $block, Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(BlockType::class, $block);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($block);
            $em->flush();
            $this->addFlash('success', 'Блок сохранён.');
            return $this->redirectToRoute('app_tasks', ['block' => $block->getId()], Response::HTTP_SEE_OTHER);
        }
        return $this->render('block/form.html.twig', ['form' => $form, 'block' => $block]);
    }

    #[Route('/blocks/{id}/delete', name: 'app_block_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(TaskBlock $block, Request $request, AttachmentManager $attachments): Response
    {
        $this->requireOwner($block);
        $this->requireCsrf($request, 'delete-block'.$block->getId());
        $attachments->deleteBlock($block);
        $this->addFlash('success', 'Блок удалён вместе с задачами, чек-листами, вложениями и ссылками.');
        return $this->redirectToRoute('app_tasks', status: Response::HTTP_SEE_OTHER);
    }
}
