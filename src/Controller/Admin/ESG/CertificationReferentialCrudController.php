<?php

namespace App\Controller\Admin\ESG;

use App\Controller\Admin\BaseTenantCrudController;
use App\ESG\Entity\CertificationReferential;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class CertificationReferentialCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return CertificationReferential::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('code', 'Code Référentiel');
        yield TextField::new('name', 'Nom de la Certification');
        yield TextField::new('category', 'Catégorie');
        yield TextField::new('version', 'Version');
        yield TextField::new('certLevel', 'Niveau / Label')->hideOnIndex();
        yield BooleanField::new('isActive', 'Actif');
        yield NumberField::new('thresholdEnvironment', 'Seuil Environnement (%)')->hideOnIndex();
        yield NumberField::new('thresholdSocial', 'Seuil Social (%)')->hideOnIndex();
        yield NumberField::new('thresholdGovernance', 'Seuil Gouvernance (%)')->hideOnIndex();
        yield NumberField::new('thresholdClimate', 'Seuil Climat (%)')->hideOnIndex();
        yield NumberField::new('thresholdGlobal', 'Seuil Global (%)');
        yield IntegerField::new('durationMinMonths', 'Durée Min (mois)')->hideOnIndex();
        yield IntegerField::new('durationMaxMonths', 'Durée Max (mois)')->hideOnIndex();
        yield IntegerField::new('costMinCad', 'Coût Min (CAD)')->hideOnIndex();
        yield IntegerField::new('costMaxCad', 'Coût Max (CAD)')->hideOnIndex();
        yield TextareaField::new('description', 'Description')->hideOnIndex();
        yield TextareaField::new('marketImpact', 'Impact Marché')->hideOnIndex();
        yield AssociationField::new('subsidyPrograms', 'Programmes de Subventions Associés')->hideOnIndex();
        yield DateTimeField::new('activatedAt', 'Activé le')->hideOnForm();
    }
}
