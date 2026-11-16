<?php

namespace App\Controller\StorefrontController;

use App\Services\Storefront\StorefrontException;
use App\Services\Storefront\StorefrontService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * L'état public du site (lot CONSTRUCTION, 16/11/2026, `docs/boutique-en-construction.md`). Deux routes publiques (l'état, le contrôle
 * d'un lien d'aperçu) ; quatre routes de l'administrateur du site (liste de contrôle, ouvrir, refermer, lien d'aperçu). Le jeton d'aperçu
 * arrive dans l'en-tête `X-Storefront-Preview` ou dans le corps du contrôle : il n'est jamais écrit dans un journal ni rendu.
 */
#[Route('/api/site/storefront')]
class StorefrontController extends AbstractController
{
    public function __construct(private readonly StorefrontService $storefront, private readonly \App\Security\StorefrontViewer $viewer)
    {
    }

    /**
     * L'état du site, lu par le front sur chaque page → 200 `{ state: construction | open, name, locale, openedAt, preview: { active,
     * expiresAt } }`. `preview.active` : la requête voit la vraie boutique (compte d'équipe connecté, ou jeton d'aperçu valable).
     * Cache court, propre à chaque visiteur dès qu'un jeton ou une session est en jeu.
     */
    #[Route('', name: 'api_site_storefront', methods: ['GET'])]
    public function state(Request $request): JsonResponse
    {
        $team = $this->viewer->isTeam($request);
        $link = !$team && $this->storefront->previewValid($request->headers->get(StorefrontService::PREVIEW_HEADER));
        $view = $this->storefront->publicView($team || $link, $link);
        $response = $this->json($view);
        $personal = $team || $request->headers->has(StorefrontService::PREVIEW_HEADER) || $request->headers->has('Authorization') || $request->cookies->count() > 0;
        $response->headers->set('Cache-Control', ($personal ? 'private' : 'public') . ', max-age=' . StorefrontService::CACHE_SECONDS);
        $response->headers->set('Vary', 'Authorization, Cookie, ' . StorefrontService::PREVIEW_HEADER . ', X-Tenant-Host');
        // Cette route est lue hors du pare-feu de l'API (un jeton expiré ne doit pas coûter l'état au visiteur) : elle passe donc par le
        // pare-feu à session, qui rendrait la réponse privée. Sans cookie reçu, sans session démarrée et sans cookie posé, elle reste
        // publique (même règle que `PublicReadCacheSubscriber`).
        if (!$personal && !$request->hasPreviousSession() && !($request->hasSession() && $request->getSession()->isStarted()) && $response->headers->getCookies() === []) {
            $response->headers->set(\Symfony\Component\HttpKernel\EventListener\AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        }
        if ($view['scope'] === StorefrontService::SCOPE_SITE) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }

    /** Contrôle d'un lien d'aperçu `{ token }` → 200 `{ valid: true, expiresAt }`, ou 400 `PREVIEW_LINK_INVALID` (inconnu, remplacé ou expiré) */
    #[Route('/preview', name: 'api_site_storefront_preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true);
        $token = is_array($body) ? ($body['token'] ?? null) : null;
        if (!$this->storefront->previewValid($token)) {
            $error = new StorefrontException('PREVIEW_LINK_INVALID', 400);

            return $this->json($error->body(), $error->status, ['Cache-Control' => 'no-store']);
        }

        return $this->json(['valid' => true, 'expiresAt' => self::expiry($this->storefront)], 200, ['Cache-Control' => 'no-store']);
    }

    /** La liste de contrôle avant d'ouvrir → 200 `{ state, openedAt, canOpen, items: [{ key: products | payment | terms, ok, blocking, message }] }` */
    #[Route('/checklist', name: 'api_site_storefront_checklist', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function checklist(): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->storefront->checklist()));
    }

    /** Ouvre la boutique → 200 `{ state: open, openedAt }`, ou 409 `STOREFRONT_NOT_READY { items }` (aucun moyen de paiement) */
    #[Route('/open', name: 'api_site_storefront_open', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function open(): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->storefront->open(null, $this->getUser()?->getUserIdentifier())));
    }

    /** Referme la boutique (retour en construction) → 200 `{ state: construction, openedAt: null }` */
    #[Route('/close', name: 'api_site_storefront_close', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function close(): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->storefront->close(null, $this->getUser()?->getUserIdentifier())));
    }

    /** Un lien d'aperçu neuf (7 jours ; il remplace l'ancien) → 200 `{ url, expiresAt }`. Le lien n'est rendu qu'ici, une fois */
    #[Route('/preview-link', name: 'api_site_storefront_preview_link', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function previewLink(): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->storefront->previewLink(null, $this->getUser()?->getUserIdentifier()), 200, ['Cache-Control' => 'no-store']));
    }

    private function handle(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (StorefrontException $e) {
            return $this->json($e->body(), $e->status);
        }
    }

    private static function expiry(StorefrontService $storefront): ?string
    {
        $at = $storefront->current()['previewExpiresAt'];

        return $at === null ? null : (new \DateTimeImmutable($at, new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
    }
}
