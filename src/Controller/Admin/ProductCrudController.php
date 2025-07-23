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
use App\Controller\Admin\BaseTenantCrudController; 
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
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
use Symfony\Component\HttpFoundation\RedirectResponse;


class ProductCrudController extends BaseTenantCrudController
{
    private AdminUrlGenerator $adminUrlGenerator;


    public function __construct(
        TenantEntityManagerProvider $emProvider, 
        AdminUrlGenerator $adminUrlGenerator
    ) {
        parent::__construct($emProvider); 
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
            MoneyField::new('purchasePrice', "Prix d'achat de l'article")->setCurrency('USD'), 
            NumberField::new('coefficientMultiplier', 'Coefficient Multiplier'),
            NumberField::new('gemsuiteProductId', 'gemsuiteProductId'),
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
                    'query_builder' => fn(CommandeRepository $repo) => $repo->createQueryBuilder('cmd')->orderBy('cmd.date', 'DESC'), 
                    'choice_label' => 'reference',
                ]),
            ImageField::new('image')
                ->setBasePath('assets/uploads/products/')
                ->setUploadDir('public/assets/uploads/products/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(false),
            AssociationField::new('variants', 'Variantes de Produit')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'by_reference' => false, 
                ])
                ->formatValue(function ($value, $entity) {
                    $url = $this->adminUrlGenerator
                        ->setController(ProductVariantListController::class)
                        ->setAction('index')
                        ->set('productId', $entity->getId())
                        ->generateUrl();
                    return sprintf('<a href="%s">Voir les variantes (%d)</a>', $url, count($value));
                })
                ->renderAsHtml(),
        ];
    }
    
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

}