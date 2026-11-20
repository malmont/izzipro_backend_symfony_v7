<?php

namespace App\Services\SubscriptionService;

use App\Entity\StripeConfig;
use App\Entity\Subscription;
use App\Services\StripeService\StripeService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;

/**
 * Commission de la plateforme sur les ABONNEMENTS (lot ABO-CONF C, 19/11/2026, décision du propriétaire), à part de celle de la boutique :
 * - réglage de la plateforme « Commission abonnements (%) » (`platform_settings.subscription_fee_bp` de la base maître, 1 % sans réglage) ;
 * - réglage d'un site (`stripe_config.subscription_fee_bp`) qui le remplace s'il est rempli ; 0 permis ; bornes 0 à 20 % ;
 * - seulement avec un compte Stripe connecté (un site interne ou sans compte : aucune commission, hors rattrapage) ;
 * - nouvelles souscriptions : la commission du moment ; abonnements en cours : rattrapés par le passage horaire, 50 par site et par
 *   passage, une clé d'idempotence par abonnement et par commission voulue ; une erreur Stripe n'arrête pas le lot ;
 * - chaque changement au journal `platform_fee_log` de la base maître (qui, ancien, nouveau), lisible du seul super administrateur.
 * Montants en centièmes de % (100 = 1 %).
 */
class SubscriptionFeeService
{
    public const KEY = 'subscription_fee_bp';
    public const DEFAULT_BP = 100;
    public const MAX_BP = 2000;
    public const BATCH = 50;
    /** États d'un abonnement qui facture encore (ou facturera après sa pause) */
    public const BILLING = [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING, Subscription::STATUS_PAST_DUE, Subscription::STATUS_PAUSED];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionManager $tenants,
        private readonly StripeService $stripe,
        private readonly SubscriptionStripeGatewayInterface $gateway,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** La commission de la plateforme (centièmes de %), 1 % sans réglage */
    public function platformBp(): int
    {
        try {
            $stmt = $this->tenants->getPdoMaster()->prepare('SELECT value FROM platform_settings WHERE key = ?');
            $stmt->execute([self::KEY]);
            $value = $stmt->fetchColumn();
        } catch (\PDOException) {
            return self::DEFAULT_BP;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : self::DEFAULT_BP;
    }

    /** @throws \InvalidArgumentException hors bornes */
    public function setPlatformBp(int $bp, ?string $by): void
    {
        self::assertBounds($bp);
        $old = $this->platformBp();
        $this->tenants->getPdoMaster()->prepare('INSERT INTO platform_settings (key, value, updated_at, updated_by) VALUES (?, ?, NOW(), ?) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW(), updated_by = EXCLUDED.updated_by')
            ->execute([self::KEY, (string) $bp, $by]);
        $this->journal('platform', null, $old, $bp, $by);
    }

    /** La commission propre au site courant (null = celle de la plateforme) */
    public function siteBp(): ?int
    {
        return $this->config()?->getSubscriptionFeeBp();
    }

    /**
     * Règle la commission du site courant (null = retour à celle de la plateforme) ; réservé au super administrateur (écran Stripe du site).
     *
     * @throws \InvalidArgumentException hors bornes
     */
    public function setSiteBp(?int $bp, ?string $by): void
    {
        if ($bp !== null) {
            self::assertBounds($bp);
        }
        $config = $this->config() ?? throw new \InvalidArgumentException('Le site n\'a pas de réglage Stripe.');
        $old = $config->getSubscriptionFeeBp();
        if ($old === $bp) {
            return;
        }
        $config->setSubscriptionFeeBp($bp);
        $this->emProvider->getEntityManager()->flush();
        $this->journal('site', (string) $this->tenants->getCurrentTenantCode(), $old, $bp, $by);
    }

    /** La commission voulue pour le site courant, ou null sans compte Stripe connecté (site interne ou sans compte : pas de commission) */
    public function effectiveBp(): ?int
    {
        $options = $this->stripe->accountOptions();
        if ($options === null || !isset($options['stripe_account'])) {
            return null;
        }

        return $this->siteBp() ?? $this->platformBp();
    }

    /** Le pourcentage à poser chez Stripe (`application_fee_percent`, deux décimales au plus) */
    public static function percent(int $bp): float
    {
        return round($bp / 100, 2);
    }

    /** Nombre d'abonnements du site qui attendent la commission voulue (affiché dans l'écran Stripe du site) */
    public function pendingCount(): int
    {
        $bp = $this->effectiveBp();
        if ($bp === null) {
            return 0;
        }

        return (int) $this->emProvider->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM subscription WHERE stripe_subscription_id IS NOT NULL AND status IN (?) AND fee_bp_applied IS DISTINCT FROM ?',
            [self::BILLING, $bp], [\Doctrine\DBAL\ArrayParameterType::STRING, \PDO::PARAM_INT]);
    }

    /**
     * Rattrapage des abonnements en cours du site courant (passage horaire) : au plus `BATCH`, chacun mis à jour chez Stripe avec une clé
     * d'idempotence (abonnement + commission voulue), puis noté. Une erreur Stripe n'arrête pas le lot : l'abonnement est repris au passage
     * suivant.
     *
     * @return array{updated: int, failed: int}
     */
    public function catchUp(int $limit = self::BATCH): array
    {
        $bp = $this->effectiveBp();
        $out = ['updated' => 0, 'failed' => 0];
        if ($bp === null) {
            return $out;
        }
        $em = $this->emProvider->getEntityManager();
        $ids = $em->getConnection()->fetchFirstColumn(
            'SELECT id FROM subscription WHERE stripe_subscription_id IS NOT NULL AND status IN (?) AND fee_bp_applied IS DISTINCT FROM ? ORDER BY id LIMIT ' . max(1, $limit),
            [self::BILLING, $bp], [\Doctrine\DBAL\ArrayParameterType::STRING, \PDO::PARAM_INT]);
        foreach ($ids as $id) {
            $subscription = $em->getRepository(Subscription::class)->find((int) $id);
            if ($subscription === null) {
                continue;
            }
            try {
                $this->gateway->updateFee((string) $subscription->getStripeSubscriptionId(), self::percent($bp), sprintf('fee-%s-%d', $subscription->getStripeSubscriptionId(), $bp));
                $subscription->setFeeBpApplied($bp);
                $em->flush();
                ++$out['updated'];
            } catch (\Throwable $e) {
                ++$out['failed'];
                $this->logger->error(sprintf('[Subscription] Commission non posée sur l\'abonnement %d (%s)', (int) $id, $e::class));
            }
        }

        return $out;
    }

    /** @return list<array{setting: string, tenant_code: ?string, old_bp: ?int, new_bp: ?int, changed_by: ?string, changed_at: string}> les derniers changements */
    public function journalRows(int $limit = 20): array
    {
        try {
            $stmt = $this->tenants->getPdoMaster()->prepare('SELECT setting, tenant_code, old_bp, new_bp, changed_by, changed_at FROM platform_fee_log ORDER BY id DESC LIMIT ' . max(1, $limit));
            $stmt->execute();

            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return [];
        }
    }

    private static function assertBounds(int $bp): void
    {
        if ($bp < 0 || $bp > self::MAX_BP) {
            throw new \InvalidArgumentException('Commission des abonnements : de 0 à 20 %.');
        }
    }

    private function journal(string $setting, ?string $tenant, ?int $old, ?int $new, ?string $by): void
    {
        $this->tenants->getPdoMaster()->prepare('INSERT INTO platform_fee_log (setting, tenant_code, old_bp, new_bp, changed_by, changed_at) VALUES (?, ?, ?, ?, ?, NOW())')
            ->execute([$setting, $tenant, $old, $new, $by !== null ? mb_substr($by, 0, 180) : null]);
    }

    private function config(): ?StripeConfig
    {
        return $this->emProvider->getEntityManager()->getRepository(StripeConfig::class)->findOneBy([], ['id' => 'ASC']);
    }
}
