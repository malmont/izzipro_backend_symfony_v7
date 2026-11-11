<?php

namespace App\Gestion\Services;

use App\Repository\TenantAppKeyRepository;
use App\Services\Platform\EntitlementService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;

/**
 * Transition du lot MOD (11/11/2026, `docs/gestion-mise-a-niveau.md` § MOD) : avant le passage de la plateforme en mode live, accorder
 * l'application de gestion (droit app.management manuel, sans fin, note « transition MOD ») à tout site qui s'en sert déjà : données de
 * gestion (périodes, commandes fournisseurs, notes de frais, conseils, documents lus) ou clé d'activation de l'application (« admin ») valide.
 * Un site qui a déjà le droit n'est pas touché ; rejouable sans effet. Retour arrière : annuler les droits notés « transition MOD ».
 */
class GestionModuleTransitionService
{
    public const FEATURE = 'app.management';
    public const NOTE = 'transition MOD';
    /** table => libellé de la raison (données de gestion) */
    private const DATA = ['collections' => 'périodes', 'commande' => 'commandes fournisseurs', 'note_de_frais' => 'notes de frais', 'gestion_advice' => 'conseils', 'gestion_document' => 'documents'];

    public function __construct(
        private readonly TenantConnectionManager $tenants,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly EntitlementService $entitlements,
        private readonly TenantAppKeyRepository $appKeys,
    ) {
    }

    /**
     * @param ?string $only code d'un seul site
     * @return list<array{code: string, reasons: list<string>, alreadyEnabled: bool, action: string, error?: string}>
     */
    public function run(bool $apply, ?string $only = null, ?string $by = null): array
    {
        $out = [];
        foreach ($this->tenants->getPdoMaster()->query('SELECT code, dbname FROM tenants ORDER BY code')->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $code = (string) $row['code'];
            if ($only !== null && $code !== $only) {
                continue;
            }
            try {
                $this->emProvider->switchTenant((string) $row['dbname'], $code);
                $reasons = $this->reasons($code);
                $enabled = $this->entitlements->isEnabled($code, self::FEATURE);
                $action = $reasons === [] ? 'none' : ($enabled ? 'already' : ($apply ? 'granted' : 'to_grant'));
                if ($action === 'granted') {
                    $this->entitlements->grant($code, self::FEATURE, 'manual', null, $by, self::NOTE . ' : ' . implode(', ', $reasons), null, null, false);
                }
                $out[] = ['code' => $code, 'reasons' => $reasons, 'alreadyEnabled' => $enabled, 'action' => $action];
            } catch (\Throwable $e) {
                $out[] = ['code' => $code, 'reasons' => [], 'alreadyEnabled' => false, 'action' => 'error', 'error' => $e->getMessage()];
            }
        }

        return $out;
    }

    /** @return list<string> raisons d'accorder le module (données de gestion, clé de l'application valide) */
    public function reasons(string $code): array
    {
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $reasons = [];
        foreach (self::DATA as $table => $label) {
            try {
                $n = (int) $conn->fetchOne("SELECT COUNT(*) FROM $table");
            } catch (\Throwable) {
                $n = 0; // table absente sur une vieille base
            }
            if ($n > 0) {
                $reasons[] = "$n $label";
            }
        }
        foreach ($this->appKeys->listForTenant($code) as $key) {
            $expired = !empty($key['expires_at']) && strtotime((string) $key['expires_at']) < time();
            if (($key['app'] ?? null) === 'admin' && empty($key['revoked_at']) && !$expired) {
                $reasons[] = 'clé de l\'application de gestion valide';
                break;
            }
        }

        return $reasons;
    }
}
