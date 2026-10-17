<?php

namespace App\MemoiresVivantes\Controller;

use App\Entity\User;
use App\MemoiresVivantes\Exception\ApiErrorException;
use App\MemoiresVivantes\UseCase\Account\DeleteMobileAccountUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Suppression du compte depuis l'application mobile (17/10/2026, exigence App Store et Google Play). Connexion
 * obligatoire (access_control `^/api/memoires/mobile/`), mot de passe redemandé, essais limités par compte.
 * Contrat : docs/memoires-vivantes.md, « Suppression du compte ».
 */
#[Route('/api/memoires/mobile/account')]
class MobileAccountController extends AbstractController
{
    public function __construct(
        private readonly DeleteMobileAccountUseCase $delete,
        #[Autowire(service: 'limiter.api_login_account')] private readonly RateLimiterFactory $attempts
    ) {}

    /** {password} → 200 {deleted: true, booksDeleted, printOrdersAnonymized} ; 401 PASSWORD_INVALID ; 403 ADMIN_ACCOUNT ; 429 TOO_MANY_ATTEMPTS */
    #[Route('', methods: ['DELETE'])]
    public function delete(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) return $this->json(['error' => 'Unauthorized'], 401);
        $limiter = $this->attempts->create('mobile_account_delete_' . $user->getId());
        $limit = $limiter->consume(1);
        if (!$limit->isAccepted()) {
            $retry = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            return $this->json(['error' => 'TOO_MANY_ATTEMPTS', 'message' => 'Trop d\'essais. Réessayez dans quelques minutes.'], 429, ['Retry-After' => (string) $retry]);
        }
        $data = json_decode($request->getContent(), true);
        try {
            $result = $this->delete->execute($user, is_array($data) ? (string) ($data['password'] ?? '') : '');
        } catch (ApiErrorException $e) {
            return $this->json($e->toBody(), $e->status);
        }
        $limiter->reset();

        return $this->json($result);
    }
}
