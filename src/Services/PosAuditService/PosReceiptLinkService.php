<?php

namespace App\Services\PosAuditService;

use App\Entity\Entreprise;
use App\Entity\PosReceipt;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantPublicHostResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lien public d'un reçu (F3, 24/10/2026) : https://<hôte public du site>/api/r/<jeton>. Le jeton porte le ticket, la version
 * de son lien et sa date d'expiration, signés HMAC avec le secret de l'application : non devinable, rien à stocker ;
 * révocation par changement de version sur le ticket. Servi sous /api/ pour être routé vers le backend sur tout hôte de
 * site (le site Next.js n'est pas touché).
 */
class PosReceiptLinkService
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionManager $tenants,
        private readonly TenantPublicHostResolver $hosts,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
    ) {
    }

    public function settings(): array
    {
        $e = $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([]);

        return ['emailEnabled' => $e?->isPosReceiptEmailEnabled() ?? true, 'qrEnabled' => $e?->isPosReceiptQrEnabled() ?? true,
            'linkDays' => $e?->getPosReceiptLinkDays() ?? 90, 'qrPrintDefault' => $e?->isPosReceiptQrPrintDefault() ?? true];
    }

    /** URL publique du reçu (null si le reçu par QR est désactivé sur le site) */
    public function url(PosReceipt $receipt): ?string
    {
        $settings = $this->settings();
        if (!$settings['qrEnabled']) {
            return null;
        }
        $code = (string) $this->tenants->getCurrentTenantCode();
        $host = $code !== '' ? ($this->hosts->describe($code)['host'] ?? null) : null;
        if ($host === null) {
            return null;
        }
        // Expiration en fin de journée : le même lien (même QR) pour un ticket pendant toute la journée de son émission ou de sa réimpression
        $expires = (new \DateTimeImmutable('+' . $settings['linkDays'] . ' days'))->setTime(23, 59, 59)->getTimestamp();

        return 'https://' . $host . '/api/r/' . $this->token($receipt, $expires);
    }

    public function token(PosReceipt $receipt, int $expiresAt): string
    {
        $payload = $receipt->getId() . '.' . $receipt->getLinkVersion() . '.' . $expiresAt;
        $code = (string) $this->tenants->getCurrentTenantCode();

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=') . '.' . substr(hash_hmac('sha256', $code . '|' . $payload, $this->secret), 0, 32);
    }

    /** @return array{status: string, receipt: ?PosReceipt} status : valid | invalid | expired | revoked */
    public function resolve(string $token): array
    {
        if (!preg_match('/^([A-Za-z0-9_-]{8,80})\.([0-9a-f]{32})$/', $token, $m)) {
            return ['status' => 'invalid', 'receipt' => null];
        }
        $payload = base64_decode(strtr($m[1], '-_', '+/'), true);
        if ($payload === false || !preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $payload, $p)) {
            return ['status' => 'invalid', 'receipt' => null];
        }
        $code = (string) $this->tenants->getCurrentTenantCode();
        if (!hash_equals(substr(hash_hmac('sha256', $code . '|' . $payload, $this->secret), 0, 32), $m[2])) {
            return ['status' => 'invalid', 'receipt' => null];
        }
        $receipt = $this->emProvider->getEntityManager()->find(PosReceipt::class, (int) $p[1]);
        if (!$receipt) {
            return ['status' => 'invalid', 'receipt' => null];
        }
        if ($receipt->getLinkVersion() !== (int) $p[2]) {
            return ['status' => 'revoked', 'receipt' => $receipt];
        }
        if ((int) $p[3] < time()) {
            return ['status' => 'expired', 'receipt' => $receipt];
        }

        return ['status' => 'valid', 'receipt' => $receipt];
    }
}
