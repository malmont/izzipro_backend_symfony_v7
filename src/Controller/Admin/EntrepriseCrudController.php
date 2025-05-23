<?php

namespace App\Controller\Admin;

use App\Entity\Entreprise;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Field\JsonField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use App\Controller\Admin\AddressEntrepriseCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;



class EntrepriseCrudController extends AbstractCrudController
{
    private AdminUrlGenerator $adminUrlGenerator;

       public function __construct(AdminUrlGenerator $adminUrlGenerator)
    {
        $this->adminUrlGenerator = $adminUrlGenerator;
    }
    public static function getEntityFqcn(): string
    {
        return Entreprise::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('name', 'Nom de l\'entreprise'),
            ImageField::new('logo', 'Logo')
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
            
                
        ];
    }
}
