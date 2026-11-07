<?php

namespace App\Controller\Admin;

use App\Entity\RentalPack;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\TenantEntityManagerProvider;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/** Grilles tarifaires de location (packs) : prix par formule dans la devise du site, rattachées aux catégories (libellés revus le 08/11/2026). */
class RentalPackCrudController extends BaseTenantCrudController
{
    public function __construct(TenantEntityManagerProvider $emProvider, private readonly ?TenantCurrencyProvider $currency = null)
    {
        parent::__construct($emProvider);
    }

    public static function getEntityFqcn(): string
    {
        return RentalPack::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setEntityLabelInSingular('Grille tarifaire')->setEntityLabelInPlural('Grilles tarifaires de location')
            ->setHelp(Crud::PAGE_INDEX, 'Une grille porte les prix par formule (heure, demi-journée, journée, semaine, mois) et s\'applique à tous les produits en location des catégories rattachées. Une formule sans prix n\'est pas proposée au client.');
    }

    public function configureFields(string $pageName): iterable
    {
        $currency = $this->currency?->code() ?? 'CAD';
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name', 'Nom de la grille')->setHelp('Ex. : « Tarifs nautiques ». Visible du client quand plusieurs grilles s\'appliquent.');

        yield FormField::addPanel('Prix par formule')->setIcon('fas fa-tags')->collapsible()->setHelp('Laissez vide les formules que vous n\'offrez pas. Le client paie le prix de la formule multiplié par le nombre d\'unités, arrondi au-dessus (2 h 30 à l\'heure = 3 h). Une formule n\'est proposée que si la grille de réservation du produit la permet : à la journée, seules journée, semaine et mois ; à l\'heure avec des heures d\'ouverture, seules heure et demi-journée.');
        yield MoneyField::new('hourRate', 'Prix à l\'heure')->setCurrency($currency)->setStoredAsCents(true)->setColumns('col-md-4');
        yield MoneyField::new('halfDayRate', 'Prix à la demi-journée (4 h)')->setCurrency($currency)->setStoredAsCents(true)->setColumns('col-md-4');
        yield MoneyField::new('dayRate', 'Prix à la journée')->setCurrency($currency)->setStoredAsCents(true)->setColumns('col-md-4');
        yield MoneyField::new('weekRate', 'Prix à la semaine (7 jours)')->setCurrency($currency)->setStoredAsCents(true)->setColumns('col-md-4');
        yield MoneyField::new('monthRate', 'Prix au mois (30 jours)')->setCurrency($currency)->setStoredAsCents(true)->setColumns('col-md-4');

        yield FormField::addPanel('Catégories')->setIcon('fas fa-folder')->collapsible();
        yield AssociationField::new('categories', 'Catégories qui utilisent cette grille')
            ->setFormTypeOptions(['em' => $this->emProvider->getEntityManager()])
            ->setHelp('Tous les produits en location de ces catégories prennent ces prix. Un produit sans grille pour sa catégorie ne peut pas être réservé.');
    }
}
