<?php

namespace App\Controller\NoteDeFraisController;

use App\Entity\NoteDeFrais;
use App\Entity\Collections;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class NoteDeFraisController extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/api/collections/{id}/notes-de-frais', name: 'get_notes_de_frais_by_collection', methods: ['GET'])]
    public function getNotesDeFraisByCollection(Collections $collection): JsonResponse
    {
        $notesDeFrais = $collection->getNoteDeFrais();

        $notesArray = [];
        foreach ($notesDeFrais as $note) {
            $notesArray[] = [
                'id' => $note->getId(),
                'name' => $note->getName(),
                'description' => $note->getDescription(),
                'imageNdf' => $note->getImageNdf(),
                'montant' => $note->getMontant(),
                'date' => $note->getDate()->format('Y-m-d'),
            ];
        }

        return $this->json($notesArray, JsonResponse::HTTP_OK);
    }

    #[Route('/api/collections/{id}/notes-de-frais', name: 'create_note_de_frais', methods: ['POST'])]
    public function createNoteDeFrais(Collections $collection, Request $request): JsonResponse
    {
        // Récupérer le contenu brut de la requête
        $rawContent = $request->getContent();
    
        // Décoder le contenu JSON en tableau PHP
        $data = json_decode($rawContent, true);
        
        // Vérifier si le JSON est valide
        if ($data === null) {
            return new JsonResponse(['error' => 'Invalid JSON'], JsonResponse::HTTP_BAD_REQUEST);
        }
    
        // Créer une nouvelle note de frais
        $note = new NoteDeFrais();
    
        // Assigner les valeurs décodées aux propriétés de la note de frais
        $note->setDescription($data['description'] ?? null);
    
        // Vérification et conversion du montant
        $montant = $data['montant'] ?? null;
        if ($montant === null) {
            return new JsonResponse(['error' => 'Montant is required'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $note->setMontant((float) $montant);
    
        // Assigner la date
        $note->setDate(new \DateTime($data['date']));
    
        // Assigner la collection
        $note->setCollection($collection);
    
        // Assigner l'URL de l'image
        $imageUrl = $data['imageNdf'] ?? null;
        if ($imageUrl) {
            $note->setImageNdf($imageUrl);
        }
    
        // Sauvegarder la note de frais dans la base de données
        $this->entityManager->persist($note);
        $this->entityManager->flush();
    
        // Retourner une réponse JSON avec le succès de l'opération
        return $this->json(['success' => 'Note de frais created', 'note_id' => $note->getId()], JsonResponse::HTTP_CREATED);
    }
    

    #[Route('/api/notes-de-frais/{id}', name: 'update_note_de_frais', methods: ['PUT'])]
    public function updateNoteDeFrais(NoteDeFrais $note, Request $request): JsonResponse
    {
        $note->setName($request->get('name'));
        $note->setDescription($request->get('description'));
        $note->setMontant($request->get('montant'));
        $note->setDate(new \DateTime($request->get('date')));

        $imageFile = $request->files->get('imageNdf');
        if ($imageFile) {
            $newFilename = uniqid() . '.' . $imageFile->guessExtension();

            try {
                $imageFile->move(
                    $this->getParameter('kernel.project_dir') . '/public/assets/uploads/notes-de-frais/',
                    $newFilename
                );
            } catch (FileException $e) {
                return new JsonResponse(['error' => 'Could not upload file'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
            }

            $note->setImageNdf('/assets/uploads/notes-de-frais/' . $newFilename);
        }

        $this->entityManager->flush();

        return $this->json(['success' => 'Note de frais updated'], JsonResponse::HTTP_OK);
    }

    #[Route('/api/notes-de-frais/{id}', name: 'delete_note_de_frais', methods: ['DELETE'])]
    public function deleteNoteDeFrais(NoteDeFrais $note): JsonResponse
    {
        $this->entityManager->remove($note);
        $this->entityManager->flush();

        return $this->json(['success' => 'Note de frais deleted'], JsonResponse::HTTP_NO_CONTENT);
    }
}
