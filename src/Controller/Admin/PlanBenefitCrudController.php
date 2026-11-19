<?php

namespace App\Controller\Admin;

use App\Abo\Entity\PlanBenefit;
use App\Abo\Services\AboException;
use App\Abo\Services\BenefitService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Prestations des formules (lot ABO-0, 18/11/2026) : ce qu'une formule d'abonnement livre, combien par période, comment les dates se
 * fixent, et ses règles (délai d'annulation, report, preuve). La phrase « ce que verra l'abonné » est calculée par le serveur.
 */
class PlanBenefitCrudController extends BaseTenantCrudController
{
    public function __construct(TenantEntityManagerProvider $emProvider, private readonly BenefitService $benefits)
    {
        parent::__construct($emProvider);
    }

    public static function getEntityFqcn(): string
    {
        return PlanBenefit::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setEntityLabelInSingular('Prestation d\'une formule')->setEntityLabelInPlural('Prestations des formules')
            ->setDefaultSort(['id' => 'ASC'])
            ->setHelp('index', 'Par défaut : annulation sans frais jusqu\'à 24 h avant, report jusqu\'à la quantité d\'une période (décisions du propriétaire). Aucun effet sur la facturation.');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('plan')->add('benefit');
    }

    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('plan', 'Formule')->setRequired(true)->setFormTypeOptions(['em' => $tenantEm]);
        yield AssociationField::new('benefit', 'Prestation')->setRequired(true)->setFormTypeOptions(['em' => $tenantEm]);
        yield IntegerField::new('quantity', 'Quantité par période')->setHelp('Vide = sans limite (séances seulement).');
        yield ChoiceField::new('scheduling', 'Dates')->setChoices([
            'Sans date' => PlanBenefit::SCHEDULING_NONE, 'Le client choisit à la souscription' => PlanBenefit::SCHEDULING_CLIENT,
            'L\'équipe attribue après la souscription' => PlanBenefit::SCHEDULING_ADMIN, 'Créneaux fixés à l\'avance' => PlanBenefit::SCHEDULING_SLOTS,
        ]);
        yield ChoiceField::new('slotChoice', 'Qui choisit le créneau')->setChoices(['Le client' => 'client', 'L\'équipe' => 'admin'])->hideOnIndex()
            ->setHelp('Seulement pour « Créneaux fixés à l\'avance ».')->setRequired(false);
        yield IntegerField::new('cancelDeadlineHours', 'Annulation sans frais jusqu\'à (heures avant)')->hideOnIndex();
        yield IntegerField::new('carryOverMax', 'Report au plus')->hideOnIndex()->setHelp('Vide = la quantité d\'une période.');
        yield ChoiceField::new('proof', 'Preuve du service')->setChoices(['Aucune' => 'none', 'Note facultative' => 'note', 'Photo facultative' => 'photo'])->hideOnIndex();
        yield IntegerField::new('position', 'Ordre')->hideOnIndex();
        yield TextField::new('id', 'Ce que verra l\'abonné')->hideOnForm()->formatValue(fn ($value, ?PlanBenefit $pb) => $pb !== null && $pb->getBenefit() !== null && $pb->getPlan() !== null ? $this->benefits->summary($pb, 'fr') : '');
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($this->valid($entityInstance)) {
            parent::persistEntity($entityManager, $entityInstance);
        }
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($this->valid($entityInstance)) {
            parent::updateEntity($entityManager, $entityInstance);
        }
    }

    private function valid(PlanBenefit $pb): bool
    {
        try {
            $this->benefits->validatePlanBenefit($pb);

            return true;
        } catch (AboException $e) {
            $this->addFlash('danger', $e->getMessage());

            return false;
        }
    }
}
