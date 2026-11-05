<?php

namespace App\Services\Platform;

use App\Services\TenantConnectionManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Domaine propre d'un site (console de pilotage, 05/11/2026) : enregistrement dans la base maître (`tenants.custom_domain`, donc
 * pris en compte aussitôt par l'annuaire des hôtes `GET /api/tenant/hosts` et la résolution hôte → site), procédure DNS à suivre
 * chez le registraire, et vérifications : le DNS pointe-t-il vers le serveur du site (adresse de `FRONTEND_BASE_DOMAIN`, ou
 * CNAME vers un hôte de la plateforme) et le site répond-il en HTTPS. États : `none` (aucun domaine), `dns_pending` (enregistré,
 * DNS pas encore bon), `dns_ok` (DNS bon, HTTPS pas encore), `live`. Aucun secret, aucune écriture hors de la ligne du site.
 */
class CustomDomainService
{
    public const STATES = ['none', 'dns_pending', 'dns_ok', 'live'];
    private const HTTPS_TIMEOUT = 6;

    public function __construct(
        private readonly TenantConnectionManager $tenants,
        #[Autowire('%env(resolve:FRONTEND_BASE_DOMAIN)%')] private readonly string $frontendBaseDomain,
        #[Autowire('%env(resolve:BACKEND_BASE_DOMAIN)%')] private readonly string $backendBaseDomain,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array<string, mixed> état complet du domaine du site */
    public function status(string $code, bool $checkHttps = true): array
    {
        $domain = null;
        try {
            $stmt = $this->tenants->getPdoMaster()->prepare('SELECT custom_domain FROM tenants WHERE code = ? LIMIT 1');
            $stmt->execute([$code]);
            $raw = $stmt->fetchColumn();
            $domain = is_string($raw) && trim($raw) !== '' ? strtolower(trim($raw)) : null; // lecture directe : jamais depuis le cache des sites
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('[Domaine] %s : lecture impossible : %s', $code, $e->getMessage()));
        }
        $expected = $this->expected($code);
        $dns = $domain !== null ? $this->checkDns($domain, $expected['ips']) : ['ok' => false, 'found' => [], 'cname' => null];
        $https = $domain !== null && $dns['ok'] && $checkHttps ? $this->checkHttps($domain) : ['ok' => false, 'status' => null, 'checked' => false];
        $state = $domain === null ? 'none' : (!$dns['ok'] ? 'dns_pending' : ($https['ok'] ? 'live' : 'dns_ok'));

        return [
            'domain' => $domain,
            'platformHost' => $code . '.' . $this->frontendBaseDomain,
            'state' => $state,
            'expected' => $expected,
            'checks' => ['dns' => $dns, 'https' => $https],
            'instructions' => $this->instructions($domain, $expected, $code),
        ];
    }

    /**
     * Enregistre le domaine (minuscules, sans `www.`) après validation : forme, pas un hôte de la plateforme, pas déjà pris.
     * @return array<string, mixed> état après enregistrement
     * @throws \InvalidArgumentException forme invalide ou hôte de la plateforme
     * @throws \DomainException domaine déjà pris par un autre site
     */
    public function set(string $code, string $domain, ?string $by): array
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim(explode('/', $domain)[0], '.');
        if (str_starts_with($domain, 'www.')) {
            $domain = substr($domain, 4);
        }
        if ($domain === '' || strlen($domain) > 253 || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            throw new \InvalidArgumentException('Domaine invalide : attendu un nom comme maboutique.com (sans http:// ni chemin).');
        }
        foreach ([$this->frontendBaseDomain, $this->backendBaseDomain] as $base) {
            $base = strtolower(trim($base));
            if ($base !== '' && ($domain === $base || str_ends_with($domain, '.' . $base))) {
                throw new \InvalidArgumentException(sprintf('Les adresses en %s sont fournies par la plateforme : indiquez votre propre domaine.', $base));
            }
        }
        $owner = $this->tenants->tenantCodeByCustomDomain($domain);
        if ($owner !== null && $owner !== $code) {
            throw new \DomainException('Ce domaine est déjà utilisé par un autre site de la plateforme.');
        }
        $this->tenants->setCustomDomain($code, $domain);
        $this->logger->notice(sprintf('[Domaine] %s : domaine propre %s enregistré par %s', $code, $domain, $by ?? 'inconnu'));

        return $this->status($code);
    }

    public function remove(string $code, ?string $by): array
    {
        $this->tenants->setCustomDomain($code, null);
        $this->logger->notice(sprintf('[Domaine] %s : domaine propre retiré par %s', $code, $by ?? 'inconnu'));

        return $this->status($code);
    }

    /** @return array{ips: list<string>, cnameTarget: string} cibles attendues : adresses du serveur des sites, ou CNAME vers l'hôte de la plateforme */
    private function expected(string $code): array
    {
        $ips = [];
        try {
            $ips = array_values(array_unique(array_filter((array) @gethostbynamel($this->frontendBaseDomain))));
        } catch (\Throwable) {
        }

        return ['ips' => $ips, 'cnameTarget' => $code . '.' . $this->frontendBaseDomain];
    }

    /** @return array{ok: bool, found: list<string>, cname: ?string} */
    private function checkDns(string $domain, array $expectedIps): array
    {
        $found = [];
        $cname = null;
        try {
            foreach ((array) @dns_get_record($domain, DNS_A) as $r) {
                if (!empty($r['ip'])) {
                    $found[] = $r['ip'];
                }
            }
            foreach ((array) @dns_get_record($domain, DNS_CNAME) as $r) {
                $cname = $r['target'] ?? null;
            }
            if ($found === []) {
                $found = array_values(array_filter((array) @gethostbynamel($domain)));
            }
        } catch (\Throwable $e) {
            $this->logger->info(sprintf('[Domaine] DNS de %s illisible : %s', $domain, $e->getMessage()));
        }
        $ok = $expectedIps !== [] && array_intersect($found, $expectedIps) !== [];
        if (!$ok && $cname !== null && (str_ends_with(strtolower($cname), '.' . strtolower($this->frontendBaseDomain)) || strtolower($cname) === strtolower($this->frontendBaseDomain))) {
            $ok = true;
        }

        return ['ok' => $ok, 'found' => array_values(array_unique($found)), 'cname' => $cname];
    }

    /** @return array{ok: bool, status: ?int, checked: bool} */
    private function checkHttps(string $domain): array
    {
        try {
            $c = curl_init('https://' . $domain . '/');
            curl_setopt_array($c, [CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => self::HTTPS_TIMEOUT, CURLOPT_CONNECTTIMEOUT => self::HTTPS_TIMEOUT, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'Arkanoa-domain-check']);
            curl_exec($c);
            $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
            $error = curl_error($c);
            curl_close($c);
            if ($error !== '') {
                $this->logger->info(sprintf('[Domaine] HTTPS de %s : %s', $domain, $error));
            }

            return ['ok' => $status >= 200 && $status < 400, 'status' => $status > 0 ? $status : null, 'checked' => true];
        } catch (\Throwable) {
            return ['ok' => false, 'status' => null, 'checked' => true];
        }
    }

    /** @return list<array{step: string, detail: string}> procédure à suivre chez le registraire */
    private function instructions(?string $domain, array $expected, string $code): array
    {
        $d = $domain ?? 'votre-domaine.com';
        $ip = $expected['ips'][0] ?? '<adresse du serveur>';

        return [
            ['step' => 'Acheter le domaine', 'detail' => 'Chez un registraire (OVH, GoDaddy, Namecheap…) ; la plateforme ne vend pas de domaines.'],
            ['step' => 'Enregistrer le domaine ici', 'detail' => sprintf('Indiquez %s dans « Mon site » : il est ajouté aussitôt à l\'annuaire de la plateforme.', $d)],
            ['step' => 'Créer les enregistrements DNS', 'detail' => sprintf('Chez le registraire, zone DNS de %s : un enregistrement A pour « @ » vers %s, et un enregistrement CNAME pour « www » vers %s (ou un second A vers %s). TTL 300 à 3600 s.', $d, $ip, $d, $ip)],
            ['step' => 'Attendre la propagation', 'detail' => 'De quelques minutes à 24 h selon le registraire. Cliquez « Vérifier » : le DNS doit pointer vers le serveur.'],
            ['step' => 'Certificat et mise en ligne', 'detail' => sprintf('Dès que le DNS est bon, le certificat HTTPS est émis automatiquement et %s sert votre site ; l\'adresse %s continue de fonctionner.', $d, $code . '.' . $this->frontendBaseDomain)],
        ];
    }
}
