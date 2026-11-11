<?php

namespace App\Gestion\Services;

use App\Entity\RentalPack;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantCacheService;
use App\Services\TenantEntityManagerProvider;

/**
 * Changement d'une grille de location depuis la gestion (lot LOC4, 11/11/2026, `docs/locations-gestion.md` § 4, contrat LOC validé par
 * l'application). Une grille (`rental_pack`) est rattachée à des CATÉGORIES : la changer change le prix de tous les articles louables de ces
 * catégories. L'aperçu n'écrit rien ; l'application exige l'empreinte et, quand la grille sert à plus d'un article, `allowShared`
 * (409 SHARED_GRID avec la liste des articles) ; elle écrit la grille par l'entité (comme le questionnaire `RentalConfiguratorService` :
 * cache du site vidé, journal d'audit) et garde les anciens tarifs ; annulation dans les 24 h si la grille n'a pas été retouchée.
 * Les réservations déjà faites gardent leur prix (figé dans la ligne de commande).
 */
class GestionRentalGridService
{
    /** clé de l'API => suffixe des accesseurs de RentalPack (mêmes clés que le questionnaire de location) */
    public const RATES = ['hour' => 'HourRate', 'halfDay' => 'HalfDayRate', 'day' => 'DayRate', 'week' => 'WeekRate', 'month' => 'MonthRate'];
    public const PERCENT_RANGE = [-50, 200];
    public const ROUNDINGS = ['none', 'dollar'];
    public const BIG_CHANGE = 30;
    public const UNDO_HOURS = 24;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly ContentAuditRecorder $audit,
        private readonly ?TenantCacheService $cache = null,
    ) {
    }

    /** GET /api/gestion/locations/grilles : grilles, tarifs (cents) et articles louables qui les utilisent */
    public function list(): array
    {
        return array_map(fn (RentalPack $p) => ['id' => (int) $p->getId(), 'name' => $p->getName(), 'rates' => self::rates($p), 'products' => $this->products((int) $p->getId())],
            $this->emProvider->getEntityManager()->getRepository(RentalPack::class)->findBy([], ['id' => 'ASC']));
    }

    /** POST /api/gestion/locations/grilles/apercu : aucune écriture */
    public function preview(array $body): array
    {
        [$pack, $in] = $this->input($body);
        $before = self::rates($pack);
        $after = $before;
        foreach (self::RATES as $k => $_) {
            if ($in['rates'] !== null && array_key_exists($k, $in['rates'])) {
                $after[$k] = $in['rates'][$k];
            } elseif ($in['percent'] !== null && $before[$k] !== null) {
                $v = $before[$k] * (100 + $in['percent']) / 100;
                $after[$k] = $in['rounding'] === 'dollar' ? (int) (round($v / 100) * 100) : (int) round($v);
            }
        }
        $changes = [];
        foreach ($before as $k => $v) {
            if ($v !== $after[$k]) {
                $changes[] = ['type' => $k, 'before' => $v, 'after' => $after[$k], 'percent' => $v !== null && $v > 0 && $after[$k] !== null ? round(($after[$k] - $v) / $v * 100, 1) : null];
            }
        }
        $products = $this->products((int) $pack->getId());
        $warnings = [];
        if (count($products) > 1) {
            $warnings[] = 'SHARED_GRID';
        }
        if (array_filter($changes, fn ($c) => $c['percent'] !== null && abs($c['percent']) > self::BIG_CHANGE) !== []) {
            $warnings[] = 'BIG_CHANGE';
        }

        return [
            'pack' => ['id' => (int) $pack->getId(), 'name' => $pack->getName()], 'before' => $before, 'after' => $after, 'changes' => $changes,
            'products' => $products, 'shared' => count($products) > 1, 'warnings' => $warnings,
            'previewHash' => hash('sha256', (string) json_encode([$in, $before, $after, array_column($products, 'productId')])),
        ];
    }

    /** POST /api/gestion/locations/grilles/appliquer : empreinte, accord pour une grille partagée, écriture par l'entité, journal */
    public function apply(array $body, ?string $user): array
    {
        $hash = $body['previewHash'] ?? null;
        if (!is_string($hash) || $hash === '') {
            throw new GestionValidationException(['previewHash' => 'REQUIRED']);
        }
        $preview = $this->preview($body);
        if (!hash_equals($preview['previewHash'], $hash)) {
            throw new GestionConflictException('PRICES_CHANGED', 'La grille ou ses articles ont changé depuis l\'aperçu : voici l\'aperçu à jour.', 409, ['preview' => $preview]);
        }
        if ($preview['shared'] && ($body['allowShared'] ?? false) !== true) {
            throw new GestionConflictException('SHARED_GRID', sprintf('Cette grille sert à %d articles : son changement les touche tous. Confirmez avec allowShared, ou passez par une promotion de location sur un seul article.', count($preview['products'])),
                409, ['products' => $preview['products'], 'preview' => $preview]);
        }
        if ($preview['changes'] === []) {
            throw new GestionConflictException('NOTHING_TO_CHANGE', 'Les tarifs demandés sont déjà ceux de la grille.', 422, ['preview' => $preview]);
        }
        $em = $this->emProvider->getEntityManager();
        $pack = $em->find(RentalPack::class, $preview['pack']['id']);
        self::write($pack, $preview['after']);
        $em->flush();
        $this->cache?->invalidateTenant();
        $id = (int) $em->getConnection()->fetchOne(
            'INSERT INTO gestion_rental_grid_change (rental_pack_id, before_rates, after_rates, product_ids, advice_id, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW()) RETURNING id',
            [$preview['pack']['id'], json_encode($preview['before']), json_encode($preview['after']), json_encode(array_column($preview['products'], 'productId')), is_int($body['adviceId'] ?? null) ? $body['adviceId'] : null, $user]
        );
        $this->audit->record('rental_pack', $preview['pack']['id'], 'update', ['rates' => $preview['before']], ['rates' => $preview['after'], 'by' => $user, 'source' => 'gestion'], ['rates']);

        return $this->change($id);
    }

    /** POST /api/gestion/locations/grilles/annuler { changeId } : dans les 24 h, si la grille n'a pas été retouchée depuis */
    public function undo(mixed $changeId, ?string $user): array
    {
        if (!is_int($changeId) || $changeId <= 0) {
            throw new GestionValidationException(['changeId' => $changeId === null ? 'REQUIRED' : 'INVALID_ID']);
        }
        $row = $this->conn()->fetchAssociative('SELECT * FROM gestion_rental_grid_change WHERE id = ?', [$changeId]);
        if ($row === false) {
            throw new GestionConflictException('CHANGE_NOT_FOUND', 'Changement de grille introuvable.', 404, ['changeId' => $changeId]);
        }
        if ($row['undone_at'] !== null) {
            throw new GestionConflictException('ALREADY_UNDONE', 'Ce changement de grille est déjà annulé.', 409, ['changeId' => $changeId]);
        }
        if (new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC')) < new \DateTimeImmutable('-' . self::UNDO_HOURS . ' hours', new \DateTimeZone('UTC'))) {
            throw new GestionConflictException('UNDO_EXPIRED', 'L\'annulation n\'est possible que dans les 24 heures.', 409, ['changeId' => $changeId]);
        }
        $em = $this->emProvider->getEntityManager();
        $pack = $em->find(RentalPack::class, (int) $row['rental_pack_id']);
        $after = json_decode((string) $row['after_rates'], true) ?: [];
        if ($pack === null || self::rates($pack) != $after) {
            throw new GestionConflictException('GRID_EDITED_SINCE', 'La grille a été retouchée depuis : rien n\'est annulé.', 409, ['changeId' => $changeId, 'current' => $pack === null ? null : self::rates($pack)]);
        }
        $before = json_decode((string) $row['before_rates'], true) ?: [];
        self::write($pack, $before);
        $em->flush();
        $this->cache?->invalidateTenant();
        $this->conn()->executeStatement('UPDATE gestion_rental_grid_change SET undone_at = NOW(), undone_by = ? WHERE id = ?', [$user, $changeId]);
        $this->audit->record('rental_pack', (int) $row['rental_pack_id'], 'update', ['rates' => $after], ['rates' => $before, 'by' => $user, 'source' => 'gestion_undo'], ['rates']);

        return $this->change($changeId);
    }

    /** GET /api/gestion/locations/grilles/modifications */
    public function changes(int $limit): array
    {
        $ids = $this->conn()->fetchFirstColumn('SELECT id FROM gestion_rental_grid_change ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)));

        return array_map(fn ($id) => $this->change((int) $id), $ids);
    }

    // ----- interne -----

    private function change(int $id): array
    {
        $r = $this->conn()->fetchAssociative('SELECT c.*, rp.name FROM gestion_rental_grid_change c LEFT JOIN rental_pack rp ON rp.id = c.rental_pack_id WHERE c.id = ?', [$id]);
        $atom = fn (?string $v) => $v === null ? null : (new \DateTimeImmutable($v, new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $created = new \DateTimeImmutable((string) $r['created_at'], new \DateTimeZone('UTC'));

        return [
            'id' => (int) $r['id'], 'pack' => ['id' => (int) $r['rental_pack_id'], 'name' => $r['name']], 'before' => json_decode((string) $r['before_rates'], true), 'after' => json_decode((string) $r['after_rates'], true),
            'productIds' => json_decode((string) $r['product_ids'], true), 'adviceId' => $r['advice_id'] === null ? null : (int) $r['advice_id'], 'createdBy' => $r['created_by'],
            'createdAt' => $created->format(\DateTimeInterface::ATOM), 'undoUntil' => $r['undone_at'] === null ? $created->modify('+' . self::UNDO_HOURS . ' hours')->format(\DateTimeInterface::ATOM) : null,
            'undoneAt' => $atom($r['undone_at']), 'undoneBy' => $r['undone_by'],
        ];
    }

    /** @return list<array{productId: int, name: string}> articles louables des catégories de la grille */
    private function products(int $packId): array
    {
        return array_map(fn ($r) => ['productId' => (int) $r['id'], 'name' => (string) $r['name']], $this->conn()->fetchAllAssociative(
            'SELECT DISTINCT p.id, p.name FROM product p JOIN product_categories pc ON pc.product_id = p.id JOIN rental_pack_categories rc ON rc.categories_id = pc.categories_id'
            . ' WHERE rc.rental_pack_id = ? AND COALESCE(p.rental_enabled, false) ORDER BY p.name, p.id', [$packId]
        ));
    }

    /** @return array<string, ?int> tarifs de la grille en cents (null = pas de tarif) */
    private static function rates(RentalPack $p): array
    {
        $out = [];
        foreach (self::RATES as $k => $suffix) {
            $v = $p->{'get' . $suffix}();
            $out[$k] = $v === null || (float) $v <= 0 ? null : (int) round((float) $v);
        }

        return $out;
    }

    private static function write(RentalPack $p, array $rates): void
    {
        foreach (self::RATES as $k => $suffix) {
            $p->{'set' . $suffix}(($rates[$k] ?? null) === null ? null : (float) $rates[$k]);
        }
    }

    /** @return array{0: RentalPack, 1: array{rates: ?array<string, ?int>, percent: ?int, rounding: string}} */
    private function input(array $body): array
    {
        $errors = [];
        $id = $body['rentalPackId'] ?? null;
        $pack = null;
        if (!is_int($id) || $id <= 0) {
            $errors['rentalPackId'] = $id === null ? 'REQUIRED' : 'INVALID_ID';
        } else {
            $pack = $this->emProvider->getEntityManager()->find(RentalPack::class, $id);
        }
        $rates = $body['rates'] ?? null;
        $percent = $body['percent'] ?? null;
        if (($rates === null) === ($percent === null)) {
            $errors['rates'] = 'RATES_OR_PERCENT';
        }
        if ($rates !== null) {
            if (!is_array($rates) || $rates === [] || array_is_list($rates)) {
                $errors['rates'] = 'MUST_BE_OBJECT';
            } else {
                foreach ($rates as $k => $v) {
                    if (!array_key_exists($k, self::RATES)) {
                        $errors["rates.$k"] = 'UNKNOWN_RATE';
                    } elseif ($v !== null && (!is_int($v) || $v <= 0)) {
                        $errors["rates.$k"] = 'MUST_BE_POSITIVE_INTEGER_CENTS';
                    }
                }
            }
        }
        if ($percent !== null && (!is_int($percent) || $percent < self::PERCENT_RANGE[0] || $percent > self::PERCENT_RANGE[1] || $percent === 0)) {
            $errors['percent'] = is_int($percent) ? 'OUT_OF_RANGE' : 'MUST_BE_INTEGER';
        }
        $rounding = $body['rounding'] ?? 'none';
        if (!in_array($rounding, self::ROUNDINGS, true)) {
            $errors['rounding'] = 'INVALID_ROUNDING';
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors, 'Changement de grille invalide');
        }
        if ($pack === null) {
            throw new GestionConflictException('PACK_NOT_FOUND', 'Grille de location introuvable.', 404, ['rentalPackId' => $id]);
        }
        if ($rates !== null && array_filter(array_replace(self::rates($pack), $rates), fn ($v) => $v !== null) === []) {
            throw new GestionValidationException(['rates' => 'AT_LEAST_ONE_RATE'], 'La grille doit garder au moins un tarif');
        }

        return [$pack, ['rates' => $rates, 'percent' => $percent, 'rounding' => $rounding]];
    }

    private function conn(): \Doctrine\DBAL\Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }
}
