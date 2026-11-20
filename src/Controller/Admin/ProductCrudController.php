<?php
// src/Controller/Admin/ProductCrudController.php
namespace App\Controller\Admin;

use App\Entity\Product;
use App\Repository\CategoriesRepository;
use App\Repository\CommandeRepository;
use App\Repository\ProductTypeRepository;
use App\Repository\StyleRepository;
use App\Services\BarcodeService\CatalogBarcodeService;
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
    use SubscriptionToggleGuardTrait; // case « Abonnement » : mêmes règles que la gestion web (lot ABO-CONF-b)

    private AdminUrlGenerator $adminUrlGenerator;

    public function __construct(
        TenantEntityManagerProvider $emProvider,
        AdminUrlGenerator $adminUrlGenerator,
        private readonly CatalogBarcodeService $barcodes, // codes-barres du catalogue (lot CB-1, 15/11/2026, `docs/codes-barres.md`)
        private readonly ?\App\Services\OrderService\TaxCategoryAudit $taxAudit = null, // alerte « catégorie sans taxe » (F18 partie 2a)
        private readonly ?\App\Services\BoutiqueSettingsService\TenantCurrencyProvider $currency = null, // devise du site (libellés, 07/11/2026)
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
        yield FormField::addPanel('Détails du Produit')->collapsible();

        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name', 'Nom du produit');
        yield SlugField::new('slug', 'Adresse de la fiche (slug)')->setTargetFieldName('name')->hideOnIndex();
        yield TextEditorField::new('description', 'Description')->setColumns('col-md-12')->setRequired(false); 
        yield TextEditorField::new('moreinformations', 'Informations supplémentaires')->hideOnIndex()->setColumns('col-md-12')->setRequired(false);
        yield CollectionField::new('translations', 'Traductions')
        ->setEntryType(ProductTranslationType::class)
        ->setFormTypeOptions([
            'by_reference' => false, 
        ])
        ->renderExpanded()
        ->setColumns('col-12');
        yield FormField::addPanel('Informations Commerciales')->collapsible();
        yield MoneyField::new('price', 'Prix de vente')->setCurrency($this->currency?->code() ?? 'CAD')->setStoredAsCents(true)->setColumns('col-md-4');
        yield MoneyField::new('purchasePrice', "Prix d'achat")->setCurrency($this->currency?->code() ?? 'CAD')->setStoredAsCents(true)->setColumns('col-md-4');
        yield NumberField::new('coefficientMultiplier', 'Coefficient')->setColumns('col-md-4');
        yield TextField::new('barcode', 'Code-barres')->setColumns('col-md-4')->setRequired(false)->setFormTypeOption('attr', ['maxlength' => 64])
            ->setHelp('Facultatif. Le code du fabricant quand la pièce en porte un (scannez-le dans ce champ), sinon « Générer un code-barres ». Un code ne désigne qu\'une seule chose : un article ou une variante. Un article qui a des variantes porte ses codes sur ses variantes.');
        yield MoneyField::new('specialPrice', 'Prix promotionnel')->setCurrency($this->currency?->code() ?? 'CAD')->setStoredAsCents(true)->setColumns('col-md-4');
        // Catégorie fiscale (F18 partie 2a) : vide = standard ; « exonéré » = aucune taxe ; figée sur chaque ligne de commande à la vente
        yield \EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField::new('taxCategory', 'Catégorie fiscale')->setChoices(array_flip(\App\Services\OrderService\TaxCategory::LABELS))->setRequired(false)->setColumns('col-md-4')->hideOnIndex()
            ->setHelp(($this->taxAudit?->warning() ?? 'Vide : taux normal. Au Québec, laisser vide (TPS et TVQ sans catégorie). France : la catégorie choisit la TVA (20, 10, 5,5 ou 2,1 %).') . ' À utiliser seulement quand les applications à jour sont publiées : une caisse antérieure à la règle hors ligne v2 calcule sans catégorie.');
        // Instants stockés en UTC (comparés en UTC partout) ; affichage, saisie et mention « (heure du magasin) » par la règle commune des
        // écrans de site (`StoreTimezoneFieldConfigurator`, lot EA-FUSEAU, 12/11/2026). Depuis P5b (11/11/2026), seul l'affichage était au
        // fuseau du magasin : la saisie était lue en UTC malgré la mention.
        yield DateTimeField::new('specialPriceFrom', 'Promotion du')->setColumns('col-md-4')->hideOnIndex();
        yield DateTimeField::new('specialPriceTo', 'Promotion au')->setColumns('col-md-4')->hideOnIndex();
        yield IntegerField::new('quantity', 'Quantité')->onlyOnIndex();
        // Modes de la boutique réglable (08/10/2026) : un produit peut cumuler vente et location
        yield FormField::addPanel('Modes de vente')->collapsible()->setHelp('Un produit peut se vendre et se louer à la fois. La location se règle dans « Configuration de location ».');
        yield BooleanField::new('saleEnabled', 'Vente')->setColumns('col-md-3');
        yield BooleanField::new('rentalEnabled', 'Location / réservation')->setColumns('col-md-3');
        yield BooleanField::new('subscriptionEnabled', 'Abonnement')->setColumns('col-md-3');
        yield BooleanField::new('requiresShipping', 'À expédier')->setColumns('col-md-3')->setHelp('Décochez pour un service, un produit numérique ou retiré sur place : aucun colis ni étiquette (livraison, lot 1).')->hideOnIndex();
        // « Personnalisable » n'est plus une case (09/10/2026) : le bouton « Personnaliser » paraît dès qu'une variante a des
        // combinaisons saisies (Combinaisons de personnalisation), comme le calcule Product::hasCustomizationConfig

        yield FormField::addTab('Organisation & Média');
        yield FormField::addPanel('Catégorisation')->collapsible();

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
        yield TextField::new('tags', 'Mots-clés')->setColumns('col-md-6');
        
        yield FormField::addPanel('Média')->collapsible();
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
        yield FormField::addPanel('Options d\'Affichage (Flags)')->collapsible();
        
        yield BooleanField::new('isbestseller', 'Meilleure vente')->setColumns('col-md-3');
        yield BooleanField::new('isnewarrival', 'Nouveauté')->setColumns('col-md-3');
        yield BooleanField::new('isfeatured', 'En vedette')->setColumns('col-md-3');
        yield BooleanField::new('isspecialoffer', 'Offre spéciale')->setColumns('col-md-3');
        yield BooleanField::new('isAccessory', 'Accessoire')->setColumns('col-md-3');
        yield BooleanField::new('isLandingPage', 'Sur la landing page')->setColumns('col-md-3');
        
        yield FormField::addPanel('Statut de Publication')->collapsible();
        yield BooleanField::new('isWeb', 'Actif sur le site Web')->setColumns('col-md-3');
        yield BooleanField::new('isPos', 'Actif sur le Point de vente')->setColumns('col-md-3');
        
        yield FormField::addPanel('Données Externes')->collapsible();
        // lot L0 de la gestion (10/11/2026) : la commande du produit est un raccourci de sa dernière ligne d'achat, en lecture seule ici
        yield AssociationField::new('commande', 'Dernière commande fournisseur')
            ->setFormTypeOptions(['em' => $tenantEm, 'query_builder' => fn(CommandeRepository $repo) => $repo->createQueryBuilder('cmd')->orderBy('cmd.date', 'DESC')])
            ->hideOnIndex()
            ->setDisabled(true)
            ->setHelp('Tenue par les lignes d\'achat de l\'application de gestion (rattacher un article à une commande).')
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
        // IA pour ce produit (07/11/2026) : description depuis les photos, traductions, image générée
        $ai = Action::new('productAi', 'IA', 'fas fa-magic')->linkToRoute('admin_product_ai', fn (\App\Entity\Product $p) => ['id' => $p->getId()]);
        $photos = Action::new('productPhotos', 'Photos', 'fas fa-images')->linkToRoute('admin_product_photos', fn (\App\Entity\Product $p) => ['id' => $p->getId()]); // page Photos (07/11/2026)
        // Lot CB-1 : proposé seulement à l'article qui n'a ni code ni variante (le code généré d'un article à variantes va sur ses variantes) ;
        // l'action ne remplace jamais un code présent
        $generateBarcode = Action::new('generateBarcode', 'Générer un code-barres', 'fa fa-barcode')
            ->linkToUrl(fn (Product $product) => $this->csrfActionUrl('generateBarcode', $product->getId()))
            ->displayIf(static fn (Product $product): bool => $product->getBarcode() === null && $product->getVariants()->isEmpty());

        $manageSpecifications = Action::new('manageSpecifications', 'Caractéristiques', 'fa fa-cogs')
            ->linkToRoute('admin_product_specifications', function (Product $product): array {
                return ['id' => $product->getId()];
            });

        return $actions
            ->add(Crud::PAGE_INDEX, $manageSpecifications)
            ->add(Crud::PAGE_EDIT, $manageSpecifications)
            ->add(Crud::PAGE_INDEX, $generateBarcode)
            ->add(Crud::PAGE_EDIT, $generateBarcode)
            ->add(Crud::PAGE_INDEX, $ai)->add(Crud::PAGE_EDIT, $ai)->add(Crud::PAGE_DETAIL, $ai)
            ->add(Crud::PAGE_INDEX, $photos)->add(Crud::PAGE_EDIT, $photos)->add(Crud::PAGE_DETAIL, $photos);
    }

    public function persistEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        $changed = $this->guardSubscriptionToggle($entityManager, $entityInstance);
        parent::persistEntity($entityManager, $entityInstance);
        $this->recordSubscriptionToggle($entityInstance, $changed);
        $this->warnAboutBarcode($entityInstance, null);
    }

    public function updateEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        $before = $entityInstance instanceof Product ? $this->barcodes->storedCode($entityInstance) : null;
        $changed = $this->guardSubscriptionToggle($entityManager, $entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
        $this->recordSubscriptionToggle($entityInstance, $changed);
        $this->warnAboutBarcode($entityInstance, $before);
    }

    /**
     * Code accepté mais à signaler (13 chiffres dont la clé de contrôle est fausse), seulement quand le code vient de changer (pas à
     * chaque enregistrement, ni aux bascules de la liste) : le refus, lui, est rendu sous le champ par la validation
     */
    private function warnAboutBarcode(mixed $product, ?string $before): void
    {
        $warning = $product instanceof Product ? $this->barcodes->warning($product->getBarcode(), $before) : null;
        if ($warning !== null) {
            $this->addFlash('warning', $warning);
        }
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
        
        // Lot CB-1 : EAN-13 interne tiré de la séquence du site, jamais par-dessus un code présent (avant : « BAR-… » au hasard, qui écrasait)
        $generated = $this->barcodes->tryGenerateFor($product); // un refus ou une panne revient en message, jamais en page d'erreur
        $generated['code'] !== null ? $this->addFlash('success', 'Code-barres généré : ' . $generated['code']) : $this->addFlash($generated['failed'] ? 'danger' : 'warning', (string) $generated['refusal']);

        $url = $this->adminUrlGenerator
                    ->setController(self::class)
                    ->setAction('edit')
                    ->setEntityId($product->getId())
                    ->generateUrl();

        return $this->redirect($url);
    }
}
