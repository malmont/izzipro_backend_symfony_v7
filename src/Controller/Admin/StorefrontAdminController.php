<?php

namespace App\Controller\Admin;

use App\Services\Storefront\StorefrontException;
use App\Services\Storefront\StorefrontService;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Page « Ouverture du site » de l'espace d'administration (lot CONSTRUCTION, 16/11/2026, `docs/boutique-en-construction.md`) :
 * l'administrateur du site y lit l'état public de SON site, la liste de contrôle, et fait les mêmes gestes que par l'API
 * (`/api/site/storefront/*`) : ouvrir, refermer, créer un lien d'aperçu. Chaque geste revérifie le rôle et le jeton CSRF. Le lien
 * d'aperçu est montré UNE fois, dans la réponse de sa création ; il n'est gardé nulle part en clair, pas même en session.
 */
#[IsGranted('ROLE_ADMIN')]
class StorefrontAdminController extends AbstractController
{
    private const CSRF_ID = 'admin_ouverture_site';

    public function __construct(private readonly StorefrontService $storefront, private readonly AdminUrlGenerator $urls)
    {
    }

    #[Route('/admin/ouverture-du-site', name: 'admin_ouverture_site', methods: ['GET'])]
    public function index(Request $request): Response
    {
        try {
            $checklist = $this->storefront->checklist();
        } catch (StorefrontException $e) {
            $checklist = null;
            $this->addFlash('warning', $e->getMessage());
        }
        return $this->page($checklist, null);
    }

    /** @param array<string, mixed>|null $checklist @param array{url: string, expiresAt: string}|null $link */
    private function page(?array $checklist, ?array $link): Response
    {
        $response = $this->render('admin/ouverture_site.html.twig', ['csrf_id' => self::CSRF_ID, 'checklist' => $checklist, 'link' => $link, 'days' => StorefrontService::PREVIEW_DAYS]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/admin/ouverture-du-site/{action}', name: 'admin_ouverture_site_action', methods: ['POST'], requirements: ['action' => 'ouvrir|refermer|apercu'])]
    public function action(string $action, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide : rien n\'a été modifié.');

            return $this->redirect($this->pageUrl());
        }
        $by = $this->getUser()?->getUserIdentifier();
        try {
            if ($action === 'ouvrir') {
                $this->storefront->open(null, $by);
                $this->addFlash('success', 'La boutique est ouverte au public.');
            } elseif ($action === 'refermer') {
                $this->storefront->close(null, $by);
                $this->addFlash('success', 'La boutique est refermée : elle est de nouveau « en construction ». Le site vitrine reste servi.');
            } else {
                // le lien est rendu dans CETTE réponse, une fois : il n'est gardé ni en session ni ailleurs
                $link = $this->storefront->previewLink(null, $by);

                return $this->page($this->storefront->checklist(), $link);
            }
        } catch (StorefrontException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirect($this->pageUrl());
    }

    private function pageUrl(): string
    {
        try {
            return $this->urls->unsetAll()->setRoute('admin_ouverture_site')->generateUrl();
        } catch (\Throwable) {
            return $this->generateUrl('admin_ouverture_site');
        }
    }
}
