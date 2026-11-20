<?php

namespace App\Controller\Admin;

use App\Abo\Entity\Benefit;
use App\Abo\Services\AboException;
use App\Abo\Services\BenefitService;
use App\Controller\Admin\Field\JsonTextField;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Prestations du site (lot ABO-0, 18/11/2026, `docs/plan-abo-universel.md`) : ce que les formules d'abonnement livrent (séances, services,
 * quotas). Une prestation livrée par une formule ne se supprime pas : elle se désactive (BENEFIT_IN_USE).
 */
class BenefitCrudController extends BaseTenantCrudController
{
    public const KIND_LABELS = ['Séance planifiée (yoga, cours…)' => Benefit::KIND_SESSION, 'Service à rendre (ménage, courses…)' => Benefit::KIND_SERVICE, 'Quota (carte de séances, interventions)' => Benefit::KIND_QUOTA];

    public function __construct(TenantEntityManagerProvider $emProvider, private readonly BenefitService $benefits)
    {
        parent::__construct($emProvider);
    }

    public static function getEntityFqcn(): string
    {
        return Benefit::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setEntityLabelInSingular('Prestation')->setEntityLabelInPlural('Prestations')
            ->setDefaultSort(['position' => 'ASC', 'id' => 'ASC'])
            ->setHelp('index', 'Ce que vos formules d\'abonnement livrent : des séances planifiées, des services à rendre ou un quota d\'utilisations. Réglez ensuite, dans « Prestations des formules », combien chaque formule en donne et comment les dates se fixent.');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield ChoiceField::new('kind', 'Type')->setChoices(self::KIND_LABELS);
        yield JsonTextField::new('names', 'Noms par langue (JSON)')->setHelp('{"fr": "Yoga du mardi", "en": "Tuesday yoga"}')
            ->formatValue(fn ($value) => is_array($value) ? ($value['fr'] ?? reset($value)) : $value);
        yield JsonTextField::new('descriptions', 'Description par langue (JSON)')->hideOnIndex()->setHelp('{"fr": "Cours collectif, tapis fourni"} — texte en clair, 600 caractères au plus.');
        yield IntegerField::new('durationMinutes', 'Durée (minutes)');
        yield TextField::new('location', 'Lieu')->hideOnIndex();
        yield IntegerField::new('defaultStaffUserId', 'Intervenant par défaut (n° de compte)')->hideOnIndex()->setHelp('Facultatif. Jamais montré aux abonnés.');
        yield BooleanField::new('active', 'Active');
        yield IntegerField::new('position', 'Ordre')->hideOnIndex();
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($this->valid($entityInstance)) {
            parent::persistEntity($entityManager, $entityInstance);
        }
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        // lot ABO-CONF : la même règle que la gestion web : une prestation qui a des droits à utiliser ne se désactive pas
        $original = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance);
        if ($entityInstance instanceof Benefit && ($original['active'] ?? false) === true && !$entityInstance->isActive()) {
            try {
                $this->benefits->assertDeactivatable($entityInstance);
            } catch (\App\Abo\Services\AboException $e) {
                $entityInstance->setActive(true);
                $this->addFlash('danger', $e->getMessage());

                return;
            }
        }
        if ($this->valid($entityInstance)) {
            parent::updateEntity($entityManager, $entityInstance);
        }
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        try {
            $this->benefits->assertDeletable($entityInstance);
        } catch (AboException $e) {
            $this->addFlash('danger', $e->getMessage());

            return;
        }
        parent::deleteEntity($entityManager, $entityInstance);
    }

    private function valid(Benefit $benefit): bool
    {
        // les textes saisis passent par les règles de l'entité (en clair, bornés)
        $benefit->setNames($benefit->getNames())->setDescriptions($benefit->getDescriptions());
        try {
            $this->benefits->validateBenefit($benefit);

            return true;
        } catch (AboException $e) {
            $this->addFlash('danger', $e->getMessage());

            return false;
        }
    }
}
