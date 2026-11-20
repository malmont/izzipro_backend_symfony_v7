<?php

namespace App\Abo\Controller;

use App\Abo\Services\AboException;
use App\Abo\UseCase\AboDeliveryUseCase;
use App\Entity\User;
use App\Services\SubscriptionService\SubscriptionException;
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
 * « Fait » et suivi des droits (lot ABO-3a, 20/11/2026, `docs/boutique.md` § « Fait et suivi »), dates ATOM à l'heure du magasin.
 * - ÉQUIPE, `/api/site/abonnements/...` : `ROLE_ADMIN` (`access_control`), module Boutique (`ModuleAccessService`) ; gestion web et
 *   application de gestion mobile (onglet Abonnements). Refus `{ error: CODE, code, message, field? }`. Le directeur d'un groupe entré
 *   lit, il n'écrit rien (`EntryModeGuardSubscriber`).
 * - ABONNÉ, `/api/subscriptions/{id}/...` : `ROLE_USER_INTERNET`, son abonnement seulement (404 sinon) ; boutique web et commerce mobile.
 * Une photo de preuve n'a jamais d'adresse publique : elle est servie par ces routes seulement, sans cache partagé.
 */
class AboDeliveryController extends AbstractController
{
    private const MAX_BODY_BYTES = 16384;

    public function __construct(private readonly AboDeliveryUseCase $useCase)
    {
    }

    // ---- équipe ---------------------------------------------------------------------------------------------------------------------------

    /** « À faire aujourd'hui » `?date=AAAA-MM-JJ` (jour du magasin, aujourd'hui par défaut) → 200 { date, timezone, counts, sessions, appointments } */
    #[Route('/api/site/abonnements/aujourdhui', name: 'site_abo_today', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function today(Request $request): JsonResponse
    {
        return $this->team(fn () => $this->useCase->today($request->query->get('date') !== null ? (string) $request->query->get('date') : null, self::locale($request)));
    }

    /** Les contestations en attente → 200 { contestations } */
    #[Route('/api/site/abonnements/contestations', name: 'site_abo_contestations', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function contestations(Request $request): JsonResponse
    {
        return $this->team(fn () => $this->useCase->contestations(self::locale($request)));
    }

    /**
     * Marque « fait » une date planifiée : JSON `{ note? }` ou multipart (`note`, `photo` : JPEG, PNG ou WebP, 10 Mo au plus) → 200
     * { occurrence, changed, subscription } ; déjà fait : 200, `changed: false`, aucun second courriel.
     */
    #[Route('/api/site/abonnements/{id}/occurrences/{occurrenceId}/fait', name: 'site_abo_occurrence_done', requirements: ['id' => '\d+', 'occurrenceId' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function done(Request $request, int $id, int $occurrenceId): JsonResponse
    {
        $photo = $request->files->get('photo');
        $note = $request->request->get('note');
        if ($note === null && !$request->files->count()) {
            $note = $this->body($request)['note'] ?? null;
        }

        return $this->team(fn () => $this->useCase->markDone($id, $occurrenceId, is_string($note) ? $note : null, $photo instanceof UploadedFile ? $photo : null, $this->by(), $request->getHost(), self::locale($request)));
    }

    /** Marque « absent » `{ note? }` (compté comme utilisé) → 200 { occurrence, changed, subscription } */
    #[Route('/api/site/abonnements/{id}/occurrences/{occurrenceId}/absent', name: 'site_abo_occurrence_missed', requirements: ['id' => '\d+', 'occurrenceId' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function missed(Request $request, int $id, int $occurrenceId): JsonResponse
    {
        return $this->team(fn () => $this->useCase->markMissed($id, $occurrenceId, $this->body($request), $this->by(), self::locale($request)));
    }

    /** Corrige un « fait » ou un « absent » posé par erreur `{ reason }` → 200 { occurrence, subscription } ; 409 CONTESTATION_PENDING | UNDO_PERIOD_CLOSED */
    #[Route('/api/site/abonnements/{id}/occurrences/{occurrenceId}/correction', name: 'site_abo_occurrence_undo', requirements: ['id' => '\d+', 'occurrenceId' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function undo(Request $request, int $id, int $occurrenceId): JsonResponse
    {
        return $this->team(fn () => $this->useCase->undo($id, $occurrenceId, $this->body($request), $this->by(), self::locale($request)));
    }

    /** Tranche une contestation `{ decision: kept | returned, note? }` → 200 { occurrence, subscription } */
    #[Route('/api/site/abonnements/{id}/occurrences/{occurrenceId}/contestation', name: 'site_abo_occurrence_resolve', requirements: ['id' => '\d+', 'occurrenceId' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function resolve(Request $request, int $id, int $occurrenceId): JsonResponse
    {
        return $this->team(fn () => $this->useCase->resolve($id, $occurrenceId, $this->body($request), $this->by(), self::locale($request)));
    }

    /** La photo de preuve → 200 image/jpeg ; 404 PROOF_NOT_FOUND */
    #[Route('/api/site/abonnements/{id}/occurrences/{occurrenceId}/preuve', name: 'site_abo_occurrence_proof', requirements: ['id' => '\d+', 'occurrenceId' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function teamProof(int $id, int $occurrenceId): Response
    {
        try {
            return self::image($this->useCase->teamProof($id, $occurrenceId));
        } catch (AboException $e) {
            return $this->json($e->toArray(), $e->status);
        }
    }

    /** Une séance entière `{ absentSubscriptionIds?: [], note? }` : « fait » pour chaque inscrit, sauf les absents → 200 { slotId, done, missed, unchanged, failed } */
    #[Route('/api/site/abonnements/creneaux/{slotId}/fait', name: 'site_abo_slot_done', requirements: ['slotId' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function session(Request $request, int $slotId): JsonResponse
    {
        return $this->team(fn () => $this->useCase->markSession($slotId, $this->body($request), $this->by(), $request->getHost(), self::locale($request)));
    }

    // ---- abonné ---------------------------------------------------------------------------------------------------------------------------

    /** L'avis de l'abonné `{ decision: confirmed | contested, comment? }` (commentaire exigé pour contester) → 200 { acknowledgement, subscription } */
    #[Route('/api/subscriptions/{id}/occurrences/{occurrenceId}/avis', name: 'api_subscriptions_occurrence_ack', requirements: ['id' => '\d+', 'occurrenceId' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER_INTERNET')]
    public function acknowledge(Request $request, int $id, int $occurrenceId): JsonResponse
    {
        return $this->client(fn () => $this->useCase->acknowledge($this->customer(), $id, $occurrenceId, $this->body($request), self::locale($request)));
    }

    /** La photo de preuve d'une de ses prestations → 200 image/jpeg */
    #[Route('/api/subscriptions/{id}/occurrences/{occurrenceId}/preuve', name: 'api_subscriptions_occurrence_proof', requirements: ['id' => '\d+', 'occurrenceId' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER_INTERNET')]
    public function clientProof(int $id, int $occurrenceId): Response
    {
        try {
            return self::image($this->useCase->clientProof($this->customer(), $id, $occurrenceId));
        } catch (SubscriptionException $e) {
            return $this->refusal($e);
        }
    }

    /** Couper ou rallumer le courriel « prestation faite » `{ doneEmails: bool }` → 200 { doneEmails } */
    #[Route('/api/subscriptions/{id}/courriels', name: 'api_subscriptions_emails', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    #[IsGranted('ROLE_USER_INTERNET')]
    public function emails(Request $request, int $id): JsonResponse
    {
        return $this->client(fn () => $this->useCase->setDoneEmails($this->customer(), $id, $this->body($request)));
    }

    // ---- interne ----------------------------------------------------------------------------------------------------------------------------

    /** @param array{path: string, mime: string, sha256: ?string} $file */
    private static function image(array $file): BinaryFileResponse
    {
        // `public: false` : BinaryFileResponse se déclare publique par défaut (un cache partagé garderait la photo d'un abonné)
        $response = new BinaryFileResponse($file['path'], 200, ['Content-Type' => $file['mime'], 'X-Content-Type-Options' => 'nosniff'], false);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'preuve.jpg');
        if ($file['sha256'] !== null) {
            $response->headers->set('X-Proof-Sha256', $file['sha256']);
        }

        return $response;
    }

    private function team(callable $fn, int $status = 200): JsonResponse
    {
        try {
            return $this->json($fn(), $status);
        } catch (AboException $e) {
            return $this->json($e->toArray(), $e->status);
        }
    }

    private function client(callable $fn, int $status = 200): JsonResponse
    {
        try {
            return $this->json($fn(), $status);
        } catch (SubscriptionException $e) {
            return $this->refusal($e);
        }
    }

    private function refusal(SubscriptionException $e): JsonResponse
    {
        return $this->json(array_filter(['error' => $e->getMessage(), 'errors' => $e->errors]) + ['code' => $e->code(), 'message' => $e->getMessage()] + $e->details, $e->getStatusCode());
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

    private function customer(): User
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

    private static function locale(Request $request): string
    {
        return $request->query->get('locale') === 'en' ? 'en' : 'fr';
    }
}
