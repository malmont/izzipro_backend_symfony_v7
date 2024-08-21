<?php

namespace App\Controller\FraisDePortController;

use App\Entity\FraisDePort;
use App\Entity\Transporteur;
use App\Entity\Commande;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class FraisDePortController extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/api/commandes/{id}/frais-de-port', name: 'create_frais_de_port', methods: ['POST'])]
    public function createFraisDePort(Commande $commande, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        // Création de l'objet FraisDePort
        $fraisDePort = new FraisDePort();
        $fraisDePort->setName($data['name'] ?? '');
        $fraisDePort->setFacture($data['facture'] ?? '');
        $fraisDePort->setImage($data['image'] ?? null);
        $fraisDePort->setTracknumber($data['tracknumber'] ?? '');
        $fraisDePort->setPrice((float)($data['price'] ?? 0));

        // Lier le FraisDePort à la commande
        $fraisDePort->setCommande($commande);

        // Gestion du transporteur
        if (isset($data['transporteur']['id'])) {
            $transporteur = $this->entityManager->getRepository(Transporteur::class)->find($data['transporteur']['id']);
            if ($transporteur) {
                $fraisDePort->setTransporteur($transporteur);
            } else {
                return new JsonResponse(['error' => 'Transporteur not found'], JsonResponse::HTTP_BAD_REQUEST);
            }
        }

        $this->entityManager->persist($fraisDePort);
        $this->entityManager->flush();

        return $this->json(['success' => 'Frais de port created', 'frais_de_port_id' => $fraisDePort->getId()], JsonResponse::HTTP_CREATED);
    }

    #[Route('/api/commandes/{id}/frais-de-port', name: 'get_frais_de_port', methods: ['GET'])]
    public function getFraisDePort(Commande $commande): JsonResponse
    {
        $fraisDePort = $commande->getFraisDePort();

        if (!$fraisDePort) {
            return new JsonResponse(['error' => 'No shipping cost associated with this order'], JsonResponse::HTTP_NOT_FOUND);
        }

        $fraisDePortData = [
            'id' => $fraisDePort->getId(),
            'name' => $fraisDePort->getName(),
            'facture' => $fraisDePort->getFacture(),
            'image' => $fraisDePort->getImage(),
            'tracknumber' => $fraisDePort->getTracknumber(),
            'price' => $fraisDePort->getPrice(),
            'transporteur' => [
                'id' => $fraisDePort->getTransporteur()->getId(),
                'name' => $fraisDePort->getTransporteur()->getName(),
                'logo' => $fraisDePort->getTransporteur()->getLogo(),
                'contact' => $fraisDePort->getTransporteur()->getContact(),
            ]
        ];

        return $this->json($fraisDePortData, JsonResponse::HTTP_OK);
    }

    #[Route('/api/commandes/{id}/frais-de-port', name: 'delete_frais_de_port', methods: ['DELETE'])]
    public function deleteFraisDePort(Commande $commande): JsonResponse
    {
        $fraisDePort = $commande->getFraisDePort();

        if (!$fraisDePort) {
            return new JsonResponse(['error' => 'No shipping cost associated with this order'], JsonResponse::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($fraisDePort);
        $this->entityManager->flush();

        return new JsonResponse(['success' => 'Frais de port deleted'], JsonResponse::HTTP_NO_CONTENT);
    }
}
