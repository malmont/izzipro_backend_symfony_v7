<?php

namespace App\Controller\Admin;

use App\Entity\Product;
use App\Entity\Categories;
use App\Entity\Style;
use App\Entity\Commande;
use App\Repository\CategoriesRepository;
use App\Repository\StyleRepository;
use App\Repository\CommandeRepository;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
// ✅ DÉBUT DU BLOC MANQUANT
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
// ✅ FIN DU BLOC MANQUANT

class ProductCrudController extends AbstractCrudController
{
    private TenantEntityManagerProvider $emProvider;
    private AdminUrlGenerator $adminUrlGenerator;

    public function __construct(
        TenantEntityManagerProvider $emProvider,
        AdminUrlGenerator $adminUrlGenerator
    ) {
        $this->emProvider = $emProvider;
        $this->adminUrlGenerator = $adminUrlGenerator;
    }

    public static function getEntityFqcn(): string
    {
        return Product::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name'),
            SlugField::new('slug')->setTargetFieldName('name')->hideOnIndex(),
            TextEditorField::new('description')->setLabel('Description'),
            TextEditorField::new('moreinformations')->hideOnIndex()->setLabel('moreinformations'),
            MoneyField::new('price')->setCurrency('USD')->onlyOnIndex(), 
            MoneyField::new('purchasePrice', "Prix d'achat de l'article")->setCurrency('USD')->setStoredAsCents(false),
            NumberField::new('coefficientMultiplier', 'Coefficient Multiplier'),
            TextField::new('barcode', 'Barcode'),
            IntegerField::new('quantity')->onlyOnIndex(),
            TextField::new('tags'),
            BooleanField::new('isbestseller', 'BestSeller'),
            BooleanField::new('isnewarrival', 'New Arrival'),
            BooleanField::new('isfeatured', 'Featured'),
            BooleanField::new('isspecialoffer', 'Special Offer'),
            BooleanField::new('isAccessory', 'Accessoires '),
            BooleanField::new('isWeb', 'diffusion sur le web'),
            BooleanField::new('isPos', 'diffusion sur le Point de vente'),
            AssociationField::new('category')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn(CategoriesRepository $repo) => $repo->createQueryBuilder('c')->orderBy('c.name', 'ASC'),
                    'choice_label' => 'name',
                ]),
            AssociationField::new('style', 'Style')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn(StyleRepository $repo) => $repo->createQueryBuilder('s')->orderBy('s.name', 'ASC'),
                    'choice_label' => 'name',
                ]),
            AssociationField::new('commande', 'Commande')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn(CommandeRepository $repo) => $repo->createQueryBuilder('cmd')
                        // CORRECTION ICI : Remplacez 'orderDate' par le vrai nom de votre champ date,
                        // qui est très probablement 'createdAt'.
                        ->orderBy('cmd.date', 'DESC'), 
                    'choice_label' => 'reference',
                ]),
            ImageField::new('image')
                ->setBasePath('assets/uploads/products/')
                ->setUploadDir('public/assets/uploads/products/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(false),
            AssociationField::new('variants', 'Variantes de Produit')
                ->formatValue(function ($value, $entity) {
                    $url = $this->adminUrlGenerator
                        ->setController(ProductVariantListController::class)
                        ->setAction('index')
                        ->set('productId', $entity->getId())
                        ->generateUrl();
                    return sprintf('<a href="%s">Voir les variantes</a>', $url);
                })
                ->renderAsHtml(),
        ];
    }
    
    // ... le reste de vos méthodes (configureActions, generateBarcode, et les surcharges CRUD) ...
    public function configureActions(Actions $actions): Actions
    {
        $generateBarcode = Action::new('generateBarcode', 'Générer Code Barre')
            ->linkToCrudAction('generateBarcode');

        return $actions
            ->add(Crud::PAGE_EDIT, $generateBarcode)
            ->add(Crud::PAGE_DETAIL, $generateBarcode)
            ->add(Crud::PAGE_INDEX, $generateBarcode);
    }

    public function generateBarcode(AdminContext $context): RedirectResponse
    {
        /** @var Product $product */
        $product = $context->getEntity()->getInstance();
        if (!$product) {
            $this->addFlash('error', 'Produit non trouvé.');
            return $this->redirect($this->adminUrlGenerator->setAction('index')->generateUrl());
        }
        
        $tenantEm = $this->emProvider->getEntityManager();
        $managedProduct = $tenantEm->merge($product);
        
        $generatedBarcode = strtoupper(uniqid('BAR-', true));
        $managedProduct->setBarcode($generatedBarcode);

        $tenantEm->flush();

        $this->addFlash('success', 'Code barre généré avec succès : ' . $generatedBarcode);

        $url = $this->adminUrlGenerator
                    ->setController(self::class)
                    ->setAction('edit')
                    ->setEntityId($managedProduct->getId())
                    ->generateUrl();

        return $this->redirect($url);
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Product::class)->createQueryBuilder('p');
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $tenantEm->persist($entityInstance);
        $tenantEm->flush();
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $tenantEm->merge($entityInstance);
        $tenantEm->flush();
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $managedEntity = $tenantEm->merge($entityInstance);
        $tenantEm->remove($managedEntity);
        $tenantEm->flush();
    }
}