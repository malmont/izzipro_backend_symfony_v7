<?php

namespace App\Controller\Admin\ESG;

use App\Controller\Admin\BaseTenantCrudController;
use App\ESG\Entity\DiagnosticQuestion;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;

class DiagnosticQuestionCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return DiagnosticQuestion::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield ChoiceField::new('domain', 'Domaine ESG')
            ->setChoices([
                'Environnement (E)' => 'E',
                'Social (S)' => 'S',
                'Gouvernance (G)' => 'G',
                'Climat / Carbone (C)' => 'C',
            ]);
        yield TextareaField::new('questionText', 'Intitulé de la question');
        yield TextareaField::new('helpText', 'Texte d\'aide / Explication')->hideOnIndex();
        yield ChoiceField::new('answerType', 'Type de réponse')
            ->setChoices([
                'Binaire (Oui/Non)' => 'boolean',
                'Échelle 1-5' => 'scale',
                'Choix multiple' => 'choice',
                'Saisie numérique' => 'number',
            ]);
        yield IntegerField::new('weight', 'Pondération');
        yield IntegerField::new('displayOrder', 'Ordre d\'affichage');
        yield BooleanField::new('isActive', 'Active');
        yield DateTimeField::new('createdAt', 'Créée le')->hideOnForm();
    }
}
