<?php

namespace App\Controller\SiteFeaturesController;

use App\Services\Platform\SiteReadinessService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Cheminement de préparation du site (console de pilotage, 05/11/2026), administrateur du site : { code, percent, done, total, next,
 * steps[{ key, group, title, description, status: done | todo | optional | pending, where: backend | editor | platform, action: { type:
 * screen | registry | editor | request | shop, screen?, feature?, url? } } }. L'éditeur exécute les étapes `editor` sur place, renvoie
 * vers EasyAdmin pour `backend`, vers le forfait ou « Demander » pour `platform`. L'assistant IA lit la même liste. Fiche :
 * docs/console-pilotage.md.
 */
class SiteReadinessController extends AbstractController
{
    public function __construct(private readonly SiteReadinessService $readiness)
    {
    }

    #[Route('/api/site/readiness', name: 'api_site_readiness', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function show(): JsonResponse
    {
        return $this->json($this->readiness->forCurrentSite());
    }
}
