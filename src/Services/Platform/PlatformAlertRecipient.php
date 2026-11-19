<?php

namespace App\Services\Platform;

use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\TenantConnectionManager;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * L'adresse qui REÇOIT les alertes de la plateforme (demande du propriétaire, 19/11/2026) : un réglage du super administrateur (page
 * Plateforme, `platform_settings.platform_alert_email` de la base maître), puis la variable `POS_ALERT_PLATFORM_EMAIL` du serveur en
 * secours, sinon aucun envoi. SEUL point de lecture de tous les usages : courriel du jour des incidents (`app:platform:incidents:digest`) et
 * copie des alertes de la caisse (`PosHealthService`). L'adresse n'est écrite dans aucun journal ni aucun incident ; un changement est
 * journalisé sous une forme masquée. Courriel d'essai : 3 par heure au plus.
 */
class PlatformAlertRecipient
{
    public const KEY = 'platform_alert_email';
    public const ENV = 'POS_ALERT_PLATFORM_EMAIL';
    public const MAX_LENGTH = 180;
    public const TESTS_PER_HOUR = 3;
    private const TEST_CACHE_KEY = 'platform_alert_email_tests';

    public function __construct(
        private readonly TenantConnectionManager $tenants,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'cache.app')] private readonly CacheItemPoolInterface $cache,
        private readonly ?EmailSenderService $mailer = null,
    ) {
    }

    /** L'adresse qui reçoit les alertes, ou null : alors rien ne part */
    public function get(): ?string
    {
        return $this->setting() ?? self::valid($_ENV[self::ENV] ?? $_SERVER[self::ENV] ?? getenv(self::ENV));
    }

    /**
     * Pour l'écran : l'adresse réglée (en clair, pour le super administrateur seul), son origine, et quand et par qui elle a changé.
     *
     * @return array{email: ?string, source: 'setting'|'env'|null, updatedAt: ?string, updatedBy: ?string, masked: ?string}
     */
    public function state(): array
    {
        $row = $this->row();
        $setting = self::valid($row['value'] ?? null);
        $env = self::valid($_ENV[self::ENV] ?? $_SERVER[self::ENV] ?? getenv(self::ENV));
        $effective = $setting ?? $env;

        return ['email' => $setting, 'source' => $setting !== null ? 'setting' : ($env !== null ? 'env' : null), 'updatedAt' => $row['updated_at'] ?? null,
            'updatedBy' => $row['updated_by'] ?? null, 'masked' => self::mask($effective)];
    }

    /**
     * Règle l'adresse (vide : retire le réglage, la variable du serveur reprend la main).
     *
     * @throws \InvalidArgumentException adresse invalide
     */
    public function set(?string $email, ?string $by): void
    {
        $email = trim((string) $email);
        $pdo = $this->tenants->getPdoMaster();
        if ($email === '') {
            $pdo->prepare('DELETE FROM platform_settings WHERE key = ?')->execute([self::KEY]);
            $this->logger->notice(sprintf('Adresse des alertes de la plateforme retirée (par %s)', $by ?? 'inconnu'));

            return;
        }
        if (self::valid($email) === null) {
            throw new \InvalidArgumentException('Adresse qui reçoit les alertes : une adresse de courriel valide est attendue (' . self::MAX_LENGTH . ' caractères au plus).');
        }
        $pdo->prepare('INSERT INTO platform_settings (key, value, updated_at, updated_by) VALUES (?, ?, NOW(), ?) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW(), updated_by = EXCLUDED.updated_by')
            ->execute([self::KEY, $email, $by]);
        // le journal ne porte que la forme masquée, jamais l'adresse
        $this->logger->notice(sprintf('Adresse des alertes de la plateforme : %s (par %s)', self::mask($email), $by ?? 'inconnu'));
    }

    /**
     * Courriel d'essai à l'adresse en vigueur ; 3 par heure au plus.
     *
     * @return array{sent: bool, masked: ?string, remaining: int}
     * @throws \DomainException plafond atteint, ou aucune adresse
     */
    public function sendTest(?string $by): array
    {
        $to = $this->get();
        if ($to === null) {
            throw new \DomainException('Aucune adresse ne reçoit les alertes : réglez-la d\'abord.');
        }
        $item = $this->cache->getItem(self::TEST_CACHE_KEY);
        $sent = array_values(array_filter(is_array($item->get()) ? $item->get() : [], fn ($t) => is_int($t) && $t > time() - 3600));
        if (count($sent) >= self::TESTS_PER_HOUR) {
            throw new \DomainException(sprintf('%d courriels d\'essai par heure au plus : réessayez plus tard.', self::TESTS_PER_HOUR));
        }
        $sent[] = time();
        $item->set($sent)->expiresAfter(3600);
        $this->cache->save($item);
        $ok = $this->mailer?->sendPlatformEmail($to, 'Essai : adresse des alertes de la plateforme', 'emails/platform_alert_test.html.twig', ['by' => $by]) ?? false;
        $this->logger->notice(sprintf('Courriel d\'essai des alertes de la plateforme vers %s : %s (par %s)', self::mask($to), $ok ? 'envoyé' : 'en échec', $by ?? 'inconnu'));

        return ['sent' => $ok, 'masked' => self::mask($to), 'remaining' => self::TESTS_PER_HOUR - count($sent)];
    }

    /** « m***@exemple.com » : la seule forme écrite dans un journal */
    public static function mask(?string $email): ?string
    {
        if ($email === null || !str_contains($email, '@')) {
            return null;
        }
        [$user, $domain] = explode('@', $email, 2);

        return mb_substr($user, 0, 1) . '***@' . $domain;
    }

    private function setting(): ?string
    {
        return self::valid($this->row()['value'] ?? null);
    }

    /** @return array{value?: string, updated_at?: string, updated_by?: ?string} */
    private function row(): array
    {
        try {
            $stmt = $this->tenants->getPdoMaster()->prepare('SELECT value, updated_at, updated_by FROM platform_settings WHERE key = ?');
            $stmt->execute([self::KEY]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            return is_array($row) ? $row : [];
        } catch (\PDOException) {
            return []; // base maître injoignable : la variable du serveur sert de secours
        }
    }

    private static function valid(mixed $email): ?string
    {
        $email = is_string($email) ? trim($email) : '';

        return $email !== '' && mb_strlen($email) <= self::MAX_LENGTH && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
