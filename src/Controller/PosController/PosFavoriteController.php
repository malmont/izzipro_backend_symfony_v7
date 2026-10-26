<?php

namespace App\Controller\PosController;

use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\Services\MediaUrlResolver;
use App\UseCase\PosFavoriteUseCase\PosFavoriteUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Grille de favoris de la caisse (F10) : grille (caisse, ETag et 304 comme le pack hors ligne), épinglés d'une portée et leur
 * remplacement (responsable). Rôles : access_control ^/api/pos/favorites ; responsable exigé dans le service pour les épinglés.
 */
class PosFavoriteController extends AbstractController
{
    public function __construct(private readonly PosFavoriteUseCase $favorites, private readonly MediaUrlResolver $media)
    {
    }

    /** ?cashRegisterId= ; If-None-Match → 304 */
    #[Route('/api/pos/favorites', name: 'pos_favorites', methods: ['GET'])]
    public function grid(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $grid = $this->favorites->grid($request->query->get('cashRegisterId'), $this->media->getProductsBaseUrl($request->getSchemeAndHttpHost()));
            $etag = '"' . $grid['etag'] . '"';
            if (trim((string) preg_replace('#^W/#', '', (string) $request->headers->get('If-None-Match', ''))) === $etag) {
                return new JsonResponse(null, 304, ['ETag' => $etag]);
            }
            $response = $this->json($grid['body']);
            $response->headers->set('ETag', $etag);
            $response->headers->set('Cache-Control', 'private, no-cache');

            return $response;
        });
    }

    /** ?cashRegisterId= (sans : épinglés du site) ; responsable */
    #[Route('/api/pos/favorites/pins', name: 'pos_favorites_pins', methods: ['GET'])]
    public function pins(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->favorites->pins($this->user(), $request->query->get('cashRegisterId'))));
    }

    /** {cashRegisterId?: int|null, pins: [{productId, variantId?}]} : remplace la liste ordonnée ; responsable */
    #[Route('/api/pos/favorites/pins', name: 'pos_favorites_pins_replace', methods: ['PUT'])]
    public function replacePins(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $body = json_decode($request->getContent(), true);

            return $this->json($this->favorites->replacePins($this->user(), is_array($body) ? $body : []));
        });
    }

    private function guard(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (CashRegisterException $e) {
            return $this->json($e->toBody(), $e->status);
        }
    }

    private function user(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new CashRegisterException('UNAUTHENTICATED', 'Utilisateur non connecté', 401);
        }

        return $user;
    }
}
