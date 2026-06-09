<?php

namespace App\Controller\OrderController;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\UseCase\OrderUseCase\CreateOrderUseCase;
use App\UseCase\OrderUseCase\CancelOrderUseCase;
use App\UseCase\OrderUseCase\GetOrdersBySourceUseCase;
use App\Dto\CreateOrderDTO;
use App\Dto\PaymentMethodDTO;
use App\Dto\CreateOrderMultiPaymentDTO;
use Symfony\Component\Security\Core\Security;
use App\UseCase\OrderUseCase\GetOrdersByUserUseCase;
use App\Entity\User;
use App\Entity\Adress;
use App\Entity\Carrier;
use App\Entity\Order;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantCacheService;
use Symfony\Contracts\Cache\ItemInterface;
use App\Services\GemsuiteImporterService\GemsuiteSaleManager;
use App\Services\GemsuiteImporterService\GemsuiteClientManager;
use App\Services\TenantConnectionManager;
use Psr\Log\LoggerInterface;
use App\Services\OrderService\OrderMailerService;
use App\Services\StripeService\StripeService;

class OrderController extends AbstractController
{
    private GetOrdersByUserUseCase $getOrdersByUserUseCase;
    private CreateOrderUseCase $createOrderUseCase;
    private CancelOrderUseCase $cancelOrderUseCase;
    private GetOrdersBySourceUseCase $getOrdersBySourceUseCase;
    private TenantEntityManagerProvider $emProvider;
    private TenantCacheService $cache;
    private GemsuiteSaleManager $gemsuiteSaleManager;
    private LoggerInterface $logger;
    private OrderMailerService $orderMailerService;
    private StripeService $stripeService;
    private GemsuiteClientManager $gemsuiteClientManager;
    private TenantConnectionManager $tenantManager;

    public function __construct(
        CreateOrderUseCase $createOrderUseCase,
        CancelOrderUseCase $cancelOrderUseCase,
        GetOrdersBySourceUseCase $getOrdersBySourceUseCase,
        GetOrdersByUserUseCase $getOrdersByUserUseCase,
        TenantEntityManagerProvider $emProvider,
        TenantCacheService $cache,
        GemsuiteSaleManager $gemsuiteSaleManager,
        LoggerInterface $logger,
        OrderMailerService $orderMailerService,
        StripeService $stripeService,
        GemsuiteClientManager $gemsuiteClientManager,
        TenantConnectionManager $tenantManager
    ) {
        $this->createOrderUseCase = $createOrderUseCase;
        $this->cancelOrderUseCase = $cancelOrderUseCase;
        $this->getOrdersBySourceUseCase = $getOrdersBySourceUseCase;
        $this->getOrdersByUserUseCase = $getOrdersByUserUseCase;
        $this->emProvider = $emProvider;
        $this->cache = $cache;
        $this->gemsuiteSaleManager = $gemsuiteSaleManager;
        $this->logger = $logger;
        $this->orderMailerService = $orderMailerService;
        $this->stripeService = $stripeService;
        $this->gemsuiteClientManager = $gemsuiteClientManager;
        $this->tenantManager = $tenantManager;
    }

    /**
     * @Route("api/order/create", name="order_create", methods={"POST"})
     */
    public function createOrder(Request $request): JsonResponse
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'User not authenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['orderSource'], $data['paymentMethod'], $data['addressId'], $data['items'], $data['typeOrder'])) {
            return $this->json(['error' => 'Missing required fields'], JsonResponse::HTTP_BAD_REQUEST);
        }
        // ---------------------------------------------------------------------

        $em = $this->emProvider->getEntityManager();
        $address = $em->getRepository(Adress::class)->find($data['addressId']);
        if (!$address || $address->getUserAdress() !== $user) {
            return $this->json(['error' => 'Unauthorized: Invalid address'], JsonResponse::HTTP_FORBIDDEN);
        }

        $carrierId = $data['carrierId'] ?? null;
        $carrier = null;

        if ($carrierId) {
            $carrier = $em->getRepository(Carrier::class)->find($carrierId);
            if (!$carrier) {
                return $this->json(['error' => 'Carrier not found'], JsonResponse::HTTP_NOT_FOUND);
            }
        }

        $paymentData = $data['payment'] ?? [];
        $paymentIntentId = $data['paymentIntentId'] ?? $paymentData['stripePaymentId'] ?? null;

        // --- SÉCURISATION : Vérification obligatoire du paiement Stripe ---
        if ($paymentIntentId && ($data['paymentMethod'] ?? 2) == 2) {
            $paymentIntent = $this->stripeService->verifyPaymentIntent($paymentIntentId);
            if (!$paymentIntent) {
                return $this->json(['error' => 'Payment verification failed or payment not completed'], JsonResponse::HTTP_PAYMENT_REQUIRED);
            }

            // On écrase les données du front par les données certifiées de Stripe
            $charge = $paymentIntent->charges->data[0] ?? null;
            $paymentData = [
                'stripePaymentId' => $paymentIntent->id,
                'status' => $paymentIntent->status,
                'receiptUrl' => $charge ? $charge->receipt_url : null,
                'cardBrand' => $charge ? $charge->payment_method_details->card->brand : null,
                'last4' => $charge ? $charge->payment_method_details->card->last4 : null,
                'riskLevel' => $charge ? $charge->outcome->risk_level : null,
            ];
        }

        $dto = new CreateOrderDTO(
            $user->getId(),
            $data['orderSource'],
            $data['paymentMethod'],
            $data['addressId'],
            $carrierId,
            $data['typeOrder'] ?? null,
            $data['items'],
            $data['priceShipping'] ?? null,
            $paymentData['squarePaymentId'] ?? null,
            $paymentData['squareOrderId'] ?? null,
            $paymentData['squareReceiptUrl'] ?? null,
            $paymentData['squareStatus'] ?? null,
            $paymentData['squareCardBrand'] ?? null,
            $paymentData['squareLast4'] ?? null,
            $paymentData['squareRiskLevel'] ?? null,
            $paymentData['stripePaymentId'] ?? null,
            $paymentData['receiptUrl'] ?? null,
            $paymentData['status'] ?? null,
            $paymentData['cardBrand'] ?? null,
            $paymentData['last4'] ?? null,
            $paymentData['riskLevel'] ?? null,
            $data['licenseNumber'] ?? null,
            $data['licenseExpirationDate'] ?? null
        );

        $result = $this->createOrderUseCase->execute($dto);

        if ($result instanceof Order) {
            $tenantEm = $this->emProvider->getEntityManager();
            
            // Auto-update user profile if license info is missing
            if ($user) {
                $hasChanged = false;
                if (!$user->getLicenseNumber() && isset($data['licenseNumber'])) {
                    $user->setLicenseNumber($data['licenseNumber']);
                    $hasChanged = true;
                }
                if (!$user->getLicenseExpirationDate() && isset($data['licenseExpirationDate'])) {
                    $user->setLicenseExpirationDate(new \DateTime($data['licenseExpirationDate']));
                    $hasChanged = true;
                }
                if ($hasChanged) {
                    $tenantEm->persist($user);
                    $tenantEm->flush();
                }
            }

            $tenantEm->refresh($result);
            $this->gemsuiteSaleManager->createSale($result);
            try {
                $locale = $request->query->get('locale', 'fr');
                $domain = $request->getSchemeAndHttpHost() . '/assets/uploads/email-logos/';
                $this->orderMailerService->sendOrderConfirmation($result, $locale, $domain);
                $this->orderMailerService->sendShippingNotification($result, $locale, $domain);
            } catch (\Exception $e) {
                $this->logger->error("Le service d'email a échoué mais la commande est créée : " . $e->getMessage(), [
                    'orderId' => $result->getId(),
                    'exception' => $e
                ]);
            }
            return $this->json([
                'success' => true,
                'orderId' => $result->getId(),
                'message' => 'Commande créée et synchronisée avec succès.'
            ], JsonResponse::HTTP_CREATED);
        }
        return $result;
    }

    /**
     * @Route("api/order/create-guest", name="order_create_guest", methods={"POST"})
     */
    public function createGuestOrder(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['guestInfo'], $data['items'], $data['shippingAddress'], $data['paymentIntentId'])) {
            return $this->json(['error' => 'Missing required guest fields'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $guestInfo = $data['guestInfo'];
        $email = $guestInfo['email'] ?? null;
        if (!$email) {
            return $this->json(['error' => 'Email is required'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $em = $this->emProvider->getEntityManager();
        $userRepo = $em->getRepository(User::class);
        $user = $userRepo->findOneBy(['email' => $email]);

        $firstName = $guestInfo['firstName'] ?? 'Guest';
        $lastName = $guestInfo['lastName'] ?? 'User';
        $tenantCode = $this->tenantManager->getCurrentTenantCode();

        // 1. S'assurer que le client existe dans Gemsuite (et en local)
        $gemsuiteClient = $this->gemsuiteClientManager->findOrCreateClient($email, $firstName, $lastName, $tenantCode);
        
        // On récupère l'EM après le switch potentiel du GemsuiteClientManager
        $em = $this->emProvider->getEntityManager();

        if (!$user) {
            $user = new User();
            $user->setEmail($email);
            $user->setFirstname($firstName);
            $user->setLastname($lastName);
            $user->setUsername($email);
            $user->setPassword(bin2hex(random_bytes(10)));
            $user->setIsVerified(true);
            $user->setRoles(['ROLE_USER_INTERNET']);
            
            if ($gemsuiteClient) {
                $user->setGemsuiteClient($gemsuiteClient);
            }

            $em->persist($user);
            $em->flush();
        } elseif ($gemsuiteClient && !$user->getGemsuiteClient()) {
            // Si l'user existe mais n'était pas lié au client Gemsuite
            $user->setGemsuiteClient($gemsuiteClient);
            $em->flush();
        }

        // Verify Stripe Payment and extract details
        $paymentIntent = $this->stripeService->verifyPaymentIntent($data['paymentIntentId']);
        if (!$paymentIntent) {
            return $this->json(['error' => 'Payment verification failed or payment not completed'], JsonResponse::HTTP_PAYMENT_REQUIRED);
        }

        $charge = $paymentIntent->charges->data[0] ?? null;
        $paymentData = [
            'stripePaymentId' => $paymentIntent->id,
            'status' => $paymentIntent->status,
            'receiptUrl' => $charge ? $charge->receipt_url : null,
            'cardBrand' => $charge ? $charge->payment_method_details->card->brand : null,
            'last4' => $charge ? $charge->payment_method_details->card->last4 : null,
            'riskLevel' => $charge ? $charge->outcome->risk_level : null,
        ];

        // Handle Addresses
        $shippingData = $data['shippingAddress'];
        $billingData = $data['billingAddress'] ?? $shippingData;

        $guestPhone = $guestInfo['phone'] ?? $guestInfo['phoneNumber'] ?? $guestInfo['contactNumber'] ?? null;
        $shippingAddress = $this->createAddressFromData($shippingData, $user, $guestPhone);
        $billingAddress = $this->createAddressFromData($billingData, $user, $guestPhone);

        $em->persist($shippingAddress);
        $em->persist($billingAddress);
        $em->flush();

        $carrierId = $data['carrierId'] ?? null;

        $dto = new CreateOrderDTO(
            $user->getId(),
            $data['orderSource'] ?? 1,
            $data['paymentMethod'] ?? 2, // Stripe
            $shippingAddress->getId(),
            $carrierId,
            $data['typeOrder'] ?? null,
            $data['items'],
            $data['priceShipping'] ?? null,
            $paymentData['squarePaymentId'] ?? null,
            $paymentData['squareOrderId'] ?? null,
            $paymentData['squareReceiptUrl'] ?? null,
            $paymentData['squareStatus'] ?? null,
            $paymentData['squareCardBrand'] ?? null,
            $paymentData['squareLast4'] ?? null,
            $paymentData['squareRiskLevel'] ?? null,
            $paymentData['stripePaymentId'] ?? null,
            $paymentData['receiptUrl'] ?? null,
            $paymentData['status'] ?? null,
            $paymentData['cardBrand'] ?? null,
            $paymentData['last4'] ?? null,
            $paymentData['riskLevel'] ?? null,
            $data['guestInfo']['licenseNumber'] ?? null,
            $data['guestInfo']['licenseExpirationDate'] ?? null
        );

        $result = $this->createOrderUseCase->execute($dto, $user);

        if ($result instanceof Order) {
            // Auto-update user profile (for the newly created or existing user from guest info)
            if ($user) {
                $hasChanged = false;
                if (!$user->getLicenseNumber() && isset($data['guestInfo']['licenseNumber'])) {
                    $user->setLicenseNumber($data['guestInfo']['licenseNumber']);
                    $hasChanged = true;
                }
                if (!$user->getLicenseExpirationDate() && isset($data['guestInfo']['licenseExpirationDate'])) {
                    $user->setLicenseExpirationDate(new \DateTime($data['guestInfo']['licenseExpirationDate']));
                    $hasChanged = true;
                }
                if ($hasChanged) {
                    $em->persist($user);
                    $em->flush();
                }
            }

            $em->refresh($result);
            $this->gemsuiteSaleManager->createSale($result);
            try {
                $locale = $request->query->get('locale', 'fr');
                $domain = $request->getSchemeAndHttpHost() . '/assets/uploads/email-logos/';
                $this->orderMailerService->sendOrderConfirmation($result, $locale, $domain);
                $this->orderMailerService->sendShippingNotification($result, $locale, $domain);
            } catch (\Exception $e) {
                $this->logger->error("Le service d'email Guest a échoué : " . $e->getMessage(), [
                    'orderId' => $result->getId()
                ]);
            }
            return $this->json([
                'success' => true,
                'orderId' => $result->getId(),
                'message' => 'Commande Guest créée avec succès.'
            ], JsonResponse::HTTP_CREATED);
        }

        return $result;
    }

    private function createAddressFromData(array $data, User $user, ?string $fallbackPhone = null): Adress
    {
        $address = new Adress();
        $address->setUserAdress($user);

        // Robust name detection for Guest Checkout
        $firstname = $data['firstname'] ?? $data['firstName'] ?? $user->getFirstname() ?? '';
        $lastname = $data['lastname'] ?? $data['lastName'] ?? $user->getLastname() ?? '';
        $address->setFirstname($firstname);
        $address->setLastname($lastname);
        $address->setFullname($data['fullname'] ?? trim($firstname . ' ' . $lastname));
        
        $address->setCompany($data['company'] ?? null);
        $address->setAddress($data['addressLineOne'] ?? $data['address'] ?? $data['street'] ?? $data['street1'] ?? '');
        $address->setComplement($data['addressLineTwo'] ?? $data['complement'] ?? $data['street2'] ?? null);
        $address->setCity($data['city'] ?? '');
        $address->setProvince($data['province'] ?? null);
        $address->setCodepostal($data['zipCode'] ?? $data['zip'] ?? $data['postalCode'] ?? $data['postcode'] ?? $data['codepostal'] ?? '');
        $address->setCountry($data['country'] ?? '');

        // Phone fallback checks
        $phone = $data['contactNumber'] ?? $data['phone'] ?? $data['phoneNumber'] ?? $data['telephone'] ?? $fallbackPhone ?? '';
        $address->setPhone($phone);

        return $address;
    }

    /**
     * @Route("api/order/create-multi-payment", name="order_create_multi_payment", methods={"POST"})
     */
    public function createOrderWithMultiplePayments(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'User not authenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }
        $paymentData = $data['payment'] ?? [];
        $paymentMethods = array_map(function ($method) {
            return new PaymentMethodDTO($method['type'], $method['amount']);
        }, $data['paymentMethods'] ?? []);

        $dto = new CreateOrderMultiPaymentDTO(
            $user->getId(),
            $data['orderSource'],
            $paymentMethods,
            $data['addressId'] ?? null,
            $data['carrierId'] ?? null,
            $data['typeOrder'] ?? null,
            $data['items'] ?? [],
            $data['priceShipping'] ?? null,
            $paymentData['squarePaymentId'] ?? null,
            $paymentData['squareOrderId'] ?? null,
            $paymentData['squareReceiptUrl'] ?? null,
            $paymentData['squareStatus'] ?? null,
            $paymentData['squareCardBrand'] ?? null,
            $paymentData['squareLast4'] ?? null,
            $paymentData['squareRiskLevel'] ?? null,
            $paymentData['stripePaymentId'] ?? null,
            $paymentData['receiptUrl'] ?? null,
            $paymentData['status'] ?? null,
            $paymentData['cardBrand'] ?? null,
            $paymentData['last4'] ?? null,
            $paymentData['riskLevel'] ?? null,
            $data['licenseNumber'] ?? null,
            $data['licenseExpirationDate'] ?? null
        );
        $result = $this->createOrderUseCase->execute($dto);
        if ($result instanceof Order) {
            $tenantEm = $this->emProvider->getEntityManager();

            // Auto-update user profile
            if ($user) {
                $hasChanged = false;
                if (!$user->getLicenseNumber() && isset($data['licenseNumber'])) {
                    $user->setLicenseNumber($data['licenseNumber']);
                    $hasChanged = true;
                }
                if (!$user->getLicenseExpirationDate() && isset($data['licenseExpirationDate'])) {
                    $user->setLicenseExpirationDate(new \DateTime($data['licenseExpirationDate']));
                    $hasChanged = true;
                }
                if ($hasChanged) {
                    $tenantEm->persist($user);
                    $tenantEm->flush();
                }
            }

            $tenantEm->refresh($result);
            $this->gemsuiteSaleManager->createSale($result);

            try {
                $locale = $request->query->get('locale', 'fr');
                $domain = $request->getSchemeAndHttpHost() . '/assets/uploads/email-logos/';
                $this->orderMailerService->sendOrderConfirmation($result, $locale, $domain);
                $this->orderMailerService->sendShippingNotification($result, $locale, $domain);
            } catch (\Exception $e) {
                $this->logger->error("Le service d'email (multi-paiement) a échoué : " . $e->getMessage(), [
                    'orderId' => $result->getId(),
                    'exception' => $e
                ]);
            }

            return $this->json([
                'success' => true,
                'orderId' => $result->getId(),
                'message' => 'Commande créée et synchronisée avec succès.'
            ], JsonResponse::HTTP_CREATED);
        }
        return $result;
    }

    /**
     * @Route("api/order/cancel/{id}", name="order_cancel", methods={"POST"})
     */
    public function cancelOrder(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'User not authenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $em = $this->emProvider->getEntityManager();
        $order = $em->getRepository(Order::class)->find($id);
        if (!$order) {
            return $this->json(['error' => 'Order not found'], JsonResponse::HTTP_NOT_FOUND);
        }
        if ($order->getUserId() !== $user) {
            return $this->json(['error' => 'Unauthorized: You can only cancel your own orders'], JsonResponse::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        return $this->cancelOrderUseCase->execute($id, $data['paymentMethod'] ?? null);
    }

    #[Route("api/orders", name: "get_orders", methods: ["GET"])]
    public function getOrders(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $orderSourceId = $request->query->get('orderSource');
        if (!$orderSourceId) {
            return $this->json(['error' => 'orderSource parameter is required'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $days = $request->query->get('days');
        $host = $request->getSchemeAndHttpHost();
        $cacheKey = 'orders_source_' . $orderSourceId . ($days ? '_days_' . (int)$days : '');

        $orderDTOs = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($orderSourceId, $host, $days) {
                $item->expiresAfter(300);
                $item->tag(['orders_source']);
                return $this->getOrdersBySourceUseCase->execute((int)$orderSourceId, $host, $days ? (int)$days : null);
            },
        );

        $orderData = array_map(fn($dto) => $dto->toArray(), $orderDTOs);
        return $this->json($orderData);
    }

    #[Route("api/ordersuser", name: "get_user_orders", methods: ["GET"])]
    public function getUserOrders(Request $request, Security $security): JsonResponse
    {
        /** @var User $user */ // Type hint pour clarté
        $user = $security->getUser();
        if (!$user) {
            return $this->json(['error' => 'User not authenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $host = $request->getSchemeAndHttpHost();
        $locale = $request->getLocale();
        $cacheKeySuffix = 'orders_user_' . $user->getId() . '_' . $locale;
        $tags = ['orders_user', 'orders_user_' . $user->getId()];

        $orderDTOs = $this->cache->get(
            $cacheKeySuffix,
            function (ItemInterface $item) use ($user, $host, $locale, $cacheKeySuffix) {
                $this->logger->info('Cache MISS for getUserOrders. Computing...', [
                    'key_suffix_requested' => $cacheKeySuffix
                ]);
                return $this->getOrdersByUserUseCase->execute($user->getId(), $host, $locale);
            },
            300,
            $tags
        );

        if (empty($orderDTOs)) {
            $this->logger->info('No orders found for getUserOrders (after cache check/compute)', [
                'user_id' => $user->getId(),
                'locale' => $locale
            ]);
            // Retourner un tableau vide avec 200 OK au lieu de 404
            return $this->json([], JsonResponse::HTTP_OK);
        }

        $orderData = array_map(fn($dto) => $dto->toArray(), $orderDTOs);
        return $this->json($orderData);
    }
}
