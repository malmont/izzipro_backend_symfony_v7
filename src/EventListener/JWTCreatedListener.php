<?php
// src/EventListener/JWTCreatedListener.php
namespace App\EventListener;

use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Symfony\Component\Security\Core\User\UserInterface;

class JWTCreatedListener
{
    public function onJWTCreated(JWTCreatedEvent $event)
    {
        $user = $event->getUser();

        if (!$user instanceof UserInterface) {
            return;
        }

        $payload = $event->getData();
        $payload['id'] = $user->getId(); // Ajouter l'ID de l'utilisateur au payload
        $payload['firstName'] = $user->getFirstname();
        $payload['lastName'] = $user->getLastname();
        $payload['email'] = $user->getEmail();
        $event->setData($payload);
    }
}
