<?php

namespace App\Controller\Admin;

use App\Abo\Entity\BenefitSlotRule;
use App\Abo\Services\AboAgendaService;
use App\Abo\Services\AboException;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Règles de créneaux des prestations (lot ABO-2, 20/11/2026) : jours, heure du magasin, durée, capacité, lieu, intervenant. Enregistrer
 * une règle crée ses créneaux 8 semaines devant ; la modifier refait les créneaux futurs SANS réservation (ceux qui en ont restent).
 */
class BenefitSlotRuleCrudController extends BaseTenantCrudController
{
    public function __construct(TenantEntityManagerProvider $emProvider, private readonly AboAgendaService $agenda)
    {
        parent::__construct($emProvider);
    }

    public static function getEntityFqcn(): string
    {
        return BenefitSlotRule::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setEntityLabelInSingular('Règle de créneaux')->setEntityLabelInPlural('Règles de créneaux')
            ->setDefaultSort(['id' => 'ASC'])
            ->setHelp('index', 'Les créneaux récurrents d\'une séance ou d\'un service (ex. : yoga le mardi et le jeudi à 18:00, 12 places). Les créneaux datés sont créés 8 semaines devant, à l\'heure de votre magasin ; une règle modifiée ne touche jamais un créneau qui a déjà des réservations.');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('benefit', 'Prestation');
        yield TextField::new('weekdaysText', 'Jours (1 = lundi … 7 = dimanche)')->setHelp('Ex. : 2,4 pour le mardi et le jeudi.');
        yield TextField::new('localTime', 'Heure (HH:MM, heure du magasin)');
        yield IntegerField::new('durationMinutes', 'Durée (minutes)');
        yield IntegerField::new('capacity', 'Places');
        yield TextField::new('location', 'Lieu')->hideOnIndex();
        yield IntegerField::new('staffUserId', 'Intervenant (n° de compte)')->hideOnIndex()->setHelp('Facultatif. Jamais montré aux abonnés.');
        yield DateField::new('validFrom', 'À partir du')->hideOnIndex();
        yield DateField::new('validUntil', 'Jusqu\'au')->hideOnIndex();
        yield BooleanField::new('active', 'Active');
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->save($entityInstance, true);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->save($entityInstance, false);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->agenda->deleteRule($entityInstance); // ses créneaux futurs sans réservation partent avec elle
    }

    private function save(BenefitSlotRule $rule, bool $creating): void
    {
        try {
            $this->agenda->persistRule($rule);
        } catch (AboException $e) {
            $this->addFlash('danger', $e->getMessage());
            if (!$creating) {
                $this->emProvider->getEntityManager()->refresh($rule);
            }
        }
    }
}
