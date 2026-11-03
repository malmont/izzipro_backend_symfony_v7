<?php

namespace App\Controller\Admin;

use App\Entity\BoutiqueSetting;
use App\Controller\Admin\Field\JsonTextField;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;

/**
 * Réglages publiés de la boutique réglable (JSON brut, une ligne par site) : secours pour l'administration ; l'éditeur
 * du frontend passe par PUT /api/boutique-settings, qui contrôle le contenu (ici, aucun contrôle).
 */
class BoutiqueSettingCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return BoutiqueSetting::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Configuration de la boutique')
            ->setEntityLabelInPlural('Configurations de la boutique')
            ->setPageTitle('index', 'Réglages de la boutique réglable')
            ->setPageTitle('edit', 'Modifier les réglages de la boutique');
    }

    public function configureActions(Actions $actions): Actions
    {
        return parent::configureActions($actions)
            ->disable(Action::DELETE, Action::BATCH_DELETE) // pas d'action Détail : la configuration (tableau) ne se rend pas dans la fiche
            ->setPermission(Action::NEW, 'ROLE_SUPER_ADMIN')
            ->setPermission(Action::EDIT, 'ROLE_SUPER_ADMIN');
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            JsonTextField::new('configuration', 'Configuration JSON')
                ->setFormTypeOption('attr', ['data-ea-code-editor-language' => 'javascript', 'rows' => 30])
                ->hideOnIndex(),
            CodeEditorField::new('configuration', 'Aperçu de la configuration')
                ->formatValue(fn ($value) => is_array($value) ? substr(json_encode($value), 0, 100) . '...' : $value)
                ->onlyOnIndex(),
        ];
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN', null, 'Configuration JSON réservée au super administrateur.');
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN', null, 'Configuration JSON réservée au super administrateur.');
        parent::updateEntity($entityManager, $entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        throw $this->createAccessDeniedException('La suppression de la configuration de la boutique est désactivée.');
    }
}
