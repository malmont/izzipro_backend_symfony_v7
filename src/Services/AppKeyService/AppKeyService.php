<?php

namespace App\Services\AppKeyService;

use App\Repository\TenantAppKeyRepository;
use App\Services\TenantPublicHostResolver;

/**
 * Clés d'activation des applications (15/10/2026). Une clé identifie un SITE et une APPLICATION (pos, mobile, admin,
 * livingmemory), jamais un utilisateur ni un appareil : le caissier ou l'administrateur se connecte ensuite
 * normalement (POST /api/login). Aucune limite d'appareils (chaque activation compte et note le dernier appareil) :
 * l'application grand public Living Memory embarque une seule clé livingmemory par site, la même sur tous les téléphones.
 * Format : IZ-<POS|MOB|ADM|LMV>-XXXX-XXXX-XXXX (alphabet sans caractères ambigus, 60 bits d'aléa) ; seule l'empreinte
 * SHA-256 est stockée (base maître), la clé en clair n'est montrée qu'une fois. Révocable, expiration facultative.
 */
class AppKeyService
{
    /** application → préfixe de clé : POS (caisse), e-commerce mobile (Flutter), gestion (.NET MAUI), LivingMemory (Mémoires Vivantes) */
    public const APPS = ['pos' => 'POS', 'mobile' => 'MOB', 'admin' => 'ADM', 'livingmemory' => 'LMV'];
    public const LABELS = ['pos' => 'Caisse (POS)', 'mobile' => 'E-commerce mobile', 'admin' => 'Gestion', 'livingmemory' => 'LivingMemory (Mémoires Vivantes)'];
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly TenantAppKeyRepository $keys, private readonly TenantPublicHostResolver $hosts, private readonly ?\App\Services\PosAuditService\PosTerminalService $terminals = null)
    {
    }

    /**
     * @param array{id: int, name: string}|null $cashRegister poste de caisse d'une clé POS (présélectionné par le terminal)
     * @return array{key: string, row: array<string, mixed>} la clé en clair (une seule fois) et sa ligne
     */
    public function generate(string $tenantCode, string $app, string $label, string $createdBy, ?\DateTimeInterface $expiresAt, ?array $cashRegister = null): array
    {
        if (!isset(self::APPS[$app])) {
            throw new AppKeyException('VALIDATION_FAILED', 'app : pos, mobile, admin ou livingmemory attendu', 422, [['path' => 'app', 'message' => 'pos, mobile, admin ou livingmemory attendu']]);
        }
        $random = '';
        for ($i = 0; $i < 12; $i++) {
            $random .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        $key = sprintf('IZ-%s-%s-%s-%s', self::APPS[$app], substr($random, 0, 4), substr($random, 4, 4), substr($random, 8, 4));
        $row = $this->keys->insert($tenantCode, $app, self::hash($key), substr($key, 0, 11) . '-****-****', $label, $createdBy, $expiresAt, $app === 'pos' ? $cashRegister : null);

        return ['key' => $key, 'row' => $row];
    }

    /** @return list<array<string, mixed>> */
    public function list(string $tenantCode): array
    {
        return $this->keys->listForTenant($tenantCode);
    }

    /** Désactivation d'une application sur le site : toutes ses clés actives sont révoquées ; rend leur nombre */
    public function revokeAllForApp(string $tenantCode, string $app): int
    {
        return $this->keys->revokeAllForApp($tenantCode, $app);
    }

    /** Modification d'une clé du site : libellé et, pour une clé POS, poste de caisse (null : aucun) */
    public function update(int $id, string $tenantCode, ?string $label, bool $setRegister, ?array $cashRegister): array
    {
        $row = $this->keys->findOneForTenant($id, $tenantCode) ?? throw new AppKeyException('APP_KEY_NOT_FOUND', 'Clé introuvable', 404);
        if ($setRegister && $cashRegister !== null && $row['app'] !== 'pos') {
            throw new AppKeyException('VALIDATION_FAILED', 'cashRegisterId : seule une clé de caisse (pos) a un poste', 422, [['path' => 'cashRegisterId', 'message' => 'seule une clé de caisse (pos) a un poste']]);
        }
        $this->keys->update($id, $tenantCode, $label, $setRegister, $cashRegister);

        return $this->keys->findOneForTenant($id, $tenantCode) ?? $row;
    }

    public function revoke(int $id, string $tenantCode): array
    {
        $row = $this->keys->findOneForTenant($id, $tenantCode) ?? throw new AppKeyException('APP_KEY_NOT_FOUND', 'Clé introuvable', 404);
        $this->keys->revoke($id, $tenantCode);

        return $this->keys->findOneForTenant($id, $tenantCode) ?? $row;
    }

    /**
     * Activation d'une application : la saisie est normalisée (casse, espaces, tirets), la clé doit exister, ne pas
     * être révoquée ni expirée et correspondre à l'application déclarée. Rend le site (code, nom, hôte complet).
     *
     * @return array{tenant: array{code: string, name: string, host: string}, app: string, label: string, expiresAt: ?string}
     */
    public function activate(string $rawKey, ?string $declaredApp, ?string $device): array
    {
        $key = self::canonical($rawKey);
        if ($key === null) {
            throw new AppKeyException('APP_KEY_MISSING', 'Indiquez la clé d\'activation', 400);
        }
        $row = $this->keys->findByHash(self::hash($key));
        if (!$row || $row['revoked_at'] !== null || ($row['expires_at'] !== null && new \DateTimeImmutable($row['expires_at']) < new \DateTimeImmutable())) {
            throw new AppKeyException('APP_KEY_INVALID', 'Clé d\'activation inconnue, révoquée ou expirée', 404);
        }
        $declaredApp = $declaredApp !== null ? strtolower(trim($declaredApp)) : null;
        if ($declaredApp !== null && $declaredApp !== '' && $declaredApp !== $row['app']) {
            throw new AppKeyException('APP_KEY_WRONG_APP', sprintf('Cette clé est destinée à l\'application « %s »', $row['app']), 403);
        }
        $tenant = $this->hosts->describe($row['tenant_code']) ?? throw new AppKeyException('APP_KEY_INVALID', 'Le site de cette clé n\'existe plus', 404);
        $this->keys->touch((int) $row['id'], $device);

        return [
            'tenant' => $tenant, 'app' => $row['app'], 'label' => $row['label'],
            'expiresAt' => $row['expires_at'] ? (new \DateTimeImmutable($row['expires_at']))->format(\DateTimeInterface::ATOM) : null,
            // Poste de caisse rattaché à une clé POS (copie faite à la création : le POS le présélectionne et confirme par GET /api/cash-registers)
            'cashRegister' => $row['cash_register_id'] !== null ? ['id' => (int) $row['cash_register_id'], 'name' => (string) $row['cash_register_name']] : null,
        ] + ($row['app'] === 'pos' && $this->terminals ? [
            // Terminal de caisse (C2) : même {id, token} à chaque activation de la même clé ; jeton à envoyer dans X-Pos-Terminal
            'terminal' => ['id' => (int) $row['id'], 'token' => $this->terminals->token((int) $row['id'], (string) $row['tenant_code'])],
        ] : []);
    }

    /** Forme canonique IZ-XXX-XXXX-XXXX-XXXX d'une saisie (minuscules, espaces et tirets tolérés) ; null si vide ou malformée */
    public static function canonical(string $raw): ?string
    {
        $flat = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '');
        if ($flat === '') {
            return null;
        }
        if (!preg_match('/^IZ(POS|MOB|ADM|LMV)([A-Z0-9]{12})$/', $flat, $m)) {
            return $flat; // inconnue : l'empreinte ne correspondra à rien (APP_KEY_INVALID)
        }

        return sprintf('IZ-%s-%s-%s-%s', $m[1], substr($m[2], 0, 4), substr($m[2], 4, 4), substr($m[2], 8, 4));
    }

    public static function hash(string $key): string
    {
        return hash('sha256', $key);
    }
}
