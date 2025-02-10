<?php

namespace App\Controller\Admin;

use App\Entity\EmailConfiguration;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;

class EmailConfigurationCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return EmailConfiguration::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('fromEmail', 'Email d\'envoi'),
            TextField::new('fromName', 'Nom de l\'expéditeur'),
            ImageField::new('logo', 'Logo')
                ->setBasePath('assets/uploads/email-logos/')
                ->setUploadDir('public/assets/uploads/email-logos/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(false),
            TextEditorField::new('signature', 'Signature')->setRequired(false),
        ];
    }
}
