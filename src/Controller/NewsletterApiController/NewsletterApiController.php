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


    #[Route('', name: 'api_newsletter_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $subscribers = $this->getAllUseCase->execute();
  
        $outputDtos = array_map(
            fn($subscriber) => new NewsletterOutputDto($subscriber),
            $subscribers
        );

        return $this->json($outputDtos);
    }
}