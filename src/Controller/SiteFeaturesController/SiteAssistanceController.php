<?php

namespace App\Controller\SiteFeaturesController;

use App\Entity\User;
use App\Security\Entry\EntryContext;
use App\Services\Agency\Assistance\AssistanceException;
use App\Services\Agency\Assistance\AssistanceService;
use App\Services\Platform\PlatformAccessJournal;
use App\Services\TenantConnectionProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * L'accès d'assistance de l'agence, côté COMMERÇANT (lot AG-ASSIST, 16/11/2026, `docs/ecrans-et-contextes.md` § 8). Administrateur DU
 * site seulement : un opérateur extérieur (agence entrée par un jeton d'entrée) et toute entrée par un jeton (y compris le compte de la
 * plateforme) sont refusés, 403 `ASSISTANCE_FORBIDDEN` ; la garde du mode ferme d'ailleurs ces routes à l'agence. Demander et fermer sont
 * aussi refusés au compte de la plateforme : seul le commerçant ouvre l'assistance.
 */
#[Route('/api/site/assistance')]
#[IsGranted('ROLE_ADMIN')]
class SiteAssistanceController extends AbstractController
{
    public function __construct(
        private readonly AssistanceService $assistance,
        private readonly EntryContext $entry,
        private readonly TenantConnectionProvider $tenants,
        private readonly PlatformAccessJournal $journal,
    ) {
    }

    /** Les demandes du site, les plus récentes d'abord → 200 `{ assistance: [{ id, section, mode, state, agency, requestedBy, … }] }` */
    #[Route('', name: 'api_site_assistance_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        if ($this->merchant(false) === null) {
            return $this->forbidden();
        }

        return $this->json(['assistance' => $this->assistance->list($this->tenant())]);
    }

    /**
     * Demander l'assistance de mon agence `{ section, mode?: read | write }` → 201 `{ assistance }` (avec `notified` : courriels partis).
     * 422 `ASSISTANCE_SECTION_INVALID` | `ASSISTANCE_MODE_INVALID` | `ASSISTANCE_READ_ONLY` (pertes en écriture) ; 409
     * `ASSISTANCE_NO_AGENCY` | `ASSISTANCE_ALREADY_OPEN`.
     */
    #[Route('', name: 'api_site_assistance_request', methods: ['POST'])]
    public function request(Request $request): JsonResponse
    {
        $merchant = $this->merchant(true);
        if ($merchant === null) {
            return $this->forbidden();
        }
        $body = json_decode((string) $request->getContent(), true);
        $body = is_array($body) ? $body : [];
        try {
            return $this->json(['assistance' => $this->assistance->request($this->tenant(), $body['section'] ?? null, $body['mode'] ?? null, $merchant)], 201);
        } catch (AssistanceException $e) {
            return $this->json(['error' => $e->errorCode, 'code' => $e->errorCode, 'message' => $e->getMessage()], $e->status);
        }
    }

    /** Fermer l'assistance, à tout moment → 200 `{ assistance }` ; 404 `ASSISTANCE_NOT_FOUND` */
    #[Route('/{id}/close', name: 'api_site_assistance_close', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function close(int $id): JsonResponse
    {
        $merchant = $this->merchant(true);
        if ($merchant === null) {
            return $this->forbidden();
        }
        try {
            return $this->json(['assistance' => $this->assistance->close($this->tenant(), $id, $merchant)]);
        } catch (AssistanceException $e) {
            return $this->json(['error' => $e->errorCode, 'code' => $e->errorCode, 'message' => $e->getMessage()], $e->status);
        }
    }

    /**
     * Ce que l'agence a consulté et modifié pendant UNE assistance → 200 `{ journal: [{ at, account, action: assistance_read |
     * assistance_write, method, path, status }] }`, le plus récent d'abord ; 404 `ASSISTANCE_NOT_FOUND`
     */
    #[Route('/{id}/journal', name: 'api_site_assistance_journal', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function journal(int $id): JsonResponse
    {
        if ($this->merchant(false) === null) {
            return $this->forbidden();
        }
        if (!$this->assistance->belongs($this->tenant(), $id)) {
            return $this->json(['error' => 'ASSISTANCE_NOT_FOUND', 'code' => 'ASSISTANCE_NOT_FOUND', 'message' => 'Demande d\'assistance introuvable.'], 404);
        }

        return $this->json(['journal' => array_map(static fn (array $line): array => ['at' => $line['at']->format(\DATE_ATOM), 'account' => $line['account'], 'action' => $line['channel'],
            'method' => $line['method'], 'path' => $line['path'], 'status' => $line['status']], $this->journal->assistance(1000, $id))]);
    }

    /** Le compte du site qui parle, ou null : opérateur extérieur, entrée par un jeton, ou (pour écrire) compte de la plateforme */
    private function merchant(bool $write): ?User
    {
        $user = $this->getUser();
        if (!$user instanceof User || $this->entry->active() || ($write && PlatformAccessJournal::isPlatformAccount($user->getRoles()))) {
            return null;
        }

        return $user;
    }

    private function tenant(): string
    {
        return (string) $this->tenants->getTenantCode();
    }

    private function forbidden(): JsonResponse
    {
        return $this->json(['error' => 'ASSISTANCE_FORBIDDEN', 'code' => 'ASSISTANCE_FORBIDDEN', 'message' => 'Seul un administrateur du site ouvre ou ferme l\'assistance de son agence.'], 403);
    }
}
