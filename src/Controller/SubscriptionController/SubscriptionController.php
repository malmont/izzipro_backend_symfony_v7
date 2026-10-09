<?php

namespace App\Controller\SubscriptionController;

use App\Entity\User;
use App\Services\SubscriptionService\SubscriptionException;
use App\UseCase\SubscriptionUseCase\ListSubscriptionPlansUseCase;
use App\UseCase\SubscriptionUseCase\ManageSubscriptionUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Abonnements de la boutique réglable (09/10/2026). Formules : lecture publique. Souscription et gestion : client
 * connecté (ROLE_USER_INTERNET ; un invité reçoit 401). Montants en cents, devise de la formule.
 */
class SubscriptionController extends AbstractController
{
    private const MAX_BODY_BYTES = 16384;

    public function __construct(
        private readonly ListSubscriptionPlansUseCase $plans,
        private readonly ManageSubscriptionUseCase $subscriptions
    ) {
    }

    #[Route('/api/subscription-plans', name: 'api_subscription_plans', methods: ['GET'])]
    public function plans(Request $request): JsonResponse
    {
        $productId = $request->query->get('productId');

        return $this->json($this->plans->execute(is_numeric($productId) ? (int) $productId : null, $this->locale($request)));
    }

    #[Route('/api/subscriptions', name: 'api_subscriptions_create', methods: ['POST'])]
    #[IsGranted('ROLE_USER_INTERNET')]
    public function subscribe(Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->subscriptions->subscribe($this->customer(), $this->body($request), $this->locale($request)), 201));
    }

    #[Route('/api/subscriptions', name: 'api_subscriptions_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER_INTERNET')]
    public function list(Request $request): JsonResponse
    {
        return $this->json($this->subscriptions->list($this->customer(), $this->locale($request)));
    }

    #[Route('/api/subscriptions/portal-session', name: 'api_subscriptions_portal', methods: ['POST'])]
    #[IsGranted('ROLE_USER_INTERNET')]
    public function portal(Request $request): JsonResponse
    {
        $body = $this->body($request);
        $fallback = 'https://' . ($request->headers->get('x-tenant-host') ?: $request->getHost()) . '/dashboard';

        return $this->handle(fn () => $this->json(['url' => $this->subscriptions->portalUrl($this->customer(), $body['returnUrl'] ?? null, $fallback)]));
    }

    #[Route('/api/subscriptions/{id}', name: 'api_subscriptions_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER_INTERNET')]
    public function get(int $id, Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->subscriptions->get($id, $this->customer(), $this->locale($request))));
    }

    #[Route('/api/subscriptions/{id}/cancel', name: 'api_subscriptions_cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER_INTERNET')]
    public function cancel(int $id, Request $request): JsonResponse
    {
        $body = $this->body($request);

        return $this->handle(fn () => $this->json($this->subscriptions->cancel($id, $this->customer(), ($body['atPeriodEnd'] ?? true) !== false, $this->locale($request), $request->getSchemeAndHttpHost())));
    }

    #[Route('/api/subscriptions/{id}/resume', name: 'api_subscriptions_resume', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER_INTERNET')]
    public function resume(int $id, Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->subscriptions->resume($id, $this->customer(), $this->locale($request))));
    }

    #[Route('/api/subscriptions/{id}/pause', name: 'api_subscriptions_pause', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER_INTERNET')]
    public function pause(int $id, Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->subscriptions->pause($id, $this->customer(), $this->locale($request), $request->getSchemeAndHttpHost())));
    }

    #[Route('/api/subscriptions/{id}/change-plan', name: 'api_subscriptions_change_plan', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER_INTERNET')]
    public function changePlan(int $id, Request $request): JsonResponse
    {
        $body = $this->body($request);

        return $this->handle(fn () => $this->json($this->subscriptions->changePlan($id, $this->customer(), $body['planId'] ?? null, $this->locale($request))));
    }

    private function customer(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        if ($request->getContent() === '' || strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            return [];
        }
        $body = json_decode($request->getContent(), true);

        return is_array($body) ? $body : [];
    }

    private function locale(Request $request): string
    {
        $locale = (string) $request->query->get('locale', 'fr');

        return preg_match('/^[a-z]{2}$/', $locale) ? $locale : 'fr';
    }

    private function handle(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (SubscriptionException $e) {
            return $this->json(array_filter(['error' => $e->getMessage(), 'errors' => $e->errors]), $e->getStatusCode());
        }
    }
}
