<?php

namespace App\Controller\Admin\ESG;

use App\Controller\Admin\BaseTenantCrudController;
use App\ESG\Entity\OddMapping;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class OddMappingCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return OddMapping::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('referential', 'Référentiel Associé');
        yield IntegerField::new('oddNumber', 'Numéro ODD (1-17)');
        yield TextField::new('oddName', 'Nom de l\'ODD');
        yield TextareaField::new('justification', 'Justification / Impact')->hideOnIndex();
    }
}
