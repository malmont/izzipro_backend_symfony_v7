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
use App\Services\TenantCacheService;
use App\Services\StripeService\StripeService;
use Symfony\Contracts\Cache\ItemInterface;
use App\Entity\StripeConfig;
use App\Services\TenantConnectionManager;

class PaymentsController extends AbstractController
{
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
        private readonly \App\Security\OptionalCustomerResolver $customers
    ) {
        $this->getPaymentsByOrderSourceUseCase = $getPaymentsByOrderSourceUseCase;
        $this->createPaymentUseCase = $createPaymentUseCase;
        $this->emProvider = $emProvider;
        $this->security = $security;
        $this->cache = $cache;
        $this->stripeService = $stripeService;
        $this->connectionManager = $connectionManager;
    }

    #[Route('api/payments', name: 'get_payments', methods: ['GET'])]
    public function getPayments(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'User not authenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }
        $orderSourceId = $request->query->get('orderSource');
        if (!$orderSourceId) {
            return $this->json(['error' => 'orderSource parameter is required'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $days = $request->query->get('days');
        $host = $request->getSchemeAndHttpHost();
        $locale = $request->getLocale();
        $em = $this->emProvider->getEntityManager();
        $userOrders = $em->getRepository(Order::class)->findBy(['user' => $user]);
        if (!$userOrders) {
            return $this->json(['error' => 'Unauthorized access to payments'], JsonResponse::HTTP_FORBIDDEN);
        }
        $cacheKey = 'payments_orderSource_' . $orderSourceId . '_locale_' . $locale . ($days ? '_days_' . (int)$days : '');
        $paymentDTOs = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($orderSourceId, $host, $locale, $days) {
                $item->expiresAfter(300);
                $item->tag(['payments', 'payments_orderSource_' . $orderSourceId]);
                return $this->getPaymentsByOrderSourceUseCase->execute((int)$orderSourceId, $host, $locale, $days ? (int)$days : null);
            },
        );
        $paymentData = array_map(fn($dto) => $dto->toArray(), $paymentDTOs);
        return $this->json($paymentData);
    }

    #[Route('/api/payment', name: 'process_payment', methods: ['POST'])]
    public function processPayment(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'User not authenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }
        $data = json_decode($request->getContent(), true);
        $paymentIntentId = $data['paymentIntentId'] ?? null;
        if (!$paymentIntentId) {
            return $this->json(['success' => false, 'error' => 'Invalid data: paymentIntentId is missing.'], JsonResponse::HTTP_BAD_REQUEST);
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
            return $this->json(['error' => 'Corps JSON invalide'], 400);
        }
        // Route hors du pare-feu JWT (un jeton expiré ne doit pas bloquer un invité) : client reconnu s'il a un jeton valide
        $user = $this->customers->resolve($request);
        $locale = (string) $request->query->get('locale', $data['order']['locale'] ?? 'fr');
        try {
            return $this->json($this->prepareCheckout->execute($data, $user instanceof \App\Entity\User ? $user : null,
                preg_match('/^[a-z]{2}$/', $locale) ? $locale : 'fr', $request->getSchemeAndHttpHost()));
        } catch (\App\Services\CheckoutService\CheckoutException $e) {
            return $this->json(array_filter(['error' => $e->getMessage(), 'errors' => $e->errors]), $e->getStatusCode());
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
            return $this->json(['error' => 'Tenant non identifiable.'], 400);
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
            return $this->json(['error' => 'Aucun compte Stripe actif n\'est connecté pour ce site.'], 404);
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
            return $this->json(['error' => 'User not authenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $cacheKey = 'square_config';
        $host = $request->getSchemeAndHttpHost();

        $squareConfigData = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($host) {
                $item->expiresAfter(3600); 
                $item->tag(['square_config']);
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
