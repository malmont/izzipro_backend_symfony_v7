<?php

namespace App\Controller\paymentController;

use App\UseCase\PaymentUseCase\CreatePaymentUseCase;
use App\UseCase\PaymentUseCase\GetPaymentsByOrderSourceUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\Entity\SquareConfig;
use App\Entity\Order;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Security\Core\Security;
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
        TenantConnectionManager $connectionManager 
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
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'User not authenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }
        $data = json_decode($request->getContent(), true);
        $amount = $data['amount'] ?? null; 
        if (!$amount || $amount <= 0) {
            return $this->json(['error' => 'Montant invalide ou non fourni'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $clientSecret = $this->stripeService->createPaymentIntent((int)$amount, 'cad');
        if (!$clientSecret) {
            return $this->json(['error' => 'Impossible de créer l\'intention de paiement. Vérifiez la configuration Stripe du tenant.'], 500);
        }
        return $this->json(['clientSecret' => $clientSecret]);
    }

    /**
     * ✅ MÉTHODE CORRIGÉE AVEC L'AIGUILLAGE
     * Récupère la clé publique Stripe et (si pertinent) l'ID du compte connecté.
     */
    #[Route('/api/stripe-config', name: 'get_stripe_config', methods: ['GET'])]
    public function getStripeConfig(TenantEntityManagerProvider $emProvider): JsonResponse
    {
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
                'stripeAccountId' => null 
            ]);
        }

        $em = $emProvider->getEntityManager();
        $stripeConfig = $em->getRepository(StripeConfig::class)->findOneBy(['isActive' => true]);
        
        if (!$stripeConfig) {
            return $this->json(['error' => 'Aucun compte Stripe actif n\'est connecté pour ce site.'], 404);
        }
        
        return $this->json([
            'publicKey' => $publicKey,
            'stripeAccountId' => $stripeConfig->getAccountId()
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