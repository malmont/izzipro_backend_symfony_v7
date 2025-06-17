<?php

namespace App\Services;

use App\Entity\Product;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class CartServices
{
    private SessionInterface $session;
    private TenantEntityManagerProvider $emProvider;
    private float $tva = 0.2;

    public function __construct(RequestStack $requestStack, TenantEntityManagerProvider $emProvider)
    {
        $this->session = $requestStack->getSession();
        $this->emProvider = $emProvider;
    }

    public function addToCart($id)
    {
        $cart = $this->getCart();
        if (isset($cart[$id])) {
            $cart[$id]++;
        } else {
            $cart[$id] = 1;
        }
        $this->updateCart($cart);
    }

    public function deleteFromCart($id)
    {
        $cart = $this->getCart();

        if (isset($cart[$id])) {
            if ($cart[$id] > 1) {
                $cart[$id]--;
            } else {
                unset($cart[$id]);
            }
            $this->updateCart($cart);
        }
    }

    public function deleteCart()
    {
        $this->updateCart([]);
    }

    public function deleteAllToCart($id)
    {
        $cart = $this->getCart();

        if (isset($cart[$id])) {
            unset($cart[$id]);
            $this->updateCart($cart);
        }
    }

    public function updateCart($cart)
    {
        $this->session->set('cart', $cart);
        $this->session->set('cartData', $this->getFullCart());
    }

    public function getCart()
    {
        return $this->session->get('cart', []);
    }

    public function getFullCart()
    {
        $em = $this->emProvider->getEntityManager();
        $repoProduct = $em->getRepository(Product::class);

        $cart = $this->getCart();
        $fullCart = [];
        $quantity_cart = 0;
        $subTotal = 0;
        
        foreach ($cart as $id => $quantity) {
            $product = $repoProduct->find($id);
            if ($product) {
                $fullCart["products"][] = [
                    "quantity" => $quantity,
                    "product" => $product
                ];
                $quantity_cart += $quantity;
                $subTotal += $quantity * $product->getPrice() / 100;
            } else {
                // Si le produit n'existe plus en base, on le retire du panier
                $this->deleteFromCart($id);
            }
        }

        $fullCart['data'] = [
            "quantity_cart" => $quantity_cart,
            "subTotalHT" =>  $subTotal,
            "Taxe" => round($subTotal * $this->tva, 2),
            "subTotalTTC" => round($subTotal + ($subTotal * $this->tva), 2)
        ];

        return $fullCart;
    }
}