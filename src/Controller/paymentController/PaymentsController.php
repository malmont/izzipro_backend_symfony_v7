<?php

namespace App\Controller\paymentController;

use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\UseCase\PaymentUseCase\CreatePaymentUseCase;
use App\UseCase\PaymentUseCase\GetPaymentsByOrderSourceUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\Entity\SquareConfig;
use App\Entity\Order;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\SecurityBundle\Security;
use App\Cache\CacheTags;
use App\Services\TenantCacheService;
use App\Services\StripeService\StripeService;
use App\Entity\StripeConfig;
use App\Services\TenantConnectionManager;

class PaymentsController extends AbstractController
{
    private const ORDER_SOURCE_POS = 2; // table order_source

    private GetPaymentsByOrderSourceUseCase $getPaymentsByOrderSourceUseCase;
    private CreatePaymentUseCase $createPaymentUseCase;
    private TenantEntityManagerProvider $emProvider;
    private Security $security;
    private TenantCacheService $cache;
    private StripeService $stripeService;
    private TenantConnectionManager $connectionManager;

    public function __construct(
        GetPaymentsByOrderSourceUseCase $getPaymentsByOrderSourceUseCase,
        CreatePaymentUseCase $createPaymentUseCase,
        TenantEntityManagerProvider $emProvider,
        Security $security,
        TenantCacheService $cache,
        StripeService $stripeService,
        TenantConnectionManager $connectionManager,
        private readonly \App\UseCase\CheckoutUseCase\PrepareCheckoutUseCase $prepareCheckout,
        private readonly \App\Security\OptionalCustomerResolver $customers,
        private readonly \App\Services\PaymentService\PaymentOwnershipService $ownership,
        private readonly \Psr\Log\LoggerInterface $securityLogger,
        private readonly ?\App\Services\CaisseService\SiteLocaleResolver $siteLocale = null, // langue du site quand ?locale= manque (03/11/2026)
    ) {
        $this->getPaymentsByOrderSourceUseCase = $getPaymentsByOrderSourceUseCase;
        $this->createPaymentUseCase = $createPaymentUseCase;
        $this->emProvider = $emProvider;
        $this->security = $security;
        $this->cache = $cache;
        $this->stripeService = $stripeService;
        $this->connectionManager = $connectionManager;
    }

    /**
     * Paiements du site par source (application de gestion, caisse). 15/10/2026 : réservé à ROLE_ADMIN (toutes sources)
     * et à ROLE_USER_POS (source caisse, orderSource 2, seulement) ; tout autre compte → 403. Avant, n'importe quel client
     * connecté ayant passé une commande recevait tous les paiements du site.
     */
    #[Route('api/payments', name: 'get_payments', methods: ['GET'])]
    public function getPayments(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'User not authenticated', 'code' => 'UNAUTHENTICATED', 'message' => 'User not authenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }
        $orderSourceId = $request->query->get('orderSource');
        if (!$orderSourceId) {
            return $this->json(['error' => 'orderSource parameter is required', 'code' => 'ORDER_SOURCE_REQUIRED', 'message' => 'orderSource parameter is required'], JsonResponse::HTTP_BAD_REQUEST);
        }
        // lot CMD-WEB-1 (20/11/2026) : `orderSource=all` (toutes les sources) pour l'administrateur seulement, et seulement par page
        $all = $orderSourceId === 'all';
        if (!$this->isGranted('ROLE_ADMIN') && !(($this->isGranted('ROLE_USER_POS') || $this->isGranted('ROLE_POS_MANAGER')) && !$all && (int) $orderSourceId === self::ORDER_SOURCE_POS)) {
            return $this->json(['error' => 'FORBIDDEN', 'message' => 'Accès réservé aux administrateurs et, pour la caisse, aux caissiers'], JsonResponse::HTTP_FORBIDDEN);
        }
        $paging = \App\Services\Http\ListPage::fromRequest($request);
        if ($all && $paging === null) {
            return $this->json(['error' => 'PAGE_REQUIRED', 'code' => 'PAGE_REQUIRED', 'message' => 'orderSource=all se lit par page (?page=&limit=).', 'field' => 'page'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $days = $request->query->get('days');
        $host = $request->getSchemeAndHttpHost();
        // lot CMD-WEB-1 : `?locale=fr|en` choisit la langue des moyens et des états de paiement ; sans lui, la langue de la requête, comme avant
        $asked = $request->query->get('locale');
        $locale = is_string($asked) && in_array($asked, ['fr', 'en'], true) ? $asked : $request->getLocale();
        $cacheKey = 'payments_orderSource_' . ($all ? 'all' : (int) $orderSourceId) . '_locale_' . $locale . ($days ? '_days_' . (int)$days : '');
        $tags = [...CacheTags::listTags(CacheTags::PAYMENTS), ...CacheTags::listTags(CacheTags::ORDERS)];
        if ($paging !== null) {
            // lot CMD-WEB-1 : une page lue en base, du plus récent au plus ancien ; en-têtes X-Total-Count, X-Page, X-Limit (comme PERF-PAGINATION)
            $found = $this->cache->get(
                $cacheKey . '_page_' . $paging->page . '_' . $paging->limit,
                fn () => $this->getPaymentsByOrderSourceUseCase->executePage($all ? null : (int) $orderSourceId, $locale, $days ? (int) $days : null, $paging->offset(), $paging->limit),
                300,
                $tags
            );

            return $this->json(array_map(fn ($dto) => $dto->toArray(), $found['items']), JsonResponse::HTTP_OK, $paging->headers($found['total']));
        }
        // 300 s ; un paiement porte sa commande : vidé par les écritures des deux (registre CacheTags)
        $paymentDTOs = $this->cache->get(
            $cacheKey,
            fn () => $this->getPaymentsByOrderSourceUseCase->execute((int)$orderSourceId, $host, $locale, $days ? (int)$days : null),
            300,
            $tags
        );
        $paymentData = array_map(fn($dto) => $dto->toArray(), $paymentDTOs);
        return $this->json($paymentData, JsonResponse::HTTP_OK, ['X-Total-Count' => (string) count($paymentData)]);
    }

    #[Route('/api/payment', name: 'process_payment', methods: ['POST'])]
    public function processPayment(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'User not authenticated', 'code' => 'UNAUTHENTICATED', 'message' => 'User not authenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }
        $data = json_decode($request->getContent(), true);
        $paymentIntentId = $data['paymentIntentId'] ?? null;
        if (!$paymentIntentId) {
            return $this->json(['success' => false, 'error' => 'Invalid data: paymentIntentId is missing.', 'code' => 'PAYMENT_INTENT_REQUIRED', 'message' => 'Invalid data: paymentIntentId is missing.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        // S7 (31/10/2026) : paiement de l'utilisateur (session de paiement ou commande), invité avec son jeton, ou administrateur ;
        // sinon 404 sans révéler si l'identifiant existe (avant : tout utilisateur connecté, tout paiement du site)
        if (!($user instanceof \App\Entity\User) || !$this->ownership->owns((string) $paymentIntentId, $user, is_string($data['guestToken'] ?? null) ? $data['guestToken'] : null, $this->isGranted('ROLE_ADMIN'))) {
            $this->securityLogger->warning('payment.verify.not_owner', ['userId' => $user?->getId(), 'paymentIntentId' => (string) $paymentIntentId, 'ip' => $request->getClientIp()]);

            return $this->json(['success' => false, 'error' => 'PAYMENT_NOT_FOUND', 'message' => 'Aucun paiement de ce compte ne correspond à cet identifiant'], JsonResponse::HTTP_NOT_FOUND);
        }
        $result = $this->createPaymentUseCase->execute($paymentIntentId);
        if ($result['success']) {
            $response = [
                'success' => true,
                'payment' => $result['payment']
            ];
            return $this->json($response);
        }
        return $this->json(['success' => false, 'errors' => $result['errors']], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
    }

    #[Route('/api/stripe/create-intent', name: 'api_stripe_create_intent', methods: ['POST'])]
    public function createStripePaymentIntent(Request $request): JsonResponse
    {
        // Un intent par panier jusqu'à la commande (paymentIntentId pour le réutiliser) ; « order » = corps de la commande
        // à venir, gardé pour que le webhook la crée si le navigateur ne le fait pas (09/10/2026, PrepareCheckoutUseCase)
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Corps JSON invalide', 'code' => 'INVALID_BODY', 'message' => 'Corps JSON invalide'], 400);
        }
        // Route hors du pare-feu JWT (un jeton expiré ne doit pas bloquer un invité) : client reconnu s'il a un jeton valide
        $user = $this->customers->resolve($request);
        // Locale de la session de paiement (courriels de la commande) : ?locale=, sinon order.locale, sinon la langue du site (03/11/2026 ; avant : fr)
        $locale = (string) $request->query->get('locale', $data['order']['locale'] ?? '');
        try {
            return $this->json($this->prepareCheckout->execute($data, $user instanceof \App\Entity\User ? $user : null,
                preg_match('/^[a-z]{2}$/', $locale) ? $locale : ($this->siteLocale?->siteLocale() ?? 'fr'), $request->getSchemeAndHttpHost()));
        } catch (\App\Services\CheckoutService\CheckoutException $e) {
            return $this->json(array_filter(['error' => $e->getMessage(), 'errors' => $e->errors]) + ['code' => $e->code(), 'message' => $e->getMessage()], $e->getStatusCode()); // code + errors[] codées (02/11/2026)
        }
    }

    /**
     * ✅ MÉTHODE CORRIGÉE AVEC L'AIGUILLAGE
     * Récupère la clé publique Stripe et (si pertinent) l'ID du compte connecté.
     */
    #[Route('/api/stripe-config', name: 'get_stripe_config', methods: ['GET'])]
    public function getStripeConfig(TenantEntityManagerProvider $emProvider, TenantCurrencyProvider $currencyProvider): JsonResponse
    {
        $currency = $currencyProvider->code(); // devise du site (fiche entreprise), celle de tous les montants
        $publicKey = $_ENV['STRIPE_PUBLIC_KEY'] ?? null;
        if (!$publicKey) {
            return $this->json(['error' => 'Clé publique Stripe non configurée sur le serveur.'], 500);
        }

        $tenantCode = $this->connectionManager->getCurrentTenantCode();
        if (!$tenantCode) {
            return $this->json(['error' => 'Tenant non identifiable.', 'code' => 'TENANT_UNKNOWN', 'message' => 'Tenant non identifiable.'], 400);
        }

        $isInternal = $this->connectionManager->isTenantInternal($tenantCode);

        if ($isInternal) {

            return $this->json([
                'publicKey' => $publicKey,
                'stripeAccountId' => null,
                'currency' => $currency,
            ]);
        }

        $em = $emProvider->getEntityManager();
        $stripeConfig = $em->getRepository(StripeConfig::class)->findOneBy(['isActive' => true]);

        if (!$stripeConfig) {
            return $this->json(['error' => 'Aucun compte Stripe actif n\'est connecté pour ce site.', 'code' => 'STRIPE_ACCOUNT_MISSING', 'message' => 'Aucun compte Stripe actif n\'est connecté pour ce site.'], 404);
        }

        return $this->json([
            'publicKey' => $publicKey,
            'stripeAccountId' => $stripeConfig->getAccountId(),
            'currency' => $currency,
        ]);
    }


    // --- MÉTHODES SQUARE MISES EN COMMENTAIRE ---
    /*
    #[Route('/api/square-config', name: 'get_square_config', methods: ['GET'])]
    public function getSquareConfig(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'User not authenticated', 'code' => 'UNAUTHENTICATED', 'message' => 'User not authenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $cacheKey = 'square_config';
        $host = $request->getSchemeAndHttpHost();

        $squareConfigData = $this->cache->get(
            $cacheKey,
            function () use ($host) {
                $em = $this->emProvider->getEntityManager();
                $squareConfig = $em
                    ->getRepository(SquareConfig::class)
                    ->findOneBy(['isActive' => true]);
                if (!$squareConfig) {
                    return null;
                }
                return [
                    'applicationId' => $squareConfig->getApplicationId(),
                    'locationId'    => $squareConfig->getLocationId(),
                ];
            },
            3600,
            ['square_config']
        );

        if (!$squareConfigData) {
            return $this->json(['success' => false, 'error' => 'Configuration Square introuvable.'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json(['success' => true, 'data' => $squareConfigData]);
    }
    */
}
