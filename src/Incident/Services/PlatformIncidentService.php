<?php

namespace App\Incident\Services;

use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\Incident\Repository\PlatformIncidentRepository;
use App\Services\Privacy\PersonalDataMask;
use App\Services\TenantConnectionManager;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Incidents de la plateforme (lot INC-1a, 18/11/2026, `docs/incidents-et-signalements.md`) :
 * - `report` : une application signale une erreur (`POST /api/incidents/report`). Le site vient de la requête (hôte ou jeton), jamais
 *   du corps ; l'empreinte est calculée ici, jamais reprise du corps ; le message et le contexte sont masqués (`PersonalDataMask`), le
 *   contexte passe par une liste blanche (2 Ko au plus). Sans jeton : limite par site et par IP, et au plus `DAILY_NEW_ANONYMOUS`
 *   incidents NOUVEAUX par site et par jour ; au-delà, un seul incident « plafond atteint » du site compte les rapports ;
 * - `recordServerError` : une panne du serveur (5xx), au plus une écriture par empreinte et par `WRITE_EVERY_SECONDS`, les autres
 *   comptées en cache puis écrites avec la suivante ; ne lève jamais ;
 * - lecture, mise à jour, courriel du jour et purge, pour le super administrateur.
 */
class PlatformIncidentService
{
    /** Applications qui signalent (le serveur écrit lui-même `backend`) */
    public const APPS = ['web', 'boutique', 'pos', 'mobile', 'livingmemory', 'gestion', 'boussole', 'memoires']; // boussole, memoires : INC-2 (18/11/2026)
    /** INC-2 : `context.repeats`, les répétitions de la minute regroupées par l'application, ajoutées au compte de l'incident */
    public const MAX_REPEATS = 1000;
    public const DAILY_NEW_ANONYMOUS = 50;
    public const WRITE_EVERY_SECONDS = 60;
    /** Champs permis du contexte, valeurs simples seulement ; tout autre champ (un nom, un courriel, un corps de requête) est retiré */
    public const CONTEXT_KEYS = ['url', 'method', 'httpStatus', 'errorCode', 'component', 'action', 'os', 'osVersion', 'device', 'locale', 'online', 'durationMs', 'stack'];
    private const CONTEXT_MAX_BYTES = 2048;

    public function __construct(
        private readonly PlatformIncidentRepository $incidents,
        private readonly IncidentLimits $limits,
        private readonly TenantConnectionManager $tenants,
        #[Autowire(service: 'cache.app')] private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
    ) {
    }

    /**
     * @param array<string, mixed> $body `{ app, appVersion?, screen?, route?, level?, message, context? }`
     *
     * @return array{incidentId: int, fingerprint: string}
     *
     * @throws GestionValidationException 422 `fields`, GestionConflictException 429 `RATE_LIMITED`
     */
    public function report(array $body, ?int $userId, string $ip, ?string $requestId): array
    {
        $site = $this->tenants->getCurrentTenantCode();
        $wait = $userId !== null ? $this->limits->user($site, $userId) : $this->limits->anonymous($site, $ip);
        if ($wait !== null) {
            throw new GestionConflictException('RATE_LIMITED', 'Trop de rapports. Réessayez plus tard.', 429, ['retryAfterSeconds' => $wait]);
        }
        $row = $this->checked($body, $site, $userId === null);
        $now = gmdate('Y-m-d H:i:s');
        if ($userId === null && !$this->incidents->exists($row['fingerprint'])
            && $this->incidents->anonymousCreatedSince($site, gmdate('Y-m-d 00:00:00')) >= self::DAILY_NEW_ANONYMOUS) {
            $row = ['repeats' => $row['repeats']] + $this->overflow($site); // plafond du jour atteint : on compte sans créer
        }
        $saved = $this->incidents->upsert($row, $row['repeats'], $now);
        $this->incidents->addEvent($saved['id'], $now, $row['appVersion'], $userId !== null ? $this->userHash($userId) : null, self::requestId($requestId), $row['repeats']);

        return ['incidentId' => $saved['id'], 'fingerprint' => $row['fingerprint']];
    }

    /** Une panne du serveur (5xx) : jamais d'exception, jamais de corps ni d'en-tête de la requête */
    public function recordServerError(?\Throwable $error, int $status, ?string $route, ?string $requestId): void
    {
        try {
            $site = $this->tenants->getCurrentTenantCode();
            $message = $error !== null ? self::serverMessage($error) : 'HTTP ' . $status;
            $place = $route !== null && $route !== '' ? mb_substr($route, 0, 160) : null;
            $fingerprint = self::fingerprint($site, 'backend', $place, $message);
            $item = $this->cache->getItem('platform_incident_w_' . $fingerprint);
            $state = $item->isHit() && is_array($item->get()) ? $item->get() : null;
            if ($state !== null && (int) $state['at'] > time() - self::WRITE_EVERY_SECONDS) {
                $item->set(['at' => (int) $state['at'], 'pending' => (int) $state['pending'] + 1]);
                $item->expiresAfter(3600);
                $this->cache->save($item);

                return;
            }
            $count = 1 + (int) ($state['pending'] ?? 0);
            $context = ['httpStatus' => $status] + ($error !== null ? ['exception' => $error::class] : []);
            $now = gmdate('Y-m-d H:i:s');
            $saved = $this->incidents->upsert(['fingerprint' => $fingerprint, 'tenant' => $site, 'app' => 'backend', 'appVersion' => null, 'screen' => null, 'route' => $place,
                'level' => 'error', 'message' => mb_substr($message, 0, 200), 'context' => (string) json_encode($context), 'anonymous' => false], $count, $now);
            $this->incidents->addEvent($saved['id'], $now, null, null, self::requestId($requestId), $count);
            $item->set(['at' => time(), 'pending' => 0]);
            $item->expiresAfter(3600);
            $this->cache->save($item);
        } catch (\Throwable $e) {
            $this->logger->warning('Incident du serveur non enregistré (' . $e::class . ').');
        }
    }

    /**
     * @param array{status?: ?string, app?: ?string, tenant?: ?string, level?: ?string} $filters
     *
     * @return array{incidents: list<array<string, mixed>>, total: int, page: int, limit: int}
     */
    public function list(array $filters, int $page, int $limit): array
    {
        $errors = [];
        foreach (['status' => PlatformIncidentRepository::STATUSES, 'app' => [...self::APPS, 'backend'], 'level' => PlatformIncidentRepository::LEVELS] as $key => $allowed) {
            if (($filters[$key] ?? null) !== null && !in_array($filters[$key], $allowed, true)) {
                $errors[$key] = 'INVALID_VALUE';
            }
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors);
        }
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        $found = $this->incidents->search($filters, $limit, ($page - 1) * $limit);

        return ['incidents' => array_map(self::view(...), $found['items']), 'total' => $found['total'], 'page' => $page, 'limit' => $limit];
    }

    /** @return array<string, mixed> l'incident et ses dernières occurrences ; 404 `INCIDENT_NOT_FOUND` */
    public function get(int $id): array
    {
        $row = $this->incidents->find($id) ?? throw new GestionConflictException('INCIDENT_NOT_FOUND', 'Incident introuvable.', 404);

        return self::view($row) + ['events' => array_map(fn (array $e): array => ['occurredAt' => self::date($e['occurred_at']), 'appVersion' => $e['app_version'],
            'userHash' => $e['user_hash'], 'requestId' => $e['request_id'], 'repeats' => (int) $e['repeats']], $this->incidents->events($id))];
    }

    /**
     * `{ status?, note?, externalUrl?, fixedIn? }` ; corriger efface la marque « revenu ».
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function update(int $id, array $body, string $by): array
    {
        $row = $this->incidents->find($id) ?? throw new GestionConflictException('INCIDENT_NOT_FOUND', 'Incident introuvable.', 404);
        $errors = [];
        $set = [];
        if (array_key_exists('status', $body)) {
            if (!in_array($body['status'], PlatformIncidentRepository::STATUSES, true)) {
                $errors['status'] = 'INVALID_VALUE';
            } elseif ($body['status'] !== $row['status']) {
                $set += ['status' => $body['status'], 'status_changed_at' => gmdate('Y-m-d H:i:s'), 'status_changed_by' => mb_substr($by, 0, 255)];
                if ($body['status'] === 'fixed') {
                    $set['reopened'] = false;
                }
            }
        }
        foreach (['note' => [2000, null], 'fixedIn' => [80, '/^[\w.\-\/#]+$/u'], 'externalUrl' => [500, null]] as $key => [$max, $pattern]) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            $value = $body[$key];
            if ($value === null || $value === '') {
                $set[self::column($key)] = null;
            } elseif (!is_string($value) || mb_strlen($value) > $max || ($pattern !== null && !preg_match($pattern, $value))
                || ($key === 'externalUrl' && (!filter_var($value, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $value)))) {
                $errors[$key] = 'INVALID_VALUE';
            } else {
                $set[self::column($key)] = trim($value);
            }
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors);
        }
        $this->incidents->update($id, $set);

        return $this->get($id);
    }

    /** @return array{new: list<array<string, mixed>>, reopened: list<array<string, mixed>>} les incidents des dernières 24 heures */
    public function digest(): array
    {
        $found = $this->incidents->digest(gmdate('Y-m-d H:i:s', time() - 86400));

        return ['new' => array_map(self::view(...), $found['new']), 'reopened' => array_map(self::view(...), $found['reopened'])];
    }

    /** @return array{0: int, 1: int} occurrences et incidents effacés */
    public function purge(): array
    {
        return $this->incidents->purge(gmdate('Y-m-d H:i:s', time() - 90 * 86400), gmdate('Y-m-d H:i:s', time() - 365 * 86400));
    }

    /** Empreinte : site, application, écran ou route, message normalisé (chiffres, identifiants et jetons sans effet sur le regroupement) */
    public static function fingerprint(?string $site, string $app, ?string $place, string $message): string
    {
        $normal = mb_strtolower($message);
        $normal = (string) preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', '{uuid}', $normal);
        $normal = (string) preg_replace('/\b[0-9a-f]*\d[0-9a-f]*\b/', '#', $normal);
        $normal = trim((string) preg_replace('/\s+/u', ' ', $normal));

        return hash('sha256', (string) json_encode([$site, $app, $place, $normal]));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{fingerprint: string, tenant: ?string, app: string, appVersion: ?string, screen: ?string, route: ?string, level: string, message: string, context: ?string, anonymous: bool, repeats: int}
     */
    private function checked(array $body, ?string $site, bool $anonymous): array
    {
        $errors = [];
        $app = $body['app'] ?? null;
        if ($app === null || $app === '') {
            $errors['app'] = 'REQUIRED';
        } elseif (!in_array($app, self::APPS, true)) {
            $errors['app'] = 'INVALID_VALUE';
        }
        $level = $body['level'] ?? 'error';
        if (!in_array($level, PlatformIncidentRepository::LEVELS, true)) {
            $errors['level'] = 'INVALID_VALUE';
        }
        $message = $body['message'] ?? null;
        if (!is_string($message) || trim($message) === '') {
            $errors['message'] = $message === null ? 'REQUIRED' : 'INVALID_VALUE';
        }
        $version = $body['appVersion'] ?? null;
        if ($version !== null && (!is_string($version) || !preg_match('/^[\w.+\-]{1,40}$/', $version))) {
            $errors['appVersion'] = 'INVALID_VALUE';
        }
        $places = [];
        foreach (['screen', 'route'] as $key) {
            $value = $body[$key] ?? null;
            if ($value !== null && (!is_string($value) || mb_strlen($value) > 500)) {
                $errors[$key] = 'INVALID_VALUE';
            }
            $places[$key] = is_string($value) && trim($value) !== '' ? PersonalDataMask::text(self::path($value), 160) : null;
        }
        $context = $body['context'] ?? null;
        $repeats = 1;
        if ($context !== null && !is_array($context)) {
            $errors['context'] = 'INVALID_VALUE';
        } elseif (is_array($context) && array_key_exists('repeats', $context)) {
            $repeats = $context['repeats'];
            if (!is_int($repeats) || $repeats < 1 || $repeats > self::MAX_REPEATS) {
                $errors['context.repeats'] = 'INVALID_VALUE';
            }
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors);
        }
        $masked = PersonalDataMask::text((string) $message, 200);

        return ['fingerprint' => self::fingerprint($site, (string) $app, $places['screen'] ?? $places['route'], $masked), 'tenant' => $site, 'app' => (string) $app,
            'appVersion' => $version, 'screen' => $places['screen'], 'route' => $places['route'], 'level' => (string) $level, 'message' => $masked,
            'context' => is_array($context) ? self::context($context) : null, 'anonymous' => $anonymous, 'repeats' => (int) $repeats];
    }

    /** Liste blanche, valeurs simples masquées ; 2 Ko au plus (la pile d'appels part la première) */
    private static function context(array $context): ?string
    {
        $kept = [];
        foreach (self::CONTEXT_KEYS as $key) {
            $value = $context[$key] ?? null;
            if (is_bool($value) || is_int($value) || is_float($value)) {
                $kept[$key] = $value;
            } elseif (is_string($value) && $value !== '') {
                $kept[$key] = PersonalDataMask::text($key === 'url' ? self::path($value) : $value, $key === 'stack' ? 1500 : 300);
            }
        }
        $json = (string) json_encode($kept, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > self::CONTEXT_MAX_BYTES) {
            unset($kept['stack']);
            $json = (string) json_encode($kept, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $kept === [] || strlen($json) > self::CONTEXT_MAX_BYTES ? null : $json;
    }

    /** Une adresse sans sa requête ni son ancre (les paramètres peuvent porter un courriel, un jeton) */
    private static function path(string $value): string
    {
        return (string) preg_replace('/[?#].*$/s', '', trim($value));
    }

    /** Le message d'une panne du serveur : sa classe et son texte masqué, sans paramètres SQL ni texte entre guillemets */
    private static function serverMessage(\Throwable $error): string
    {
        $text = (string) preg_replace('/ with params \[.*$/s', ' with params […]', $error->getMessage());
        $text = (string) preg_replace('/(["\'])(?:(?!\1).){3,}\1/u', '$1…$1', $text);

        return (new \ReflectionClass($error))->getShortName() . ': ' . PersonalDataMask::text($text, 180);
    }

    /** @return array{fingerprint: string, tenant: ?string, app: string, appVersion: ?string, screen: ?string, route: ?string, level: string, message: string, context: ?string, anonymous: bool} */
    private function overflow(?string $site): array
    {
        $message = 'Plafond du jour atteint : rapports sans jeton comptés sans créer d\'incident';

        return ['fingerprint' => hash('sha256', 'overflow|' . ($site ?? '-')), 'tenant' => $site, 'app' => 'backend', 'appVersion' => null, 'screen' => null,
            'route' => '/api/incidents/report', 'level' => 'warning', 'message' => $message, 'context' => null, 'anonymous' => false];
    }

    private function userHash(int $userId): string
    {
        return substr(hash_hmac('sha256', 'user:' . $userId, $this->secret), 0, 16);
    }

    private static function requestId(?string $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9._\-]{1,64}$/', $value) ? $value : null;
    }

    private static function column(string $key): string
    {
        return ['note' => 'note', 'fixedIn' => 'fixed_in', 'externalUrl' => 'external_url'][$key];
    }

    private static function date(?string $value): ?string
    {
        return $value === null ? null : (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    /** @param array<string, mixed> $row */
    private static function view(array $row): array
    {
        return ['id' => (int) $row['id'], 'fingerprint' => $row['fingerprint'], 'tenantCode' => $row['tenant_code'], 'app' => $row['app'], 'appVersion' => $row['app_version'],
            'screen' => $row['screen'], 'route' => $row['route'], 'level' => $row['level'], 'message' => $row['message'],
            'context' => $row['context'] !== null ? json_decode((string) $row['context'], true) : null, 'count' => (int) $row['count'],
            'anonymous' => (bool) $row['anonymous'], 'firstSeenAt' => self::date($row['first_seen_at']), 'lastSeenAt' => self::date($row['last_seen_at']),
            'status' => $row['status'], 'reopened' => (bool) $row['reopened'], 'note' => $row['note'], 'externalUrl' => $row['external_url'], 'fixedIn' => $row['fixed_in'],
            'statusChangedAt' => self::date($row['status_changed_at']), 'statusChangedBy' => $row['status_changed_by']];
    }
}
