<?php

namespace App\Controller\Admin\Memoires;

use App\Controller\Admin\BaseTenantCrudController;
use App\MemoiresVivantes\Entity\MemoireQuestion;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;

class MemoireQuestionCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return MemoireQuestion::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setEntityLabelInSingular('Question Mémoires Vivantes')
            ->setEntityLabelInPlural('Questions Mémoires Vivantes')
            ->setDefaultSort(['bookType' => 'ASC', 'theme' => 'ASC', 'displayOrder' => 'ASC'])
            ->setPaginatorPageSize(30);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('bookType', 'Type de livre')->setChoices([
                'Individuel' => 'individuel',
                'Couple' => 'couple',
                'Famille' => 'famille',
                'Hommage' => 'hommage',
            ]))
            ->add(ChoiceFilter::new('theme', 'Thème')->setChoices([
                'Enfance (Individuel)' => 'enfance',
                'Adulte (Individuel)' => 'adulte',
                'Sagesse (Individuel)' => 'sagesse',
                'Avant nous 1 (Couple)' => 'avant_nous_1',
                'Avant nous 2 (Couple)' => 'avant_nous_2',
                'La Rencontre (Couple)' => 'la_rencontre',
                'Construire ensemble (Couple)' => 'construire_ensemble',
                'Ce que nous avons appris (Couple)' => 'ce_que_nous_avons_appris',
                'Message final (Couple)' => 'message_final',
                'Regards croisés (Famille)' => 'regards_croises',
                'Regards petits-enfants (Famille)' => 'regards_petits_enfants',
                'Épilogue collectif (Famille)' => 'epilogue_collectif',
                'Portrait croisé (Hommage)' => 'portrait_croise',
                'Les voix (Hommage)' => 'les_voix',
                'Une vie (Hommage)' => 'une_vie',
                'Ce qu\'il/elle nous laisse (Hommage)' => 'ce_quil_nous_laisse',
                'Ce qu\'on aurait voulu dire (Hommage)' => 'ce_quon_aurait_voulu_dire',
            ]))
            ->add(ChoiceFilter::new('role', 'Rôle (Hommage)')->setChoices([
                'Tous / Transversal' => '',
                'Enfant' => 'enfant',
                'Petit-enfant' => 'petit_enfant',
                'Conjoint' => 'conjoint',
                'Frère / Sœur' => 'frere_soeur',
                'Ami(e)' => 'ami',
                'Collègue' => 'collegue',
                'Proche' => 'proche',
            ]))
            ->add(BooleanFilter::new('isActive', 'Active'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();

        yield ChoiceField::new('bookType', 'Type de livre')
            ->setChoices([
                'Individuel' => 'individuel',
                'Couple' => 'couple',
                'Famille' => 'famille',
                'Hommage' => 'hommage',
            ]);

        yield ChoiceField::new('theme', 'Thème / Chapitre')
            ->setChoices([
                'Enfance (Individuel)' => 'enfance',
                'Adulte (Individuel)' => 'adulte',
                'Sagesse (Individuel)' => 'sagesse',
                'Avant nous 1 (Couple)' => 'avant_nous_1',
                'Avant nous 2 (Couple)' => 'avant_nous_2',
                'La Rencontre (Couple)' => 'la_rencontre',
                'Construire ensemble (Couple)' => 'construire_ensemble',
                'Ce que nous avons appris (Couple)' => 'ce_que_nous_avons_appris',
                'Message final (Couple)' => 'message_final',
                'Regards croisés (Famille)' => 'regards_croises',
                'Regards petits-enfants (Famille)' => 'regards_petits_enfants',
                'Épilogue collectif (Famille)' => 'epilogue_collectif',
                'Portrait croisé (Hommage)' => 'portrait_croise',
                'Les voix (Hommage)' => 'les_voix',
                'Une vie (Hommage)' => 'une_vie',
                'Ce qu\'il/elle nous laisse (Hommage)' => 'ce_quil_nous_laisse',
                'Ce qu\'on aurait voulu dire (Hommage)' => 'ce_quon_aurait_voulu_dire',
            ]);

        yield ChoiceField::new('role', 'Rôle du contributeur (Hommage)')
            ->setChoices([
                'Tous / Transversal' => null,
                'Enfant' => 'enfant',
                'Petit-enfant' => 'petit_enfant',
                'Conjoint' => 'conjoint',
                'Frère / Sœur' => 'frere_soeur',
                'Ami(e)' => 'ami',
                'Collègue' => 'collegue',
                'Proche' => 'proche',
            ])
            ->setHelp('Applicable particulièrement aux questions spécifiques du chapitre « Les voix ». Laisser vide si la question est commune.')
            ->setRequired(false);

        yield IntegerField::new('displayOrder', 'Index (Ordre)');

        yield TextareaField::new('questionText', 'Intitulé de la question');

        yield TextareaField::new('tip', 'Conseil d\'inspiration (Tip)')
            ->setHelp('Conseil affiché sous la question pour aider la personne à répondre.')
            ->hideOnIndex();

        yield BooleanField::new('isActive', 'Active');

        yield DateTimeField::new('createdAt', 'Créée le')->hideOnForm();
    }
}
