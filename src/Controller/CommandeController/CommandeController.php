<?php
namespace App\Controller\CommandeController;

use App\Entity\Collections;
use App\Entity\Commande;
use App\Entity\Fournisseur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Security;

class CommandeController extends AbstractController
{
    private $entityManager;
    private $security;

    public function __construct(EntityManagerInterface $entityManager, Security $security)
    {
        $this->entityManager = $entityManager;
        $this->security = $security;
    }

    #[Route('/api/collections/{id}/commandes', name: 'get_commandes_by_collection', methods: ['GET'])]
    public function getCommandesByCollection(Collections $collection): JsonResponse
    {
        $commandes = $this->entityManager->getRepository(Collections::class)
            ->find($collection->getId())
            ->getCommandes();

        $commandesArray = [];
        foreach ($commandes as $commande) {
            $fournisseur = $commande->getFournisseur();
            $commandesArray[] = [
                'id' => $commande->getId(),
                'budget' => $commande->getBudget(),
                'date' => $commande->getDate()->format('Y-m-d H:i:s'),
                'name' => $commande->getName(),
                'photo' => $commande->getPhoto(),
                'collectionId' => $commande->getCollections()->getId(),
                'fournisseur' => $fournisseur ? [
                    'id' => $fournisseur->getId(),
                    'name' => $fournisseur->getName(),
                    'photo' => $fournisseur->getPhoto(),
                    'adresse' => $fournisseur->getAdresse(),
                    'ville' => $fournisseur->getVille(),
                    'pays' => $fournisseur->getPays(),
                    'tel' => $fournisseur->getTel(),
                ] : null,
            ];
        }

        return $this->json($commandesArray, 200);
    }

    #[Route('/api/collections/{id}/commandes', name: 'create_commande', methods: ['POST'])]
    public function createCommande(Request $request, Collections $collection): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $currentUser = $this->security->getUser();
        if (!$currentUser) {
            return $this->json(['error' => 'Unauthenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $commande = new Commande();
        $commande->setBudget($data['budget']);
        $commande->setDate(new \DateTime($data['date']));
        $commande->setName($data['name']);
        $commande->setPhoto($data['photo']);
        $commande->setCollections($collection);

        // Associer l'objet fournisseur à la commande
        if (isset($data['fournisseur'])) {
            $fournisseurData = $data['fournisseur'];

            // Rechercher un fournisseur existant ou en créer un nouveau
            $fournisseur = $this->entityManager->getRepository(Fournisseur::class)->findOneBy([
                'name' => $fournisseurData['name'],
                'adresse' => $fournisseurData['adresse'],
                'ville' => $fournisseurData['ville'],
                'pays' => $fournisseurData['pays'],
                'tel' => $fournisseurData['tel']
            ]);

            if (!$fournisseur) {
                $fournisseur = new Fournisseur();
                $fournisseur->setName($fournisseurData['name']);
                $fournisseur->setPhoto($fournisseurData['photo']);
                $fournisseur->setAdresse($fournisseurData['adresse']);
                $fournisseur->setVille($fournisseurData['ville']);
                $fournisseur->setPays($fournisseurData['pays']);
                $fournisseur->setTel($fournisseurData['tel']);

                $this->entityManager->persist($fournisseur);
            }

            $commande->setFournisseur($fournisseur);
        }

        $this->entityManager->persist($commande);
        $this->entityManager->flush();

        // Inclure l'objet fournisseur dans la réponse
        $fournisseur = $commande->getFournisseur();
        $commandeArray = [
            'id' => $commande->getId(),
            'budget' => $commande->getBudget(),
            'date' => $commande->getDate()->format('Y-m-d H:i:s'),
            'name' => $commande->getName(),
            'photo' => $commande->getPhoto(),
            'collectionId' => $commande->getCollections()->getId(),
            'fournisseur' => $fournisseur ? [
                'id' => $fournisseur->getId(),
                'name' => $fournisseur->getName(),
                'photo' => $fournisseur->getPhoto(),
                'adresse' => $fournisseur->getAdresse(),
                'ville' => $fournisseur->getVille(),
                'pays' => $fournisseur->getPays(),
                'tel' => $fournisseur->getTel(),
            ] : null,
        ];

        return $this->json($commandeArray, JsonResponse::HTTP_CREATED);
    }

    // Ajouter une méthode pour mettre à jour le fournisseur d'une commande existante
    #[Route('/api/commandes/{id}/fournisseur', name: 'update_commande_fournisseur', methods: ['PUT'])]
    public function updateCommandeFournisseur(Request $request, Commande $commande): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (isset($data['fournisseur'])) {
            $fournisseurData = $data['fournisseur'];

            // Rechercher un fournisseur existant ou en créer un nouveau
            $fournisseur = $this->entityManager->getRepository(Fournisseur::class)->findOneBy([
                'name' => $fournisseurData['name'],
                'adresse' => $fournisseurData['adresse'],
                'ville' => $fournisseurData['ville'],
                'pays' => $fournisseurData['pays'],
                'tel' => $fournisseurData['tel']
            ]);

            if (!$fournisseur) {
                $fournisseur = new Fournisseur();
                $fournisseur->setName($fournisseurData['name']);
                $fournisseur->setPhoto($fournisseurData['photo']);
                $fournisseur->setAdresse($fournisseurData['adresse']);
                $fournisseur->setVille($fournisseurData['ville']);
                $fournisseur->setPays($fournisseurData['pays']);
                $fournisseur->setTel($fournisseurData['tel']);

                $this->entityManager->persist($fournisseur);
            }

            $commande->setFournisseur($fournisseur);
            $this->entityManager->flush();

            return $this->json([
                'success' => 'Fournisseur mis à jour avec succès',
                'fournisseur' => [
                    'id' => $fournisseur->getId(),
                    'name' => $fournisseur->getName(),
                    'photo' => $fournisseur->getPhoto(),
                    'adresse' => $fournisseur->getAdresse(),
                    'ville' => $fournisseur->getVille(),
                    'pays' => $fournisseur->getPays(),
                    'tel' => $fournisseur->getTel(),
                ],
            ], JsonResponse::HTTP_OK);
        }

        return $this->json(['error' => 'Fournisseur non trouvé'], JsonResponse::HTTP_BAD_REQUEST);
    }
}
