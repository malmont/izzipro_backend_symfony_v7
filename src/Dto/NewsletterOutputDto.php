<?php

namespace App\Dto;

use App\Entity\NewsletterSubscriber;

class NewsletterOutputDto
{
    public int $id;
    public string $email;
    public string $subscribedAt;

    public function __construct(NewsletterSubscriber $subscriber)
    {
        $this->id = $subscriber->getId();
        $this->email = $subscriber->getEmail();
        $this->subscribedAt = $subscriber->getSubscribedAt()->format('Y-m-d H:i:s');
    }
}