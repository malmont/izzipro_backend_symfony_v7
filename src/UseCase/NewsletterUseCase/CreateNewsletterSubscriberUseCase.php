<?php

namespace App\UseCase\NewsletterUseCase;

use App\Dto\NewsletterInputDto;
use App\Entity\NewsletterSubscriber;
use App\Services\NewsletterService\NewsletterService;

class CreateNewsletterSubscriberUseCase
{
    private NewsletterService $newsletterService;

    public function __construct(NewsletterService $newsletterService)
    {
        $this->newsletterService = $newsletterService;
    }

    public function execute(NewsletterInputDto $dto): NewsletterSubscriber
    {
        return $this->newsletterService->createSubscriber($dto);
    }
}