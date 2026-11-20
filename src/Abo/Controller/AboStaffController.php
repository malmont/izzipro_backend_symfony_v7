<?php

namespace App\Abo\Controller;

use App\Abo\Services\AboException;
use App\Abo\UseCase\AboStaffUseCase;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Intervenants des abonnements (lot ABO-3b, 20/11/2026, `docs/boutique.md` § « Intervenant et fil de l'abonné »), dates ATOM à l'heure du
 * magasin, refus `{ error: CODE, code, message, field? }`.
 * - ÉQUIPE, `/api/site/abonnements/intervenants` et `…/{id}/occurrences/{occ}/intervenant` : `ROLE_ADMIN`, module Boutique ; le directeur
 *   d'un groupe entré n'y écrit rien (`EntryModeGuardSubscriber`).
 * - INTERVENANT, `/api/staff/abonnements/...` : `ROLE_STAFF_PROVIDER` (`access_control`), module Boutique ; SES dates seulement (404 pour
 *   celle d'un autre), le prénom de l'abonné et l'adresse d'une prestation chez le client, rien d'autre. Routes neutres : la page web
 *   d'abord, un mode « intervenant » de l'application mobile plus tard.
 */
class AboStaffController extends AbstractController
{
    private const MAX_BODY_BYTES = 16384;

    public function __construct(private readonly AboStaffUseCase $useCase)
    {
    }

    // ---- équipe ---------------------------------------------------------------------------------------------------------------------------

    /** Les intervenants → 200 { staff: [{ userId, firstname, lastname, email, pending, invitationExpiresAt }] } */
    #[Route('/api/site/abonnements/intervenants', name: 'site_abo_staff_list', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function list(): JsonResponse
    {
        return $this->json($this->useCase->list());
    }

    /** Inviter `{ email, firstName, lastName, locale? }` → 200 { staff, changed, invitation: { emailSent, expiresAt, validHours } | null } ; 409 STAFF_IS_ADMIN | STAFF_IS_CUSTOMER */
    #[Route('/api/site/abonnements/intervenants', name: 'site_abo_staff_invite', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function invite(Request $request): JsonResponse
    {
        return $this->safe(fn () => $this->useCase->invite($this->body($request), $this->me()));
    }

    /** Retirer le rôle → 200 { userId, unassigned } ; 404 STAFF_NOT_FOUND */
    #[Route('/api/site/abonnements/intervenants/{userId}', name: 'site_abo_staff_revoke', requirements: ['userId' => '\d+'], methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function revoke(int $userId): JsonResponse
    {
        return $this->safe(fn () => $this->useCase->revoke($userId));
    }

    /** L'intervenant d'une date `{ staffUserId: int | null }` → 200 { assignment: { occurrenceId, staffUserId, staffName }, subscription } ; 422 STAFF_INVALID */
    #[Route('/api/site/abonnements/{id}/occurrences/{occurrenceId}/intervenant', name: 'site_abo_occurrence_staff', requirements: ['id' => '\d+', 'occurrenceId' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function assign(Request $request, int $id, int $occurrenceId): JsonResponse
    {
        return $this->safe(fn () => $this->useCase->assign($id, $occurrenceId, $this->body($request), $this->by(), self::locale($request)));
    }

    // ---- intervenant ------------------------------------------------------------------------------------------------------------------------

    /** Sa journée `?date=AAAA-MM-JJ` → 200 { date, timezone, counts, sessions, appointments } */
    #[Route('/api/staff/abonnements/aujourdhui', name: 'staff_abo_today', methods: ['GET'])]
    #[IsGranted('ROLE_STAFF_PROVIDER')]
    public function today(Request $request): JsonResponse
    {
        return $this->safe(fn () => $this->useCase->day($this->me(), self::query($request, 'date'), self::locale($request)));
    }

    /** Ses 14 jours `?from=AAAA-MM-JJ` (30 jours en arrière à 14 devant) → 200 { from, to, timezone, appointments } */
    #[Route('/api/staff/abonnements/semaine', name: 'staff_abo_week', methods: ['GET'])]
    #[IsGranted('ROLE_STAFF_PROVIDER')]
    public function week(Request $request): JsonResponse
    {
        return $this->safe(fn () => $this->useCase->week($this->me(), self::query($request, 'from'), self::locale($request)));
    }

    /** « Fait » sur une de ses dates : JSON `{ note? }` ou multipart (`note`, `photo`) → 200 { occurrence, changed } */
    #[Route('/api/staff/abonnements/occurrences/{occurrenceId}/fait', name: 'staff_abo_occurrence_done', requirements: ['occurrenceId' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_STAFF_PROVIDER')]
    public function done(Request $request, int $occurrenceId): JsonResponse
    {
        $photo = $request->files->get('photo');
        $note = $request->request->get('note');
        if ($note === null && !$request->files->count()) {
            $note = $this->body($request)['note'] ?? null;
        }

        return $this->safe(fn () => $this->useCase->markDone($this->me(), $occurrenceId, is_string($note) ? $note : null, $photo instanceof UploadedFile ? $photo : null, $request->getHost(), self::locale($request)));
    }

    /** « Absent » `{ note? }` → 200 { occurrence, changed } */
    #[Route('/api/staff/abonnements/occurrences/{occurrenceId}/absent', name: 'staff_abo_occurrence_missed', requirements: ['occurrenceId' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_STAFF_PROVIDER')]
    public function missed(Request $request, int $occurrenceId): JsonResponse
    {
        return $this->safe(fn () => $this->useCase->markMissed($this->me(), $occurrenceId, $this->body($request), self::locale($request)));
    }

    /** Sa séance entière `{ absentOccurrenceIds?: [], note? }` → 200 { slotId, done, missed, unchanged, failed } */
    #[Route('/api/staff/abonnements/creneaux/{slotId}/fait', name: 'staff_abo_slot_done', requirements: ['slotId' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_STAFF_PROVIDER')]
    public function session(Request $request, int $slotId): JsonResponse
    {
        return $this->safe(fn () => $this->useCase->markSession($this->me(), $slotId, $this->body($request), $request->getHost(), self::locale($request)));
    }

    /** La photo d'une de ses dates → 200 image/jpeg ; 404 */
    #[Route('/api/staff/abonnements/occurrences/{occurrenceId}/preuve', name: 'staff_abo_occurrence_proof', requirements: ['occurrenceId' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_STAFF_PROVIDER')]
    public function proof(int $occurrenceId): Response
    {
        try {
            $file = $this->useCase->proof($this->me(), $occurrenceId);
        } catch (AboException $e) {
            return $this->json($e->toArray(), $e->status);
        }
        // jamais publique ni gardée par un cache partagé (BinaryFileResponse est publique par défaut)
        $response = new BinaryFileResponse($file['path'], 200, ['Content-Type' => $file['mime'], 'X-Content-Type-Options' => 'nosniff'], false);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'preuve.jpg');

        return $response;
    }

    // ---- interne ----------------------------------------------------------------------------------------------------------------------------

    private function safe(callable $fn): JsonResponse
    {
        try {
            return $this->json($fn());
        } catch (AboException $e) {
            return $this->json($e->toArray(), $e->status);
        }
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        if ($request->getContent() === '' || strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            return [];
        }
        $body = json_decode((string) $request->getContent(), true);

        return is_array($body) ? $body : [];
    }

    private function me(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    private function by(): ?string
    {
        $user = $this->getUser();

        return $user instanceof User ? (trim((string) $user->getFirstname()) ?: $user->getUserIdentifier()) : null;
    }

    private static function query(Request $request, string $key): ?string
    {
        $v = $request->query->get($key);

        return $v !== null ? (string) $v : null;
    }

    private static function locale(Request $request): string
    {
        return $request->query->get('locale') === 'en' ? 'en' : 'fr';
    }
}
