<?php

namespace App\Controller\Admin;

use App\Entity\Color;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\Size;
use App\Repository\ColorRepository;
use App\Repository\ProductRepository;
use App\Repository\SizeRepository;
use App\Repository\ProductOptionValueRepository;
use App\Services\BarcodeService\CatalogBarcodeService;
use App\Services\TenantEntityManagerProvider;
use App\Controller\Admin\BaseTenantCrudController; 
use App\UseCase\OrderUseCase\UpdateStockAndInventoryUseCase;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use Symfony\Component\HttpFoundation\RedirectResponse;




class ProductVariantCrudController extends BaseTenantCrudController
{
    use CsrfProtectedActionTrait;

    private UpdateStockAndInventoryUseCase $updateStockAndInventoryUseCase;
    private AdminUrlGenerator $adminUrlGenerator;
    public function __construct(
        TenantEntityManagerProvider $emProvider, 
        UpdateStockAndInventoryUseCase $updateStockAndInventoryUseCase,
        AdminUrlGenerator $adminUrlGenerator,
        private readonly CatalogBarcodeService $barcodes // codes-barres du catalogue (lot CB-1, 15/11/2026, `docs/codes-barres.md`)
    ) {
        parent::__construct($emProvider); 
        $this->updateStockAndInventoryUseCase = $updateStockAndInventoryUseCase;
        $this->adminUrlGenerator = $adminUrlGenerator; 
    }

    public static function getEntityFqcn(): string
    {
        return ProductVariant::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->hideOnForm(),
            AssociationField::new('product', 'Produit')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn($repo) => $repo->createQueryBuilder('p')->orderBy('p.name', 'ASC'),
                    'choice_label' => 'name',
                ]),
            AssociationField::new('color', 'Couleur')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn($repo) => $repo->createQueryBuilder('c')->orderBy('c.name', 'ASC'),
                    'choice_label' => 'name',
                ]),
            AssociationField::new('size', 'Taille')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn($repo) => $repo->createQueryBuilder('s')->orderBy('s.name', 'ASC'),
                    'choice_label' => 'name',
                ]),
            IntegerField::new('stockQuantity', 'Quantité en stock'),
            IntegerField::new('price', 'Prix de la variante (cents)')->setHelp('Vide : prix du produit. 2 499 $ = 249900.')->hideOnIndex(),
            TextField::new('barcode', 'Code-barres de la variante')->setRequired(false)->setFormTypeOption('attr', ['maxlength' => 64])
                ->setHelp('Facultatif : c\'est le code de la pièce vendue. Le code du fabricant quand la pièce en porte un (scannez-le dans ce champ), sinon « Générer un code-barres » depuis la liste. Un code ne désigne qu\'une seule chose : un article ou une variante. La caisse cherche d\'abord la variante, puis l\'article.'),
            AssociationField::new('optionValues', 'Valeurs de cette variante')
                ->setHelp('Sélectionnez la combinaison exacte de valeurs pour cette variante (ex: "Rouge" et "XL").')
                ->setFormTypeOption('by_reference', false) 
                ->setFormTypeOptions([
                    'em' => $tenantEm, 
                    'query_builder' => fn($repo) => $repo->createQueryBuilder('pov')
                        ->join('pov.productOption', 'po')
                        ->orderBy('po.name', 'ASC')
                        ->addOrderBy('pov.value', 'ASC'),
                ])->onlyOnForms(),
                 AssociationField::new('optionValues', 'Valeurs')
                ->formatValue(function ($value, ProductVariant $variant) {
                    $count = count($variant->getOptionValues());
                    if ($count === 0) {
                        return 'Aucune';
                    }
                    
                    $url = $this->adminUrlGenerator
                        ->setController(ProductOptionValueListController::class)
                        ->setAction('index')
                        ->set('variantId', $variant->getId())
                        ->generateUrl();
                    
                    return sprintf('<a href="%s">Voir les valeurs (%d)</a>', $url, $count);
                })
                ->renderAsHtml()->hideOnForm(),
        ];
    }
    
     public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof ProductVariant) {
            $tenantEm = $this->emProvider->getEntityManager();
            if ($product = $entityInstance->getProduct()) {
                $entityInstance->setProduct($tenantEm->merge($product));
            }
            if ($color = $entityInstance->getColor()) {
                $entityInstance->setColor($tenantEm->merge($color));
            }
            if ($size = $entityInstance->getSize()) {
                $entityInstance->setSize($tenantEm->merge($size));
            }
            foreach ($entityInstance->getOptionValues() as $optionValue) {
                $tenantEm->merge($optionValue);
            }
            $this->updateStockAndInventoryUseCase->executeNewProductVariant($entityInstance, 0, 1);
        }
        parent::persistEntity($entityManager, $entityInstance);
        $this->warnAboutBarcode($entityInstance, null);
    }

    /**
     * Correction pour la MISE À JOUR
     */
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $barcodeBefore = $entityInstance instanceof ProductVariant ? $this->barcodes->storedCode($entityInstance) : null;
        if ($entityInstance instanceof ProductVariant) {
            $tenantEm = $this->emProvider->getEntityManager();

             if ($product = $entityInstance->getProduct()) {
                $entityInstance->setProduct($tenantEm->merge($product));
            }
            if ($color = $entityInstance->getColor()) {
                $entityInstance->setColor($tenantEm->merge($color));
            }
            if ($size = $entityInstance->getSize()) {
                $entityInstance->setSize($tenantEm->merge($size));
            }
            foreach ($entityInstance->getOptionValues() as $optionValue) {
                $tenantEm->merge($optionValue);
            }
            $unitOfWork = $tenantEm->getUnitOfWork();
            $originalData = $unitOfWork->getOriginalEntityData($entityInstance);
            $stockBeforeMovement = $originalData['stockQuantity'] ?? 0;
            $this->updateStockAndInventoryUseCase->executeNewProductVariant($entityInstance, $stockBeforeMovement, 4);
        }
        parent::updateEntity($entityManager, $entityInstance);
        $this->warnAboutBarcode($entityInstance, $barcodeBefore ?? null);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof ProductVariant) {
             $tenantEm = $this->emProvider->getEntityManager();
             $managedVariant = $tenantEm->merge($entityInstance); 
            try { // ART-SUPPR (12/11/2026) : refus avant toute écriture (la sortie de stock ci-dessous laisserait un mouvement orphelin)
                \App\Services\ProductService\ProductUsageGuard::assertVariantDeletable($tenantEm->getConnection(), $managedVariant);
            } catch (\App\Gestion\Services\GestionConflictException $e) {
                throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException($e->getMessage(), $e);
            }

            $this->updateStockAndInventoryUseCase->executeNewProductVariant($managedVariant, $managedVariant->getStockQuantity(), 2);
        }
        parent::deleteEntity($entityManager, $entityInstance);
    }

    public function configureActions(Actions $actions): Actions
    {
        $editCustom = Action::new('edit_custom', 'Modifier', 'fa fa-pencil')
            ->linkToRoute('admin_product_variant_edit_custom', function (ProductVariant $variant): array {
                return ['id' => $variant->getId()];
            });

        // Lot CB-1 : proposé seulement à la variante sans code ; l'action ne remplace jamais un code présent
        $generateBarcode = Action::new('generateBarcode', 'Générer un code-barres', 'fa fa-barcode')
            ->linkToUrl(fn (ProductVariant $variant) => $this->csrfActionUrl('generateBarcode', $variant->getId()))
            ->displayIf(static fn (ProductVariant $variant): bool => $variant->getBarcode() === null);

        return $actions
            // On remplace l'action 'edit' par la nôtre sur la page de liste
            ->remove(Crud::PAGE_INDEX, Action::EDIT)
            ->add(Crud::PAGE_INDEX, $editCustom)
            ->add(Crud::PAGE_INDEX, $generateBarcode)
            ->add(Crud::PAGE_DETAIL, $generateBarcode)
            // On peut aussi la retirer de la page de détail si besoin
            ->remove(Crud::PAGE_DETAIL, Action::EDIT);
    }

    /** Donne à la variante un code généré (EAN-13 interne du site), jamais par-dessus un code présent (lot CB-1) */
    public function generateBarcode(AdminContext $context): RedirectResponse
    {
        $index = $this->container->get(AdminUrlGenerator::class)->unsetAll()->setController(self::class)->setAction('index')->generateUrl();
        if (!$this->isCsrfActionValid($context, 'generateBarcode')) {
            $this->addFlash('danger', 'Lien invalide ou expiré : action annulée. Utilisez le bouton depuis la liste.');

            return $this->redirect($index);
        }
        $variant = $context->getEntity()->getInstance();
        if (!$variant instanceof ProductVariant) {
            $this->addFlash('danger', 'Variante introuvable.');

            return $this->redirect($index);
        }
        $generated = $this->barcodes->tryGenerateFor($variant); // un refus ou une panne revient en message, jamais en page d'erreur
        $generated['code'] !== null
            ? $this->addFlash('success', sprintf('Code-barres généré pour la variante n° %d : %s', $variant->getId(), $generated['code']))
            : $this->addFlash($generated['failed'] ? 'danger' : 'warning', (string) $generated['refusal']);

        return $this->redirect($index);
    }

    /** Code accepté mais à signaler (13 chiffres dont la clé de contrôle est fausse) : le refus, lui, est rendu sous le champ par la validation */
    private function warnAboutBarcode(mixed $variant, ?string $before): void
    {
        $warning = $variant instanceof ProductVariant ? $this->barcodes->warning($variant->getBarcode(), $before) : null;
        if ($warning !== null) {
            $this->addFlash('warning', $warning);
        }
    }
}
