<?php

namespace App\Controller\ContactApiController;

use App\Dto\ContactSubmitDto;
use App\UseCase\ContactUseCase\SubmitContactUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ContactSubmitController extends AbstractController
{
    #[Route('/api/contact/submit', name: 'api_contact_submit', methods: ['POST'])]
    public function submit(
        Request $request,
        SubmitContactUseCase $submitContactUseCase,
        ValidatorInterface $validator
    ): JsonResponse {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (!$data) {
                return $this->json(['message' => 'JSON invalide.'], Response::HTTP_BAD_REQUEST);
            }

            $dto = new ContactSubmitDto();
            $dto->name = $data['name'] ?? null;
            $dto->email = $data['email'] ?? null;
            $dto->phone = $data['phone'] ?? null;
            $dto->service = $data['service'] ?? null;
            $dto->message = $data['message'] ?? null;

            $violations = $validator->validate($dto);

            if (count($violations) > 0) {
                // On retourne le message de la première violation comme demandé dans les specs
                $violation = $violations[0];
                return $this->json([
                    'message' => $violation->getMessage()
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $host = $request->headers->get('X-Tenant-Host');
            if (!$host) {
                return $this->json(['message' => 'Header X-Tenant-Host manquant.'], Response::HTTP_BAD_REQUEST);
            }

            // On peut récupérer la locale depuis le request ou mettre 'fr' par défaut
            $locale = $request->getLocale() ?: 'fr';

            $submitContactUseCase->execute($dto, $host, $locale);

            return $this->json([
                'message' => 'Message envoyé avec succès.'
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return $this->json([
                'message' => 'Une erreur est survenue lors de l\'envoi du message.',
                'error' => $e->getMessage() // Optionnel, selon si on veut exposer l'erreur
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
