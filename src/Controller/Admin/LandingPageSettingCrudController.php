<?php

namespace App\Controller\Admin;

use App\Entity\LandingPageSetting;
use App\Controller\Admin\BaseTenantCrudController;
use App\Controller\Admin\Field\JsonTextField; 
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
// MODIFICATION: On importe le champ générique "Field"
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;

/**
 * Ce contrôleur gère le CRUD pour l'entité LandingPageSetting dans EasyAdmin.
 */
class LandingPageSettingCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return LandingPageSetting::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Configuration de la Landing Page')
            ->setEntityLabelInPlural('Configurations de la Landing Page')
            ->setPageTitle('index', 'Liste des Configurations')
            ->setPageTitle('edit', 'Modifier la Configuration');
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
                ->setFormTypeOption('attr', [
                    'data-ea-code-editor-language' => 'javascript',
                    'rows' => 30, 
                ])
                ->hideOnIndex(),
            CodeEditorField::new('configuration', 'Aperçu de la Configuration')
                ->formatValue(function ($value) {
                    if (is_array($value)) {
                        return substr(json_encode($value), 0, 100) . '...';
                    }
                    return $value;
                })
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
        throw $this->createAccessDeniedException('La suppression de la configuration de la Landing Page est désactivée.');
    }
}
