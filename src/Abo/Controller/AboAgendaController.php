<?php

namespace App\Abo\Controller;

use App\Abo\Services\AboException;
use App\Abo\UseCase\AboAgendaUseCase;
use App\Entity\User;
use App\Services\SubscriptionService\SubscriptionException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Agenda des prestations (lot ABO-2, 20/11/2026, `docs/boutique.md`), dates ATOM à l'heure du magasin.
 * - ÉQUIPE, `/api/site/abonnements/...` : `ROLE_ADMIN` (`access_control`), module Boutique (`ModuleAccessService`) ; gestion web et
 *   application de gestion mobile. Refus `{ error: CODE, code, message, field? }`.
 * - ABONNÉ, `/api/subscriptions/{id}/...` et `/api/subscription-plans/{id}/creneaux` : `ROLE_USER_INTERNET`, son abonnement seulement (404
 *   sinon) ; boutique web et commerce mobile. Refus de la forme des autres routes `/api/subscriptions`.
 */
class AboAgendaController extends AbstractController
{
    private const MAX_BODY_BYTES = 16384;

    public function __construct(private readonly AboAgendaUseCase $useCase)
    {
    }

    // ---- équipe ---------------------------------------------------------------------------------------------------------------------------

    /** Les créneaux et leurs places restantes `?benefitId=&from=AAAA-MM-JJ&to=AAAA-MM-JJ` (14 jours par défaut, 62 au plus) → 200 { slots } */
    #[Route('/api/site/abonnements/creneaux', name: 'site_abo_slots', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function slots(Request $request): JsonResponse
    {
        return $this->team(fn () => $this->useCase->slots($request->query->get('benefitId') !== null ? $request->query->getInt('benefitId') : null,
            $request->query->get('from') !== null ? (string) $request->query->get('from') : null, $request->query->get('to') !== null ? (string) $request->query->get('to') : null));
    }

    /** Les droits à planifier, par abonnement → 200 { subscriptions } */
    #[Route('/api/site/abonnements/a-planifier', name: 'site_abo_to_schedule', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function toSchedule(): JsonResponse
    {
        return $this->team(fn () => $this->useCase->toSchedule());
    }

    /** Donne une date `{ benefitId, occurrenceId?, slotId | startsAt }` → 201 { appointment, subscription } ; 409 SLOT_FULL | SLOT_ALREADY_BOOKED | BALANCE_EMPTY */
    #[Route('/api/site/abonnements/{id}/rendez-vous', name: 'site_abo_appointment_create', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function teamBook(Request $request, int $id): JsonResponse
    {
        return $this->team(fn () => $this->useCase->teamBook($id, $this->body($request), $this->by(), self::locale($request)), 201);
    }

    /** Déplace une date `{ slotId | startsAt }`, à tout moment, sans perte → 200 { appointment, subscription } */
    #[Route('/api/site/abonnements/{id}/rendez-vous/{occurrenceId}', name: 'site_abo_appointment_move', requirements: ['id' => '\d+', 'occurrenceId' => '\d+'], methods: ['PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    public function teamMove(Request $request, int $id, int $occurrenceId): JsonResponse
    {
        return $this->team(fn () => $this->useCase->teamMove($id, $occurrenceId, $this->body($request), $this->by(), self::locale($request)));
    }

    /** Annule une date, sans perte (le droit redevient à planifier) → 200, la fiche */
    #[Route('/api/site/abonnements/{id}/rendez-vous/{occurrenceId}', name: 'site_abo_appointment_cancel', requirements: ['id' => '\d+', 'occurrenceId' => '\d+'], methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function teamCancel(Request $request, int $id, int $occurrenceId): JsonResponse
    {
        return $this->team(fn () => $this->useCase->teamCancel($id, $occurrenceId, $this->by(), self::locale($request)));
    }

    /** Annule un créneau `{ reason }` : ses droits redeviennent à planifier, chaque abonné est prévenu par courriel → 200 { slotId, released } */
    #[Route('/api/site/abonnements/creneaux/{slotId}/annulation', name: 'site_abo_slot_cancel', requirements: ['slotId' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function cancelSlot(Request $request, int $slotId): JsonResponse
    {
        return $this->team(fn () => $this->useCase->cancelSlot($slotId, $this->body($request), $this->by(), $request->getHost(), self::locale($request)));
    }

    // ---- abonné ---------------------------------------------------------------------------------------------------------------------------

    /** Les créneaux ouverts au choix de l'abonné pour une prestation de sa formule `?benefitId=` (8 semaines) → 200 { slots } */
    #[Route('/api/subscriptions/{id}/creneaux', name: 'api_subscriptions_slots', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER_INTERNET')]
    public function clientSlots(Request $request, int $id): JsonResponse
    {
        return $this->client(fn () => $this->useCase->clientSlots($this->customer(), $id, $request->query->getInt('benefitId')));
    }

    /** Les créneaux à choisir à la souscription d'une formule (prestation `client_at_subscription`) `?benefitId=` → 200 { slots } */
    #[Route('/api/subscription-plans/{id}/creneaux', name: 'api_subscription_plan_slots', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER_INTERNET')]
    public function planSlots(Request $request, int $id): JsonResponse
    {
        return $this->client(fn () => $this->useCase->planSlots($id, $request->query->getInt('benefitId')));
    }

    /** Réserve `{ benefitId, slotId }` → 201 { appointment, subscription } ; 409 SLOT_FULL | SLOT_ALREADY_BOOKED | SLOT_AFTER_VALIDITY | BALANCE_EMPTY */
    #[Route('/api/subscriptions/{id}/rendez-vous', name: 'api_subscriptions_appointment_create', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER_INTERNET')]
    public function clientBook(Request $request, int $id): JsonResponse
    {
        return $this->client(fn () => $this->useCase->clientBook($this->customer(), $id, $this->body($request), self::locale($request)), 201);
    }

    /** Déplace `{ slotId }` avant le délai → 200 { appointment, subscription } ; 409 CANCEL_DEADLINE_PASSED */
    #[Route('/api/subscriptions/{id}/rendez-vous/{occurrenceId}', name: 'api_subscriptions_appointment_move', requirements: ['id' => '\d+', 'occurrenceId' => '\d+'], methods: ['PATCH'])]
    #[IsGranted('ROLE_USER_INTERNET')]
    public function clientMove(Request $request, int $id, int $occurrenceId): JsonResponse
    {
        return $this->client(fn () => $this->useCase->clientMove($this->customer(), $id, $occurrenceId, $this->body($request), self::locale($request)));
    }

    /** Annule : avant le délai, le droit redevient à planifier ; après, il compte comme utilisé (`late_cancelled`) → 200 { cancellation, subscription } */
    #[Route('/api/subscriptions/{id}/rendez-vous/{occurrenceId}', name: 'api_subscriptions_appointment_cancel', requirements: ['id' => '\d+', 'occurrenceId' => '\d+'], methods: ['DELETE'])]
    #[IsGranted('ROLE_USER_INTERNET')]
    public function clientCancel(Request $request, int $id, int $occurrenceId): JsonResponse
    {
        return $this->client(fn () => $this->useCase->clientCancel($this->customer(), $id, $occurrenceId, self::locale($request)));
    }

    // ---- lot ABO-4 : liste d'attente --------------------------------------------------------------------------------------------------------

    /** La liste d'attente d'un créneau (lecture seule : rien n'est promu, une offre échue se montre échue) → 200 { slotId, freePlaces, waitlist } */
    #[Route('/api/site/abonnements/creneaux/{slotId}/attente', name: 'site_abo_slot_waitlist', requirements: ['slotId' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function slotWaitlist(int $slotId): JsonResponse
    {
        return $this->team(fn () => $this->useCase->slotWaitlist($slotId));
    }

    /** L'équipe propose la place libre à cette personne (même à moins d'1 h) → 200 { wait } ; 409 SLOT_FULL ; 404 WAITLIST_NOT_FOUND */
    #[Route('/api/site/abonnements/attente/{waitId}/proposer', name: 'site_abo_waitlist_propose', requirements: ['waitId' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function proposeWait(Request $request, int $waitId): JsonResponse
    {
        return $this->team(fn () => $this->useCase->proposeWait($waitId, $this->by(), $request->getHost()));
    }

    /** Les attentes ouvertes de l'abonnement (lecture seule) → 200 { waitlist } */
    #[Route('/api/subscriptions/{id}/attente', name: 'api_subscriptions_waitlist', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER_INTERNET')]
    public function myWaits(int $id): JsonResponse
    {
        return $this->client(fn () => $this->useCase->myWaits($this->customer(), $id));
    }

    /** S'inscrire sur la liste d'attente d'un créneau complet → 201 { wait } ; 409 SLOT_NOT_FULL | ALREADY_WAITING | WAITLIST_LIMIT | BALANCE_EMPTY */
    #[Route('/api/subscriptions/{id}/creneaux/{slotId}/attente', name: 'api_subscriptions_waitlist_join', requirements: ['id' => '\d+', 'slotId' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER_INTERNET')]
    public function joinWait(Request $request, int $id, int $slotId): JsonResponse
    {
        return $this->client(fn () => $this->useCase->joinWait($this->customer(), $id, $slotId, $request->getHost()), 201);
    }

    /** Sortir de la liste d'attente (une place offerte passe à la suite) → 200 { left } ; 404 WAITLIST_NOT_FOUND */
    #[Route('/api/subscriptions/{id}/creneaux/{slotId}/attente', name: 'api_subscriptions_waitlist_leave', requirements: ['id' => '\d+', 'slotId' => '\d+'], methods: ['DELETE'])]
    #[IsGranted('ROLE_USER_INTERNET')]
    public function leaveWait(Request $request, int $id, int $slotId): JsonResponse
    {
        return $this->client(fn () => $this->useCase->leaveWait($this->customer(), $id, $slotId, $request->getHost()));
    }

    /** Accepter la place offerte → 201 { appointment, subscription } ; 409 OFFER_EXPIRED ; 404 WAITLIST_NOT_FOUND */
    #[Route('/api/subscriptions/{id}/attente/{waitId}/accepter', name: 'api_subscriptions_waitlist_accept', requirements: ['id' => '\d+', 'waitId' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER_INTERNET')]
    public function acceptWait(Request $request, int $id, int $waitId): JsonResponse
    {
        return $this->client(fn () => $this->useCase->acceptWait($this->customer(), $id, $waitId, $request->getHost(), self::locale($request)), 201);
    }

    // ---- interne ----------------------------------------------------------------------------------------------------------------------------

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
            return $this->json(array_filter(['error' => $e->getMessage(), 'errors' => $e->errors]) + ['code' => $e->code(), 'message' => $e->getMessage()] + $e->details, $e->getStatusCode());
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
