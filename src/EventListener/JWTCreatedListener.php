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

        // Récupérer le payload existant
        $payload = $event->getData();

        // Ajouter des informations supplémentaires au payload du JWT
        $payload['id'] = $user->getId();
        $payload['firstName'] = $user->getFirstname();
        $payload['lastName'] = $user->getLastname();
        $payload['email'] = $user->getEmail();
        $payload['roles'] = $user->getRoles(); // Ajouter les rôles de l'utilisateur au JWT

        // Mettre à jour le payload du JWT
        $event->setData($payload);
    }
}
