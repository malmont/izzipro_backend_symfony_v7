<?php

namespace App\Controller;

use App\Entity\Product;
use App\Entity\SearchProduct;
use App\Form\SearchProductType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        return $this->render('home/index.html.twig', [
            'controller_name' => 'HomeController',
        ]);
    }

    #[Route('/product/{slug}', name: 'product_details')]
    public function show(?Product $product):Response
    {
        if(!$product){
            return $this->redirectToRoute('app_home');
        }
        return $this->render("home/single_product.html.twig",[
            'product'=>$product
        ]);
    }


    #[Route('/shop', name: 'shop')]
    public function shop(ProductRepository $repoProduct ,Request $request): Response
    {
        $products = $repoProduct->findAll();
        $search = new SearchProduct();
        $form = $this->createForm(SearchProductType::class, $search);
        $form->handleRequest($request);
        if($form->isSubmitted() && $form->isValid()){
            $products = $repoProduct->findWithSearch($search);
        }
        return $this->render('home/shop.html.twig', [
        
            'products'=>$products,
            'search'=>$form->createView(),

          
        ]);
    }
}
