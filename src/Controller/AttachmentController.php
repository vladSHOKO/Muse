<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Attachment;
use App\Entity\Task;
use App\Form\UploadType;
use App\Service\AttachmentManager;
use App\Service\FileStorage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AttachmentController extends AppController
{
    #[Route('/tasks/{id}/attachments', name: 'app_attachment_upload', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function upload(Task $task, Request $request, AttachmentManager $attachments, LoggerInterface $logger): Response
    {
        $this->requireOwner($task);
        $form = $this->createForm(UploadType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $attachments->upload($task, $form->get('files')->getData());
                $this->addFlash('success', 'Файлы прикреплены.');
                return $this->redirectToRoute('app_task_show', ['id' => $task->getId()], Response::HTTP_SEE_OTHER);
            } catch (\Throwable $exception) {
                $logger->error('Upload failed.', ['exception' => $exception]);
                $form->addError(new FormError('Не удалось сохранить файлы. Попробуйте ещё раз.'));
            }
        }
        return $this->render('task/upload.html.twig', ['task' => $task, 'form' => $form], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/attachments/{id}/download', name: 'app_attachment_download', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function download(Attachment $attachment, FileStorage $storage): Response
    {
        $this->requireOwner($attachment);
        $path = $storage->path($attachment->getStoredName());
        if (!is_file($path)) { throw $this->createNotFoundException(); }
        $response = $this->file($path, $attachment->getOriginalName());
        $response->headers->set('Content-Type', 'application/octet-stream');
        return $response;
    }

    #[Route('/attachments/{id}/delete', name: 'app_attachment_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Attachment $attachment, Request $request, AttachmentManager $attachments): Response
    {
        $this->requireOwner($attachment);
        $this->requireCsrf($request, 'delete-file'.$attachment->getId());
        $taskId = $attachment->getTask()->getId();
        $attachments->deleteAttachment($attachment);
        $this->addFlash('success', 'Файл удалён.');
        return $this->redirectToRoute('app_task_show', ['id' => $taskId], Response::HTTP_SEE_OTHER);
    }
}
