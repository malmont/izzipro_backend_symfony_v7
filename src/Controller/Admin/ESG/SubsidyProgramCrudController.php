<?php

namespace App\Controller\Admin\ESG;

use App\Controller\Admin\BaseTenantCrudController;
use App\ESG\Entity\SubsidyProgram;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class SubsidyProgramCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return SubsidyProgram::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('code', 'Code Programme');
        yield TextField::new('name', 'Nom du Programme');
        yield TextField::new('organism', 'Organisme Bénéficiaire');
        yield ChoiceField::new('territory', 'Territoire')
            ->setChoices([
                'Québec' => 'quebec',
                'Canada' => 'canada',
                'International' => 'international',
            ]);
        yield NumberField::new('subsidyRatePercent', 'Taux Subvention (%)');
        yield IntegerField::new('maxAmountCad', 'Montant Max (CAD)');
        yield TextareaField::new('conditions', 'Conditions d\'éligibilité')->hideOnIndex();
        yield BooleanField::new('isActive', 'Actif');
    }
}
