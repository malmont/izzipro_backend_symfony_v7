<?php

namespace App\Controller\Admin\ESG;

use App\Controller\Admin\BaseTenantCrudController;
use App\ESG\Entity\CertificationRecommendation;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class CertificationRecommendationCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return CertificationRecommendation::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('referential', 'Référentiel Associé');
        yield TextField::new('recommendationKey', 'Clé de Recommandation');
        yield TextareaField::new('title', 'Titre de la Recommandation');
        yield TextareaField::new('description', 'Description détaillée');
        yield IntegerField::new('priority', 'Priorité (1 = haute)');
    }
}
