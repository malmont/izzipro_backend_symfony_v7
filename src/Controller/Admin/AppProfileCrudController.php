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
use EasyCorp\Bundle\EasyAdminBundle\Field\TelephoneField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;

/**
 * Profils des applications du site (20/10/2026) : une fiche par application (code fixe choisi dans la liste), tous
 * les champs facultatifs ; un champ vide est pris dans la fiche entreprise par GET /api/app-profile/{app}.
 */
class AppProfileCrudController extends BaseTenantCrudController
{
    private const UPLOAD_DIR = 'var/storage/public_bucket/assets/uploads/email-logos/';
    private const BASE_PATH = '/bucket-simulator/assets/uploads/email-logos/';

    public static function getEntityFqcn(): string
    {
        return AppProfile::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setEntityLabelInSingular('Profil d\'application')
            ->setEntityLabelInPlural('Profils des applications')
            ->setPageTitle(Crud::PAGE_INDEX, 'Profils des applications')
            ->setHelp(Crud::PAGE_INDEX, 'Nom, logo, coordonnées et textes propres à une application (Living Memory, Mémoires Vivantes, Boussole ESG, caisse…). Tout champ laissé vide reprend la fiche Entreprise. La landing page et la boutique utilisent toujours la fiche Entreprise.')
            ->setDefaultSort(['app' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addPanel('Application');
        yield ChoiceField::new('app', 'Application')->setChoices(array_flip(AppProfile::APPS))
            ->setHelp('Code fixe, connu de l\'application : il ne se change pas après création.')
            ->setFormTypeOption('disabled', $pageName === Crud::PAGE_EDIT);
        yield TextField::new('appName', 'Nom affiché')->setRequired(false);
        yield TextField::new('tagline', 'Accroche')->setRequired(false)->hideOnIndex();
        yield ImageField::new('logo', 'Logo')->setBasePath(self::BASE_PATH)->setUploadDir(self::UPLOAD_DIR)->setUploadedFileNamePattern('[randomhash].[extension]')->setRequired(false);
        yield ImageField::new('icon', 'Icône')->setBasePath(self::BASE_PATH)->setUploadDir(self::UPLOAD_DIR)->setUploadedFileNamePattern('[randomhash].[extension]')->setRequired(false)->hideOnIndex();
        yield ColorField::new('primaryColor', 'Couleur principale')->setRequired(false)->hideOnIndex();
        yield FormField::addPanel('Coordonnées');
        yield EmailField::new('email', 'E-mail')->setRequired(false);
        yield TelephoneField::new('phone', 'Téléphone')->setRequired(false)->hideOnIndex();
        yield UrlField::new('website', 'Site web')->setRequired(false)->hideOnIndex();
        yield UrlField::new('supportUrl', 'Page d\'assistance')->setRequired(false)->hideOnIndex();
        yield UrlField::new('privacyPolicyUrl', 'Lien de la politique de confidentialité')->setRequired(false)->hideOnIndex();
        yield FormField::addPanel('Adresse')->setHelp('Saisie, elle remplace en entier celle de l\'entreprise ; vide, celle de l\'entreprise est utilisée.');
        yield TextField::new('street1', 'Adresse')->setRequired(false)->hideOnIndex();
        yield TextField::new('street2', 'Complément')->setRequired(false)->hideOnIndex();
        yield TextField::new('zip', 'Code postal')->setRequired(false)->hideOnIndex();
        yield TextField::new('city', 'Ville')->setRequired(false)->hideOnIndex();
        yield TextField::new('province', 'Province / région')->setRequired(false)->hideOnIndex();
        yield TextField::new('country', 'Pays')->setRequired(false)->hideOnIndex();
        yield FormField::addPanel('Textes (langue par défaut)')->setHelp('Utilisés quand aucune traduction n\'existe pour la langue demandée ; vides, ceux de la fiche Entreprise.');
        yield TextareaField::new('apropos', 'À propos')->setRequired(false)->hideOnIndex();
        yield TextareaField::new('privacyPolicy', 'Politique de confidentialité')->setRequired(false)->hideOnIndex();
        yield TextareaField::new('conditionOfUse', 'Conditions d\'utilisation')->setRequired(false)->hideOnIndex();
        yield TextareaField::new('legalNotice', 'Mentions légales')->setRequired(false)->hideOnIndex();
        yield FormField::addPanel('Traductions')->setHelp('Nom affiché, accroche et textes par langue, comme les « Contenus traduits » de la fiche Entreprise. Un champ vide reprend la langue par défaut.');
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
}
