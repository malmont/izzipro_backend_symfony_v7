<?php

namespace App\Controller\Admin;

use App\Entity\LandingPageSetting;
use App\Controller\Admin\BaseTenantCrudController;
use App\Form\Type\JsonTextType; 
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

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),

            // ==================================================================
            // CORRECTION FINALE
            // ==================================================================
            // On utilise le champ générique "Field" au lieu de "TextareaField"
            Field::new('configuration', 'Configuration JSON')
                // On lui dit d'utiliser notre type de formulaire personnalisé
                ->setFormType(JsonTextType::class)
                // On garde les options pour le style de l'éditeur de code
                ->setFormTypeOption('attr', [
                    'data-ea-code-editor-language' => 'javascript',
                    'rows' => 30, // On déplace la configuration des lignes ici
                ])
                ->hideOnIndex(),

            // On garde le CodeEditorField pour l'aperçu sur la page de liste
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
}
