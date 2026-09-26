<?php

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

/**
 * Protège les actions personnalisées EasyAdmin qui modifient des données.
 *
 * Ces actions sont des liens (GET) : sans jeton, une page externe peut les déclencher avec la session
 * d'un admin connecté (CSRF). On signe donc l'URL avec un jeton CSRF propre à l'action,
 * vérifié avant toute modification.
 *
 * Usage : ->linkToUrl(fn (Entite $e) => $this->csrfActionUrl('monAction', $e->getId()))
 *         puis en tête de l'action : if (!$this->isCsrfActionValid($context, 'monAction')) { ... }
 */
trait CsrfProtectedActionTrait
{
    protected function csrfActionUrl(string $action, int|string $entityId): string
    {
        return $this->container->get(AdminUrlGenerator::class)
            ->unsetAll()
            ->setController(static::class)
            ->setAction($action)
            ->setEntityId($entityId)
            ->set('_csrf', $this->container->get('security.csrf.token_manager')->getToken('ea_action_' . $action)->getValue())
            ->generateUrl();
    }

    protected function isCsrfActionValid(AdminContext $context, string $action): bool
    {
        return $this->isCsrfTokenValid('ea_action_' . $action, (string) $context->getRequest()->query->get('_csrf', ''));
    }
}
