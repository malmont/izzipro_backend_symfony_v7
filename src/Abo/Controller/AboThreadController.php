<?php

namespace App\Abo\Controller;

use App\Abo\Services\AboException;
use App\Abo\UseCase\AboThreadUseCase;
use App\Entity\User;
use App\Services\SubscriptionService\SubscriptionException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Le fil de l'abonné (lot ABO-3b, 20/11/2026, `docs/boutique.md` § « Intervenant et fil de l'abonné »), dates ATOM à l'heure du magasin.
 * - ÉQUIPE, `/api/site/abonnements/{id}/fil` et `/api/site/abonnements/fils` : `ROLE_ADMIN`, module Boutique ; fermé au directeur d'un
 *   groupe entré, en lecture comme en écriture.
 * - ABONNÉ, `/api/subscriptions/{id}/fil` : `ROLE_USER_INTERNET`, son abonnement seulement (404 sinon).
 * `?before=` : les 50 messages d'avant ce numéro.
 */
class AboThreadController extends AbstractController
{
    private const MAX_BODY_BYTES = 16384;

    public function __construct(private readonly AboThreadUseCase $useCase)
    {
    }

    /** Le fil (les 50 derniers messages, ou d'avant `before`), les messages de l'abonné marqués lus → 200 { messages: [{ id, author, authorName, body, occurrenceId, createdAt, read }] } */
    #[Route('/api/site/abonnements/{id}/fil', name: 'site_abo_thread', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function teamThread(Request $request, int $id): JsonResponse
    {
        return $this->team(fn () => $this->useCase->teamThread($id, $request->query->get('before')));
    }

    /** Répondre `{ body }` (1 à 2000 caractères) → 201 { message, emailed } */
    #[Route('/api/site/abonnements/{id}/fil', name: 'site_abo_thread_post', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function teamPost(Request $request, int $id): JsonResponse
    {
        $user = $this->getUser();

        return $this->team(fn () => $this->useCase->teamPost($id, $this->body($request), $user instanceof User ? $user : null, $request->getHost(), self::locale($request)), 201);
    }

    /** Les fils où l'abonné attend une réponse → 200 { threads: [{ subscriptionId, plan, unread, lastAt, customer }] } */
    #[Route('/api/site/abonnements/fils', name: 'site_abo_threads_unread', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function unread(Request $request): JsonResponse
    {
        return $this->team(fn () => $this->useCase->unread(self::locale($request)));
    }

    /** Le fil de son abonnement, les messages de l'équipe marqués lus → 200 { messages, unread } */
    #[Route('/api/subscriptions/{id}/fil', name: 'api_subscriptions_thread', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER_INTERNET')]
    public function clientThread(Request $request, int $id): JsonResponse
    {
        return $this->client(fn () => $this->useCase->clientThread($this->customer(), $id, $request->query->get('before')));
    }

    /** Écrire `{ body }` (1 à 2000 caractères, 20 par jour) → 201 { message } ; 429 THREAD_RATE_LIMITED */
    #[Route('/api/subscriptions/{id}/fil', name: 'api_subscriptions_thread_post', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER_INTERNET')]
    public function clientPost(Request $request, int $id): JsonResponse
    {
        return $this->client(fn () => $this->useCase->clientPost($this->customer(), $id, $this->body($request), $request->getHost(), self::locale($request)), 201);
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

    private static function locale(Request $request): string
    {
        return $request->query->get('locale') === 'en' ? 'en' : 'fr';
    }
}
