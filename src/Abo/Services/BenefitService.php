<?php

namespace App\Abo\Services;

use App\Abo\Entity\Benefit;
use App\Abo\Entity\PlanBenefit;
use App\Abo\Repository\BenefitRepository;
use App\Abo\Repository\PlanBenefitRepository;
use App\Entity\SubscriptionPlan;
use App\Services\TenantEntityManagerProvider;

/**
 * Prestations du site et prestations des formules (lot ABO-0, 18/11/2026, `docs/plan-abo-universel.md`) : validation et codes stables,
 * écriture (EasyAdmin, assistant du site après confirmation), lecture par formule et phrase « ce que verra l'abonné » calculée par le
 * serveur. Aucun effet sur la facturation ni sur les droits.
 */
class BenefitService
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    public function benefits(): BenefitRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(Benefit::class);
    }

    public function planBenefits(): PlanBenefitRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(PlanBenefit::class);
    }

    /** @throws AboException 422 */
    public function validateBenefit(Benefit $benefit): void
    {
        if (!in_array($benefit->getKind(), Benefit::KINDS, true)) {
            throw new AboException(422, 'BENEFIT_KIND_INVALID', 'Type de prestation inconnu : séance, service ou quota.', 'kind');
        }
        if (!in_array($benefit->getKind(), Benefit::OPEN_KINDS, true)) {
            throw new AboException(422, 'BENEFIT_KIND_RESERVED', 'L\'accès à un module de la plateforme n\'est pas encore une prestation (lot ABO-5).', 'kind');
        }
        if (trim($benefit->getName()) === '') {
            throw new AboException(422, 'BENEFIT_NAME_REQUIRED', 'Donnez un nom à la prestation.', 'names');
        }
    }

    /** @throws AboException 422 */
    public function validatePlanBenefit(PlanBenefit $pb): void
    {
        $benefit = $pb->getBenefit();
        if ($pb->getPlan() === null || $benefit === null) {
            throw new AboException(422, 'PLAN_BENEFIT_INVALID', 'Choisissez la formule et la prestation.', 'benefit');
        }
        $quantity = $pb->getQuantity();
        if (($quantity === null && $benefit->getKind() !== Benefit::KIND_SESSION) || ($quantity !== null && ($quantity < 1 || $quantity > PlanBenefit::MAX_QUANTITY))) {
            throw new AboException(422, 'PLAN_BENEFIT_QUANTITY_INVALID', sprintf('Quantité par période : de 1 à %d (sans limite : séances seulement).', PlanBenefit::MAX_QUANTITY), 'quantity');
        }
        if (!in_array($pb->getScheduling(), PlanBenefit::SCHEDULINGS, true)
            || ($pb->getScheduling() === PlanBenefit::SCHEDULING_SLOTS && $benefit->getKind() !== Benefit::KIND_SESSION)
            || ($pb->getScheduling() === PlanBenefit::SCHEDULING_SLOTS) !== ($pb->getSlotChoice() !== null)
            || ($pb->getSlotChoice() !== null && !in_array($pb->getSlotChoice(), PlanBenefit::SLOT_CHOICES, true))) {
            throw new AboException(422, 'PLAN_BENEFIT_SCHEDULING_INVALID', 'Mode des dates incohérent : les créneaux fixés ne valent que pour une séance, et demandent qui choisit (le client ou l\'équipe).', 'scheduling');
        }
        if ($pb->getCancelDeadlineHours() < 0 || $pb->getCancelDeadlineHours() > 720 || ($pb->getCarryOverMax() !== null && ($pb->getCarryOverMax() < 0 || $pb->getCarryOverMax() > PlanBenefit::MAX_QUANTITY))
            || !in_array($pb->getProof(), PlanBenefit::PROOFS, true)) {
            throw new AboException(422, 'PLAN_BENEFIT_RULES_INVALID', 'Règles invalides : délai d\'annulation de 0 à 720 heures, report de 0 à 999, preuve aucune, note ou photo.', 'rules');
        }
        foreach ($this->planBenefits()->forPlan($pb->getPlan()) as $other) {
            if ($other !== $pb && $other->getBenefit()?->getId() === $benefit->getId()) {
                throw new AboException(422, 'PLAN_BENEFIT_DUPLICATE', 'Cette prestation est déjà dans la formule : changez sa quantité.', 'benefit');
            }
        }
    }

    /** Une prestation utilisée par une formule ne se supprime pas : elle se désactive. @throws AboException 409 */
    public function assertDeletable(Benefit $benefit): void
    {
        if ($benefit->getId() !== null && $this->planBenefits()->isUsed($benefit)) {
            throw new AboException(409, 'BENEFIT_IN_USE', 'Cette prestation est livrée par une formule : désactivez-la plutôt que de la supprimer.');
        }
        // lot ABO-1 : des droits d'abonnés (même passés) la citent : leur historique est gardé, la prestation se désactive
        if ($benefit->getId() !== null && $this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT 1 FROM benefit_occurrence WHERE benefit_id = ? LIMIT 1', [$benefit->getId()]) !== false) {
            throw new AboException(409, 'BENEFIT_IN_USE', 'Des abonnés ont des droits sur cette prestation : désactivez-la plutôt que de la supprimer.');
        }
    }

    /**
     * Crée ou modifie une prestation (assistant du site après confirmation) ; mêmes règles que l'écran.
     *
     * @param array{kind?: string, names?: array<string, string>, descriptions?: ?array<string, string>, durationMinutes?: ?int, location?: ?string, active?: bool} $data
     * @throws AboException
     */
    public function saveBenefit(?Benefit $benefit, array $data): Benefit
    {
        $benefit ??= new Benefit();
        if (array_key_exists('kind', $data)) {
            $benefit->setKind((string) $data['kind']);
        }
        if (array_key_exists('names', $data)) {
            $benefit->setNames(is_array($data['names']) ? $data['names'] : []);
        }
        if (array_key_exists('descriptions', $data)) {
            $benefit->setDescriptions(is_array($data['descriptions']) ? $data['descriptions'] : null);
        }
        if (array_key_exists('durationMinutes', $data)) {
            $benefit->setDurationMinutes($data['durationMinutes'] !== null ? (int) $data['durationMinutes'] : null);
        }
        if (array_key_exists('location', $data)) {
            $benefit->setLocation($data['location'] !== null ? (string) $data['location'] : null);
        }
        if (array_key_exists('active', $data)) {
            $benefit->setActive((bool) $data['active']);
        }
        $this->validateBenefit($benefit);
        $em = $this->emProvider->getEntityManager();
        $em->persist($benefit);
        $em->flush();

        return $benefit;
    }

    /**
     * Règle une prestation dans une formule (création ou mise à jour de la ligne) ; mêmes règles que l'écran.
     *
     * @param array{quantity?: ?int, scheduling?: string, slotChoice?: ?string, cancelDeadlineHours?: int, carryOverMax?: ?int, proof?: string} $rules
     * @throws AboException
     */
    public function setPlanBenefit(SubscriptionPlan $plan, Benefit $benefit, array $rules, bool $flush = true): PlanBenefit
    {
        $pb = null;
        foreach ($this->planBenefits()->forPlan($plan) as $row) {
            if ($row->getBenefit()?->getId() === $benefit->getId()) {
                $pb = $row;
            }
        }
        $pb ??= (new PlanBenefit())->setPlan($plan)->setBenefit($benefit);
        if (array_key_exists('quantity', $rules)) {
            $pb->setQuantity($rules['quantity'] !== null ? (int) $rules['quantity'] : null);
        }
        foreach (['scheduling' => 'setScheduling', 'proof' => 'setProof'] as $key => $setter) {
            if (array_key_exists($key, $rules)) {
                $pb->$setter((string) $rules[$key]);
            }
        }
        if (array_key_exists('slotChoice', $rules)) {
            $pb->setSlotChoice($rules['slotChoice'] !== null ? (string) $rules['slotChoice'] : null);
        }
        if (array_key_exists('cancelDeadlineHours', $rules)) {
            $pb->setCancelDeadlineHours((int) $rules['cancelDeadlineHours']);
        }
        if (array_key_exists('carryOverMax', $rules)) {
            $pb->setCarryOverMax($rules['carryOverMax'] !== null ? (int) $rules['carryOverMax'] : null);
        }
        $this->validatePlanBenefit($pb);
        $em = $this->emProvider->getEntityManager();
        $em->persist($pb);
        if ($flush) {
            $em->flush();
        }

        return $pb;
    }

    /**
     * La prestation d'une formule telle que l'abonné et l'équipe la lisent (grille publique, suivi, assistant) : jamais l'intervenant.
     *
     * @return array<string, mixed>
     */
    public function view(PlanBenefit $pb, string $locale): array
    {
        $b = $pb->getBenefit();

        return [
            'id' => (int) $pb->getId(), 'benefitId' => (int) $b?->getId(), 'kind' => (string) $b?->getKind(), 'name' => (string) $b?->getName($locale),
            'description' => $b?->getDescription($locale), 'durationMinutes' => $b?->getDurationMinutes(), 'location' => $b?->getLocation(),
            'quantity' => $pb->getQuantity(), 'scheduling' => $pb->getScheduling(), 'slotChoice' => $pb->getSlotChoice(),
            'cancelDeadlineHours' => $pb->getCancelDeadlineHours(), 'carryOverMax' => $pb->getEffectiveCarryOver(), 'proof' => $pb->getProof(),
            'summary' => $this->summary($pb, $locale),
        ];
    }

    /** @return list<array<string, mixed>> les prestations d'une formule, dans l'ordre */
    public function viewsFor(SubscriptionPlan $plan, string $locale): array
    {
        return array_map(fn (PlanBenefit $pb) => $this->view($pb, $locale), $this->planBenefits()->forPlan($plan));
    }

    /**
     * « Ce que verra l'abonné », calculé par le serveur (fr ou en). Exemple : « 4 séances Yoga par mois ; vous choisissez vos créneaux ;
     * annulation sans frais jusqu'à 24 h avant ; jusqu'à 4 reportées à la période suivante. »
     */
    public function summary(PlanBenefit $pb, string $locale): string
    {
        $en = str_starts_with($locale, 'en');
        $b = $pb->getBenefit();
        $plan = $pb->getPlan();
        $name = (string) $b?->getName($locale);
        $period = self::period((string) $plan?->getInterval(), (int) $plan?->getIntervalCount(), $en);
        $q = $pb->getQuantity();
        $unit = match ($b?->getKind()) {
            Benefit::KIND_SESSION => $en ? ($q === 1 ? 'session' : 'sessions') : ($q === 1 ? 'séance' : 'séances'),
            Benefit::KIND_SERVICE => $en ? ($q === 1 ? 'service' : 'services') : ($q === 1 ? 'intervention' : 'interventions'),
            default => $en ? ($q === 1 ? 'use' : 'uses') : ($q === 1 ? 'utilisation' : 'utilisations'),
        };
        $parts = [$q === null
            ? ($en ? sprintf('Unlimited %s', $name) : sprintf('%s sans limite', $name))
            : sprintf($en ? '%d %s %s per %s' : '%d %s %s par %s', $q, $unit, $name, $period)];
        $parts[] = match ($pb->getScheduling()) {
            PlanBenefit::SCHEDULING_CLIENT => $en ? 'you choose your dates when subscribing' : 'vous choisissez vos dates à la souscription',
            PlanBenefit::SCHEDULING_ADMIN => $en ? 'dates set by the team' : 'dates fixées par l\'équipe',
            PlanBenefit::SCHEDULING_SLOTS => $pb->getSlotChoice() === 'client'
                ? ($en ? 'you book your slots' : 'vous choisissez vos créneaux')
                : ($en ? 'slots assigned by the team' : 'créneaux attribués par l\'équipe'),
            default => null,
        };
        if ($pb->getScheduling() !== PlanBenefit::SCHEDULING_NONE) {
            $parts[] = sprintf($en ? 'free cancellation up to %d h before' : 'annulation sans frais jusqu\'à %d h avant', $pb->getCancelDeadlineHours());
        }
        $carry = $pb->getEffectiveCarryOver();
        if ($q !== null && $carry !== null && $carry > 0) {
            $parts[] = sprintf($en ? 'up to %d carried over to the next period' : 'jusqu\'à %d reportées à la période suivante', $carry);
        }
        $text = implode(' ; ', array_filter($parts));
        if ($en) {
            $text = str_replace(' ; ', '; ', $text);
        }

        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1) . '.';
    }

    private static function period(string $interval, int $count, bool $en): string
    {
        $unit = match ($interval) {
            'week' => $en ? 'week' : 'semaine',
            'year' => $en ? 'year' : 'an',
            default => $en ? 'month' : 'mois',
        };
        if ($count <= 1) {
            return $unit;
        }

        return $en ? sprintf('%d %ss', $count, $unit) : sprintf('%d %s', $count, $unit === 'mois' ? 'mois' : $unit . 's');
    }
}
