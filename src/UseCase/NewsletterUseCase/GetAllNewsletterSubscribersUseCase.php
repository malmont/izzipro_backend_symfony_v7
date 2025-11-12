<?php

namespace App\UseCase\NewsletterUseCase;

use App\Services\NewsletterService\NewsletterService;

class GetAllNewsletterSubscribersUseCase
{
    private NewsletterService $newsletterService;

    public function __construct(NewsletterService $newsletterService)
    {
        $this->newsletterService = $newsletterService;
    }

    /**
     * @return NewsletterSubscriber[]
     */
    public function execute(): array
    {
        return $this->newsletterService->getAllSubscribers();
    }
}