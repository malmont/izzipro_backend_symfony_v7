<?php

namespace App\Services\CaisseService;

use App\Entity\TypeCash;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\PosAuditService\PosAuditService;
use App\Services\TenantEntityManagerProvider;

/**
 * Coupures (billets et pièces) de la devise du site, servies au POS (GET /api/caisse/denominations, 19/10/2026), et
 * contrôle des comptages `cashDetails` [{typeCash, nombreItems}] (dépôts, retraits, fermeture).
 * Un site sans aucune coupure dans sa devise reçoit une liste vide (lecture seule, jamais d'écriture à la lecture) ; les coupures
 * par défaut de la devise se créent par createDefaults() (fiche Entreprise, commande, bouton EasyAdmin ; F18 partie 1, 01/11/2026).
 */
class DenominationService
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly TenantCurrencyProvider $currency, private readonly ?PosAuditService $audit = null)
    {
    }

    /** Le site a-t-il au moins une coupure (active ou non) dans cette devise ? */
    public function hasAny(string $currency): bool
    {
        return (int) $this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM type_cash WHERE UPPER(currency) = ?', [strtoupper($currency)]) > 0;
    }

    /**
     * Coupures par défaut de la devise (DenominationDefaults), créées seulement si le site n'en a aucune dans cette devise :
     * insertions seulement, rien n'est modifié ni supprimé ; journal DENOMINATIONS_CREATED. Idempotent.
     *
     * @param string $source « entreprise » (fiche Entreprise), « easyadmin » (bouton), « command »
     * @return int nombre de coupures créées (0 : déjà présentes, ou devise sans liste par défaut)
     */
    public function createDefaults(string $currency, string $source): int
    {
        $currency = strtoupper($currency);
        $defaults = DenominationDefaults::for($currency);
        if ($defaults === [] || $this->hasAny($currency)) {
            return 0;
        }
        $em = $this->emProvider->getEntityManager();
        // Les coupures d'origine ont été copiées avec leurs identifiants : la séquence peut être en retard sur MAX(id) (séquence seulement, aucune ligne touchée)
        $sequence = $em->getClassMetadata(TypeCash::class)->sequenceGeneratorDefinition['sequenceName'] ?? 'type_cash_id_seq';
        $em->getConnection()->executeStatement(sprintf('SELECT setval(%1$s, GREATEST((SELECT COALESCE(MAX(id), 0) FROM type_cash), (SELECT last_value FROM %2$s)) + 1, false)', $em->getConnection()->quote($sequence), $sequence));
        $ids = [];
        foreach ($defaults as $d) {
            $type = (new TypeCash())->setName($d['label'])->setValue($d['valueCents'] / 100)->setValueCents($d['valueCents'])->setKind($d['kind'])->setActive($d['active'])->setCurrency($currency)->setPosition($d['position']);
            $em->persist($type);
            $ids[] = $type;
        }
        $em->flush();
        $this->audit?->record(PosAuditService::DENOMINATIONS_CREATED, ['source' => $source, 'currency' => $currency, 'count' => count($ids), 'typeCashIds' => array_map(fn (TypeCash $t) => (int) $t->getId(), $ids)]);

        return count($ids);
    }

    /** @return list<array{id: int, label: string, valueCents: int, kind: ?string, active: bool}> */
    public function list(): array
    {
        $rows = $this->emProvider->getEntityManager()->createQueryBuilder()->select('t')->from(TypeCash::class, 't')
            ->andWhere('t.currency = :currency')->setParameter('currency', $this->currency->code())
            ->orderBy('t.position', 'ASC')->addOrderBy('t.id', 'ASC')->getQuery()->getResult();

        return array_map(fn (TypeCash $t) => [
            'id' => (int) $t->getId(), 'label' => (string) $t->getName(), 'valueCents' => $t->getValueCents(), 'kind' => $t->getKind(), 'active' => $t->isActive(),
        ], $rows);
    }

    /**
     * Comptage détaillé et total (cents) ; coupure inconnue ou nombre invalide → 400 INVALID_CASH_DETAILS.
     *
     * @param mixed $cashDetails
     * @return array{lines: list<array{typeCash: int, label: string, valueCents: int, count: int, totalCents: int}>, total: int}
     */
    public function count(mixed $cashDetails): array
    {
        if (!is_array($cashDetails)) {
            throw new CashRegisterException('INVALID_CASH_DETAILS', 'cashDetails : liste [{typeCash, nombreItems}] attendue', 400);
        }
        $em = $this->emProvider->getEntityManager();
        $lines = [];
        $total = 0;
        foreach (array_values($cashDetails) as $i => $detail) {
            $typeId = is_array($detail) ? ($detail['typeCash'] ?? null) : null;
            $count = is_array($detail) ? ($detail['nombreItems'] ?? null) : null;
            if (!is_numeric($typeId) || !is_numeric($count) || (int) $count < 0 || (float) $count != (int) $count) {
                throw new CashRegisterException('INVALID_CASH_DETAILS', sprintf('cashDetails[%d] : typeCash et nombreItems (entier positif) attendus', $i), 400);
            }
            $type = $em->find(TypeCash::class, (int) $typeId);
            if (!$type) {
                throw new CashRegisterException('INVALID_CASH_DETAILS', sprintf('cashDetails[%d] : coupure %d inconnue', $i, (int) $typeId), 400);
            }
            $lineTotal = $type->getValueCents() * (int) $count;
            $total += $lineTotal;
            $lines[] = ['typeCash' => (int) $type->getId(), 'label' => (string) $type->getName(), 'valueCents' => $type->getValueCents(), 'count' => (int) $count, 'totalCents' => $lineTotal];
        }

        return ['lines' => $lines, 'total' => $total];
    }
}
