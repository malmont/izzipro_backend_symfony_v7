<?php

namespace App\Import\Controller;

use App\Import\ImportException;
use App\Import\ImportInvitationService;
use App\Services\MediaUrlResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lot IMP-2 (`docs/import-boutique.md` § 9) : le lien d'invitation d'un client repris d'une ancienne boutique. Trois routes PUBLIQUES
 * (`access_control` `^/api/import-invitation/`, limitées en débit comme la connexion) : la preuve est le jeton du lien, à usage unique,
 * envoyé dans le corps (jamais dans l'adresse, qu'un serveur journalise). Un refus rend `{ error: CODE, code, message, … }`.
 */
class ImportInvitationController extends AbstractController
{
    public function __construct(private readonly ImportInvitationService $invitations, private readonly ?MediaUrlResolver $mediaUrlResolver = null)
    {
    }

    /** `{ token }` → 200 `{ firstname, email, site, expiresAt, marketing, terms }` : ce que la page affiche avant tout choix ; 404 `IMPORT_INVITATION_INVALID` */
    #[Route('/api/import-invitation/verify', name: 'api_import_invitation_verify', methods: ['POST'])]
    public function verify(Request $request): JsonResponse
    {
        return $this->guard($request, fn (array $body) => $this->invitations->show($body['token'] ?? null, $this->host($request), $body['locale'] ?? $request->query->get('locale')));
    }

    /** `{ token, password, termsVersion, accepted: true, marketing?: bool }` → 200 `{ accepted: true, email, marketing }` : le compte est ouvert, le client se connecte ensuite */
    #[Route('/api/import-invitation/accept', name: 'api_import_invitation_accept', methods: ['POST'])]
    public function accept(Request $request): JsonResponse
    {
        return $this->guard($request, fn (array $body) => $this->invitations->accept($body['token'] ?? null, $body['password'] ?? null, $body['termsVersion'] ?? null, $body['accepted'] ?? null,
            $body['marketing'] ?? null, $this->host($request), $body['locale'] ?? $request->query->get('locale')));
    }

    /** `{ token }` → 200 `{ declined: true }` : ce qui a été repris de l'ancienne boutique est effacé tout de suite */
    #[Route('/api/import-invitation/decline', name: 'api_import_invitation_decline', methods: ['POST'])]
    public function decline(Request $request): JsonResponse
    {
        return $this->guard($request, fn (array $body) => $this->invitations->decline($body['token'] ?? null));
    }

    private function guard(Request $request, callable $action): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true);
        try {
            if (!is_array($body)) {
                throw new ImportException('IMPORT_INVITATION_INVALID', 404);
            }

            return $this->json($action($body));
        } catch (ImportException $e) {
            return $this->json(['error' => $e->errorCode, 'code' => $e->errorCode, 'message' => $e->getMessage()] + $e->extra, $e->status);
        }
    }

    private function host(Request $request): string
    {
        return $this->mediaUrlResolver?->getPublicHost($request->getSchemeAndHttpHost()) ?? $request->getSchemeAndHttpHost();
    }
}
