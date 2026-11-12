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
                ->setHelp('Vide : la taxe s\'applique à tous les produits non exonérés (TPS, TVQ). Une catégorie : seulement aux produits de cette catégorie (France : TVA 20 % standard, 10 % intermédiaire, 5,5 % réduit, 2,1 % super réduit). À utiliser seulement quand les applications à jour sont publiées : une caisse antérieure à la règle hors ligne v2 calcule sans catégorie.'),
            TextField::new('country', 'Pays (ISO, ex. CA ; vide = tous)')->setRequired(false),
            TextField::new('province', 'Région')->setHelp('« Toutes », ou codes séparés par des virgules : QC ; ON,NB,NL,PE,NS')->hideOnIndex(),
            // PAYS-1 (12/11/2026) : code stable, dates de validité (jours du magasin) et archivage ; une taxe utilisée garde son taux
            \EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField::new('code', 'Code')->setChoices(array_combine(\App\Services\OrderService\TaxCode::CODES, \App\Services\OrderService\TaxCode::CODES))->setRequired(false)
                ->setHelp('gst (TPS), qst (TVQ), hst (TVH), pst (TVP), vat (TVA), other. Vide : déduit du nom.'),
            \EasyCorp\Bundle\EasyAdminBundle\Field\DateField::new('validFrom', 'Valide à partir du')->setRequired(false)->hideOnIndex()
                ->setHelp('Inclus. Vide : sans borne. Pour un nouveau taux, créez une nouvelle taxe datée et indiquez la fin de validité de l\'ancienne.'),
            \EasyCorp\Bundle\EasyAdminBundle\Field\DateField::new('validTo', 'Valide jusqu\'au (exclu)')->setRequired(false)->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField::new('archived', 'Archivée')->renderAsSwitch(false)
                ->setHelp('Une taxe archivée n\'est plus appliquée ; les commandes passées gardent leur taxe gelée.'),
            AssociationField::new('orderTaxes', 'Taxes de commandes')->hideOnForm()
        ];
    }

    public function persistEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof Tax && $entityInstance->getCode() === null) {
            $entityInstance->setCode(\App\Services\OrderService\TaxCode::fromLabel($entityInstance->getName())); // code stable (PAYS-1)
        }
        parent::persistEntity($entityManager, $entityInstance);
    }

    /** Taxe utilisée (PAYS-1) : refus clair (TAX_IN_USE) au lieu d'une erreur, la fiche est rechargée */
    public function updateEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        try {
            parent::updateEntity($entityManager, $entityInstance);
        } catch (\App\Services\OrderService\TaxInUseException $e) {
            $this->emProvider->getEntityManager()->refresh($entityInstance);
            $this->addFlash('danger', $e->getMessage());
        }
    }

    public function deleteEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof Tax && $entityInstance->getId() !== null
            && \App\EventSubscriber\TaxInUseGuardSubscriber::isUsed($this->emProvider->getEntityManager()->getConnection(), (int) $entityInstance->getId())) {
            $this->addFlash('danger', (new \App\Services\OrderService\TaxInUseException((int) $entityInstance->getId(), [], true))->getMessage());

            return;
        }
        parent::deleteEntity($entityManager, $entityInstance);
    }
}