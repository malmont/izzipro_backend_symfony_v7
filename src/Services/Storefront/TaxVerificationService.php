<?php

namespace App\Services\Storefront;

use App\Entity\User;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantEntityManagerProvider;

/**
 * « TAXES VÉRIFIÉES PAR LE COMMERÇANT » (lot AG-3d, 18/11/2026, plan validé : `docs/plan-entree-et-groupe.md` § 3, `docs/ecrans-et-contextes.md`
 * § 10). Les taxes restent ouvertes à l'agence ; le commerçant confirme qu'il les a vérifiées. Seul un administrateur DU site pose la
 * vérification (le contrôleur refuse l'opérateur d'une agence, toute entrée par jeton et le compte de la plateforme). TOUTE écriture d'une taxe,
 * par quelque chemin que ce soit, la fait tomber : un déclencheur de la base sur `tax` (`taxes_verification_falls`). Elle avertit sans bloquer
 * (ligne `taxes` de la liste d'ouverture).
 */
class TaxVerificationService
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly ?ContentAuditRecorder $audit = null)
    {
    }

    /** @return array{verified: bool, at: ?string, by: ?string} */
    public function state(): array
    {
        $row = $this->emProvider->getEntityManager()->getConnection()->fetchAssociative('SELECT taxes_verified_at, taxes_verified_by FROM entreprise ORDER BY id LIMIT 1') ?: [];

        return self::view($row);
    }

    /** Le commerçant dit « J'ai vérifié mes taxes » : l'heure, son prénom, une ligne au journal du site */
    public function verify(User $merchant): array
    {
        $by = mb_substr(trim((string) $merchant->getFirstname()) ?: 'administrateur', 0, 100);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $this->emProvider->getEntityManager()->getConnection()->executeStatement('UPDATE entreprise SET taxes_verified_at = ?, taxes_verified_by = ?', [$now, $by]);
        $this->audit?->record('taxes', null, 'verify', null, ['verifiedAt' => $now, 'by' => $by], ['taxes_verified_at']);

        return $this->state();
    }

    /** L'état lu d'une base de site quelconque (liste d'ouverture d'un site géré) ; colonnes absentes : non vérifié @return array{verified: bool, at: ?string, by: ?string} */
    public static function fromPdo(\PDO $pdo): array
    {
        try {
            $row = $pdo->query('SELECT taxes_verified_at, taxes_verified_by FROM entreprise ORDER BY id LIMIT 1')->fetch(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            $row = [];
        }

        return self::view($row);
    }

    /** La ligne de la liste d'ouverture : elle avertit sans bloquer @return array{key: string, ok: bool, blocking: bool, message: string} */
    public static function checklistItem(array $state): array
    {
        return ['key' => 'taxes', 'ok' => $state['verified'], 'blocking' => false, 'message' => $state['verified']
            ? sprintf('Taxes vérifiées par %s le %s.', $state['by'] ?? 'le commerçant', (new \DateTimeImmutable((string) $state['at']))->format('d/m/Y'))
            : 'Taxes à vérifier par le commerçant : « J\'ai vérifié mes taxes » (une taxe modifiée redemande la vérification).'];
    }

    /** @param array<string, mixed> $row @return array{verified: bool, at: ?string, by: ?string} */
    private static function view(array $row): array
    {
        $at = $row['taxes_verified_at'] ?? null;

        return ['verified' => $at !== null, 'at' => $at !== null ? (new \DateTimeImmutable((string) $at, new \DateTimeZone('UTC')))->format(\DATE_ATOM) : null,
            'by' => $at !== null ? (string) ($row['taxes_verified_by'] ?? '') : null];
    }
}
