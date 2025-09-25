<?php
// src/Controller/Admin/ProductSpecificationController.php

namespace App\Controller\Admin;

use App\Entity\Product;
use App\Form\Type\SpecificationsType;
use App\Services\TenantEntityManagerProvider; // Make sure this is imported
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException; // Make sure this is imported
use Symfony\Component\Routing\Annotation\Route;

class ProductSpecificationController extends AbstractController
{
    private TenantEntityManagerProvider $emProvider;

    // Inject the provider in the constructor
    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    #[Route('/admin/product/{id}/specifications', name: 'admin_product_specifications')]
    public function edit(
        int $id, // <-- Change #1: Inject the ID, not the Product object
        Request $request,
        AdminUrlGenerator $adminUrlGenerator
    ): Response {
        // Change #2: Manually fetch the product using the correct entity manager
        $tenantEm = $this->emProvider->getEntityManager();
        $product = $tenantEm->getRepository(Product::class)->find($id);

        if (!$product) {
            throw new NotFoundHttpException('Produit non trouvé.');
        }

        // The rest of the controller logic remains the same
        $form = $this->createForm(SpecificationsType::class, $product->getSpecifications(), [
            'specifications_template' => $product->getProductType()?->getSpecificationsTemplate() ?? [],
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $product->setSpecifications($form->getData());
            $tenantEm->flush(); // Use the tenant entity manager to save

            $this->addFlash('success', 'Les caractéristiques ont été mises à jour.');

            $targetUrl = $adminUrlGenerator
                ->setController(ProductCrudController::class)
                ->setAction(Action::EDIT)
                ->setEntityId($product->getId())
                ->generateUrl();

            return $this->redirect($targetUrl);
        }

        return $this->render('admin/product_specifications/edit.html.twig', [
            'product' => $product,
            'form' => $form->createView(),
        ]);
    }
}