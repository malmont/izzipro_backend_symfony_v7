<?php

namespace App\Services\NewsletterService;

use App\Dto\NewsletterInputDto;
use App\Entity\NewsletterSubscriber;
use App\Services\TenantEntityManagerProvider;

class NewsletterService
{
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    /**
     * @return NewsletterSubscriber[]
     */
    public function getAllSubscribers(): array
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(NewsletterSubscriber::class)->findBy([], ['subscribedAt' => 'DESC']);
    }

    public function createSubscriber(NewsletterInputDto $dto): NewsletterSubscriber
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $existing = $tenantEm->getRepository(NewsletterSubscriber::class)->findOneBy(['email' => $dto->email]);
        if ($existing) {
            return $existing;
        }
        $subscriber = new NewsletterSubscriber();
        $subscriber->setEmail($dto->email);
        $subscriber->setSubscribedAt(new \DateTimeImmutable());
        $tenantEm->persist($subscriber);
        $tenantEm->flush();

        return $subscriber;
    }
}