<?php

namespace App\Services;

use App\Entity\Cart;
use App\Entity\Order;
use App\Entity\Carrier;
use App\Entity\CartDetails;
use App\Entity\OrderDetails;
use App\Entity\Product; // <-- On importe l'entité Product
use App\Services\TenantEntityManagerProvider; // <-- On importe notre provider

class OrderServices
{
    // MODIFICATION 1 : Le service ne dépend plus que du provider
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function createOrder($cart)
    {
        // MODIFICATION 2 : On récupère l'EM du tenant ici
        $em = $this->emProvider->getEntityManager();
        
        $order = new Order();
        $order
            ->setReference($cart->getReference())
            ->setCarriername($cart->getCarriername())
            ->setCarrierprice($cart->getCarrierprice() / 100)
            ->setFullname($cart->getFullname())
            ->setDeleveryaddress($cart->getDeleveryaddress())
            ->setMoreinformations($cart->getMoreinformations())
            ->setQuantity($cart->getQuantity())
            ->setSubTotalHt($cart->getSubTotalHt() / 100)
            ->setTaxe($cart->getTaxe() / 100)
            ->setSubTotalTTC($cart->getSubTotalTTC() / 100)
            ->setUserOrder($cart->getUserCart())
            ->setCreatedAt($cart->getCreatedAt());
        
        // GARDE-FOU : On s'assure que le user est bien géré par l'EM
        $em->persist($cart->getUserCart());
        $em->persist($order);

        $products = $cart->getCartDetails()->getValues();
        foreach ($products as $cart_products) {
            $orderDetails = new OrderDetails();
            $orderDetails->setOrders($order)
                         ->setProductname($cart_products->getProductName())
                         ->setProducprice($cart_products->getProducprice())
                         ->setQuantity($cart_products->getQuantity())
                         ->setSubTotalHT($cart_products->getSubTotalHT())
                         ->setSubTotalTTC($cart_products->getSubTotalTTC())
                         ->setTaxe($cart_products->getTaxe());
            $em->persist($orderDetails);            
        }
        
        $em->flush();
        return $order;
    }

    public function getLineItems($cart)
    {
        $em = $this->emProvider->getEntityManager();
        $repoProduct = $em->getRepository(Product::class);
        
        $cartDetails = $cart->getCartDetails();
        $line_items = [];
        foreach($cartDetails as $details){
            // On utilise le repository obtenu depuis l'EM du tenant
            $product = $repoProduct->findOneBy(['name' => $details->getProductName()]); // findOneBy est plus sûr que findOneByName
            if ($product) { // S'assurer que le produit existe
                $line_items[] = [
                    'price_data' =>[
                      'currency' =>'usd',
                      'unit_amount' => $product->getPrice(),
                      'product_data'=>[
                        'name' => $product->getName(),
                      ],
                    ],
                    'quantity'=> $details->getQuantity(),
                ];
            }
        }
        // ... le reste de la logique pour la taxe et le transporteur est inchangée
        return $line_items;
    }

    public function saveCart($data, $user)
    {
        $em = $this->emProvider->getEntityManager();

        $cart = new Cart();
        $reference = $this->generateUuid();
        $address = $data['checkout']['address'];
        $carrier = $data['checkout']['carrier'];
        $informations = $data['checkout']['informations'];

        $cart
            ->setReference($reference)
            ->setCarriername($carrier->getName())
            ->setCarrierprice($carrier->getPrice() / 100)
            ->setFullname($address->getFullname())
            ->setDeleveryaddress($address)
            ->setMoreinformations($informations)
            ->setQuantity($data['data']['quantity_cart'])
            ->setSubTotalHt($data['data']['subTotalHT'])
            ->setTaxe($data['data']['Taxe'])
            ->setSubTotalTTC($data['data']['subTotalTTC'] + $carrier->getPrice() / 100, 2)
            ->setUserCart($user)
            ->setCreatedAt(new \DateTime());
        
        // GARDE-FOU : On s'assure que les entités liées sont gérées
        $em->persist($user);
        $em->persist($address);
        $em->persist($carrier);

        $em->persist($cart);

        foreach ($data['products'] as $products) {
            $cartDetails = new CartDetails();
            $subTotal = ($products['quantity'] * $products['product']->getPrice()) / 100;

            $cartDetails
                ->setCarts($cart)
                ->setProductname($products['product']->getName())
                // ... etc
            ;
            $em->persist($cartDetails);
        }
        
        $em->flush();
        return $reference;
    }

    // INCHANGÉ : Méthode pure
    public function generateUuid()
    {
        // ...
    }
}