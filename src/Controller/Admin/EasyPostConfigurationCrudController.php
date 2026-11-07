<?php

namespace App\Controller\Admin;

use App\Entity\EasyPostConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;

class EasyPostConfigurationCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return EasyPostConfiguration::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return parent::configureActions($actions)
            ->disable(Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->setPermission(Action::NEW, 'ROLE_SUPER_ADMIN')
            ->setPermission(Action::EDIT, 'ROLE_SUPER_ADMIN')
            ->setPermission(Action::DELETE, 'ROLE_SUPER_ADMIN');
    }

    public function configureCrud(\EasyCorp\Bundle\EasyAdminBundle\Config\Crud $crud): \EasyCorp\Bundle\EasyAdminBundle\Config\Crud
    {
        return parent::configureCrud($crud)->setEntityLabelInSingular('Clés EasyPost')->setEntityLabelInPlural('Clés EasyPost')
            ->setHelp('index', 'Votre compte EasyPost (easypost.com) : tarifs en direct au paiement et étiquettes facturées sur votre compte. Une seule ligne par site ; l\'environnement utilisé (test ou réel) se choisit dans la page Livraison. Sans compte, vos clients choisissent un transporteur à prix fixe et vous expédiez vous-même.');
    }

    public function configureFields(string $pageName): iterable
    {

        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('easypostApiKeySandbox', 'Clé de test (sandbox)')
                ->setFormType(PasswordType::class)
                ->setRequired(false)
                ->setFormTypeOption('empty_data', '')
                ->setTemplatePath('admin/field/masked_secret.html.twig') // index et détail : jamais la valeur, même dans title
                ->setHelp('Clé utilisée pour l\'environnement de test. Laissez vide lors d\'une modification pour conserver la clé actuelle.'),
            TextField::new('easypostApiKeyProd', 'Clé réelle (production)')
                ->setFormType(PasswordType::class)
                ->setRequired(false)
                ->setFormTypeOption('empty_data', '')
                ->setTemplatePath('admin/field/masked_secret.html.twig') // index et détail : jamais la valeur, même dans title
                ->setHelp('Clé chiffrée, masquée sur l’index et la vue détail. Laissez vide lors d\'une modification pour conserver la clé actuelle.'),
        ];
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN', null, 'Configuration EasyPost réservée au super administrateur.');
        if ($entityInstance instanceof EasyPostConfiguration && trim((string) $entityInstance->getEasypostApiKeyProd()) === '') {
            $entityInstance->setEasypostApiKeyProdEncrypted(null);
        }
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN', null, 'Configuration EasyPost réservée au super administrateur.');
        if ($entityInstance instanceof EasyPostConfiguration) {
            $id = (int) $entityInstance->getId();
            if ($id <= 0) {
                throw $this->createAccessDeniedException('Configuration EasyPost introuvable.');
            }
            $row = $this->emProvider->getEntityManager()->getConnection()->fetchAssociative(
                'SELECT easypost_api_key_sandbox, easypost_api_key_prod FROM easy_post_configuration WHERE id = ?',
                [$id]
            );
            if (!is_array($row)) {
                throw $this->createAccessDeniedException('Configuration EasyPost introuvable.');
            }
            if (trim((string) $entityInstance->getEasypostApiKeySandbox()) === '' && $row['easypost_api_key_sandbox'] !== null) {
                $entityInstance->setEasypostApiKeySandbox((string) $row['easypost_api_key_sandbox']);
            }
            if (trim((string) $entityInstance->getEasypostApiKeyProd()) === '') {
                $entityInstance->setEasypostApiKeyProdEncrypted($row['easypost_api_key_prod'] !== null ? (string) $row['easypost_api_key_prod'] : null);
            }
        }
        parent::updateEntity($entityManager, $entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN', null, 'Configuration EasyPost réservée au super administrateur.');
        parent::deleteEntity($entityManager, $entityInstance);
    }
}
