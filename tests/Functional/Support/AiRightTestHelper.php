<?php

namespace App\Tests\Functional\Support;

use App\Services\TenantConnectionManager;
use Psr\Container\ContainerInterface;

/**
 * Depuis le 08/11/2026 l'assistant suit les droits « Aide IA » / « IA complète » même en mode démo (IA bornée) : l'amorçage des tests donne
 * « IA complète » au site de test (tests/bootstrap.php). Les tests qui vident la table des droits la remettent en fin de test avec ce helper.
 */
final class AiRightTestHelper
{
    public static function restoreFullAi(ContainerInterface $container, string $tenantCode = MV_TEST_TENANT_CODE): void
    {
        $master = $container->get(TenantConnectionManager::class)->getPdoMaster();
        $has = $master->prepare("SELECT COUNT(*) FROM tenant_entitlements WHERE tenant_code = ? AND feature_key = 'ai.full' AND status = 'active'");
        $has->execute([$tenantCode]);
        if ((int) $has->fetchColumn() === 0) {
            $master->prepare("INSERT INTO tenant_entitlements (tenant_code, feature_key, source, status, granted_by, note) VALUES (?, 'ai.full', 'manual', 'active', 'bootstrap', 'IA complète du site de test')")->execute([$tenantCode]);
        }
    }
}
