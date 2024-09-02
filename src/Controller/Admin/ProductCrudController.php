<?php
namespace App\Controller\Admin;

use App\Entity\Product;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

class ProductCrudController extends AbstractCrudController
{
    private $adminUrlGenerator;

    // Injection du service AdminUrlGenerator via le constructeur
    public function __construct(AdminUrlGenerator $adminUrlGenerator)
    {
        $this->adminUrlGenerator = $adminUrlGenerator;
    }
    public static function getEntityFqcn(): string
    {
        return Product::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name'),
            SlugField::new('slug')->setTargetFieldName('name')->hideOnIndex(),
            TextEditorField::new('description'),
            TextEditorField::new('moreinformations')->hideOnIndex(),
            MoneyField::new('price')->setCurrency('USD')->onlyOnIndex(), // Prix calculé automatiquement
            NumberField::new('purchasePrice', "Prix d'achat de l'article"),
            NumberField::new('coefficientMultiplier', 'Coefficient Multiplier'),
            TextField::new('barcode', 'Barcode'),
            IntegerField::new('quantity')->onlyOnIndex(), // Afficher uniquement dans la vue de liste/détail
            TextField::new('tags'),
            BooleanField::new('isbestseller', 'BestSeller'),
            BooleanField::new('isnewarrival', 'New Arrival'),
            BooleanField::new('isfeatured', 'Featured'),
            BooleanField::new('isspecialoffer', 'Special Offer'),
            AssociationField::new('category'),
            AssociationField::new('style', 'Style'),
            AssociationField::new('commande', 'Commande'),
            ImageField::new('image')->setBasePath('assets/uploads/products/')
                ->setUploadDir('public/assets/uploads/products/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(false),

                AssociationField::new('variants', 'Variantes de Produit')
                ->formatValue(function ($value, $entity) {
                    $productVariantsUrl = $this->adminUrlGenerator
                        ->setController(ProductVariantListController::class)
                        ->setAction('index')
                        ->set('productId', $entity->getId())
                        ->generateUrl();

                    return sprintf(
                        '<a href="%s" style="text-decoration: none; color: #007bff;">Voir les variantes</a>',
                        $productVariantsUrl
                    );
                })
                ->renderAsHtml(),
        ];
    }
}
