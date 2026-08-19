<?php

namespace App\Controller\Admin;

use App\Entity\EmailConfiguration;
use App\Form\EmailConfigurationTranslationType;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;

class EmailConfigurationCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return EmailConfiguration::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            FormField::addPanel('Informations Générales'),
            IdField::new('id')->hideOnForm(),
            TextField::new('fromEmail', 'Email d\'envoi'),
            ImageField::new('logo', 'Logo')
                ->setBasePath('assets/uploads/email-logos/')
                ->setUploadDir('public/assets/uploads/email-logos/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(false),
            CollectionField::new('translations', 'Traductions')
                ->setEntryType(EmailConfigurationTranslationType::class)
                ->setFormTypeOption('by_reference', false)
                ->onlyOnForms(),
            TextField::new('fromName', 'Nom (Défaut)'),
            TextEditorField::new('signature', 'Signature')->setRequired(false),

            FormField::addPanel('Configuration SMTP Dédiée (Multi-tenant)')
                ->setHelp('Laissez ces champs vides si vous souhaitez utiliser le serveur SMTP global par défaut.'),
            TextField::new('smtpHost', 'Serveur SMTP')
                ->setHelp('Exemple : smtp.hostinger.com, in-v3.mailjet.com')
                ->setRequired(false),
            IntegerField::new('smtpPort', 'Port SMTP')
                ->setHelp('Exemple : 465 (SSL) ou 587 (TLS)')
                ->setRequired(false),
            TextField::new('smtpUser', 'Utilisateur SMTP')
                ->setRequired(false),
            TextField::new('smtpPassword', 'Mot de passe SMTP')
                ->setFormType(PasswordType::class)
                ->hideOnIndex()
                ->setRequired(false),
        ];
    }
}