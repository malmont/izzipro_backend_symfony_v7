<?php

namespace App\Controller\NewsletterApiController;

use App\Dto\NewsletterInputDto;
use App\Dto\NewsletterOutputDto;
use App\UseCase\NewsletterUseCase\CreateNewsletterSubscriberUseCase;
use App\UseCase\NewsletterUseCase\GetAllNewsletterSubscribersUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/newsletter')]
class NewsletterApiController extends AbstractController
{
    public function __construct(
        private CreateNewsletterSubscriberUseCase $createUseCase,
        private GetAllNewsletterSubscribersUseCase $getAllUseCase
    ) {}


    #[Route('/subscribe', name: 'api_newsletter_subscribe', methods: ['POST'])]
    public function subscribe(
        #[MapRequestPayload] NewsletterInputDto $dto
    ): JsonResponse {
        
        $subscriber = $this->createUseCase->execute($dto);
        
        return $this->json(new NewsletterOutputDto($subscriber), Response::HTTP_CREATED);
    }


    /**
     * Liste des inscrits : administrateur du site seulement. Jusqu'au 27/10/2026, tout compte connecté (la création
     * de compte client est publique) lisait les adresses. Règle access_control en second rempart (security.yaml).
     */
    #[Route('', name: 'api_newsletter_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['message' => 'Réservé à l\'administrateur du site.'], Response::HTTP_FORBIDDEN);
        }
        $subscribers = $this->getAllUseCase->execute();
  
        $outputDtos = array_map(
            fn($subscriber) => new NewsletterOutputDto($subscriber),
            $subscribers
        );

        return $this->json($outputDtos);
    }
}