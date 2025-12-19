<?php

namespace App\Services\Booking;

use App\Services\TenantConnectionManager;
use Symfony\Component\HttpFoundation\RequestStack;

class BookingAuthService
{
    private const SESSION_KEY = 'booking_access_granted';

    public function __construct(
        private TenantConnectionManager $tenantManager,
        private RequestStack $requestStack
    ) {}

    /**
     * Vérifie si le visiteur est déjà authentifié en session
     */
    public function isAuthenticated(): bool
    {
        $session = $this->requestStack->getSession();
        return $session->get(self::SESSION_KEY) === true;
    }

    /**
     * Tente de valider le token soumis
     */
    public function attemptLogin(string $submittedToken): bool
    {
        $currentSubdomain = $this->tenantManager->getCurrentTenantCode();
        
        if (!$currentSubdomain) {
            return false;
        }

        $expectedToken = $this->tenantManager->getTenantToken($currentSubdomain);

        if ($expectedToken && $submittedToken === $expectedToken) {
            $this->requestStack->getSession()->set(self::SESSION_KEY, true);
            return true;
        }

        return false;
    }

    public function logout(): void
    {
        $this->requestStack->getSession()->remove(self::SESSION_KEY);
    }
}