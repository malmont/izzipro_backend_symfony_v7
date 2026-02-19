<?php
// src/EventListener/JWTCreatedListener.php
namespace App\EventListener;

use App\Services\TenantConnectionProvider; // <-- 1. On importe notre provider
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Symfony\Component\Security\Core\User\UserInterface;

class JWTCreatedListener
{
    // 2. On ajoute une propriété pour stocker notre provider
    private TenantConnectionProvider $tenantProvider;

    // 3. On injecte le provider dans le constructeur
    public function __construct(TenantConnectionProvider $tenantProvider)
    {
        $this->tenantProvider = $tenantProvider;
    }

    public function onJWTCreated(JWTCreatedEvent $event)
    {
        
        $user = $event->getUser();

        if (!$user instanceof UserInterface) {
            return;
        }

        $payload = $event->getData();

        // Ajout des informations existantes
        $payload['id'] = $user->getId();
        $payload['firstName'] = $user->getFirstname();
        $payload['lastName'] = $user->getLastname();
        $payload['email'] = $user->getEmail();
        $payload['roles'] = $user->getRoles();

        // 4. On récupère le code du tenant depuis le provider et on l'ajoute au payload
        $tenantCode = $this->tenantProvider->getTenantCode();
        if ($tenantCode) {
            $payload['tenant_code'] = $tenantCode;
        }

        $event->setData($payload);
    }
}