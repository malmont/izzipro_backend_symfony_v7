<?php
namespace App\Controller\Admin;

use App\Entity\Tax;
use App\Controller\Admin\BaseTenantCrudController; // <-- 1. On importe notre base
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;

// 2. On étend notre contrôleur de base
class TaxCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return Tax::class;
    }

    public function __construct(\App\Services\TenantEntityManagerProvider $emProvider, private readonly \App\Services\OrderService\TaxCategoryAudit $audit)
    {
        parent::__construct($emProvider);
    }

    /** Alerte « catégorie sans taxe » (F18 partie 2a) en tête de la liste */
    public function configureCrud(\EasyCorp\Bundle\EasyAdminBundle\Config\Crud $crud): \EasyCorp\Bundle\EasyAdminBundle\Config\Crud
    {
        $crud = parent::configureCrud($crud)->setEntityLabelInSingular('Tax')->setEntityLabelInPlural('Taxes')->setSearchFields(['name', 'province', 'type']);
        $warning = $this->audit->warning();

        return $crud->setHelp('index', ($warning !== null ? '⚠️ ' . $warning . ' — ' : '') . 'Taxes du site par pays et région ; une taxe sans catégorie frappe tous les produits non exonérés.');
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name', 'Name'),
            NumberField::new('rate', 'Rate')->setNumDecimals(2),
            TextField::new('type', 'Type'),
            // Catégorie fiscale (F18 partie 2a) : vide = tous les produits non exonérés (comportement d'avant) ; sinon les produits de cette catégorie seulement
            \EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField::new('category', 'Catégorie fiscale')->setChoices(array_flip(array_diff_key(\App\Services\OrderService\TaxCategory::LABELS, ['exempt' => 1])))->setRequired(false)
                ->setHelp('Vide : la taxe s\'applique à tous les produits non exonérés (TPS, TVQ). Une catégorie : seulement aux produits de cette catégorie (France : TVA 20 % standard, 10 % intermédiaire, 5,5 % réduit, 2,1 % super réduit).'),
            TextField::new('country', 'Pays (ISO, ex. CA ; vide = tous)')->setRequired(false),
            TextField::new('province', 'Région')->setHelp('« Toutes », ou codes séparés par des virgules : QC ; ON,NB,NL,PE,NS')->hideOnIndex(),
            AssociationField::new('orderTaxes', 'Order Taxes')->hideOnForm()
        ];
    }
}