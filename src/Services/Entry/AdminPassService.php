<?php

namespace App\Services\Entry;

use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * LE LAISSEZ-PASSER D'ENTRÉE D'EASYADMIN (lot AG-3c, 17/11/2026, `docs/ecrans-et-contextes.md` § 9).
 *
 * Depuis son site d'attache, un compte d'agence (ou le compte de la plateforme) ouvre une entrée (`EntryService::enterFromAdmin`) ; le
 * serveur lui remet un laissez-passer, porté par un formulaire POST auto-soumis vers `https://<site visé>/admin/entree` : JAMAIS dans
 * une adresse (ni journal du serveur, ni historique, ni en-tête Referer). Le laissez-passer est :
 * - SIGNÉ (HMAC-SHA256, clé du noyau, contexte propre) ; toute modification le rend illisible ;
 * - COURT : 60 secondes ;
 * - pour UN site : présenté ailleurs, il est refusé ;
 * - à USAGE UNIQUE : son numéro est marqué dans Redis (`SET NX`) au premier passage ; Redis injoignable = refus (fermé par défaut).
 * Il ne crée rien : l'entrée qu'il porte est relue dans la base maître par `EntryService` à chaque requête de la session ouverte.
 */
class AdminPassService
{
    public const TTL = 60;
    private const CONTEXT = 'admin-pass|v1|';
    private const USED_PREFIX = 'admin_pass_used:';
    private ?\Redis $redis = null;

    public function __construct(
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        #[Autowire('%env(REDIS_URL)%')] private readonly string $redisUrl,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array{entry: int, mode: string, home: string, target: string, otpAt: int, account?: int, email?: string} $claims
     */
    public function issue(array $claims): string
    {
        $body = $claims + ['exp' => time() + self::TTL, 'nonce' => bin2hex(random_bytes(16))];
        $encoded = self::b64(json_encode($body, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));

        return $encoded . '.' . self::b64(hash_hmac('sha256', self::CONTEXT . $encoded, $this->secret, true));
    }

    /**
     * Le laissez-passer présenté sur le site courant : ses revendications, ou `EntryException` (raison : `pass_malformed`,
     * `pass_signature`, `pass_expired`, `pass_site`, `pass_replayed`, `pass_unavailable`). La marque d'usage unique est posée en
     * DERNIER, une fois tout le reste vérifié : un laissez-passer refusé pour une autre raison ne se consume pas.
     *
     * @return array{entry: int, mode: string, home: string, target: string, otpAt: int, account?: int, email?: string, exp: int, nonce: string}
     * @throws EntryException
     */
    public function consume(mixed $pass, ?string $currentSite): array
    {
        if (!is_string($pass) || strlen($pass) > 4096 || substr_count($pass, '.') !== 1) {
            throw new EntryException('ENTRY_FORBIDDEN', 403, 'pass_malformed');
        }
        [$encoded, $signature] = explode('.', $pass);
        $expected = self::b64(hash_hmac('sha256', self::CONTEXT . $encoded, $this->secret, true));
        if (!hash_equals($expected, $signature)) {
            throw new EntryException('ENTRY_FORBIDDEN', 403, 'pass_signature');
        }
        $claims = json_decode((string) self::unb64($encoded), true);
        if (!is_array($claims) || !is_int($claims['entry'] ?? null) || !is_string($claims['mode'] ?? null) || !is_string($claims['home'] ?? null)
            || !is_string($claims['target'] ?? null) || !is_int($claims['otpAt'] ?? null) || !is_int($claims['exp'] ?? null) || !is_string($claims['nonce'] ?? null)) {
            throw new EntryException('ENTRY_FORBIDDEN', 403, 'pass_malformed');
        }
        if ($claims['exp'] < time() || $claims['exp'] > time() + self::TTL) {
            throw new EntryException('ENTRY_FORBIDDEN', 403, 'pass_expired');
        }
        if ($currentSite === null || $currentSite === '' || $claims['target'] !== $currentSite) {
            throw new EntryException('ENTRY_FORBIDDEN', 403, 'pass_site');
        }
        $redis = $this->redis();
        if ($redis === null) {
            throw new EntryException('ENTRY_UNAVAILABLE', 503, 'pass_unavailable');
        }
        try {
            $first = $redis->set(self::USED_PREFIX . $claims['nonce'], '1', ['nx', 'ex' => self::TTL * 2]);
        } catch (\Throwable $e) {
            $this->logger->error('Laissez-passer d\'entrée : marque d\'usage unique impossible (' . $e::class . ') : refusé.');

            throw new EntryException('ENTRY_UNAVAILABLE', 503, 'pass_unavailable');
        }
        if ($first !== true) {
            throw new EntryException('ENTRY_FORBIDDEN', 403, 'pass_replayed');
        }

        return $claims;
    }

    private function redis(): ?\Redis
    {
        if ($this->redis !== null) {
            return $this->redis;
        }
        try {
            $connection = RedisAdapter::createConnection($this->redisUrl, ['timeout' => 1.5]);

            return $this->redis = $connection instanceof \Redis ? $connection : null;
        } catch (\Throwable $e) {
            $this->logger->error('Laissez-passer d\'entrée : Redis injoignable (' . $e::class . ').');

            return null;
        }
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $text): string|false
    {
        return base64_decode(strtr($text, '-_', '+/'), true);
    }
}
