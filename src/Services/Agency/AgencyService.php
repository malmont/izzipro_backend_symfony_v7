<?php

namespace App\Services\Agency;

use App\Services\TenantPublicHostResolver;
use App\UseCase\PlatformUseCase\PlatformValidationException;

/**
 * Agences (lot AG-1, 15/11/2026, `docs/agences-et-import.md` § 3) : existence, sites gérés, suspension. Une agence est d'abord un
 * site (son « site d'attache ») ; la base maître dit seulement « ce site est le site d'attache de telle agence, et telle agence gère
 * tels sites ». Ce lot ne touche à AUCUNE base de site ni à aucun droit d'usage : rattacher un site ne change rien pour lui.
 *
 * Règles : un site n'a qu'une agence ; un site d'attache n'en sert qu'une et n'est jamais un site géré ; les sites techniques et le
 * site de contrôle ne se confient pas ; deux codes qui partagent une base ne se rattachent pas deux fois ; l'essai dure
 * `trial_days` jours et permet `max_sites` sites (forfait commun « essai »), le site d'attache non compté. La fin de l'essai est
 * affichée : rien ne se ferme tout seul dans ce lot.
 */
class AgencyService
{
    /** Sites qui ne se confient jamais à une agence, ni comme site d'attache ni comme site géré : sites techniques, site de contrôle des mises en ligne */
    public const RESERVED_SITES = ['gmasuite', 'tenantdefaut', 'demo'];
    public const DEFAULT_PLAN = 'essai';
    public const EVENT_CREATED = 'created';
    public const EVENT_SITE_ATTACHED = 'site_attached';
    public const EVENT_SITE_DETACHED = 'site_detached';
    public const EVENT_SUSPENDED = 'suspended';
    public const EVENT_RESUMED = 'resumed';
    public const OPEN_STATUSES = ['trial', 'active'];

    public function __construct(private readonly AgencyRepository $repository, private readonly TenantPublicHostResolver $hosts)
    {
    }

    /** @return list<array<string, mixed>> toutes les agences */
    public function all(): array
    {
        return array_map(fn (array $a): array => $this->view($a), $this->repository->all());
    }

    /** @return array<string, mixed> une agence, ses sites et son journal */
    public function show(int $id): array
    {
        $agency = $this->agency($id);

        return ['agency' => $this->view($agency), 'sites' => $this->sitesOf($agency), 'events' => $this->eventsOf($agency)];
    }

    /**
     * Crée une agence, en essai : `trial_days` jours, `max_sites` sites (forfait commun « essai »).
     *
     * @param array<string, mixed> $data { code, name, homeTenantCode, contactEmail?, test? }
     * @return array<string, mixed> l'agence
     */
    public function create(array $data, ?string $by): array
    {
        $errors = [];
        $code = is_string($data['code'] ?? null) ? mb_strtolower(trim($data['code'])) : '';
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,48}[a-z0-9]$/', $code) !== 1) {
            $errors[] = ['path' => 'code', 'message' => '3 à 50 caractères : lettres minuscules, chiffres, tirets'];
        }
        $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
        if (mb_strlen($name) < 2 || mb_strlen($name) > 160) {
            $errors[] = ['path' => 'name', 'message' => 'nom de 2 à 160 caractères attendu'];
        }
        $home = is_string($data['homeTenantCode'] ?? null) ? trim($data['homeTenantCode']) : '';
        $site = $home !== '' ? $this->repository->tenant($home) : null;
        if ($site === null) {
            $errors[] = ['path' => 'homeTenantCode', 'message' => 'code de site existant attendu'];
        }
        $email = $data['contactEmail'] ?? null;
        if ($email !== null && $email !== '' && (!is_string($email) || mb_strlen($email) > 180 || filter_var($email, \FILTER_VALIDATE_EMAIL) === false)) {
            $errors[] = ['path' => 'contactEmail', 'message' => 'adresse courriel valide attendue'];
        }
        if (array_key_exists('test', $data) && !is_bool($data['test'])) {
            $errors[] = ['path' => 'test', 'message' => 'booléen attendu'];
        }
        if ($errors !== []) {
            throw new PlatformValidationException($errors);
        }
        if (in_array($site['code'], self::RESERVED_SITES, true)) {
            throw new AgencyException('AGENCY_SITE_RESERVED', 409, ['tenantCode' => $site['code']]);
        }
        if ($this->repository->findByCode($code) !== null) {
            throw new AgencyException('AGENCY_CODE_TAKEN', 409);
        }
        if ($this->repository->findByHome($site['code']) !== null || $this->repository->managing($site['code']) !== null) {
            throw new AgencyException('AGENCY_HOME_TAKEN', 409, ['tenantCode' => $site['code']]);
        }
        if ($this->repository->sharesDatabase($site['code'], $site['dbname'])) {
            throw new AgencyException('AGENCY_SITE_SHARES_DATABASE', 409, ['tenantCode' => $site['code']]);
        }
        $plan = $this->repository->commonPlan(self::DEFAULT_PLAN) ?? ['code' => null, 'trial_days' => 60, 'max_sites' => 3];
        $trialEndsAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+' . $plan['trial_days'] . ' days');
        try {
            $agency = $this->repository->insert($code, $name, $site['code'], $trialEndsAt, $plan['max_sites'], $plan['code'], is_string($email) && $email !== '' ? mb_strtolower($email) : null, ($data['test'] ?? false) === true, $by);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23505') {
                throw new AgencyException('AGENCY_HOME_TAKEN', 409, ['tenantCode' => $site['code']]); // deux créations au même instant : la base n'en garde qu'une
            }
            throw $e;
        }
        $this->repository->recordEvent($agency['id'], self::EVENT_CREATED, $site['code'], ['code' => $code, 'trialDays' => $plan['trial_days'], 'maxSites' => $plan['max_sites'], 'test' => $agency['is_test']], $by);

        return $this->view($agency);
    }

    /** Rattache un site existant à l'agence. Rien ne change pour le site : ni ses droits, ni ses comptes. @return array<string, mixed> { agency, sites } */
    public function attach(int $id, mixed $tenantCode, ?string $by): array
    {
        $agency = $this->agency($id);
        $site = is_string($tenantCode) && trim($tenantCode) !== '' ? $this->repository->tenant(trim($tenantCode)) : null;
        if ($site === null) {
            throw new PlatformValidationException([['path' => 'tenantCode', 'message' => 'code de site existant attendu']]);
        }
        if (!in_array($agency['status'], self::OPEN_STATUSES, true)) {
            throw new AgencyException('AGENCY_NOT_OPEN', 409, ['status' => $agency['status']]);
        }
        if (in_array($site['code'], self::RESERVED_SITES, true)) {
            throw new AgencyException('AGENCY_SITE_RESERVED', 409, ['tenantCode' => $site['code']]);
        }
        if ($this->repository->findByHome($site['code']) !== null || $this->repository->managing($site['code']) !== null) {
            throw new AgencyException('AGENCY_SITE_TAKEN', 409, ['tenantCode' => $site['code']]);
        }
        if ($this->repository->sharesDatabase($site['code'], $site['dbname'])) {
            throw new AgencyException('AGENCY_SITE_SHARES_DATABASE', 409, ['tenantCode' => $site['code']]);
        }
        $done = $this->repository->attach($agency['id'], $site['code'], 'attached', $agency['max_sites'], $by);
        if ($done === 'limit') {
            throw new AgencyException('AGENCY_SITE_LIMIT', 409, ['maxSites' => $agency['max_sites']]);
        }
        if ($done !== 'attached') {
            throw new AgencyException('AGENCY_SITE_TAKEN', 409, ['tenantCode' => $site['code']]);
        }
        $this->repository->recordEvent($agency['id'], self::EVENT_SITE_ATTACHED, $site['code'], ['origin' => 'attached'], $by);

        return ['agency' => $this->view($agency), 'sites' => $this->sitesOf($agency)];
    }

    /** Détache un site de l'agence. @return array<string, mixed> { agency, sites } */
    public function detach(int $id, string $tenantCode, ?string $by): array
    {
        $agency = $this->agency($id);
        if (!$this->repository->detach($agency['id'], $tenantCode, $by)) {
            throw new AgencyException('AGENCY_SITE_NOT_ATTACHED', 404, ['tenantCode' => $tenantCode]);
        }
        $this->repository->recordEvent($agency['id'], self::EVENT_SITE_DETACHED, $tenantCode, null, $by);

        return ['agency' => $this->view($agency), 'sites' => $this->sitesOf($agency)];
    }

    /** Suspend une agence en essai ou active : elle lit encore, mais ne reçoit plus de site. @return array<string, mixed> l'agence */
    public function suspend(int $id, ?string $by, mixed $reason = null): array
    {
        $agency = $this->agency($id);
        if (!$this->repository->move($agency['id'], self::OPEN_STATUSES, 'suspended')) {
            throw new AgencyException('AGENCY_STATE', 409, ['status' => $this->agency($id)['status']]);
        }
        $this->repository->recordEvent($agency['id'], self::EVENT_SUSPENDED, null, ['from' => $agency['status'], 'reason' => is_string($reason) && trim($reason) !== '' ? mb_substr(trim($reason), 0, 300) : null], $by);

        return $this->view($this->agency($id));
    }

    /** Lève la suspension : l'agence revient à l'essai si sa fin n'est pas passée, sinon elle est active. @return array<string, mixed> l'agence */
    public function resume(int $id, ?string $by): array
    {
        $agency = $this->agency($id);
        $inTrial = $agency['trial_ends_at'] !== null && new \DateTimeImmutable((string) $agency['trial_ends_at'], new \DateTimeZone('UTC')) > new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $to = $inTrial ? 'trial' : 'active';
        if (!$this->repository->move($agency['id'], ['suspended'], $to)) {
            throw new AgencyException('AGENCY_STATE', 409, ['status' => $this->agency($id)['status']]);
        }
        $this->repository->recordEvent($agency['id'], self::EVENT_RESUMED, null, ['to' => $to], $by);

        return $this->view($this->agency($id));
    }

    /** @return array<string, mixed> l'agence par son identifiant ; 404 sinon */
    public function agency(int $id): array
    {
        return $this->repository->find($id) ?? throw new AgencyException('AGENCY_NOT_FOUND', 404);
    }

    /**
     * Une agence telle que les routes la rendent. Dates en ISO 8601 (UTC).
     *
     * @param array<string, mixed> $agency
     * @return array<string, mixed>
     */
    public function view(array $agency): array
    {
        $iso = static fn (?string $at): ?string => $at === null ? null : (new \DateTimeImmutable($at, new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $trialEndsAt = $agency['trial_ends_at'] !== null ? new \DateTimeImmutable((string) $agency['trial_ends_at'], new \DateTimeZone('UTC')) : null;

        return [
            'id' => $agency['id'], 'code' => $agency['code'], 'name' => $agency['name'], 'status' => $agency['status'], 'isTest' => $agency['is_test'],
            'homeSite' => $this->site((string) $agency['home_tenant_code']), 'trialEndsAt' => $iso($agency['trial_ends_at']),
            // l'essai est-il passé ? Affiché seulement : rien ne se ferme tout seul dans ce lot
            'trialEnded' => $agency['status'] === 'trial' && $trialEndsAt !== null && $trialEndsAt <= new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'maxSites' => $agency['max_sites'], 'sitesCount' => count($this->repository->sites($agency['id'])), 'planCode' => $agency['plan_code'], 'contactEmail' => $agency['contact_email'],
            'suspendedAt' => $iso($agency['suspended_at']), 'createdAt' => $iso($agency['created_at']),
        ];
    }

    /**
     * @param array<string, mixed> $agency
     * @return list<array<string, mixed>> les sites gérés : code, nom, adresse publique, origine, date de rattachement
     */
    public function sitesOf(array $agency): array
    {
        return array_map(fn (array $row): array => $this->site((string) $row['tenant_code']) + ['origin' => (string) $row['origin'],
            'attachedAt' => (new \DateTimeImmutable((string) $row['attached_at'], new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM)], $this->repository->sites($agency['id']));
    }

    /** @param array<string, mixed> $agency @return list<array<string, mixed>> */
    public function eventsOf(array $agency, int $limit = 100): array
    {
        return array_map(static fn (array $e): array => ['id' => (int) $e['id'], 'event' => (string) $e['event'], 'tenantCode' => $e['tenant_code'], 'payload' => $e['payload'], 'actor' => $e['actor'],
            'createdAt' => (new \DateTimeImmutable((string) $e['created_at'], new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM)], $this->repository->events($agency['id'], $limit));
    }

    /** @return array{code: string, name: string, host: ?string} un site tel que la plateforme le connaît (un site disparu garde son code) */
    private function site(string $code): array
    {
        $described = $this->hosts->describe($code);

        return ['code' => $code, 'name' => (string) ($described['name'] ?? $code), 'host' => isset($described['host']) ? (string) $described['host'] : null];
    }
}
