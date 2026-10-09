<?php

namespace App\Controller\ReviewController;

use App\Entity\User;
use App\Services\ReviewService\ReviewException;
use App\UseCase\ReviewUseCase\GetReviewEligibilityUseCase;
use App\UseCase\ReviewUseCase\ListProductReviewsUseCase;
use App\UseCase\ReviewUseCase\ManageOwnReviewUseCase;
use App\UseCase\ReviewUseCase\SubmitReviewUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Avis clients (09/10/2026). Lecture publique des avis publiés d'un produit ; droit d'écrire, dépôt et gestion de ses
 * avis : client connecté (access_control). Les routes sous /api/products/{id} passent avant celles d'API Platform.
 */
class ReviewController extends AbstractController
{
    private const MAX_BODY_BYTES = 16384;

    public function __construct(
        private readonly ListProductReviewsUseCase $list,
        private readonly GetReviewEligibilityUseCase $eligibility,
        private readonly SubmitReviewUseCase $submit,
        private readonly ManageOwnReviewUseCase $own,
        private readonly RateLimiterFactory $reviewSubmitLimiter
    ) {
    }

    #[Route('/api/products/{id}/reviews', name: 'api_product_reviews', methods: ['GET'], priority: 20, requirements: ['id' => '\d+'])]
    public function list(int $id, Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->list->execute($id, $request->query->all(), $this->locale($request))));
    }

    #[Route('/api/products/{id}/reviews/eligibility', name: 'api_product_review_eligibility', methods: ['GET'], priority: 20, requirements: ['id' => '\d+'])]
    public function eligibility(int $id, Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->eligibility->execute($this->customer(), $id, $this->locale($request))));
    }

    #[Route('/api/products/{id}/reviews', name: 'api_product_review_submit', methods: ['POST'], priority: 20, requirements: ['id' => '\d+'])]
    public function submit(int $id, Request $request): JsonResponse
    {
        $user = $this->customer();
        $limiter = $this->reviewSubmitLimiter->create('user:' . $user->getId());
        if (!$limiter->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Trop d\'avis envoyés : réessayez plus tard.'], 429);
        }

        return $this->handle(fn () => $this->json($this->submit->execute($user, $id, $this->body($request), $this->locale($request)), 201));
    }

    #[Route('/api/reviews/mine', name: 'api_reviews_mine', methods: ['GET'])]
    public function mine(Request $request): JsonResponse
    {
        return $this->json($this->own->mine($this->customer(), $this->locale($request)));
    }

    #[Route('/api/reviews/{id}', name: 'api_review_update', methods: ['PUT', 'PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->own->update($this->customer(), $id, $this->body($request), $this->locale($request))));
    }

    #[Route('/api/reviews/{id}', name: 'api_review_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        return $this->handle(function () use ($id) {
            $this->own->delete($this->customer(), $id);

            return new JsonResponse(null, 204);
        });
    }

    private function customer(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new ReviewException(401, 'Connexion requise.');
        }

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
        } catch (ReviewException $e) {
            return $this->json(array_filter(['error' => $e->getMessage(), 'errors' => $e->errors, 'reason' => $e->reason]), $e->getStatusCode());
        }
    }
}
