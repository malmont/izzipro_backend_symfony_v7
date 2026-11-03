<?php

namespace App\Controller\Admin;

use App\Entity\AppProfile;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use App\Form\AppProfileTranslationType;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ColorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TelephoneField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;

/**
 * Profils des applications du site (20/10/2026) : une fiche par application (code fixe choisi dans la liste), tous
 * les champs facultatifs ; un champ vide est pris dans la fiche entreprise par GET /api/app-profile/{app}.
 * Panneau « Apparence de l'application » (31/10/2026) : réglages lus par l'application e-commerce mobile
 * (GET /api/mobile/app-theme), contrôlés par le validateur (contraintes de l'entité AppProfile) ; une erreur est
 * affichée dans le formulaire et rien n'est enregistré. Couleurs facultatives : gabarit admin/form/optional_color.
 */
class AppProfileCrudController extends BaseTenantCrudController
{
    private const UPLOAD_DIR = 'var/storage/public_bucket/assets/uploads/email-logos/';
    private const BASE_PATH = '/bucket-simulator/assets/uploads/email-logos/';

    public function __construct(\App\Services\TenantEntityManagerProvider $emProvider, private readonly \App\Services\AppProfileService\AppThemePreviewService $previews)
    {
        parent::__construct($emProvider);
    }

    public static function getEntityFqcn(): string
    {
        return AppProfile::class;
    }

    /** Aperçu en direct (B3, 31/10/2026) : script du formulaire, chargé pour ce seul CRUD */
    public function configureAssets(\EasyCorp\Bundle\EasyAdminBundle\Config\Assets $assets): \EasyCorp\Bundle\EasyAdminBundle\Config\Assets
    {
        $file = $this->getParameter('kernel.project_dir') . '/public/assets/js/admin-apercu.js';

        return parent::configureAssets($assets)->addJsFile('assets/js/admin-apercu.js?v=' . (@filemtime($file) ?: 1));
    }

    /**
     * Données de l'aperçu posées dans l'aide du panneau « Apparence » (lues par admin-apercu.js) : gabarits, adresse du
     * cadre, repli des champs vides, profil. Sans gabarit pour l'application de la fiche, le message est affiché ici même.
     */
    private function previewData(): string
    {
        try {
            $app = $this->getContext()?->getEntity()?->getInstance()?->getApp();
        } catch (\Throwable) {
            $app = null;
        }
        $payload = $this->previews->payload($app);
        $html = sprintf('<span class="apercu-data" data-apercu="%s" hidden></span>', htmlspecialchars(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES));
        if ($app !== null && $payload['url'] === null) {
            $html .= sprintf('<br><em class="apercu-unavailable">%s.</em>', \App\Services\AppProfileService\AppThemePreviewService::UNAVAILABLE);
        }

        return $html;
    }

    /** Note propre à l'application de la fiche (31/10/2026) : la caisse n'applique pas encore le thème (décision du propriétaire, lot U9 côté caisse) */
    private function appearanceNote(): string
    {
        try {
            $app = $this->getContext()?->getEntity()?->getInstance()?->getApp();
        } catch (\Throwable) {
            $app = null;
        }

        return $app === 'pos' ? '<strong>Aperçu non disponible : la caisse n\'applique pas encore ces réglages</strong> (lot U9 prévu côté caisse : couleur principale, couleur d\'accent et mode seulement). ' : '';
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setEntityLabelInSingular('Profil d\'application')
            ->setEntityLabelInPlural('Profils des applications')
            ->setPageTitle(Crud::PAGE_INDEX, 'Profils des applications')
            ->setHelp(Crud::PAGE_INDEX, 'Nom, logo, coordonnées et textes propres à une application (Living Memory, Mémoires Vivantes, Boussole ESG, caisse…). Tout champ laissé vide reprend la fiche Entreprise. La landing page et la boutique utilisent toujours la fiche Entreprise.')
            ->setDefaultSort(['app' => 'ASC'])
            ->addFormTheme('admin/form/optional_color.html.twig');
    }

    public function configureActions(Actions $actions): Actions
    {
        return parent::configureActions($actions)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::DELETE, Action::BATCH_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addPanel('Application')->collapsible();
        yield ChoiceField::new('app', 'Application')->setChoices(array_flip(AppProfile::APPS))
            ->setHelp('Code fixe, connu de l\'application : il ne se change pas après création.')
            ->setFormTypeOption('disabled', $pageName === Crud::PAGE_EDIT);
        yield TextField::new('appName', 'Nom affiché')->setRequired(false);
        yield TextField::new('tagline', 'Accroche')->setRequired(false)->hideOnIndex();
        yield ImageField::new('logo', 'Logo')->setBasePath(self::BASE_PATH)->setUploadDir(self::UPLOAD_DIR)->setUploadedFileNamePattern('[randomhash].[extension]')->setRequired(false);
        yield ImageField::new('icon', 'Icône')->setBasePath(self::BASE_PATH)->setUploadDir(self::UPLOAD_DIR)->setUploadedFileNamePattern('[randomhash].[extension]')->setRequired(false)->hideOnIndex();
        yield FormField::addPanel('Apparence de l\'application')->collapsible()->setHelp($this->appearanceNote() . 'Lue par l\'application e-commerce mobile (GET /api/mobile/app-theme). Un réglage vide est pris dans la charte de la boutique web (réglages de la boutique) quand elle en a un, sinon l\'application applique son propre défaut.' . $this->previewData());
        yield ColorField::new('primaryColor', 'Couleur principale')->setRequired(false)->hideOnIndex()
            ->setFormTypeOption('block_prefix', 'optional_color')
            ->setHelp('Couleur des boutons et des éléments mis en avant, au format #RRGGBB. Vide : couleur principale de la charte de la boutique.');
        yield ColorField::new('accentColor', 'Couleur d\'accent')->setRequired(false)->hideOnIndex()
            ->setFormTypeOption('block_prefix', 'optional_color')
            ->setHelp('Couleur secondaire (badges, liens, détails), au format #RRGGBB. Vide : couleur d\'accent de la charte de la boutique.');
        yield ChoiceField::new('themeMode', 'Mode')->setChoices(['Clair' => 'light', 'Sombre' => 'dark', 'Selon l\'appareil' => 'system'])->setRequired(false)->hideOnIndex()
            ->setHelp('Clair, sombre, ou le réglage de l\'appareil. Vide : défaut de l\'application.');
        yield ChoiceField::new('themeFont', 'Police')->setChoices(array_combine(AppProfile::THEME_FONTS, AppProfile::THEME_FONTS))->setRequired(false)->hideOnIndex()
            ->setHelp('Police des titres et des textes ; « System » est celle de l\'appareil. Vide : police des titres de la charte de la boutique si elle est dans cette liste.');
        yield IntegerField::new('themeRadius', 'Arrondi des coins')->setRequired(false)->hideOnIndex()
            ->setFormTypeOption('attr', ['min' => 0, 'max' => AppProfile::RADIUS_MAX, 'step' => 1])
            ->setHelp(sprintf('De 0 (angles droits) à %d (très arrondi), en points. Vide : arrondi de la charte de la boutique, borné à %d.', AppProfile::RADIUS_MAX, AppProfile::RADIUS_MAX));
        yield ChoiceField::new('buttonStyle', 'Style des boutons')->setChoices(['Plein' => 'solid', 'Contour' => 'outline', 'Pilule' => 'pill'])->setRequired(false)->hideOnIndex()
            ->setHelp('Plein (fond coloré), contour (bordure seule) ou pilule (coins ronds). Vide : style de la charte de la boutique.');
        yield ChoiceField::new('productCard', 'Carte produit')->setChoices(['Classique' => 'classic', 'Texte sur l\'image' => 'overlay', 'Compacte' => 'compact'])->setRequired(false)->hideOnIndex()
            ->setHelp('Présentation des produits dans les listes : image puis texte (classique), texte sur l\'image (overlay) ou compacte. Vide : défaut de l\'application.');
        yield ChoiceField::new('homeHero', 'En-tête de l\'accueil')->setChoices(['Carrousel' => 'carousel', 'Bannière' => 'banner', 'Aucun' => 'none'])->setRequired(false)->hideOnIndex()
            ->setHelp('Haut de l\'écran d\'accueil : carrousel d\'images, bannière fixe ou aucun. Vide : défaut de l\'application.');
        yield FormField::addPanel('Coordonnées')->collapsible();
        yield EmailField::new('email', 'E-mail')->setRequired(false);
        yield TelephoneField::new('phone', 'Téléphone')->setRequired(false)->hideOnIndex();
        yield UrlField::new('website', 'Site web')->setRequired(false)->hideOnIndex();
        yield UrlField::new('supportUrl', 'Page d\'assistance')->setRequired(false)->hideOnIndex();
        yield UrlField::new('privacyPolicyUrl', 'Lien de la politique de confidentialité')->setRequired(false)->hideOnIndex();
        yield FormField::addPanel('Adresse')->collapsible()->setHelp('Saisie, elle remplace en entier celle de l\'entreprise ; vide, celle de l\'entreprise est utilisée.');
        yield TextField::new('street1', 'Adresse')->setRequired(false)->hideOnIndex();
        yield TextField::new('street2', 'Complément')->setRequired(false)->hideOnIndex();
        yield TextField::new('zip', 'Code postal')->setRequired(false)->hideOnIndex();
        yield TextField::new('city', 'Ville')->setRequired(false)->hideOnIndex();
        yield TextField::new('province', 'Province / région')->setRequired(false)->hideOnIndex();
        yield TextField::new('country', 'Pays')->setRequired(false)->hideOnIndex();
        yield FormField::addPanel('Textes (langue par défaut)')->collapsible()->setHelp('Utilisés quand aucune traduction n\'existe pour la langue demandée ; vides, ceux de la fiche Entreprise.');
        yield TextareaField::new('apropos', 'À propos')->setRequired(false)->hideOnIndex();
        yield TextareaField::new('privacyPolicy', 'Politique de confidentialité')->setRequired(false)->hideOnIndex();
        yield TextareaField::new('conditionOfUse', 'Conditions d\'utilisation')->setRequired(false)->hideOnIndex();
        yield TextareaField::new('legalNotice', 'Mentions légales')->setRequired(false)->hideOnIndex();
        yield FormField::addPanel('Traductions')->collapsible()->setHelp('Nom affiché, accroche et textes par langue, comme les « Contenus traduits » de la fiche Entreprise. Un champ vide reprend la langue par défaut.');
        yield CollectionField::new('translations', 'Contenus traduits')
            ->setEntryType(AppProfileTranslationType::class)
            ->setFormTypeOption('by_reference', false)
            ->onlyOnForms();
        yield DateTimeField::new('updatedAt', 'Modifié le')->hideOnForm();
    }

    /** Une seule fiche par application : une création en double est refusée avec un message */
    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof AppProfile && $this->emProvider->getEntityManager()->getRepository(AppProfile::class)->findOneBy(['app' => $entityInstance->getApp()]) !== null) {
            $this->addFlash('danger', sprintf('Un profil existe déjà pour « %s » : modifiez-le plutôt.', AppProfile::APPS[$entityInstance->getApp()] ?? $entityInstance->getApp()));

            return;
        }
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        throw $this->createAccessDeniedException('La suppression d\'un profil d\'application est désactivée.');
    }
}
