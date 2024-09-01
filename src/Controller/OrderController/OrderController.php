<?php
namespace App\Controller\OrderController;

use App\Entity\Order;
use App\Entity\OrderItems;
use App\Entity\Payments;
use App\Entity\TransactionCaisse;
use App\Entity\Adress;
use App\Entity\Carrier;
use App\Entity\StatusPayment;
use App\Entity\InventoryMovements;
use App\Entity\StatusCommande;
use App\Entity\OrderTax;
use App\Repository\OrderSourceRepository;
use App\Repository\PaymentMethodRepository;
use App\Repository\ProductVariantRepository;
use App\Repository\TransactionCaisseRepository;
use App\Repository\TransactionTypeRepository;
use App\Repository\MovementTypeRepository;
use App\Repository\TaxRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class OrderController extends AbstractController
{
    private $em;
    private $orderSourceRepository;
    private $paymentMethodRepository;
    private $transactionCaisseRepository;
    private $transactionTypeRepository;
    private $productVariantRepository;
    private $movementTypeRepository;
    private $taxRepository;

    public function __construct(
        EntityManagerInterface $em,
        OrderSourceRepository $orderSourceRepository,
        PaymentMethodRepository $paymentMethodRepository,
        TransactionCaisseRepository $transactionCaisseRepository,
        TransactionTypeRepository $transactionTypeRepository,
        ProductVariantRepository $productVariantRepository,
        MovementTypeRepository $movementTypeRepository,
        TaxRepository $taxRepository
    ) {
        $this->em = $em;
        $this->orderSourceRepository = $orderSourceRepository;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->transactionCaisseRepository = $transactionCaisseRepository;
        $this->transactionTypeRepository = $transactionTypeRepository;
        $this->productVariantRepository = $productVariantRepository;
        $this->movementTypeRepository = $movementTypeRepository;
        $this->taxRepository = $taxRepository;
    }

    /**
     * @Route("api/order/create", name="order_create", methods={"POST"})
     */
    public function createOrder(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $user = $this->getUser();

        // Récupération de la source de la commande
        $orderSource = $this->orderSourceRepository->find($data['orderSource']);
        if (!$orderSource) {
            return new JsonResponse(['error' => 'Invalid order source ID'], 400);
        }

        // Vérification pour les commandes en caisse
        if ($orderSource->getName() === 'caisse') {
            $caisse = $this->getOpenCaisse();
            if (!$caisse) {
                return new JsonResponse(['error' => 'No open caisse found'], 400);
            }

            // Forcer l'initialisation de l'objet proxy Caisse
            $this->em->getUnitOfWork()->initializeObject($caisse);
        }

        // Récupération et assignation de la méthode de paiement
        $paymentMethod = $this->paymentMethodRepository->find($data['paymentMethod']);
        if (!$paymentMethod) {
            return new JsonResponse(['error' => 'Invalid payment method ID'], 400);
        }

        // Création de la commande
        $order = new Order();
        $order->setReference('REF#' . uniqid());
        $order->setUserId($user);
        $order->setOrderSource($orderSource);
        $order->setOrderDate(new \DateTime());

        // Récupération et assignation de l'adresse
        $address = $this->em->getRepository(Adress::class)->find($data['addressId']);
        if (!$address) {
            return new JsonResponse(['error' => 'Invalid address ID'], 400);
        }
        $order->setShippingAdress($address);

        // Récupération et assignation du transporteur
        $carrier = $this->em->getRepository(Carrier::class)->find($data['carrierId']);
        if (!$carrier) {
            return new JsonResponse(['error' => 'Invalid carrier ID'], 400);
        }
       
        $order->setCarrier($carrier);
        $statusCommande = $this->em->getRepository(StatusCommande::class)->find(1);
        $order->setStatus($statusCommande);
        
        // Initialisation des montants
        $subtotal = 0;
        $totalTax = 0;
        
        // Traitement des items de la commande
        foreach ($data['items'] as $itemData) {
            $productVariant = $this->productVariantRepository->find($itemData['productVariantId']);
            if (!$productVariant || $productVariant->getStockQuantity() < $itemData['quantity']) {
                return new JsonResponse(['error' => 'Insufficient stock for product variant'], 400);
            }
            $unitPrice = $productVariant->getProduct()->getPrice();
            
            // Mise à jour du stock et création d'un mouvement d'inventaire
            $productVariant->setStockQuantity($productVariant->getStockQuantity() - $itemData['quantity']);
            $movementTypeOutgoing = $this->movementTypeRepository->find(2);
            $inventoryMovement = new InventoryMovements();
            $inventoryMovement->setProductVariant($productVariant);
            $inventoryMovement->setQuantity($itemData['quantity']);
            $inventoryMovement->setMovementType($movementTypeOutgoing);
            $inventoryMovement->setMovementDate(new \DateTime());

            $this->em->persist($inventoryMovement);

            // Création des OrderItems
            $orderItem = new OrderItems();
            $orderItem->setOrderAssociated($order);
            $orderItem->setProductVariant($productVariant);
            $orderItem->setQuantity($itemData['quantity']);
            $orderItem->setUnitPrice($unitPrice);
            $orderItem->setTotalPrice($unitPrice * $itemData['quantity']);
            
            $this->em->persist($orderItem);
            $subtotal += $orderItem->getTotalPrice();
        }
        // dd($subtotal);
        // Calcul des taxes
        $taxes = $this->taxRepository->findAll(); // Récupération de toutes les taxes applicables
        foreach ($taxes as $tax) {
            $taxAmount = ($subtotal * $tax->getRate());
            $totalTax += $taxAmount;
            
            // Création d'une entrée dans OrderTax
            $orderTax = new OrderTax();
            $orderTax->setOrderTax($order);
            $orderTax->setTax($tax);
            $orderTax->setAmount($taxAmount);

            $this->em->persist($orderTax);
        }
        
        // Calcul du montant total de la commande
        $totalAmount = $subtotal + $totalTax;
        $order->setSubtotal($subtotal);
        $order->setTotalTax($totalTax);
        $order->setTotalAmount($totalAmount);
        
        $this->em->persist($order);
        $statusPayment = $this->em->getRepository(StatusPayment::class)->find(2);
        if (!$statusPayment) {
            return new JsonResponse(['error' => 'Invalid status payment ID'], 400);
        }
        
        // Enregistrement du paiement
        $payment = new Payments();
        $payment->setOrderPayment($order);
        $payment->setAmount($totalAmount);
        $payment->setPaymentMethod($paymentMethod);
        $payment->setStatutPayment($statusPayment);
        $payment->setPaymentDate(new \DateTime());
        
        $this->em->persist($payment);

        // Mise à jour de la commande après paiement
        $statusCommande = $this->em->getRepository(StatusCommande::class)->find(3);
        $order->setStatus($statusCommande);
        $this->em->persist($order);

        // Si l'ordre provient de la caisse
        if ($orderSource->getName() === 'caisse') {
            // Création de la TransactionCaisse
            $transactionType = $this->transactionTypeRepository->findOneBy(['name' => 'Vendu']);
            $transactionCaisse = new TransactionCaisse();
            $transactionCaisse->setCaisse($caisse);
            $transactionCaisse->setUser($user);
            $transactionCaisse->setOrder($order);
            $transactionCaisse->setTransactionDate(new \DateTime());
            $transactionCaisse->setTransactionType($transactionType);
            $transactionCaisse->setAmount($totalAmount);
            $this->em->persist($transactionCaisse);
        }

        $this->em->flush();

        // Envoi de la confirmation
        $this->sendOrderConfirmation($order);

        return new JsonResponse(['message' => 'Order created successfully'], 201);
    }

    /**
     * @Route("api/order/cancel/{id}", name="order_cancel", methods={"POST"})
     */
    public function cancelOrder(int $id): JsonResponse
    {
        // Récupérer la commande par son ID
        $order = $this->em->getRepository(Order::class)->find($id);
        if (!$order) {
            return new JsonResponse(['error' => 'Order not found'], 404);
        }

        // Vérifier si la commande peut être annulée (ex: déjà livrée ?)
        $currentStatus = $order->getStatus();
        if ($currentStatus->getName() === 'Livré' || $currentStatus->getName() === 'En cours de livraison') {
            return new JsonResponse(['error' => 'Order cannot be canceled after it has been shipped or delivered'], 400);
        }

        // Mettre à jour le statut de la commande à "Annulé"
        $cancelStatus = $this->em->getRepository(StatusCommande::class)->findOneBy(['name' => 'Annulé']);
        $order->setStatus($cancelStatus);

        // Remboursement du paiement
        $this->refundPayment($order);

        // Mise à jour des mouvements de stock
        foreach ($order->getOrderItems() as $orderItem) {
            $productVariant = $orderItem->getProductVariant();
            $productVariant->setStockQuantity($productVariant->getStockQuantity() + $orderItem->getQuantity());

            $movementTypeIncoming = $this->movementTypeRepository->find(1);
            $inventoryMovement = new InventoryMovements();
            $inventoryMovement->setProductVariant($productVariant);
            $inventoryMovement->setQuantity($orderItem->getQuantity());
            $inventoryMovement->setMovementType($movementTypeIncoming);
            $inventoryMovement->setMovementDate(new \DateTime());

            $this->em->persist($inventoryMovement);
        }

        // Annulation des taxes associées
        $orderTaxes = $order->getOrderTaxes();
        foreach ($orderTaxes as $orderTax) {
            $this->em->remove($orderTax); // Supprimez les entrées de la table OrderTax associées à cette commande
        }

        // Si l'ordre provient de la caisse, créer une transaction de remboursement
        if ($order->getOrderSource()->getName() === 'caisse') {
            $this->createCaisseRefundTransaction($order);
        }

        $this->em->flush();

        return new JsonResponse(['message' => 'Order canceled and refunded successfully'], 200);
    }

    private function refundPayment(Order $order): void
    {
        $payments = $order->getPayments();
        $refundStatus = $this->em->getRepository(StatusPayment::class)->findOneBy(['name' => 'Remboursé']);

        foreach ($payments as $payment) {
            $refund = new Payments();
            $refund->setOrderPayment($order);
            $refund->setAmount(-$payment->getAmount()); // Montant négatif pour refléter le remboursement
            $refund->setPaymentMethod($payment->getPaymentMethod());
            $refund->setStatutPayment($refundStatus);
            $refund->setPaymentDate(new \DateTime());

            $this->em->persist($refund);
        }
    }

    private function createCaisseRefundTransaction(Order $order): void
    {
        $caisse = $this->getOpenCaisse();
        if (!$caisse) {
            throw new \Exception('No open caisse found');
        }

        $transactionType = $this->transactionTypeRepository->findOneBy(['name' => 'Remboursement']);
        $transactionCaisse = new TransactionCaisse();
        $transactionCaisse->setCaisse($caisse);
        $transactionCaisse->setUser($this->getUser());
        $transactionCaisse->setOrder($order);
        $transactionCaisse->setTransactionDate(new \DateTime());
        $transactionCaisse->setTransactionType($transactionType);
        $transactionCaisse->setAmount(-$order->getTotalAmount());

        $this->em->persist($transactionCaisse);
    }

    /**
     * Méthode pour obtenir la caisse ouverte.
     *
     * @return Caisse|null
     */
    private function getOpenCaisse(): ?Caisse
    {
        return $this->em->getRepository(Caisse::class)->findOneBy(['isOpen' => true]);
    }

    private function sendOrderConfirmation(Order $order)
    {
        // Logique pour envoyer un email ou une notification
        // Exemple simplifié
        $email = $order->getUserId()->getEmail();
        $message = "Votre commande numéro {$order->getId()} a été confirmée.";
        // Envoyer l'email ou la notification ici
    }
}
