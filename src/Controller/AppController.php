<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\OwnedResource;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;

abstract class AppController extends AbstractController
{
    protected function user(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) { throw $this->createAccessDeniedException(); }
        return $user;
    }

    protected function requireOwner(OwnedResource $resource): void
    {
        if (!$this->isGranted('OWNER', $resource)) { throw $this->createNotFoundException(); }
    }

    protected function requireCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Форма устарела. Обновите страницу и повторите действие.');
        }
    }
}
