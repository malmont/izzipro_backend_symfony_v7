<?php

namespace App\Controller\TransporteurController;

use App\Entity\Transporteur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class TransporteurController extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/api/transporteurs', name: 'get_transporteurs', methods: ['GET'])]
    public function getTransporteurs(): JsonResponse
    {
        $transporteurs = $this->entityManager->getRepository(Transporteur::class)->findAll();

        $transporteursData = [];
        foreach ($transporteurs as $transporteur) {
            $transporteursData[] = [
                'id' => $transporteur->getId(),
                'name' => $transporteur->getName(),
                'logo' => $transporteur->getLogo(),
                'contact' => $transporteur->getContact(),
            ];
        }

        return $this->json($transporteursData, JsonResponse::HTTP_OK);
    }

    #[Route('/api/transporteurs', name: 'create_transporteur', methods: ['POST'])]
    public function createTransporteur(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $transporteur = new Transporteur();
        $transporteur->setName($data['name'] ?? '');
        $transporteur->setLogo($data['logo'] ?? null);
        $transporteur->setContact($data['contact'] ?? null);

        $this->entityManager->persist($transporteur);
        $this->entityManager->flush();

        return $this->json(['success' => 'Transporteur created', 'transporteur_id' => $transporteur->getId()], JsonResponse::HTTP_CREATED);
    }

    #[Route('/api/transporteurs/{id}', name: 'delete_transporteur', methods: ['DELETE'])]
    public function deleteTransporteur(Transporteur $transporteur): JsonResponse
    {
        $this->entityManager->remove($transporteur);
        $this->entityManager->flush();

        return new JsonResponse(['success' => 'Transporteur deleted'], JsonResponse::HTTP_NO_CONTENT);
    }
}
