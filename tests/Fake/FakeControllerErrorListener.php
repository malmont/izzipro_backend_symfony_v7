<?php

namespace App\Tests\Fake;

use Symfony\Component\HttpKernel\Event\ControllerEvent;

/**
 * Essais seulement (lot INC-1a) : avec l'en-tête `X-Test-Raise: error` (ou `exception`), le contrôleur de la route demandée est remplacé
 * par un contrôleur qui lève une \TypeError (ou une \RuntimeException) dont le message porte un courriel et un téléphone : la panne naît
 * DANS un contrôleur, comme en production. Absent de tout autre environnement (`config/services_test.yaml`).
 */
class FakeControllerErrorListener
{
    public const MESSAGE = 'Argument #1 must be of type int, null given for marie.essai@example.invalid au 514-555-0199';

    public function __invoke(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return; // le rendu de l'erreur passe par une sous-requête (ErrorListener) : elle ne doit pas lever à son tour
        }
        $raise = $event->getRequest()->headers->get('X-Test-Raise');
        if ($raise === 'error') {
            $event->setController(static fn () => throw new \TypeError(self::MESSAGE));
        } elseif ($raise === 'exception') {
            $event->setController(static fn () => throw new \RuntimeException(self::MESSAGE));
        }
    }
}
