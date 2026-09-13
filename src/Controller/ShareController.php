<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Attachment;
use App\Entity\Task;
use App\Entity\TaskShareLink;
use App\Service\FileStorage;
use App\Service\ShareService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ShareController extends AppController
{
    #[Route('/tasks/{id}/shares', name: 'app_share_create', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function create(Task $task, Request $request, ShareService $shares): Response
    {
        $this->requireOwner($task);
        $this->requireCsrf($request, 'share'.$task->getId());
        $created = $shares->create($task);
        // The raw token is shown only in this response and is never stored in the session or database.
        return $this->render('share/created.html.twig', [
            'task' => $task, 'link' => $created['link'],
            'url' => $this->generateUrl('app_share_show', ['token' => $created['token']], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);
    }

    #[Route('/shares/{id}/revoke', name: 'app_share_revoke', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function revoke(TaskShareLink $link, Request $request, ShareService $shares): Response
    {
        $this->requireOwner($link);
        $this->requireCsrf($request, 'revoke'.$link->getId());
        $shares->revoke($link);
        $this->addFlash('success', 'Ссылка отозвана. Доступ по ней закрыт.');
        return $this->redirectToRoute('app_task_show', ['id' => $link->getTask()->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/s/{token}', name: 'app_share_show', methods: ['GET'])]
    public function show(string $token, ShareService $shares, \Psr\Clock\ClockInterface $clock): Response
    {
        $link = $shares->resolve($token);
        if ($link === null) { return $this->invalid(); }
        return $this->render('share/show.html.twig', ['link' => $link, 'task' => $link->getTask(), 'token' => $token, 'now' => $clock->now()]);
    }

    #[Route('/s/{token}/attachments/{id}', name: 'app_share_download', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function download(string $token, int $id, ShareService $shares, EntityManagerInterface $em, FileStorage $storage): Response
    {
        $link = $shares->resolve($token);
        if ($link === null) { return $this->invalid(); }
        $attachment = $em->find(Attachment::class, $id);
        if ($attachment === null || !$link->allows($attachment->getTask())) { return $this->invalid(); }
        $path = $storage->path($attachment->getStoredName());
        if (!is_file($path)) { return $this->invalid(); }
        $response = $this->file($path, $attachment->getOriginalName());
        $response->headers->set('Content-Type', 'application/octet-stream');
        return $response;
    }

    private function invalid(): Response
    {
        return $this->render('share/invalid.html.twig', [], new Response(status: Response::HTTP_NOT_FOUND));
    }
}
