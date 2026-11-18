<?php

namespace App\Services\Platform\PlanLink;

use App\Services\TenantConnectionManager;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lien prouvé entre l'acheteur d'un forfait et le site servi (lot PROVISION-1, 18/11/2026, `docs/console-pilotage.md`) :
 * - `issue` : « Mettre à jour mon forfait » (administrateur du site X) émet un jeton SIGNÉ (HMAC-SHA256, clé dérivée du secret de
 *   l'application pour ce seul usage) : site, compte administrateur, émis le, expire 30 minutes plus tard, identifiant unique. Le jeton
 *   n'est gardé nulle part ; la base maître garde seulement son identifiant et son usage (`platform_plan_link`) ;
 * - `consume` : à la souscription d'une formule branchée, le jeton est vérifié (forme, signature, expiration, identifiant connu), le compte
 *   doit être TOUJOURS administrateur du site X (relu dans la base de X par une connexion à part), et le lien ne sert qu'à UN achat (le
 *   même acheteur et la même formule : un nouvel essai de paiement est accepté) ;
 * - `attach` : l'abonnement créé est noté sur le lien (preuve lue par le super administrateur).
 * Un refus lève `PlanLinkException` avec un code stable ; aucun message ni journal ne contient le jeton.
 */
class PlatformPlanLinkService
{
    public const TTL_SECONDS = 1800;
    private const PREFIX = 'pl1';

    public function __construct(
        private readonly TenantConnectionManager $tenants,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
    ) {
    }

    public const ISSUE_PER_HOUR = 30;
    public const KEEP_DAYS = 90;

    /**
     * Un lien neuf pour l'administrateur `$userId` du site `$tenantCode` ; 30 par heure et par compte au plus (FULFILLMENT_LINK_RATE) ; les
     * liens de plus de 90 jours sont effacés au passage.
     */
    public function issue(string $tenantCode, int $userId): string
    {
        $now = time();
        $pdo = $this->tenants->getPdoMaster();
        $pdo->prepare('DELETE FROM platform_plan_link WHERE issued_at < ?')->execute([gmdate('Y-m-d H:i:s', $now - self::KEEP_DAYS * 86400)]);
        $recent = $pdo->prepare('SELECT COUNT(*) FROM platform_plan_link WHERE tenant_code = ? AND user_id = ? AND issued_at >= ?');
        $recent->execute([$tenantCode, $userId, gmdate('Y-m-d H:i:s', $now - 3600)]);
        if ((int) $recent->fetchColumn() >= self::ISSUE_PER_HOUR) {
            throw new PlanLinkException('FULFILLMENT_LINK_RATE', 'Trop de liens demandés : réessayez dans une heure.');
        }
        $claims = ['s' => $tenantCode, 'u' => $userId, 'i' => $now, 'e' => $now + self::TTL_SECONDS, 'j' => bin2hex(random_bytes(16))];
        $this->tenants->getPdoMaster()->prepare('INSERT INTO platform_plan_link (jti, tenant_code, user_id, issued_at, expires_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$claims['j'], $tenantCode, $userId, gmdate('Y-m-d H:i:s', $now), gmdate('Y-m-d H:i:s', $claims['e'])]);
        $body = self::b64((string) json_encode($claims));

        return self::PREFIX . '.' . $body . '.' . self::b64(hash_hmac('sha256', self::PREFIX . '.' . $body, $this->key(), true));
    }

    /**
     * Vérifie le lien et le réserve à cet achat (`$buyer` : site boutique, compte client, formule). Rend le code du site servi.
     *
     * @throws PlanLinkException FULFILLMENT_LINK_INVALID | FULFILLMENT_LINK_EXPIRED | FULFILLMENT_LINK_USED | FULFILLMENT_LINK_NOT_ADMIN
     */
    public function consume(string $token, string $buyer): string
    {
        $claims = $this->claims($token);
        if ($claims['e'] < time()) {
            throw new PlanLinkException('FULFILLMENT_LINK_EXPIRED', 'Ce lien a expiré : rouvrez « Mettre à jour mon forfait » depuis votre site pour en obtenir un nouveau.');
        }
        $pdo = $this->tenants->getPdoMaster();
        $row = $pdo->prepare('SELECT tenant_code, user_id, used_by FROM platform_plan_link WHERE jti = ?');
        $row->execute([$claims['j']]);
        $link = $row->fetch(\PDO::FETCH_ASSOC);
        if ($link === false || $link['tenant_code'] !== $claims['s'] || (int) $link['user_id'] !== $claims['u']) {
            throw new PlanLinkException('FULFILLMENT_LINK_INVALID', 'Lien du forfait invalide : rouvrez « Mettre à jour mon forfait » depuis votre site.');
        }
        if (!$this->stillAdmin($claims['s'], $claims['u'])) {
            throw new PlanLinkException('FULFILLMENT_LINK_NOT_ADMIN', 'Ce lien a été émis par un compte qui n\'administre plus le site.');
        }
        $mark = $pdo->prepare('UPDATE platform_plan_link SET used_by = ?, used_at = COALESCE(used_at, ?) WHERE jti = ? AND (used_by IS NULL OR used_by = ?) RETURNING jti');
        $mark->execute([$buyer, gmdate('Y-m-d H:i:s'), $claims['j'], $buyer]);
        if ($mark->fetchColumn() === false) {
            throw new PlanLinkException('FULFILLMENT_LINK_USED', 'Ce lien a déjà servi à un autre achat : rouvrez « Mettre à jour mon forfait » depuis votre site.');
        }

        return $claims['s'];
    }

    /** L'abonnement créé avec ce lien (preuve) */
    public function attach(string $token, string $shopTenant, int $subscriptionId): void
    {
        $claims = $this->claims($token);
        // la preuve du PREMIER abonnement créé avec ce lien est gardée (un nouvel essai du même achat ne l'écrase pas)
        $this->tenants->getPdoMaster()->prepare('UPDATE platform_plan_link SET shop_tenant = COALESCE(shop_tenant, ?), subscription_id = COALESCE(subscription_id, ?) WHERE jti = ?')->execute([$shopTenant, $subscriptionId, $claims['j']]);
    }

    /** @return array{s: string, u: int, i: int, e: int, j: string} */
    private function claims(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] !== self::PREFIX || strlen($token) > 1024) {
            throw new PlanLinkException('FULFILLMENT_LINK_INVALID', 'Lien du forfait invalide : rouvrez « Mettre à jour mon forfait » depuis votre site.');
        }
        $expected = self::b64(hash_hmac('sha256', $parts[0] . '.' . $parts[1], $this->key(), true));
        if (!hash_equals($expected, $parts[2])) {
            throw new PlanLinkException('FULFILLMENT_LINK_INVALID', 'Lien du forfait invalide : rouvrez « Mettre à jour mon forfait » depuis votre site.');
        }
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        if (!is_array($claims) || !is_string($claims['s'] ?? null) || !is_int($claims['u'] ?? null) || !is_int($claims['e'] ?? null) || !is_string($claims['j'] ?? null)
            || !preg_match('/^[a-z0-9-]{2,50}$/', $claims['s']) || !preg_match('/^[0-9a-f]{32}$/', $claims['j'])) {
            throw new PlanLinkException('FULFILLMENT_LINK_INVALID', 'Lien du forfait invalide : rouvrez « Mettre à jour mon forfait » depuis votre site.');
        }

        return ['s' => $claims['s'], 'u' => $claims['u'], 'i' => (int) ($claims['i'] ?? 0), 'e' => $claims['e'], 'j' => $claims['j']];
    }

    /** Le compte est-il toujours administrateur (vérifié, non supprimé) du site ? Lu dans la base du site par une connexion à part */
    private function stillAdmin(string $tenantCode, int $userId): bool
    {
        $tenant = $this->tenants->findTenantByCode($tenantCode);
        if ($tenant === null || empty($tenant['dbname'])) {
            return false;
        }
        $params = $this->tenants->getTenantParams();
        $params['dbname'] = (string) $tenant['dbname'];
        $conn = DriverManager::getConnection($params);
        try {
            $roles = $conn->fetchOne('SELECT roles FROM "user" WHERE id = ? AND deleted_at IS NULL AND is_verified = true', [$userId]);
        } catch (\Throwable) {
            return false;
        } finally {
            $conn->close();
        }
        $roles = is_string($roles) ? json_decode($roles, true) : null;

        return is_array($roles) && in_array('ROLE_ADMIN', $roles, true);
    }

    private function key(): string
    {
        return hash_hmac('sha256', 'platform-plan-link', $this->secret, true);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
