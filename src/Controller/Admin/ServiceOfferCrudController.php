<?php

namespace App\Controller\Admin;

use App\Entity\ServiceOffer;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;

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
            TextField::new('titreCommentaire', 'Titre du commentaire')->hideOnIndex(),
            TextareaField::new('descriptions', 'Description')->hideOnIndex(),
        ];
    }
}
