<?php

// src/Controller/Admin/CustomProductVariantController.php

namespace App\Controller\Admin;

use App\Entity\ProductVariant;
use App\Form\ProductVariantCustomType;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\OrderUseCase\UpdateStockAndInventoryUseCase;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class CustomProductVariantController extends AbstractController
{
    #[Route('/admin/product-variant/{id}/edit-custom', name: 'admin_product_variant_edit_custom')]
    public function edit(
        int $id,
        Request $request,
        TenantEntityManagerProvider $emProvider,
        UpdateStockAndInventoryUseCase $updateStockAndInventoryUseCase,
        AdminUrlGenerator $adminUrlGenerator 
    ): Response {

        $tenantEm = $emProvider->getEntityManager();
        $variantInstance = $tenantEm->getRepository(ProductVariant::class)->find($id);

        if (!$variantInstance) {
            throw $this->createNotFoundException('La variante demandée n\'a pas été trouvée dans ce tenant.');
        }

        $form = $this->createForm(ProductVariantCustomType::class, $variantInstance, [
            'tenant_em' => $tenantEm,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $unitOfWork = $tenantEm->getUnitOfWork();
            $originalData = $unitOfWork->getOriginalEntityData($variantInstance);
            $stockBeforeMovement = $originalData['stockQuantity'] ?? 0;
            $updateStockAndInventoryUseCase->executeNewProductVariant($variantInstance, $stockBeforeMovement, 4);
            $tenantEm->flush();
            $this->addFlash('success', 'La variante a été mise à jour avec succès.');
            $url = $adminUrlGenerator
                ->setController(ProductVariantCrudController::class)
                ->setAction('index')
                ->generateUrl();
            return $this->redirect($url);
        }
        return $this->render('admin/product_variant/edit_custom.html.twig', [
            'form' => $form->createView(),
            'variant' => $variantInstance,
        ]);
    }
}