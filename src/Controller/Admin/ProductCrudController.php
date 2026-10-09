<?php
// src/Controller/Admin/ProductCrudController.php
namespace App\Controller\Admin;

use App\Entity\Product;
use App\Repository\CategoriesRepository;
use App\Repository\CommandeRepository;
use App\Repository\ProductTypeRepository;
use App\Repository\StyleRepository;
use App\Services\TenantEntityManagerProvider;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use App\Form\ProductPictureType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use App\Entity\ProductTranslation;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use App\Form\ProductTranslationType;
use App\Enum\ProductMode;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;

class ProductCrudController extends BaseTenantCrudController
{
    use CsrfProtectedActionTrait;

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
        yield FormField::addTab('Informations Générales');
        yield FormField::addPanel('Détails du Produit');

        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name', 'Nom du produit');
        yield SlugField::new('slug')->setTargetFieldName('name')->hideOnIndex();
        yield TextEditorField::new('description')->setColumns('col-md-12')->setRequired(false); 
        yield TextEditorField::new('moreinformations', 'Informations supplémentaires')->hideOnIndex()->setColumns('col-md-12')->setRequired(false);
        yield CollectionField::new('translations', 'Traductions')
        ->setEntryType(ProductTranslationType::class)
        ->setFormTypeOptions([
            'by_reference' => false, 
        ])
        ->renderExpanded()
        ->setColumns('col-12');
        yield FormField::addPanel('Informations Commerciales');
        yield MoneyField::new('price', 'Prix de Vente ($)')->setCurrency('USD')->setStoredAsCents(true)->setColumns('col-md-4');
        yield MoneyField::new('purchasePrice', "Prix d'achat ($)")->setCurrency('USD')->setStoredAsCents(true)->setColumns('col-md-4');
        yield NumberField::new('coefficientMultiplier', 'Coefficient')->setColumns('col-md-4');
        yield TextField::new('barcode', 'Code Barre')->setColumns('col-md-4');
        yield MoneyField::new('specialPrice', 'Prix promotionnel ($)')->setCurrency('USD')->setStoredAsCents(true)->setColumns('col-md-4');
        yield DateTimeField::new('specialPriceFrom', 'Promotion du')->setColumns('col-md-4')->hideOnIndex();
        yield DateTimeField::new('specialPriceTo', 'Promotion au')->setColumns('col-md-4')->hideOnIndex();
        yield IntegerField::new('quantity', 'Quantité')->onlyOnIndex();
        // Modes de la boutique réglable (08/10/2026) : un produit peut cumuler vente et location
        yield FormField::addPanel('Modes de vente')->setHelp('Un produit peut se vendre et se louer à la fois. La location se règle dans « Configuration de location ».');
        yield BooleanField::new('saleEnabled', 'Vente')->setColumns('col-md-3');
        yield BooleanField::new('rentalEnabled', 'Location / réservation')->setColumns('col-md-3');
        yield BooleanField::new('subscriptionEnabled', 'Abonnement')->setColumns('col-md-3');
        // « Personnalisable » n'est plus une case (09/10/2026) : le bouton « Personnaliser » paraît dès qu'une variante a des
        // combinaisons saisies (Combinaisons de personnalisation), comme le calcule Product::hasCustomizationConfig

        yield FormField::addTab('Organisation & Média');
        yield FormField::addPanel('Catégorisation');

        $tenantEm = $this->emProvider->getEntityManager();
        yield AssociationField::new('productType', 'Type de Produit')
            ->setRequired(true)
            ->setFormTypeOptions(['em' => $tenantEm, 'query_builder' => fn($repo) => $repo->createQueryBuilder('pt')->orderBy('pt.name', 'ASC')])
            ->setColumns('col-md-6');
        yield AssociationField::new('category', 'Catégorie')
             ->setFormTypeOptions(['em' => $tenantEm, 'query_builder' => fn($repo) => $repo->createQueryBuilder('c')->orderBy('c.name', 'ASC')])
            ->setColumns('col-md-6');
        yield AssociationField::new('style', 'Style')
             ->setFormTypeOptions(['em' => $tenantEm, 'query_builder' => fn($repo) => $repo->createQueryBuilder('s')->orderBy('s.name', 'ASC')])
            ->setColumns('col-md-6');
        yield TextField::new('tags')->setColumns('col-md-6');
        
        yield FormField::addPanel('Média');
        yield ImageField::new('image', 'Image Principale')
            ->setBasePath('/bucket-simulator/assets/uploads/products/')
            ->setUploadDir('var/storage/public_bucket/assets/uploads/products/')
            ->setUploadedFileNamePattern('[randomhash].[extension]')
            ->setRequired(false)
            ->setColumns('col-md-12');

        yield CollectionField::new('pictures', 'Galerie Photos (Photos additionnelles)')
            ->setEntryType(ProductPictureType::class)
            ->setColumns('col-md-12')
            ->onlyOnForms();



        yield FormField::addTab('Visibilité & Options');
        yield FormField::addPanel('Options d\'Affichage (Flags)');
        
        yield BooleanField::new('isbestseller', 'BestSeller')->setColumns('col-md-3');
        yield BooleanField::new('isnewarrival', 'Nouveauté')->setColumns('col-md-3');
        yield BooleanField::new('isfeatured', 'En vedette')->setColumns('col-md-3');
        yield BooleanField::new('isspecialoffer', 'Offre spéciale')->setColumns('col-md-3');
        yield BooleanField::new('isAccessory', 'Accessoire')->setColumns('col-md-3');
        yield BooleanField::new('isLandingPage', 'isLandingPage')->setColumns('col-md-3');
        
        yield FormField::addPanel('Statut de Publication');
        yield BooleanField::new('isWeb', 'Actif sur le site Web')->setColumns('col-md-3');
        yield BooleanField::new('isPos', 'Actif sur le Point de vente')->setColumns('col-md-3');
        
        yield FormField::addPanel('Données Externes');
        yield AssociationField::new('commande', 'Commande Associée')
            ->setFormTypeOptions(['em' => $tenantEm, 'query_builder' => fn(CommandeRepository $repo) => $repo->createQueryBuilder('cmd')->orderBy('cmd.date', 'DESC')])
            ->hideOnIndex()
            ->setColumns('col-md-6');

       yield AssociationField::new('variants', 'Variantes de Produit')
            ->formatValue(function ($value, $entity) {
                $url = $this->adminUrlGenerator
                    ->setController(\App\Controller\Admin\ProductVariantListController::class)
                    ->setAction('index') 
                    ->set('productId', $entity->getId())
                    ->generateUrl();
                $count = is_countable($value) ? count($value) : 0;

                return sprintf('<a href="%s">Voir les variantes (%d)</a>', $url, $count);
            })
            ->renderAsHtml()->onlyOnIndex(); 

           
    }

    public function configureActions(Actions $actions): Actions
    {
        $generateBarcode = Action::new('generateBarcode', 'Générer Code Barre', 'fa fa-barcode')
            ->linkToUrl(fn (Product $product) => $this->csrfActionUrl('generateBarcode', $product->getId()));

        $manageSpecifications = Action::new('manageSpecifications', 'Caractéristiques', 'fa fa-cogs')
            ->linkToRoute('admin_product_specifications', function (Product $product): array {
                return ['id' => $product->getId()];
            });

        return $actions
            ->add(Crud::PAGE_INDEX, $manageSpecifications)
            ->add(Crud::PAGE_EDIT, $manageSpecifications)
            ->add(Crud::PAGE_INDEX, $generateBarcode)
            ->add(Crud::PAGE_EDIT, $generateBarcode);
    }

    public function generateBarcode(AdminContext $context): RedirectResponse
    {
        if (!$this->isCsrfActionValid($context, 'generateBarcode')) {
            $this->addFlash('danger', 'Lien invalide ou expiré : action annulée. Utilisez le bouton depuis la liste.');
            return $this->redirect($this->container->get(AdminUrlGenerator::class)->unsetAll()->setController(self::class)->setAction('index')->generateUrl());
        }

        /** @var Product $product */
        $product = $context->getEntity()->getInstance();
        if (!$product) {
            $this->addFlash('error', 'Produit non trouvé.');
            return $this->redirect($this->adminUrlGenerator->setAction('index')->generateUrl());
        }
        
        $tenantEm = $this->emProvider->getEntityManager();
        
        $generatedBarcode = 'BAR-' . strtoupper(bin2hex(random_bytes(6)));
        $product->setBarcode($generatedBarcode);

        $tenantEm->flush();
        $this->addFlash('success', 'Code barre généré avec succès : ' . $generatedBarcode);

        $url = $this->adminUrlGenerator
                    ->setController(self::class)
                    ->setAction('edit')
                    ->setEntityId($product->getId())
                    ->generateUrl();

        return $this->redirect($url);
    }
}
