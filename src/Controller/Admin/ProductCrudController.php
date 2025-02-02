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
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Doctrine\ORM\EntityManagerInterface;

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
            TextEditorField::new('description')->setLabel('Description'),
            TextEditorField::new('moreinformations')->hideOnIndex()->setLabel('moreinformations'),
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
            BooleanField::new('isAccessory', 'Accessoires '),
            AssociationField::new('category'),
            AssociationField::new('style', 'Style'),
            AssociationField::new('commande', 'Commande'),
            ImageField::new('image')
                ->setBasePath('assets/uploads/products/')
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

    public function configureActions(Actions $actions): Actions
    {
        $generateBarcode = Action::new('generateBarcode', 'Générer Code Barre')
            ->linkToCrudAction('generateBarcode');

        return $actions
            ->add(Crud::PAGE_EDIT, $generateBarcode)
            ->add(Crud::PAGE_DETAIL, $generateBarcode)
            ->add(Crud::PAGE_INDEX, $generateBarcode);
    }

    public function generateBarcode(AdminContext $context, EntityManagerInterface $em): RedirectResponse
    {
        /** @var Product $product */
        $product = $context->getEntity()->getInstance();

        if (!$product) {
            $this->addFlash('error', 'Produit non trouvé.');
            return $this->redirect($this->adminUrlGenerator->setAction('index')->generateUrl());
        }

        // Exemple simple de génération d'un code barre (à adapter selon vos besoins)
        $generatedBarcode = strtoupper(uniqid('BAR-', true));
        $product->setBarcode($generatedBarcode);

        $em->persist($product);
        $em->flush();

        $this->addFlash('success', 'Code barre généré avec succès : ' . $generatedBarcode);

        // Rediriger vers la page d'édition du produit
        $url = $this->adminUrlGenerator
                    ->setController(self::class)
                    ->setAction('edit')
                    ->setEntityId($product->getId())
                    ->generateUrl();

        return $this->redirect($url);
    }
}
