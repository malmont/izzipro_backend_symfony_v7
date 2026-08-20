<?php
namespace App\Controller\Admin;

use App\Entity\Entreprise;
use App\Form\EntrepriseTranslationType;
use App\Form\SocialNetworkType; 
use App\Controller\Admin\BaseTenantCrudController; 
use App\Services\TenantEntityManagerProvider;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use App\Repository\SocialNetworkRepository;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

class EntrepriseCrudController extends BaseTenantCrudController
{
    private AdminUrlGenerator $adminUrlGenerator;

    public function __construct(
        AdminUrlGenerator $adminUrlGenerator,
        TenantEntityManagerProvider $emProvider
    ) {
        parent::__construct($emProvider);
        $this->adminUrlGenerator = $adminUrlGenerator;
    }

    public static function getEntityFqcn(): string
    {
        return Entreprise::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->onlyOnIndex(),
            
            // --- Champs non traduits ---
            TextField::new('name', 'Nom de l\'entreprise'),
            ImageField::new('logo', 'Logo')
                ->setBasePath('assets/uploads/email-logos/')
                ->setUploadDir('public/assets/uploads/email-logos/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(false),
            ImageField::new('faviconFilename', 'Favicon')
                ->setBasePath('assets/uploads/email-logos/')
                ->setUploadDir('public/assets/uploads/email-logos/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(false),        
            TextField::new('adress', 'Adresse'),
            EmailField::new('email', 'E-mail'),
            TextField::new('tel', 'Téléphone'),
            UrlField::new('website', 'Site web'),
            TextField::new('ein', 'SIRET'),
            TextField::new('tvaIntracommunautaire', 'TVA intracommunautaire'),
            FormField::addPanel('Activation des Applications (Multi-Module)'),
            BooleanField::new('isBoutiqueActive', 'Boutique / e-Commerce')
                ->setHelp('Activer la boutique et le catalogue e-commerce pour ce tenant'),
            BooleanField::new('isLandingPageActive', 'Landing Page')
                ->setHelp('Activer le site vitrine / landing page'),
            BooleanField::new('isBoussoleEsgActive', 'Boussole ESG')
                ->setHelp('Activer le module de diagnostic et bilans ESG'),
            BooleanField::new('isMemoireVivanteActive', 'Mémoire Vivante')
                ->setHelp('Activer l\'application Mémoire Vivante'),

            FormField::addPanel('Informations Générales'),
            TextField::new('facebookPixelId', 'Facebook Pixel ID')->hideOnIndex(),
            TextareaField::new('conditionOfUse', 'Conditions d\'utilisation'),
            TextareaField::new('LegalNotice', 'Mentions légales'),
            TextareaField::new('privacyPolicy', 'Politique de confidentialité'),
            TextareaField::new('Apropos', 'A propos'),
            AssociationField::new('addressEntreprise', 'Adresse Entreprise')
                ->formatValue(function ($value, $entity) {
                    $address = $entity->getAddressEntreprise();
                    if (!$address) {
                        return 'Aucune adresse';
                    }
                    $url = $this->adminUrlGenerator
                        ->setController(AddressEntrepriseCrudController::class)
                        ->setAction(Action::DETAIL)
                        ->setEntityId($address->getId())
                        ->generateUrl();

                    return sprintf('<a href="%s" target="_blank">Voir l\'adresse</a>', $url);
                })
                ->renderAsHtml()
                ->onlyOnIndex(),


            CollectionField::new('translations', 'Contenus Traduits')
                ->setEntryType(EntrepriseTranslationType::class)
                ->setFormTypeOption('by_reference', false)
                ->onlyOnForms(),

            AssociationField::new('socialNetworks', 'Réseaux Sociaux')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn(SocialNetworkRepository $repo) => $repo->createQueryBuilder('sn')->orderBy('sn.name', 'ASC'),
                ])
                ->setColumns('col-md-6'),
        ];
    }
}