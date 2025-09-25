<?php

namespace App\Controller\Admin;

use App\Entity\ServiceOffer;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use App\Form\ServiceOfferTranslationType;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;

/**
 * Ce contrôleur hérite de notre base pour être automatiquement "multi-tenant".
 */
class ServiceOfferCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return ServiceOffer::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('titre', 'Titre de l\'offre'),
            ImageField::new('logo', 'Logo')
                ->setBasePath('assets/uploads/email-logos/')
                ->setUploadDir('public/assets/uploads/email-logos/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(false),
            ImageField::new('photoService', 'photoService')
                ->setBasePath('assets/uploads/email-logos/')
                ->setUploadDir('public/assets/uploads/email-logos/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(false),
            CollectionField::new('translations', 'Traductions')
                ->setEntryType(ServiceOfferTranslationType::class)
                ->setFormTypeOption('by_reference', false)
                ->onlyOnForms(),    
            
            TextField::new('titreCommentaire', 'Titre du commentaire')->hideOnIndex(),
            TextareaField::new('descriptions', 'Description')->hideOnIndex(),
        ];
    }
}
