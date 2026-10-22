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
        TenantEntityManagerProvider $emProvider,
        private readonly \App\Services\AppKeyService\AppActivationService $appActivation,
        private readonly \App\Services\TenantConnectionProvider $tenant,
        private readonly \Symfony\Component\HttpFoundation\RequestStack $requestStack
    ) {
        parent::__construct($emProvider);
        $this->adminUrlGenerator = $adminUrlGenerator;
    }

    /** Mise à jour habituelle, puis clés d'activation des applications mobiles selon les interrupteurs changés (15/10/2026) */
    public function updateEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $tenantEm->getUnitOfWork()->computeChangeSets();
        $changeSet = $tenantEm->getUnitOfWork()->getEntityChangeSet($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);

        $request = $this->requestStack->getCurrentRequest();
        foreach ($this->appActivation->applyChanges($changeSet, $this->tenant->getTenantCode() ?? '', $this->getUser()?->getUserIdentifier() ?? 'admin', $request?->getSchemeAndHttpHost() ?? '') as $app => $message) {
            $this->addFlash(str_contains($message, 'non créée') ? 'danger' : 'success', sprintf('%s : %s', \App\Services\AppKeyService\AppKeyService::LABELS[$app] ?? $app, $message));
        }
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
                ->setBasePath('/bucket-simulator/assets/uploads/email-logos/')
                ->setUploadDir('var/storage/public_bucket/assets/uploads/email-logos/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(false),
            ImageField::new('faviconFilename', 'Favicon')
                ->setBasePath('/bucket-simulator/assets/uploads/email-logos/')
                ->setUploadDir('var/storage/public_bucket/assets/uploads/email-logos/')
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

            FormField::addPanel('Applications mobiles')
                ->setHelp('Activer génère une clé d\'activation et l\'envoie (clé + code QR) à l\'e-mail de l\'entreprise ; désactiver révoque les clés de l\'application. Détail : menu Caisse → Clés des applications.'),
            BooleanField::new('isPosActive', 'Caisse (POS)'),
            BooleanField::new('isMobileShopActive', 'E-commerce mobile'),
            BooleanField::new('isManagementAppActive', 'Application de gestion'),
            BooleanField::new('isLivingMemoryActive', 'LivingMemory (Mémoires Vivantes)'),

            FormField::addPanel('Caisse (POS)'),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posDiscrepancyThreshold', 'Écart de caisse toléré sans motif (cents)')
                ->setHelp('À la fermeture, un écart entre espèces comptées et attendues au-delà de ce montant exige un motif. 0 : tout écart demande un motif.')
                ->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posCashRoundingStep', 'Arrondi des paiements en espèces (cents)')
                ->setHelp('Vide : selon la devise (5 ¢ pour le dollar canadien, aucun sinon). 1 : aucun arrondi.')
                ->setRequired(false)->hideOnIndex(),
            // Validation d'un responsable pour un caissier (C3) : seuil en cents, 0 = toujours, vide = jamais
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posApprovalRefund', 'Validation : remboursement au-delà de (cents)')
                ->setHelp('0 : toujours ; vide : jamais')->setRequired(false)->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posApprovalWithdrawal', 'Validation : retrait d\'espèces au-delà de (cents)')
                ->setHelp('0 : toujours ; vide : jamais')->setRequired(false)->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posApprovalFundWithdrawal', 'Validation : retrait de fond au-delà de (cents)')
                ->setHelp('0 : toujours ; vide : jamais')->setRequired(false)->hideOnIndex(),
            BooleanField::new('posApprovalDrawerOpen', 'Validation : ouverture du tiroir sans vente')->hideOnIndex(),
            BooleanField::new('posApprovalCloseWithoutCount', 'Validation : fermeture sans comptage')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posLockMinutes', 'Verrouillage du POS après (minutes)')
                ->setHelp('Inactivité avant verrouillage de l\'écran ; 0 : jamais')->hideOnIndex(),
            // Terminal de paiement Stripe (C4, 23/10/2026) : valeurs par défaut = comportement livré (1 %, Interac selon le pays, 120 s, 30 min, 5 min)
            FormField::addPanel('Terminal de paiement Stripe (caisse)')
                ->setHelp('Lecteur par poste : menu Postes de caisse. Commissions de la plateforme en centièmes de pour cent (100 = 1 %). Les paiements en ligne gardent leur commission actuelle.'),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posTerminalFeeCardBp', 'Commission en magasin, carte de crédit (centièmes de %)')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posTerminalFeeInteracBp', 'Commission en magasin, Interac (centièmes de %)')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField::new('posTerminalInterac', 'Interac accepté sur le terminal')
                ->setChoices(['Oui' => true, 'Non' => false])->setFormTypeOption('placeholder', 'Selon le pays du site (oui au Canada)')->setRequired(false)->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posTerminalCardWaitSeconds', 'Attente de la carte sur le terminal (secondes)')
                ->setHelp('Au-delà, le paiement est annulé (15 à 600)')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posTerminalAuthCancelMinutes', 'Annulation d\'une autorisation carte sans vente après (minutes)')
                ->setHelp('Carte de crédit autorisée mais vente jamais enregistrée ; Stripe libère de toute façon sous 2 jours')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posTerminalOrphanMinutes', 'Paiement considéré sans vente après (minutes)')
                ->setHelp('Liste des paiements réussis sans vente pour le responsable')->hideOnIndex(),
            BooleanField::new('posTerminalCustomerCancel', 'Annulation par le client sur l\'écran du lecteur')->hideOnIndex(),
            BooleanField::new('posTerminalCartDisplay', 'Afficher le panier sur le lecteur')->hideOnIndex(),

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


            FormField::addPanel('Référencement & SEO'),
            TextField::new('metaTitle', 'Meta Title')
                ->setHelp('Titre pour les moteurs de recherche (60-70 caractères)')
                ->hideOnIndex(),
            TextareaField::new('metaDescription', 'Meta Description')
                ->setHelp('Description affichée dans Google (150-160 caractères)')
                ->hideOnIndex(),
            TextField::new('seoKeywords', 'Mots-clés SEO')
                ->setHelp('Mots-clés séparés par des virgules (ex: conciergerie, intendance, montreal)')
                ->hideOnIndex(),
            ImageField::new('ogImage', 'Image Open Graph (og:image)')
                ->setBasePath('/bucket-simulator/assets/uploads/email-logos/')
                ->setUploadDir('var/storage/public_bucket/assets/uploads/email-logos/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(false)
                ->setHelp('Image affichée lors du partage sur les réseaux sociaux (1200x630px recommandé)')
                ->hideOnIndex(),
            TextField::new('googleSiteVerification', 'Google Site Verification')
                ->setHelp('Code de vérification Google Search Console')
                ->hideOnIndex(),

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