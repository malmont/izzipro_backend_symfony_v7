<?php

namespace App\Controller\OrderController;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use App\UseCase\OrderUseCase\CreateOrderUseCase;
use App\UseCase\OrderUseCase\CancelOrderUseCase;
use App\UseCase\OrderUseCase\GetOrdersBySourceUseCase;
use App\Dto\CreateOrderDTO;
use App\Dto\PaymentMethodDTO;
use App\Dto\CreateOrderMultiPaymentDTO;
use Symfony\Bundle\SecurityBundle\Security;
use App\UseCase\OrderUseCase\GetOrdersByUserUseCase;
use App\UseCase\OrderUseCase\GetCustomerOrderUseCase;
use App\UseCase\OrderUseCase\GetOrderStatusesUseCase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Entity\User;
use App\Entity\Adress;
use App\Entity\Carrier;
use App\Entity\Order;
use App\Entity\Payments;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantCacheService;
use Symfony\Contracts\Cache\ItemInterface;
use App\Services\TenantConnectionManager;
use Psr\Log\LoggerInterface;
use App\Services\OrderService\OrderMailerService;
use App\Services\StripeService\StripeService;
use App\Services\MediaUrlResolver;
use App\Services\BoutiqueSettingsService\BoutiqueSettingsService;
use App\Services\CaisseService\CaisseService;
use App\Dto\PosReceiptDto;

class OrderController extends AbstractController
{
    private GetOrdersByUserUseCase $getOrdersByUserUseCase;
    private CreateOrderUseCase $createOrderUseCase;
    private CancelOrderUseCase $cancelOrderUseCase;
    private GetOrdersBySourceUseCase $getOrdersBySourceUseCase;
    private TenantEntityManagerProvider $emProvider;
    private TenantCacheService $cache;
    private LoggerInterface $logger;
    private OrderMailerService $orderMailerService;
    private StripeService $stripeService;
    private TenantConnectionManager $tenantManager;
    private MediaUrlResolver $mediaUrlResolver;

    // Identifiants des tables order_source / payment_method et type de commande (1 = vente, 2 = retour)
    private const ORDER_SOURCE_ECOMMERCE = 1;
    private const ORDER_SOURCE_POS = 2;
    private const ORDER_SOURCE_MOBILE_APP = 3;
    private const PAYMENT_METHOD_ONLINE = 2; // valeur historique envoyée par le tunnel web (paiement Stripe)
    private const TYPE_ORDER_SALE = 1;

    public function __construct(
        CreateOrderUseCase $createOrderUseCase,
        CancelOrderUseCase $cancelOrderUseCase,
        GetOrdersBySourceUseCase $getOrdersBySourceUseCase,
        GetOrdersByUserUseCase $getOrdersByUserUseCase,
        TenantEntityManagerProvider $emProvider,
        TenantCacheService $cache,
        LoggerInterface $logger,
        OrderMailerService $orderMailerService,
        StripeService $stripeService,
        TenantConnectionManager $tenantManager,
        MediaUrlResolver $mediaUrlResolver,
        private readonly BoutiqueSettingsService $boutiqueSettings,
        private readonly GetCustomerOrderUseCase $getCustomerOrderUseCase,
        private readonly GetOrderStatusesUseCase $getOrderStatusesUseCase,
        private readonly \App\UseCase\CheckoutUseCase\FinalizeCheckoutOrderUseCase $finalizeCheckout,
        private readonly CaisseService $caisseService
    ) {
        $this->createOrderUseCase = $createOrderUseCase;
        $this->cancelOrderUseCase = $cancelOrderUseCase;
        $this->getOrdersBySourceUseCase = $getOrdersBySourceUseCase;
        $this->getOrdersByUserUseCase = $getOrdersByUserUseCase;
        $this->emProvider = $emProvider;
        $this->cache = $cache;
        $this->logger = $logger;
        $this->orderMailerService = $orderMailerService;
        $this->stripeService = $stripeService;
        $this->tenantManager = $tenantManager;
        $this->mediaUrlResolver = $mediaUrlResolver;
    }

    #[Route('/api/order/create', name: 'order_create', methods: ['POST'])]
    public function createOrder(Request $request): JsonResponse
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'User not authenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Corps JSON invalide'], JsonResponse::HTTP_BAD_REQUEST);
        }

        // Paiement en ligne (client, ou personnel avec un paiement Stripe) : commande unique par paiement, idempotente,
        // partagée avec le webhook (FinalizeCheckoutOrderUseCase, 09/10/2026). Sans paiement : réservé au personnel.
        $hasPayment = !empty($data['paymentIntentId']) || !empty($data['payment']['stripePaymentId']);
        if ($hasPayment || !($this->isGranted('ROLE_USER_POS') || $this->isGranted('ROLE_ADMIN'))) {
            return $this->checkout(fn () => $this->finalizeCheckout->customer($user, $data, $this->locale($request), $request->getSchemeAndHttpHost()));
        }

        if (!isset($data['addressId'], $data['items'])) {
            return $this->json(['error' => 'Missing required fields (addressId, items)'], JsonResponse::HTTP_BAD_REQUEST);
        }
        // orderSource, typeOrder et paymentMethod sont facultatifs depuis le 08/10/2026 : vente en ligne payée par Stripe
        $data['orderSource'] ??= self::ORDER_SOURCE_ECOMMERCE;
        $data['typeOrder'] ??= self::TYPE_ORDER_SALE;
        $data['paymentMethod'] ??= self::PAYMENT_METHOD_ONLINE;
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

        // Client en ligne (ni caisse ni admin) : vente web ou appli, paiement Stripe vérifié obligatoire.
        // Avant, déclarer un autre moyen de paiement que Stripe suffisait à sauter la vérification.
        $isStaff = $this->isGranted('ROLE_USER_POS') || $this->isGranted('ROLE_ADMIN');
        if (!$isStaff) {
            if (!$paymentIntentId) {
                return $this->json(['error' => 'Un paiement en ligne est requis pour cette commande.'], JsonResponse::HTTP_PAYMENT_REQUIRED);
            }
            $data['typeOrder'] = self::TYPE_ORDER_SALE;
            $data['paymentMethod'] = self::PAYMENT_METHOD_ONLINE;
            if (!in_array((int) $data['orderSource'], [self::ORDER_SOURCE_ECOMMERCE, self::ORDER_SOURCE_MOBILE_APP], true)) {
                $data['orderSource'] = self::ORDER_SOURCE_ECOMMERCE;
            }
        }

        // --- SÉCURISATION : Vérification obligatoire du paiement Stripe ---
        $paymentIntent = null;
        if ($paymentIntentId && (!$isStaff || ($data['paymentMethod'] ?? 2) == 2)) {
            $paymentIntent = $this->stripeService->verifyPaymentIntent($paymentIntentId);
            if (!$paymentIntent) {
                return $this->json(['error' => 'Payment verification failed or payment not completed'], JsonResponse::HTTP_PAYMENT_REQUIRED);
            }
            if ($this->isPaymentIntentAlreadyUsed($paymentIntent->id)) {
                return $this->json(['error' => 'Ce paiement est déjà associé à une commande.'], JsonResponse::HTTP_CONFLICT);
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

        if (isset($data['priceShipping']) && (float) $data['priceShipping'] < 0) {
            return $this->json(['error' => 'Frais de livraison invalides'], JsonResponse::HTTP_BAD_REQUEST);
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

            // Capture Stripe Payment Intent
            if ($paymentIntentId) {
                $this->logger->info("[CAPTURE DEBUG] Tentative de capture pour PaymentIntent ID: " . $paymentIntentId);
                $capturedIntent = $this->stripeService->capturePaymentIntent($paymentIntentId);
                if ($capturedIntent && $capturedIntent->status === 'succeeded') {
                    $payments = $result->getPayments();
                    if ($payments && !$payments->isEmpty()) {
                        /** @var \App\Entity\Payments $payment */
                        $payment = $payments->first();
                        $payment->setStripeStatus('succeeded');
                        $charge = $capturedIntent->charges->data[0] ?? null;
                        if ($charge) {
                            if ($charge->receipt_url) {
                                $payment->setStripeReceiptUrl($charge->receipt_url);
                            }
                            if ($charge->payment_method_details?->card?->brand) {
                                $payment->setStripeCardBrand($charge->payment_method_details->card->brand);
                            }
                            if ($charge->payment_method_details?->card?->last4) {
                                $payment->setStripeLast4($charge->payment_method_details->card->last4);
                            }
                            if ($charge->outcome?->risk_level) {
                                $payment->setStripeRiskLevel($charge->outcome->risk_level);
                            }
                        }
                        $tenantEm->persist($payment);
                        $tenantEm->flush();
                    }
                } else {
                    $this->logger->error(sprintf("[createOrder] Échec de la capture du paiement Stripe pour PaymentIntent ID: %s. Commande ID: %d.", $paymentIntentId, $result->getId()));
                }
            }
            try {
                $locale = $request->query->get('locale', 'fr');
                $domain = $this->mediaUrlResolver->getEmailLogosBaseUrl($request->getSchemeAndHttpHost()) . '/';
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
                'message' => 'Commande créée.'
            ], JsonResponse::HTTP_CREATED);
        }
        return $result;
    }

    #[Route('/api/order/create-guest', name: 'order_create_guest', methods: ['POST'])]
    public function createGuestOrder(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        // Réglage de la boutique réglable (commerce.guestCheckout) : la règle tient même sans le frontend
        if (!$this->boutiqueSettings->isGuestCheckoutAllowed()) {
            return $this->json(['error' => 'La commande sans compte est désactivée sur ce site.'], JsonResponse::HTTP_FORBIDDEN);
        }

        if (!is_array($data) || !isset($data['guestInfo'], $data['items'], $data['shippingAddress'], $data['paymentIntentId'])) {
            return $this->json(['error' => 'Missing required guest fields'], JsonResponse::HTTP_BAD_REQUEST);
        }

        // Commande unique par paiement, idempotente (même paiement et même e-mail : commande et jeton renvoyés), partagée
        // avec le webhook ; client, adresses et permis gérés par CheckoutCustomerService (09/10/2026)
        return $this->checkout(fn () => $this->finalizeCheckout->guest($data, $this->locale($request), $request->getSchemeAndHttpHost()));
    }

    /** Réponse d'une commande payée en ligne : 201 créée, 200 déjà créée pour ce paiement, sinon l'erreur */
    private function checkout(callable $finalize): JsonResponse
    {
        try {
            $result = $finalize();

            return $this->json($result['body'], $result['created'] ? JsonResponse::HTTP_CREATED : JsonResponse::HTTP_OK);
        } catch (\App\Services\CheckoutService\CheckoutException $e) {
            return $this->json(array_filter(['error' => $e->getMessage(), 'errors' => $e->errors]), $e->getStatusCode());
        }
    }

    private function locale(Request $request): string
    {
        $locale = (string) $request->query->get('locale', 'fr');

        return preg_match('/^[a-z]{2}$/', $locale) ? $locale : 'fr';
    }

    /**
     * Vente en caisse (POS) : encaissement en un ou plusieurs moyens de paiement (espèces, carte…) par le personnel.
     * Route distincte du tunnel web (createOrder, create-guest, webhook Stripe), mise à niveau le 15/10/2026 :
     * - corps validé (items, paymentMethods) ; orderSource 2 (caisse) et typeOrder 1 (vente) par défaut ;
     * - addressId et carrierId facultatifs : une vente au comptoir n'a ni adresse de livraison ni transporteur ; les
     *   taxes sont alors calculées sur l'adresse de la fiche entreprise (OrderTaxAddressResolver) ;
     * - la somme des moyens de paiement doit égaler le total calculé par le serveur (tolérance 1 cent) ;
     * - paiement Stripe (payment.stripePaymentId) vérifié seulement s'il est fourni : la caisse encaisse elle-même.
     *   Avant, $paymentIntent n'était pas défini ici : 500 à chaque vente avec le débogage actif.
     * Contrat complet : docs/caisse.md.
     */
    #[Route('/api/order/create-multi-payment', name: 'order_create_multi_payment', methods: ['POST'])]
    public function createOrderWithMultiplePayments(Request $request): JsonResponse
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'User not authenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'INVALID_BODY', 'message' => 'Corps JSON invalide'], JsonResponse::HTTP_BAD_REQUEST);
        }
        if (empty($data['items']) || !is_array($data['items'])) {
            return $this->json(['error' => 'ITEMS_REQUIRED', 'message' => 'La vente doit contenir au moins un article'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $paymentMethods = [];
        foreach (is_array($data['paymentMethods'] ?? null) ? $data['paymentMethods'] : [] as $method) {
            if (!is_array($method) || !is_numeric($method['type'] ?? null) || !is_numeric($method['amount'] ?? null) || (float) $method['amount'] < 0) {
                return $this->json(['error' => 'PAYMENT_METHODS_INVALID', 'message' => 'Chaque moyen de paiement doit avoir un type et un montant positif'], JsonResponse::HTTP_BAD_REQUEST);
            }
            $paymentMethods[] = new PaymentMethodDTO((int) $method['type'], (float) $method['amount']);
        }
        if (!$paymentMethods) {
            return $this->json(['error' => 'PAYMENT_METHODS_REQUIRED', 'message' => 'Au moins un moyen de paiement est requis'], JsonResponse::HTTP_BAD_REQUEST);
        }
        if (isset($data['priceShipping']) && (float) $data['priceShipping'] < 0) {
            return $this->json(['error' => 'Frais de livraison invalides'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $typeOrder = (int) ($data['typeOrder'] ?? self::TYPE_ORDER_SALE);
        $em = $this->emProvider->getEntityManager();
        $addressId = is_numeric($data['addressId'] ?? null) ? (int) $data['addressId'] : null;
        if ($addressId !== null && !$em->getRepository(Adress::class)->find($addressId)) {
            return $this->json(['error' => 'ADDRESS_NOT_FOUND', 'message' => 'Adresse introuvable'], JsonResponse::HTTP_NOT_FOUND);
        }
        $carrierId = is_numeric($data['carrierId'] ?? null) ? (int) $data['carrierId'] : null;
        if ($carrierId !== null && !$em->getRepository(Carrier::class)->find($carrierId)) {
            return $this->json(['error' => 'CARRIER_NOT_FOUND', 'message' => 'Transporteur introuvable'], JsonResponse::HTTP_NOT_FOUND);
        }

        // Paiement Stripe (terminal, lien de paiement) : vérifié et à usage unique s'il est fourni, comme sur le web
        $paymentData = is_array($data['payment'] ?? null) ? $data['payment'] : [];
        $paymentIntent = null;
        if (!empty($paymentData['stripePaymentId'])) {
            $paymentIntent = $this->stripeService->verifyPaymentIntent((string) $paymentData['stripePaymentId']);
            if (!$paymentIntent) {
                return $this->json(['error' => 'Payment verification failed or payment not completed'], JsonResponse::HTTP_PAYMENT_REQUIRED);
            }
            if ($this->isPaymentIntentAlreadyUsed($paymentIntent->id)) {
                return $this->json(['error' => 'Ce paiement est déjà associé à une commande.'], JsonResponse::HTTP_CONFLICT);
            }
            $charge = $paymentIntent->charges->data[0] ?? null;
            $paymentData = [
                'stripePaymentId' => $paymentIntent->id,
                'status' => $paymentIntent->status,
                'receiptUrl' => $charge?->receipt_url,
                'cardBrand' => $charge?->payment_method_details?->card?->brand,
                'last4' => $charge?->payment_method_details?->card?->last4,
                'riskLevel' => $charge?->outcome?->risk_level,
            ];
        }

        $dto = new CreateOrderMultiPaymentDTO(
            $user->getId(),
            (int) ($data['orderSource'] ?? self::ORDER_SOURCE_POS),
            $paymentMethods,
            $addressId,
            $carrierId,
            $typeOrder,
            $data['items'],
            isset($data['priceShipping']) ? (float) $data['priceShipping'] : null,
            null, null, null, null, null, null, null, // Square : plus utilisé
            $paymentData['stripePaymentId'] ?? null,
            $paymentData['receiptUrl'] ?? null,
            $paymentData['status'] ?? null,
            $paymentData['cardBrand'] ?? null,
            $paymentData['last4'] ?? null,
            $paymentData['riskLevel'] ?? null,
            $data['licenseNumber'] ?? null,
            $data['licenseExpirationDate'] ?? null
        );
        // Les moyens de paiement doivent couvrir exactement le total calculé par le serveur (négatif pour un retour)
        $paid = (int) round(array_sum(array_map(fn (PaymentMethodDTO $m) => $m->getAmount(), $paymentMethods)) * 100);
        $dto->setVerifiedPaymentAmount($typeOrder === self::TYPE_ORDER_SALE ? $paid : -$paid);
        // Poste de caisse (facultatif : poste unique sinon) ; sans session ouverte → 409 CASH_REGISTER_CLOSED, rien n'est créé
        $dto->setCashRegisterId(is_numeric($data['cashRegisterId'] ?? null) ? (int) $data['cashRegisterId'] : null);

        $result = $this->createOrderUseCase->execute($dto);
        if (!$result instanceof Order && $paymentIntent !== null) {
            $this->stripeService->cancelPaymentIntent($paymentIntent->id);
        }
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

            // Capture Stripe Payment Intent
            $paymentIntentId = $paymentData['stripePaymentId'] ?? null;
            if ($paymentIntentId) {
                $capturedIntent = $this->stripeService->capturePaymentIntent($paymentIntentId);
                if ($capturedIntent && $capturedIntent->status === 'succeeded') {
                    $payments = $result->getPayments();
                    if ($payments && !$payments->isEmpty()) {
                        /** @var \App\Entity\Payments $payment */
                        foreach ($payments as $payment) {
                            if ($payment->getPaymentMethod()?->getId() == 2 || $payment->getStripePaymentId() === $paymentIntentId) {
                                $payment->setStripeStatus('succeeded');
                                $charge = $capturedIntent->charges->data[0] ?? null;
                                if ($charge) {
                                    if ($charge->receipt_url) {
                                        $payment->setStripeReceiptUrl($charge->receipt_url);
                                    }
                                    if ($charge->payment_method_details?->card?->brand) {
                                        $payment->setStripeCardBrand($charge->payment_method_details->card->brand);
                                    }
                                    if ($charge->payment_method_details?->card?->last4) {
                                        $payment->setStripeLast4($charge->payment_method_details->card->last4);
                                    }
                                    if ($charge->outcome?->risk_level) {
                                        $payment->setStripeRiskLevel($charge->outcome->risk_level);
                                    }
                                }
                                $tenantEm->persist($payment);
                            }
                        }
                        $tenantEm->flush();
                    }
                } else {
                    $this->logger->error(sprintf("[createOrderWithMultiplePayments] Échec de la capture du paiement Stripe pour PaymentIntent ID: %s. Commande ID: %d.", $paymentIntentId, $result->getId()));
                }
            }

            try {
                $locale = $request->query->get('locale', 'fr');
                $domain = $this->mediaUrlResolver->getEmailLogosBaseUrl($request->getSchemeAndHttpHost()) . '/';
                $this->orderMailerService->sendOrderConfirmation($result, $locale, $domain);
                $this->orderMailerService->sendShippingNotification($result, $locale, $domain);
            } catch (\Exception $e) {
                $this->logger->error("Le service d'email (multi-paiement) a échoué : " . $e->getMessage(), [
                    'orderId' => $result->getId(),
                    'exception' => $e
                ]);
            }

            // Champs historiques inchangés + bloc « ticket de caisse » (16/10/2026, docs/caisse.md)
            return $this->json([
                'success' => true,
                'orderId' => $result->getId(),
                'message' => 'Commande créée.',
            ] + PosReceiptDto::fromOrder($result, $tenantEm->getRepository(\App\Entity\Entreprise::class)->findOneBy([]), $this->caisseService->getCaisseOfOrder($result), $this->locale($request)), JsonResponse::HTTP_CREATED);
        }
        return $result;
    }

    #[Route('/api/order/cancel/{id}', name: 'order_cancel', methods: ['POST'])]
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
        $data = is_array($data) ? $data : [];
        // cashRegisterId (15/10/2026) : poste du remboursement d'une vente POS si la session d'origine est fermée ; ignoré pour une commande web
        return $this->cancelOrderUseCase->execute($id, is_numeric($data['paymentMethod'] ?? null) ? (int) $data['paymentMethod'] : null,
            is_numeric($data['cashRegisterId'] ?? null) ? (int) $data['cashRegisterId'] : null);
    }

    /**
     * Commandes du site par source (application de gestion, caisse). ROLE_ADMIN : toutes sources, comme avant ;
     * ROLE_USER_POS (15/10/2026) : ventes de la caisse (orderSource 2) seulement ; autre compte : 403.
     */
    #[Route("api/orders", name: "get_orders", methods: ["GET"])]
    public function getOrders(Request $request): JsonResponse
    {
        $orderSourceId = $request->query->get('orderSource');
        if (!$this->isGranted('ROLE_ADMIN') && !($this->isGranted('ROLE_USER_POS') && (int) $orderSourceId === self::ORDER_SOURCE_POS)) {
            throw $this->createAccessDeniedException();
        }
        if (!$orderSourceId) {
            return $this->json(['error' => 'orderSource parameter is required'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $days = $request->query->get('days');
        $host = $this->mediaUrlResolver->getPublicHost($request->getSchemeAndHttpHost());
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

        $host = $this->mediaUrlResolver->getPublicHost($request->getSchemeAndHttpHost());
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

    /** Un PaymentIntent ne peut valider qu'une seule commande */
    private function isPaymentIntentAlreadyUsed(string $paymentIntentId): bool
    {
        return $this->emProvider->getEntityManager()->getRepository(Payments::class)
            ->count(['stripePaymentId' => $paymentIntentId]) > 0;
    }

    /**
     * Détail d'une commande pour son client connecté, ou pour un invité muni du jeton reçu à la commande (?token=).
     * Boutique réglable, 08/10/2026. Autre client ou jeton faux : 404.
     */
    #[Route('/api/orders/{id}', name: 'api_customer_order', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getCustomerOrder(int $id, Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();
        $host = $this->mediaUrlResolver->getPublicHost($request->getSchemeAndHttpHost());
        try {
            $order = $this->getCustomerOrderUseCase->execute($id, $user, $request->query->get('token'), $host, (string) $request->query->get('locale', 'fr'));
        } catch (HttpException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
        }

        return $this->json($order->toArray());
    }

    /** Statuts de commande du site, dans la langue demandée (public, lecture seule) */
    #[Route('/api/order-statuses', name: 'api_order_statuses', methods: ['GET'])]
    public function getOrderStatuses(Request $request): JsonResponse
    {
        return $this->json($this->getOrderStatusesUseCase->execute((string) $request->query->get('locale', 'fr')));
    }
}
