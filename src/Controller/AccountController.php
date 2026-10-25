<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Account\ChangePasswordData;
use App\Service\Account\ChangePasswordType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Pagine dell'utente autenticato. Anche un account non ancora abilitato può cambiare password.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/account')]
final class AccountController extends AbstractController
{
    #[Route('/password', name: 'app_account_password', methods: ['GET', 'POST'])]
    public function password(
        Request $request,
        #[CurrentUser]
        User $user,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        Security $security,
    ): Response {
        $data = new ChangePasswordData();
        $form = $this->createForm(ChangePasswordType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->changePassword($passwordHasher->hashPassword($user, (string) $data->password));
            $entityManager->flush();

            // Con la password cambiata Symfony invalida la sessione corrente: si rinnova il token.
            $security->login($user, 'form_login', 'main');
            $this->addFlash('success', 'Your password has been changed.');

            return $this->redirectToRoute('app_account_password');
        }

        return $this->render('account/password.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
