<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $hasher): Response
    {
        if ($this->getUser()) { return $this->redirectToRoute('app_tasks'); }
        $form = $this->createForm(RegistrationType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $user = new User($data['email']);
            $duplicate = $em->getRepository(User::class)->findOneBy(['email' => $user->getEmail()]) !== null;
            if (!$duplicate) {
                $user->setPassword($hasher->hashPassword($user, $data['password']));
                try {
                    $em->persist($user);
                    $em->flush();
                    $this->addFlash('success', 'Аккаунт создан. Войдите с вашей почтой и паролем.');
                    return $this->redirectToRoute('app_login', [], Response::HTTP_SEE_OTHER);
                } catch (UniqueConstraintViolationException) {
                    $duplicate = true;
                }
            }
            if ($duplicate) { $form->get('email')->addError(new FormError('Эта почта уже зарегистрирована.')); }
        }
        return $this->render('security/register.html.twig', ['form' => $form]);
    }

    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authentication): Response
    {
        if ($this->getUser()) { return $this->redirectToRoute('app_tasks'); }
        return $this->render('security/login.html.twig', [
            'last_username' => $authentication->getLastUsername(),
            'error' => $authentication->getLastAuthenticationError(),
        ]);
    }

    #[Route('/logout', name: 'app_logout', methods: ['POST'])]
    public function logout(): never { throw new \LogicException('Handled by the security firewall.'); }
}
