<?php

namespace App\MemoiresVivantes\EventListener;

use App\MemoiresVivantes\Dto\ChapterOutputDto;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Le chapitre rendu (ChapterOutputDto) dit si sa rédaction est verrouillée pour l'utilisateur courant
 * (writingLocked, 16/10/2026) : jamais pour un administrateur. Le DTO est construit à de nombreux endroits sans
 * accès à la sécurité : ce contexte est posé une fois par requête.
 */
class ChapterViewerContextSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Security $security)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => ['onController', 0]];
    }

    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        try {
            ChapterOutputDto::$viewerIsAdmin = $this->security->getUser() !== null && $this->security->isGranted('ROLE_ADMIN');
        } catch (\Throwable) {
            ChapterOutputDto::$viewerIsAdmin = false;
        }
    }
}
