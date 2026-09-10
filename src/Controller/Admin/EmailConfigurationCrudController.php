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

use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormInterface;

class EmailConfigurationCrudController extends BaseTenantCrudController
{
    private ?string $previousPassword = null;

    public static function getEntityFqcn(): string
    {
        return EmailConfiguration::class;
    }

    public function createEditForm(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormInterface
    {
        /** @var EmailConfiguration $entity */
        $entity = $entityDto->getInstance();
        $this->previousPassword = $entity->getSmtpPassword();
        return parent::createEditForm($entityDto, $formOptions, $context);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof EmailConfiguration) {
            if (!$entityInstance->getSmtpPassword() && $this->previousPassword) {
                $entityInstance->setSmtpPassword($this->previousPassword);
            }
        }
        parent::updateEntity($entityManager, $entityInstance);
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
                ->setHelp('Renseignez ces champs pour que le tenant envoie ses emails avec sa propre boîte sans utiliser le SMTP global.'),
            TextField::new('smtpHost', 'Serveur SMTP')
                ->setHelp('Exemple : smtp.hostinger.com, in-v3.mailjet.com')
                ->setRequired(false),
            IntegerField::new('smtpPort', 'Port SMTP')
                ->setHelp('Exemple : 465 (SSL) ou 587 (TLS)')
                ->setRequired(false),
            TextField::new('smtpUser', 'Utilisateur SMTP')
                ->setHelp('Exemple : contact@lintendantprive.com')
                ->setRequired(false),
            TextField::new('smtpPassword', 'Mot de passe SMTP')
                ->setFormType(PasswordType::class)
                ->setHelp('Saisissez le mot de passe de la boîte mail. Laissez vide lors d\'une modification pour conserver le mot de passe actuel.')
                ->hideOnIndex()
                ->setRequired(false),
        ];
    }
}