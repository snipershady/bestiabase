<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\Admin\UserAdminData;
use App\Form\Admin\UserAdminType;
use App\Repository\BlastDatabaseRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/users')]
final class UserAdminController extends AbstractController
{
    public function __construct(
        private readonly BlastDatabaseRepository $databases,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'app_admin_users', methods: ['GET'])]
    public function index(UserRepository $users): Response
    {
        return $this->render('admin/user/index.html.twig', ['users' => $users->findAllByEmail(), 'restricted' => $this->databases->findRestricted()]);
    }

    #[Route('/new', name: 'app_admin_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request, UserRepository $users, ClockInterface $clock): Response
    {
        $data = new UserAdminData();
        $form = $this->createForm(UserAdminType::class, $data, ['creating' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = (string) $data->email;
            $username = trim((string) $data->username);

            if ($users->findOneBy(['email' => $email]) instanceof User || $users->findOneBy(['username' => $username]) instanceof User) {
                $form->addError(new FormError('A user with this email or username already exists.'));
            } elseif ($email !== '' && $username !== '') {
                $user = new User($email, $username, 'not-set', $clock->now());
                $this->save($user, $data);
                $this->addFlash('success', \sprintf('User "%s" created.', $email));

                return $this->redirectToRoute('app_admin_users');
            }
        }

        return $this->userFormPage($form, null);
    }

    #[Route('/{id}', name: 'app_admin_user_edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit(#[MapEntity(id: 'id')] User $user, Request $request): Response
    {
        $data = UserAdminData::fromUser($user, $this->databases->findRestricted());
        $form = $this->createForm(UserAdminType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Un amministratore non può togliersi il ruolo o disattivarsi: resterebbe senza accesso.
            if ($user === $this->getUser() && (!$data->admin || !$data->active)) {
                $form->addError(new FormError('You cannot remove your own administrator role or disable your own account.'));
            } else {
                $this->save($user, $data);
                $this->addFlash('success', \sprintf('User "%s" updated.', $user->getEmail()));

                return $this->redirectToRoute('app_admin_users');
            }
        }

        return $this->userFormPage($form, $user);
    }

    private function save(User $user, UserAdminData $data): void
    {
        if ($data->password !== null && $data->password !== '') {
            $user->changePassword($this->passwordHasher->hashPassword($user, $data->password));
        }

        $data->applyTo($user, $this->databases->findRestricted());
        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }

    /**
     * Nome volutamente diverso da "renderForm": una regola di Rector lo scambierebbe per il metodo
     * deprecato di AbstractController e lo rinominerebbe in render().
     *
     * @param FormInterface<UserAdminData> $form
     */
    private function userFormPage(FormInterface $form, ?User $user): Response
    {
        return $this->render('admin/user/form.html.twig', ['form' => $form, 'user' => $user], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
