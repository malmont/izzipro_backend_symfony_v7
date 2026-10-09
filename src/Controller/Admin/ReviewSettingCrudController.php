<?php

namespace App\Controller\Admin;

use App\Entity\ReviewSetting;
use App\Form\Type\JsonTextType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

/**
 * Réglages des avis clients du site (une seule ligne). Sans ligne : avis activés, acheteurs vérifiés seulement,
 * modération avant publication, 20 caractères au moins, pas de politique affichée.
 */
class ReviewSettingCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return ReviewSetting::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Réglages des avis')->setEntityLabelInPlural('Réglages des avis')
            ->setPageTitle('index', 'Réglages des avis clients')
            ->setHelp('index', 'Une seule ligne par site. Sans ligne, les valeurs par défaut s\'appliquent : avis activés, acheteurs vérifiés seulement, modération avant publication.');
    }

    public function configureActions(Actions $actions): Actions
    {
        $existing = $this->emProvider->getEntityManager()->getRepository(ReviewSetting::class)->count([]);

        return $actions->update(Crud::PAGE_INDEX, Action::NEW, fn (Action $a) => $a->displayIf(fn () => $existing === 0));
    }

    public function configureFields(string $pageName): iterable
    {
        yield BooleanField::new('enabled', 'Avis activés');
        yield BooleanField::new('verifiedOnly', 'Acheteurs vérifiés seulement')
            ->setHelp('Recommandé : seul un client dont une commande payée contient le produit peut écrire (badge « Achat vérifié »). Décoché : tout client connecté.');
        yield ChoiceField::new('moderation', 'Modération')->setChoices([
            'Avant publication (recommandé)' => ReviewSetting::MODERATION_MANUAL,
            'Publication immédiate, retrait possible ensuite' => ReviewSetting::MODERATION_AUTO,
        ]);
        yield IntegerField::new('minLength', 'Longueur minimale du texte')->setHelp('En caractères, de 0 à 500.');
        yield Field::new('policy', 'Politique des avis (JSON par langue)')->setFormType(JsonTextType::class)->hideOnIndex()
            ->setHelp('Affichée sous la liste des avis. Exemple : {"fr": "Avis vérifiés : seuls nos clients ayant acheté le produit peuvent écrire. Nous publions tous les avis, positifs ou négatifs, sauf contenu injurieux ou hors sujet.", "en": "…"}');
    }
}
