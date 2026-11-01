<?php

namespace App\Controller\Account;

use App\Entity\User;
use App\UseCase\AccountUseCase\DeleteAccountUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Suppression du compte par le client de la boutique (01/11/2026, exigence App Store 5.1.1(v) et Google Play).
 * Contrat : docs/boutique.md, « Suppression du compte ».
 */
class AccountApiController extends AbstractController
{
    public function __construct(private readonly DeleteAccountUseCase $delete)
    {
    }

    /**
     * {password} ou {otpCode} → 200 {deleted: true, addressesAnonymized, reviewsAnonymized, reservationsAnonymized, newsletterRemoved,
     * stripeCustomerDeleted} ; 403 ADMIN_ACCOUNT / PASSWORD_REQUIRED / PASSWORD_INVALID / OTP_REQUIRED / OTP_INVALID ;
     * 409 SUBSCRIPTION_ACTIVE / RESERVATION_UPCOMING ; 429 TOO_MANY_ATTEMPTS
     */
    #[Route('/api/account', name: 'api_account_delete', methods: ['DELETE'])]
    public function delete(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }
        $data = json_decode((string) $request->getContent(), true);
        $result = $this->delete->execute($user, is_array($data) ? $data : [], $request);
        $headers = $result['status'] === 429 ? ['Retry-After' => (string) ($result['body']['retryAfterSeconds'] ?? 60)] : [];

        return $this->json($result['body'], $result['status'], $headers);
    }
}
