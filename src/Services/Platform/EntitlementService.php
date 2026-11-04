<?php

namespace App\Services\Platform;

use App\Message\EntitlementChangedMessage;
use App\Services\FeatureAccess\FeatureCatalog;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Droits d'usage d'un site sur les fonctionnalités de la plateforme (console de pilotage, lot 3, 04/11/2026). Un droit vivant
 * (`active`, ou `grace` après impayé) non échu rend la fonctionnalité effective ; chaque changement est projeté sur la fiche
 * Entreprise du site (EntitlementProjector), journalisé (tenant_entitlement_events) et, s'il concerne le site, notifié par
 * courriel (EntitlementChangedMessage). Un seul droit vivant par fonctionnalité : accorder remplace le précédent (statut
 * `cancelled`, note). Les politiques (essai, gratuité, grâce) viennent de FeaturePolicyRepository. En mode `demo` de la plateforme
 * les garde-fous ne lisent pas ces droits, mais la projection et la console fonctionnent de la même façon.
 */
class EntitlementService
{
    public const EVENT_GRANTED = 'granted';
    public const EVENT_TRIAL = 'trial_started';
    public const EVENT_EXPIRING_7 = 'expiring_7';
    public const EVENT_EXPIRING_1 = 'expiring_1';
    public const EVENT_EXPIRED = 'expired';
    public const EVENT_GRACE = 'grace';
    public const EVENT_SUSPENDED = 'suspended';
    public const EVENT_RESUMED = 'resumed';
    public const EVENT_CANCELLED = 'cancelled';
    public const EVENT_EXTENDED = 'extended';

    public function __construct(
        private readonly EntitlementRepository $repository,
        private readonly FeaturePolicyRepository $policies,
        private readonly EntitlementProjector $projector,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function assertGoverned(string $featureKey): void
    {
        if (!isset(FeatureCatalog::GOVERNED[$featureKey])) {
            throw new \InvalidArgumentException(sprintf('fonctionnalité « %s » inconnue ou non gouvernée par les droits d\'usage', $featureKey));
        }
    }

    /** Droit effectif maintenant (les droits échus sont passés en `expired` au passage) */
    public function isEnabled(string $tenantCode, string $featureKey): bool
    {
        return $this->effective($tenantCode)[$featureKey]['enabled'] ?? false;
    }

    /**
     * @return array<string, array{enabled: bool, id: ?int, source: ?string, status: ?string, endsAt: ?string, graceUntil: ?string, offerCode: ?string}>
     *   une entrée par fonctionnalité gouvernée
     */
    public function effective(string $tenantCode): array
    {
        $now = new \DateTimeImmutable();
        $out = [];
        foreach (array_keys(FeatureCatalog::GOVERNED) as $key) {
            $out[$key] = ['enabled' => false, 'id' => null, 'source' => null, 'status' => null, 'endsAt' => null, 'graceUntil' => null, 'offerCode' => null];
        }
        foreach ($this->repository->listForTenant($tenantCode, true) as $row) {
            $key = $row['feature_key'];
            if (!isset($out[$key]) || $out[$key]['enabled']) {
                continue;
            }
            if ($this->isDue($row, $now)) {
                $this->expireNow($row, $now);
                continue;
            }
            $out[$key] = ['enabled' => true, 'id' => (int) $row['id'], 'source' => $row['source'], 'status' => $row['status'], 'endsAt' => $row['ends_at'], 'graceUntil' => $row['grace_until'], 'offerCode' => $row['offer_code']];
        }

        return $out;
    }

    /** @return array<string, bool> */
    public function enabledMap(string $tenantCode): array
    {
        return array_map(fn (array $e) => $e['enabled'], $this->effective($tenantCode));
    }

    /**
     * Accorde un droit (remplace le droit vivant précédent de la même fonctionnalité), projette, journalise, notifie.
     * @return array<string, mixed> la ligne créée
     */
    public function grant(string $tenantCode, string $featureKey, string $source, ?\DateTimeInterface $endsAt, ?string $by, ?string $note = null, ?string $offerCode = null, ?string $reference = null, bool $notify = true): array
    {
        self::assertGoverned($featureKey);
        if (!in_array($source, EntitlementRepository::SOURCES, true)) {
            throw new \InvalidArgumentException('source : ' . implode(', ', EntitlementRepository::SOURCES) . ' attendu');
        }
        if ($endsAt !== null && $endsAt <= new \DateTimeImmutable()) {
            throw new \InvalidArgumentException('endsAt : date future attendue');
        }
        $row = $this->repository->insert($tenantCode, $featureKey, $source, $endsAt, $by, $note, $offerCode, $reference);
        foreach ($this->repository->liveFor($tenantCode, $featureKey) as $previous) {
            if ((int) $previous['id'] !== (int) $row['id']) {
                $this->repository->update((int) $previous['id'], ['status' => 'cancelled', 'note' => trim(($previous['note'] ?? '') . sprintf(' | remplacé par le droit n° %d', $row['id']))]);
            }
        }
        $this->project($tenantCode);
        $event = $source === 'trial' ? self::EVENT_TRIAL : self::EVENT_GRANTED;
        $payload = ['source' => $source, 'endsAt' => $row['ends_at'], 'by' => $by];
        $this->repository->recordEvent((int) $row['id'], $tenantCode, $featureKey, $event, $payload);
        $this->logger->notice(sprintf('[Entitlements] %s : %s accordé (%s%s) par %s', $tenantCode, $featureKey, $source, $endsAt ? ' jusqu\'au ' . $endsAt->format('Y-m-d') : '', $by ?? 'système'));
        if ($notify) {
            $this->notify($tenantCode, $featureKey, $event, (int) $row['id'], $payload);
        }

        return $row;
    }

    /** Essai gratuit selon la politique de la fonctionnalité (durée par défaut si $days est nul) */
    public function trial(string $tenantCode, string $featureKey, ?int $days, ?string $by, ?string $note = null): array
    {
        self::assertGoverned($featureKey);
        $policy = $this->policies->get($featureKey);
        if (!$policy['trial_allowed']) {
            throw new \DomainException(sprintf('Essai gratuit non autorisé pour %s', FeatureCatalog::label($featureKey)));
        }
        $days = $days ?? $policy['trial_days'];
        if ($days < 1 || $days > 730) {
            throw new \InvalidArgumentException('days : entre 1 et 730');
        }

        return $this->grant($tenantCode, $featureKey, 'trial', (new \DateTimeImmutable())->modify("+$days days"), $by, $note);
    }

    /** Gratuité permanente selon la politique */
    public function free(string $tenantCode, string $featureKey, ?string $by, ?string $note = null): array
    {
        self::assertGoverned($featureKey);
        if (!$this->policies->get($featureKey)['free_allowed']) {
            throw new \DomainException(sprintf('Gratuité permanente non autorisée pour %s', FeatureCatalog::label($featureKey)));
        }

        return $this->grant($tenantCode, $featureKey, 'free', null, $by, $note);
    }

    public function suspend(int $id, ?string $by, ?string $note = null): array
    {
        $row = $this->mustFind($id);
        if (!in_array($row['status'], EntitlementRepository::LIVE_STATUSES, true)) {
            throw new \DomainException('Seul un droit actif ou en grâce peut être suspendu');
        }
        $this->repository->update($id, ['status' => 'suspended', 'note' => self::appendNote($row, $note)]);
        $this->project($row['tenant_code']);
        $this->repository->recordEvent($id, $row['tenant_code'], $row['feature_key'], self::EVENT_SUSPENDED, ['by' => $by, 'note' => $note]);
        $this->notify($row['tenant_code'], $row['feature_key'], self::EVENT_SUSPENDED, $id, ['note' => $note]);

        return $this->mustFind($id);
    }

    public function resume(int $id, ?string $by, ?string $note = null): array
    {
        $row = $this->mustFind($id);
        if (!in_array($row['status'], ['suspended', 'grace'], true)) {
            throw new \DomainException('Seul un droit suspendu ou en grâce peut être rétabli');
        }
        $this->repository->update($id, ['status' => 'active', 'grace_until' => null, 'note' => self::appendNote($row, $note)]);
        foreach ($this->repository->liveFor($row['tenant_code'], $row['feature_key']) as $other) {
            if ((int) $other['id'] !== $id) {
                $this->repository->update((int) $other['id'], ['status' => 'cancelled', 'note' => self::appendNote($other, sprintf('remplacé par le droit n° %d rétabli', $id))]);
            }
        }
        $this->project($row['tenant_code']);
        $this->repository->recordEvent($id, $row['tenant_code'], $row['feature_key'], self::EVENT_RESUMED, ['by' => $by]);
        $this->notify($row['tenant_code'], $row['feature_key'], self::EVENT_RESUMED, $id, []);

        return $this->mustFind($id);
    }

    public function cancel(int $id, ?string $by, ?string $note = null): array
    {
        $row = $this->mustFind($id);
        if (in_array($row['status'], ['expired', 'cancelled'], true)) {
            return $row;
        }
        $this->repository->update($id, ['status' => 'cancelled', 'note' => self::appendNote($row, $note)]);
        $this->project($row['tenant_code']);
        $this->repository->recordEvent($id, $row['tenant_code'], $row['feature_key'], self::EVENT_CANCELLED, ['by' => $by, 'note' => $note]);

        return $this->mustFind($id);
    }

    public function extend(int $id, ?\DateTimeInterface $endsAt, ?string $by, ?string $note = null): array
    {
        $row = $this->mustFind($id);
        if (!in_array($row['status'], EntitlementRepository::LIVE_STATUSES, true)) {
            throw new \DomainException('Seul un droit actif ou en grâce peut être prolongé');
        }
        if ($endsAt !== null && $endsAt <= new \DateTimeImmutable()) {
            throw new \InvalidArgumentException('endsAt : date future attendue, ou vide pour un droit sans fin');
        }
        $this->repository->update($id, ['ends_at' => $endsAt, 'status' => 'active', 'grace_until' => null, 'note' => self::appendNote($row, $note)]);
        $this->project($row['tenant_code']);
        $this->repository->recordEvent($id, $row['tenant_code'], $row['feature_key'], self::EVENT_EXTENDED, ['by' => $by, 'endsAt' => $endsAt?->format('Y-m-d H:i:s')]);

        return $this->mustFind($id);
    }

    /** Impayé d'un abonnement : le droit reste effectif pendant le délai de grâce de la politique */
    public function grace(int $id, ?string $by, ?string $note = null): array
    {
        $row = $this->mustFind($id);
        if ($row['status'] !== 'active') {
            return $row;
        }
        $days = $this->policies->get($row['feature_key'])['grace_days'];
        $until = (new \DateTimeImmutable())->modify("+$days days");
        $this->repository->update($id, ['status' => 'grace', 'grace_until' => $until, 'note' => self::appendNote($row, $note)]);
        $this->repository->recordEvent($id, $row['tenant_code'], $row['feature_key'], self::EVENT_GRACE, ['by' => $by, 'graceUntil' => $until->format('Y-m-d H:i:s')]);
        $this->notify($row['tenant_code'], $row['feature_key'], self::EVENT_GRACE, $id, ['graceUntil' => $until->format(\DateTimeInterface::ATOM)]);

        return $this->mustFind($id);
    }

    /**
     * Réconciliation depuis la fiche Entreprise écrite à la main (EasyAdmin, PUT /api/entreprise par le super administrateur) :
     * indicateur vrai sans droit vivant → droit `manual` sans fin ; indicateur faux avec droit vivant → droit annulé. Aucun courriel.
     * @param array<string, bool> $flags clé gouvernée => indicateur
     * @return array{granted: list<string>, cancelled: list<string>}
     */
    public function syncFromFlags(string $tenantCode, array $flags, ?string $by): array
    {
        $effective = $this->effective($tenantCode);
        $result = ['granted' => [], 'cancelled' => []];
        foreach (FeatureCatalog::GOVERNED as $key => $column) {
            if (!array_key_exists($key, $flags)) {
                continue;
            }
            if ($flags[$key] && !$effective[$key]['enabled']) {
                $this->repository->insert($tenantCode, $key, 'manual', null, $by, 'activé sur la fiche Entreprise', null, null);
                $this->repository->recordEvent((int) $this->repository->liveFor($tenantCode, $key)[0]['id'], $tenantCode, $key, self::EVENT_GRANTED, ['source' => 'manual', 'by' => $by, 'origin' => 'entreprise']);
                $result['granted'][] = $key;
            } elseif (!$flags[$key] && $effective[$key]['enabled']) {
                foreach ($this->repository->liveFor($tenantCode, $key) as $row) {
                    $this->repository->update((int) $row['id'], ['status' => 'cancelled', 'note' => self::appendNote($row, 'désactivé sur la fiche Entreprise')]);
                    $this->repository->recordEvent((int) $row['id'], $tenantCode, $key, self::EVENT_CANCELLED, ['by' => $by, 'origin' => 'entreprise']);
                }
                $result['cancelled'][] = $key;
            }
        }

        return $result;
    }

    /** Projette les droits effectifs sur la fiche Entreprise du site */
    public function project(string $tenantCode): bool
    {
        return $this->projector->apply($tenantCode, $this->enabledMap($tenantCode));
    }

    /**
     * Balayage planifié (app:entitlements:sweep) : échéances dépassées (essai → expiré ; grâce dépassée → suspendu), rappels à J-7 et J-1.
     * @return array{expired: int, suspended: int, reminded: int}
     */
    public function sweep(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $summary = ['expired' => 0, 'suspended' => 0, 'reminded' => 0];
        foreach ($this->repository->due($now) as $row) {
            $outcome = $this->expireNow($row, $now);
            $summary[$outcome]++;
        }
        foreach ([7 => self::EVENT_EXPIRING_7, 1 => self::EVENT_EXPIRING_1] as $days => $event) {
            foreach ($this->repository->endingWithin($now, $days) as $row) {
                if ($row['source'] === 'subscription' || $this->repository->hasEvent((int) $row['id'], $event)) {
                    continue; // un abonnement se renouvelle : pas de rappel de fin d'essai
                }
                $this->repository->recordEvent((int) $row['id'], $row['tenant_code'], $row['feature_key'], $event, ['endsAt' => $row['ends_at']]);
                $this->notify($row['tenant_code'], $row['feature_key'], $event, (int) $row['id'], ['endsAt' => $row['ends_at'], 'days' => $days]);
                $summary['reminded']++;
            }
        }

        return $summary;
    }

    /** @return list<array<string, mixed>> */
    public function listForTenant(string $tenantCode): array
    {
        return $this->repository->listForTenant($tenantCode);
    }

    public function find(int $id): ?array
    {
        return $this->repository->find($id);
    }

    private function isDue(array $row, \DateTimeImmutable $now): bool
    {
        if ($row['status'] === 'active' && $row['ends_at'] !== null) {
            return new \DateTimeImmutable($row['ends_at']) <= $now;
        }
        if ($row['status'] === 'grace' && $row['grace_until'] !== null) {
            return new \DateTimeImmutable($row['grace_until']) <= $now;
        }

        return false;
    }

    /** Échéance dépassée : `expired` (fin d'un essai ou d'un droit daté) ou `suspended` (grâce dépassée) ; projection et courriel */
    private function expireNow(array $row, \DateTimeImmutable $now): string
    {
        $id = (int) $row['id'];
        $outcome = $row['status'] === 'grace' ? 'suspended' : 'expired';
        $this->repository->update($id, ['status' => $outcome]);
        $this->project($row['tenant_code']);
        $event = $outcome === 'expired' ? self::EVENT_EXPIRED : self::EVENT_SUSPENDED;
        $this->repository->recordEvent($id, $row['tenant_code'], $row['feature_key'], $event, ['at' => $now->format('Y-m-d H:i:s'), 'reason' => $outcome === 'expired' ? 'ends_at' : 'grace_until']);
        $this->logger->notice(sprintf('[Entitlements] %s : %s %s (échéance dépassée)', $row['tenant_code'], $row['feature_key'], $outcome));
        $this->notify($row['tenant_code'], $row['feature_key'], $event, $id, ['endsAt' => $row['ends_at'], 'reason' => $outcome === 'expired' ? 'ends_at' : 'grace_until']);

        return $outcome;
    }

    private function notify(string $tenantCode, string $featureKey, string $event, int $id, array $payload): void
    {
        try {
            $this->bus->dispatch(new EntitlementChangedMessage($tenantCode, $featureKey, $event, $id, $payload));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[Entitlements] notification %s de %s / %s impossible : %s', $event, $tenantCode, $featureKey, $e->getMessage()));
        }
    }

    private function mustFind(int $id): array
    {
        $row = $this->repository->find($id);
        if ($row === null) {
            throw new \OutOfBoundsException(sprintf('droit d\'usage n° %d introuvable', $id));
        }

        return $row;
    }

    private static function appendNote(array $row, ?string $note): ?string
    {
        if ($note === null || trim($note) === '') {
            return $row['note'] ?? null;
        }
        $previous = trim((string) ($row['note'] ?? ''));

        return $previous === '' ? trim($note) : $previous . ' | ' . trim($note);
    }
}
