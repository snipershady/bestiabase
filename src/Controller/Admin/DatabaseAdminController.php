<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\BlastDatabase;
use App\Form\Admin\DatabaseAdminData;
use App\Form\Admin\DatabaseAdminType;
use App\Repository\BlastDatabaseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Registro dei database BLAST (ex viroblast.ini). L'import dei FASTA resta da CLI
 * (`app:blast:import-db`): dal web si gestiscono visibilità, programmi e utenti autorizzati.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/databases')]
final class DatabaseAdminController extends AbstractController
{
    #[Route('', name: 'app_admin_databases', methods: ['GET'])]
    public function index(BlastDatabaseRepository $databases): Response
    {
        return $this->render('admin/database/index.html.twig', ['databases' => $databases->findAllByTitle()]);
    }

    #[Route('/{id}', name: 'app_admin_database_edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit(#[MapEntity(id: 'id')] BlastDatabase $database, Request $request, EntityManagerInterface $entityManager): Response
    {
        $data = DatabaseAdminData::fromDatabase($database);
        $form = $this->createForm(DatabaseAdminType::class, $data, ['mol_type' => $database->getMolType()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data->applyTo($database);
            $entityManager->flush();
            $this->addFlash('success', \sprintf('Database "%s" updated.', $database->getCode()));

            return $this->redirectToRoute('app_admin_databases');
        }

        return $this->render('admin/database/edit.html.twig', ['database' => $database, 'form' => $form], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
