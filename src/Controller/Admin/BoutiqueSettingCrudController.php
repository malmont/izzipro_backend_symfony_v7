<?php

namespace App\Controller\Admin;

use App\Entity\BoutiqueSetting;
use App\Form\Type\JsonTextType;
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

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            Field::new('configuration', 'Configuration JSON')
                ->setFormType(JsonTextType::class)
                ->setFormTypeOption('attr', ['data-ea-code-editor-language' => 'javascript', 'rows' => 30])
                ->hideOnIndex(),
            CodeEditorField::new('configuration', 'Aperçu de la configuration')
                ->formatValue(fn ($value) => is_array($value) ? substr(json_encode($value), 0, 100) . '...' : $value)
                ->onlyOnIndex(),
        ];
    }
}
