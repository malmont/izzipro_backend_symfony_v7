<?php

namespace App\Tests\Functional\Platform;

use App\Entity\Subscription;
use App\Entity\SubscriptionPlan;
use App\Entity\User;
use App\Event\SubscriptionPeriodPaidEvent;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\OfferPayments\OfferPaymentsRepository;
use App\Services\Platform\OfferPayments\OfferReconciler;
use App\Services\Platform\PlatformModeService;
use App\Services\TenantCacheService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeSubscriptionStripeGateway;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Paiements des offres, lot PROVISION-3 (18/11/2026, `docs/console-pilotage.md`) : journal des événements Stripe sans contenu (un événement
 * rejoué ne refait rien), ordre (un événement plus ancien est ignoré, la fin de période ne recule pas, une facture ne ressuscite pas un
 * abonnement résilié), « période payée » une fois par facture, file de reprise des droits avec incident au 5e échec, réconciliation
 * nocturne qui corrige l'état d'après Stripe et les droits de l'abonnement sans toucher un droit d'une autre origine.
 */
class OfferPaymentsTest extends WebTestCase
{
    private KernelBrowser $client;
    private TenantEntityManagerProvider $provider;
    private \PDO $master;
    private array $planBefore = [];
    private int $planId;
    private string $tag;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot(); // l'écouteur de « période payée » posé par l'essai reste branché d'une requête à l'autre
        $this->tag = bin2hex(random_bytes(3));
        $this->provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        static::getContainer()->get(PlatformModeService::class)->setShopTenant(MV_TEST_TENANT_CODE, 'test');
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-offres@example.invalid', false, false);
        $db = $this->provider->getEntityManager()->getConnection();
        $this->planId = (int) $db->fetchOne('SELECT sp.id FROM subscription_plan sp JOIN product p ON p.id = sp.product_id WHERE p.code = ? ORDER BY sp.id LIMIT 1', ['DEMO-PANIER']);
        $this->assertGreaterThan(0, $this->planId);
        $this->planBefore = $db->fetchAssociative('SELECT fulfillment_type, fulfillment_ref FROM subscription_plan WHERE id = ?', [$this->planId]);
        $db->executeStatement('UPDATE subscription_plan SET fulfillment_type = ?, fulfillment_ref = ? WHERE id = ?', [SubscriptionPlan::FULFILLMENT_PLATFORM_OFFER, 'boussole', $this->planId]);
        $this->provider->getEntityManager()->clear(); // la formule déjà chargée par le jeu de démonstration est relue
        $this->cleanMaster();
        FakeSubscriptionStripeGateway::reset();
    }

    protected function tearDown(): void
    {
        $db = $this->provider->getEntityManager()->getConnection();
        $db->executeStatement('DELETE FROM subscription WHERE fulfillment_target IS NOT NULL');
        $db->executeStatement('UPDATE subscription_plan SET fulfillment_type = ?, fulfillment_ref = ? WHERE id = ?', [$this->planBefore['fulfillment_type'], $this->planBefore['fulfillment_ref'], $this->planId]);
        $this->cleanMaster();
        static::getContainer()->get(TenantCacheService::class)->invalidateSite(MV_TEST_TENANT2_CODE, MV_TEST_TENANT2_DB);
        static::getContainer()->get(PlatformModeService::class)->setShopTenant(null, 'test');
        \App\Tests\Functional\Support\AiRightTestHelper::restoreFullAi(static::getContainer());
        parent::tearDown();
    }

    public function testTheJournalKeepsOrderAndAPaidPeriodIsAnnouncedOnce(): void
    {
        $periods = [];
        static::getContainer()->get('event_dispatcher')->addListener(SubscriptionPeriodPaidEvent::class, function (SubscriptionPeriodPaidEvent $e) use (&$periods) { $periods[] = $e; });
        [$subscription, $stripeId] = $this->activeSubscription();
        $t = time();
        $p2 = $t + 40 * 86400;

        // un événement : traité une fois ; le même rejoué répond sans rien refaire
        $first = $this->webhook("evt_a_$this->tag", $t, 'customer.subscription.updated', ['id' => $stripeId, 'status' => 'active', 'current_period_end' => $p2]);
        $this->assertSame('processed', $first['status']);
        $this->assertSame('duplicate', $this->webhook("evt_a_$this->tag", $t, 'customer.subscription.updated', ['id' => $stripeId, 'status' => 'past_due', 'current_period_end' => $p2])['status']);
        $this->assertSame([Subscription::STATUS_ACTIVE, $p2], $this->state($subscription));

        // un événement plus ancien que le dernier appliqué : ignoré, sans rien changer
        $late = $this->webhook("evt_b_$this->tag", $t - 100, 'customer.subscription.updated', ['id' => $stripeId, 'status' => 'past_due', 'current_period_end' => $t + 86400]);
        $this->assertSame(['ignored', 'out_of_order'], [$late['status'], $late['detail']]);
        $this->assertSame([Subscription::STATUS_ACTIVE, $p2], $this->state($subscription));
        $this->assertSame('ignored', $this->webhook("evt_b2_$this->tag", $t - 50, 'invoice.payment_failed', ['id' => "in_f_$this->tag", 'subscription' => $stripeId, 'attempt_count' => 1, 'next_payment_attempt' => $t + 86400])['status']);
        $this->assertSame(Subscription::STATUS_ACTIVE, $this->state($subscription)[0], 'un échec de paiement plus ancien ne met pas en impayé');

        // une facture payée plus récente mais d'une période plus courte : la fin de période ne recule pas ; « période payée » une seule fois
        // pour invoice.paid et invoice.payment_succeeded de la même facture
        $invoice = ['id' => "in_p_$this->tag", 'subscription' => $stripeId, 'total' => 0, 'amount_paid' => 0, 'lines' => ['data' => [['period' => ['start' => $t, 'end' => $t + 10 * 86400]]]]];
        $this->assertSame('processed', $this->webhook("evt_c_$this->tag", $t + 1, 'invoice.paid', $invoice)['status']);
        $this->assertSame('processed', $this->webhook("evt_d_$this->tag", $t + 1, 'invoice.payment_succeeded', $invoice)['status']);
        $this->assertSame($p2, $this->state($subscription)[1], 'la fin de période ne recule jamais');
        $this->assertCount(1, $periods, 'une facture, une période payée');
        $this->assertSame([(int) $subscription->getId(), MV_TEST_TENANT_CODE, "in_p_$this->tag", $t, $t + 10 * 86400], [$periods[0]->subscriptionId, $periods[0]->tenantCode, $periods[0]->invoiceId, $periods[0]->periodStart, $periods[0]->periodEnd]);
        $this->assertSame(1, (int) $this->one('SELECT COUNT(*) FROM subscription_period_paid WHERE invoice_id = ?', ["in_p_$this->tag"]));

        // résilié, puis une facture payée en retard : l'abonnement reste résilié
        $this->webhook("evt_e_$this->tag", $t + 2, 'customer.subscription.deleted', ['id' => $stripeId, 'status' => 'canceled', 'current_period_end' => $p2]);
        $this->assertSame(Subscription::STATUS_CANCELED, $this->state($subscription)[0]);
        $this->webhook("evt_f_$this->tag", $t + 3, 'invoice.paid', ['id' => "in_q_$this->tag"] + $invoice);
        $this->assertSame(Subscription::STATUS_CANCELED, $this->state($subscription)[0], 'une facture ne ressuscite pas un abonnement résilié');

        // le journal : des états et des identifiants, jamais le contenu
        $log = $this->master->prepare('SELECT status, detail, attempts FROM stripe_event_log WHERE event_id = ?');
        $log->execute(["evt_a_$this->tag"]);
        $this->assertSame('processed', $log->fetch(\PDO::FETCH_ASSOC)['status']);
        $log->execute(["evt_b_$this->tag"]);
        $this->assertSame(['status' => 'ignored', 'detail' => 'out_of_order', 'attempts' => 1], $log->fetch(\PDO::FETCH_ASSOC));
        $columns = $this->master->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'stripe_event_log' ORDER BY ordinal_position")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['event_id', 'type', 'account', 'tenant_code', 'object_id', 'event_created', 'received_at', 'status', 'detail', 'attempts', 'last_error_class', 'processed_at'], $columns, 'aucune colonne pour le contenu de l\'événement');

        // un événement en échec est repris à la livraison suivante (ici plus ancien que le dernier appliqué : ignoré, mais bien repris)
        $this->master->prepare("UPDATE stripe_event_log SET status = 'failed' WHERE event_id = ?")->execute(["evt_a_$this->tag"]);
        $this->assertSame(['ignored', 'out_of_order'], array_values(array_intersect_key($this->webhook("evt_a_$this->tag", $t, 'customer.subscription.updated', ['id' => $stripeId, 'status' => 'canceled', 'current_period_end' => $p2]), ['status' => 1, 'detail' => 1])));
        $log->execute(["evt_a_$this->tag"]);
        $this->assertSame(2, $log->fetch(\PDO::FETCH_ASSOC)['attempts']);

        // un doublon reçu PENDANT le traitement : 409, pour que Stripe livre de nouveau (jamais 200, qui le tiendrait pour livré)
        $this->master->prepare("INSERT INTO stripe_event_log (event_id, type, received_at, status) VALUES (?, 'customer.subscription.updated', ?, 'received')")->execute(["evt_g_$this->tag", gmdate('Y-m-d H:i:s')]);
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/stripe/webhook', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['id' => "evt_g_$this->tag", 'created' => $t + 10, 'type' => 'customer.subscription.updated', 'data' => ['object' => ['id' => $stripeId, 'object' => 'subscription', 'status' => 'active', 'metadata' => ['tenant_code' => MV_TEST_TENANT_CODE]]]]));
        $this->assertSame([409, 'in_progress'], [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true)['status']]);
        // un abonnement pas encore enregistré : récent, 409 (Stripe livre de nouveau) ; ancien, ignoré mais repris à un renvoi manuel
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/stripe/webhook', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['id' => "evt_n_$this->tag", 'created' => time(), 'type' => 'customer.subscription.updated', 'data' => ['object' => ['id' => "sub_absent_$this->tag", 'object' => 'subscription', 'status' => 'active', 'metadata' => ['tenant_code' => MV_TEST_TENANT_CODE]]]]));
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->assertSame('failed', $this->one('SELECT status FROM stripe_event_log WHERE event_id = ?', ["evt_n_$this->tag"]), 'rendu à la reprise');
        $this->assertSame(['ignored', 'abonnement inconnu'], array_values(array_intersect_key($this->webhook("evt_h_$this->tag", $t - 3600, 'customer.subscription.updated', ['id' => "sub_absent_$this->tag", 'status' => 'active']), ['status' => 1, 'detail' => 1])));
        $this->assertSame('ignored', $this->webhook("evt_h_$this->tag", $t - 3600, 'customer.subscription.updated', ['id' => "sub_absent_$this->tag", 'status' => 'active'])['status'], 'repris, pas « duplicate »');
    }

    public function testALatePaymentFailureOnAnUnpaidSubscriptionIsNotLost(): void
    {
        [$subscription, $stripeId] = $this->activeSubscription();
        $t = time();
        // Stripe : l'abonnement passe impayé (instantané plus récent), puis l'échec de la facture arrive en retard
        $this->webhook("evt_u_$this->tag", $t + 5, 'customer.subscription.updated', ['id' => $stripeId, 'status' => 'past_due', 'current_period_end' => $t + 30 * 86400]);
        $late = $this->webhook("evt_v_$this->tag", $t, 'invoice.payment_failed', ['id' => "in_v_$this->tag", 'subscription' => $stripeId, 'attempt_count' => 1, 'next_payment_attempt' => $t + 3 * 86400, 'amount_due' => 4900, 'currency' => 'cad', 'billing_reason' => 'subscription_cycle']);
        $this->assertSame('processed', $late['status'], 'impayé noté malgré le retard');
        $issue = $this->provider->getEntityManager()->getConnection()->fetchOne('SELECT payment_issue FROM subscription WHERE id = ?', [$subscription->getId()]);
        $this->assertSame(4900, json_decode((string) $issue, true)['amountDue']);
        // la même facture déjà payée : l'échec en retard est ignoré
        $this->webhook("evt_w_$this->tag", $t + 6, 'invoice.paid', ['id' => "in_x_$this->tag", 'subscription' => $stripeId, 'total' => 0, 'amount_paid' => 0, 'lines' => ['data' => [['period' => ['start' => $t, 'end' => $t + 30 * 86400]]]]]);
        $this->webhook("evt_y_$this->tag", $t + 7, 'customer.subscription.updated', ['id' => $stripeId, 'status' => 'past_due', 'current_period_end' => $t + 30 * 86400]);
        $this->assertSame('ignored', $this->webhook("evt_z_$this->tag", $t + 1, 'invoice.payment_failed', ['id' => "in_x_$this->tag", 'subscription' => $stripeId, 'attempt_count' => 2, 'next_payment_attempt' => $t + 86400])['status']);
        // la même facture payée, puis un échec qui n'est PAS plus ancien que le dernier instantané : ignoré quand même, rien résilié
        $paidLate = $this->webhook("evt_z2_$this->tag", $t + 30, 'invoice.payment_failed', ['id' => "in_x_$this->tag", 'subscription' => $stripeId, 'attempt_count' => 4]);
        $this->assertSame(['ignored', 'facture déjà payée'], [$paidLate['status'], $paidLate['detail']]);
        $this->assertNotSame(Subscription::STATUS_CANCELED, $this->state($subscription)[0]);
        // l'échec d'un essai plus ancien que celui déjà noté n'écrase pas l'impayé
        $this->webhook("evt_z3_$this->tag", $t + 31, 'invoice.payment_failed', ['id' => "in_n_$this->tag", 'subscription' => $stripeId, 'attempt_count' => 3, 'next_payment_attempt' => $t + 86400, 'amount_due' => 4900]);
        $this->assertSame('ignored', $this->webhook("evt_z4_$this->tag", $t + 32, 'invoice.payment_failed', ['id' => "in_n_$this->tag", 'subscription' => $stripeId, 'attempt_count' => 2, 'next_payment_attempt' => $t + 86400])['status']);
        $this->assertSame(3, json_decode((string) $this->provider->getEntityManager()->getConnection()->fetchOne('SELECT payment_issue FROM subscription WHERE id = ?', [$subscription->getId()]), true)['attemptCount']);
    }

    public function testTheReplayButtonTheCommandAndOrdinaryPlans(): void
    {
        // « Rejouer » : super administrateur et jeton CSRF exigés (noyau relancé entre les requêtes, comme en vrai : session et OTP)
        $this->client->enableReboot();
        $db = $this->provider->getEntityManager()->getConnection();
        $db->executeStatement('UPDATE subscription_plan SET fulfillment_ref = ? WHERE id = ?', ['offre-inconnue', $this->planId]);
        $this->provider->getEntityManager()->clear();
        [$subscription, $replayStripe] = $this->activeSubscription();
        FakeSubscriptionStripeGateway::$subscriptions[$replayStripe] = ['id' => $replayStripe, 'status' => 'active', 'currentPeriodEnd' => time() + 30 * 86400, 'cancelAtPeriodEnd' => false, 'paused' => false];
        $row = $this->retryRow((int) $subscription->getId());
        $this->assertNotNull($row);
        $db->executeStatement('UPDATE subscription_plan SET fulfillment_ref = ? WHERE id = ?', ['boussole', $this->planId]);
        $this->provider->getEntityManager()->clear();
        $url = 'https://' . MV_TEST_TENANT_HOST . '/admin/plateforme/offres/reprise/' . $row['id'];
        $this->loginAs(['ROLE_ADMIN', 'ROLE_USER_INTERNET']);
        $this->client->request('POST', $url, ['_token' => 'x'], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'administrateur de site : refusé');
        $this->loginAs(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $this->client->request('POST', $url, ['_token' => 'faux'], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertNotNull($this->retryRow((int) $subscription->getId()), 'jeton CSRF faux : rien n\'est fait');
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/plateforme', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertStringContainsString('Paiements des offres', (string) $this->client->getResponse()->getContent());
        $token = $crawler->filter('form[action$="/offres/reprise/' . $row['id'] . '"] input[name="_token"]')->attr('value');
        $this->client->request('POST', $url, ['_token' => $token], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertNull($this->retryRow((int) $subscription->getId()), 'rejoué : droits posés, file vidée');
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->assertTrue(static::getContainer()->get(EntitlementService::class)->isEnabled(MV_TEST_TENANT2_CODE, 'module.boussole'));

        // la passe de nuit par la commande : purge du journal à 90 jours ; une seconde exécution simultanée ne fait rien
        $this->master->prepare("INSERT INTO stripe_event_log (event_id, type, received_at, status) VALUES (?, 'invoice.paid', ?, 'processed')")->execute(["evt_old_$this->tag", gmdate('Y-m-d H:i:s', time() - 91 * 86400)]);
        $reconciler = static::getContainer()->get(OfferReconciler::class);
        $this->assertTrue($reconciler->acquireRun());
        $busy = new CommandTester((new Application(static::$kernel))->find('app:platform:offers:reconcile'));
        $other = new \PDO(...self::masterDsn());
        $held = $other->query("SELECT pg_try_advisory_lock(hashtext('platform-offers-reconcile'))")->fetchColumn();
        $this->assertFalse(filter_var($held, FILTER_VALIDATE_BOOLEAN), 'le verrou de la commande est tenu');
        $reconciler->releaseRun();
        // une autre exécution tient le verrou : la commande sort sans rien faire
        $this->assertTrue(filter_var($other->query("SELECT pg_try_advisory_lock(hashtext('platform-offers-reconcile'))")->fetchColumn(), FILTER_VALIDATE_BOOLEAN));
        $busy->execute(['--all' => true]);
        $this->assertStringContainsString('Déjà en cours', $busy->getDisplay());
        $this->assertSame(1, (int) $this->one('SELECT COUNT(*) FROM stripe_event_log WHERE event_id = ?', ["evt_old_$this->tag"]), 'rien purgé pendant qu\'une autre exécution tourne');
        $other->query("SELECT pg_advisory_unlock(hashtext('platform-offers-reconcile'))");
        $other = null;
        FakeSubscriptionStripeGateway::$subscriptions[$subscription->getStripeSubscriptionId()] = ['id' => $subscription->getStripeSubscriptionId(), 'status' => 'active', 'currentPeriodEnd' => time() + 30 * 86400, 'cancelAtPeriodEnd' => false, 'paused' => false];
        $busy->execute(['--all' => true]);
        $this->assertStringContainsString('Réconciliation : 1 abonnement(s) vu(s)', $busy->getDisplay());
        $this->assertSame(0, (int) $this->one('SELECT COUNT(*) FROM stripe_event_log WHERE event_id = ?', ["evt_old_$this->tag"]), 'purgé à 90 jours');

        // une formule ordinaire (non branchée) : aucune file, aucun droit, hors de la réconciliation
        $db->executeStatement('DELETE FROM subscription WHERE fulfillment_target IS NOT NULL');
        $db->executeStatement('UPDATE subscription_plan SET fulfillment_type = ?, fulfillment_ref = NULL WHERE id = ?', [SubscriptionPlan::FULFILLMENT_NONE, $this->planId]);
        $this->provider->getEntityManager()->clear();
        $this->cleanMaster();
        [$ordinary] = $this->activeSubscription();
        $this->assertNull($this->retryRow((int) $ordinary->getId()));
        $this->assertSame(0, (int) $this->one("SELECT COUNT(*) FROM tenant_entitlements WHERE reference LIKE 'subscription:%'", []));
        $this->assertSame(0, $reconciler->reconcileAll()['checked']);
    }

    public function testRightsThatFailAreQueuedAlertedThenReplayed(): void
    {
        $db = $this->provider->getEntityManager()->getConnection();
        $db->executeStatement('UPDATE subscription_plan SET fulfillment_ref = ? WHERE id = ?', ['offre-inconnue', $this->planId]);
        $this->provider->getEntityManager()->clear();
        [$subscription, $stripeId] = $this->activeSubscription();
        $payments = static::getContainer()->get(OfferPaymentsRepository::class);
        $row = $this->retryRow((int) $subscription->getId());
        $this->assertNotNull($row, 'l\'échec de pose entre dans la file de reprise');
        $this->assertSame([0, 'InvalidArgumentException'], [(int) $row['attempts'], $row['last_error_class']]);
        $this->assertGreaterThan(time() + 200, strtotime($row['next_retry_at'] . ' UTC'), 'première reprise dans 5 minutes');
        FakeSubscriptionStripeGateway::$subscriptions[$stripeId] = ['id' => $stripeId, 'status' => 'active', 'currentPeriodEnd' => time() + 30 * 86400, 'cancelAtPeriodEnd' => false, 'paused' => false];

        // cinq échecs de reprise : paliers croissants, puis un incident de la plateforme (une fois)
        $reconciler = static::getContainer()->get(OfferReconciler::class);
        $delays = [];
        for ($i = 1; $i <= 5; ++$i) {
            $this->master->prepare('UPDATE platform_offer_retry SET next_retry_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 1), $row['id']]);
            $this->assertSame(['done' => 0, 'failed' => 1], $reconciler->retryDue());
            $after = $this->retryRow((int) $subscription->getId());
            $this->assertSame($i, (int) $after['attempts']);
            $delays[] = strtotime($after['next_retry_at'] . ' UTC') - time();
        }
        $this->assertTrue($delays[0] < $delays[1] && $delays[1] < $delays[2] && $delays[2] < $delays[3], 'paliers croissants');
        $this->assertTrue(filter_var($this->retryRow((int) $subscription->getId())['alerted'], FILTER_VALIDATE_BOOLEAN));
        $incident = $this->master->prepare("SELECT level, tenant_code, message FROM platform_incident WHERE route = ?");
        $incident->execute(['offres/abonnement/' . $subscription->getId()]);
        $this->assertSame(['level' => 'error', 'tenant_code' => MV_TEST_TENANT_CODE, 'message' => 'Paiements des offres : droits non posés après 5 reprises'], $incident->fetch(\PDO::FETCH_ASSOC));

        // l'offre corrigée : la commande de reprise pose les droits et vide la file
        $db->executeStatement('UPDATE subscription_plan SET fulfillment_ref = ? WHERE id = ?', ['boussole', $this->planId]);
        $this->provider->getEntityManager()->clear();
        $this->master->prepare('UPDATE platform_offer_retry SET next_retry_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 1), $row['id']]);
        $command = new CommandTester((new Application(static::$kernel))->find('app:platform:offers:reconcile'));
        $command->execute([]);
        $this->assertStringContainsString('1 réussie(s), 0 en échec', $command->getDisplay());
        $this->assertNull($this->retryRow((int) $subscription->getId()));
        $this->assertTrue(static::getContainer()->get(EntitlementService::class)->isEnabled(MV_TEST_TENANT2_CODE, 'module.boussole'));
        $this->assertSame([], $payments->allRetries());
    }

    public function testTheNightlyReconciliationFixesOnlyItsOwnRights(): void
    {
        $s = static::getContainer()->get(EntitlementService::class);
        $manual = $s->grant(MV_TEST_TENANT2_CODE, 'module.landing', 'manual', null, 'test', 'droit d\'une autre origine');
        [$canceled, $canceledStripe] = $this->activeSubscription();
        $this->assertTrue($s->isEnabled(MV_TEST_TENANT2_CODE, 'module.boussole'));
        // Stripe dit « résilié », la ligne locale dit « actif » (webhook perdu)
        FakeSubscriptionStripeGateway::$subscriptions[$canceledStripe] = ['id' => $canceledStripe, 'status' => 'canceled', 'currentPeriodEnd' => time() + 30 * 86400, 'cancelAtPeriodEnd' => false, 'paused' => false, 'canceledAt' => time()];
        $reconciler = static::getContainer()->get(OfferReconciler::class);
        $out = $reconciler->reconcileAll();
        $this->assertSame([1, 1, 0, 0], [$out['checked'], $out['stripeFixed'], $out['rightsFixed'], $out['failed']]);
        $this->assertSame(Subscription::STATUS_CANCELED, $this->state($canceled)[0]);
        $this->assertFalse($s->isEnabled(MV_TEST_TENANT2_CODE, 'module.boussole'), 'le droit de l\'abonnement suit');
        $this->assertSame('active', $s->find((int) $manual['id'])['status'], 'un droit d\'une autre origine n\'est jamais touché');
        $this->assertNotNull($this->fresh($canceled)->getStripeEventAt(), 'l\'état relu chez Stripe vaut jusqu\'à maintenant');

        // droit d'un abonnement actif éteint à tort : rallumé, incident noté
        [$active, $activeStripe] = $this->activeSubscription();
        FakeSubscriptionStripeGateway::$subscriptions[$activeStripe] = ['id' => $activeStripe, 'status' => 'active', 'currentPeriodEnd' => $this->state($active)[1], 'cancelAtPeriodEnd' => false, 'paused' => false];
        $row = $this->master->query("SELECT id FROM tenant_entitlements WHERE reference = 'subscription:" . MV_TEST_TENANT_CODE . ':' . $active->getId() . "' AND status = 'active'")->fetchColumn();
        $s->suspend((int) $row, 'test', 'éteint à tort');
        $this->assertFalse($s->isEnabled(MV_TEST_TENANT2_CODE, 'module.boussole'));
        $out = $reconciler->reconcileAll();
        $this->assertSame([0, 1], [$out['stripeFixed'], $out['rightsFixed']]);
        $this->assertTrue($s->isEnabled(MV_TEST_TENANT2_CODE, 'module.boussole'));
        $this->assertSame([], static::getContainer()->get(\App\Services\Platform\OfferFulfillmentService::class)->drift($this->fresh($active), MV_TEST_TENANT_CODE));
        $incident = $this->master->prepare('SELECT message FROM platform_incident WHERE route = ?');
        $incident->execute(['offres/abonnement/' . $active->getId()]);
        $this->assertSame('Paiements des offres : droits corrigés d\'après l\'abonnement', $incident->fetchColumn());
        // rien à corriger : une nuit calme ne note rien
        $this->assertSame([0, 0, 0], [($out = $reconciler->reconcileAll())['stripeFixed'], $out['rightsFixed'], $out['failed']]);

        // un droit MANUEL posé sur la MÊME fonctionnalité (il remplace celui de l'abonnement) : la nuit n'y touche jamais, ni la reprise
        $same = $s->grant(MV_TEST_TENANT2_CODE, 'module.boussole', 'free', null, 'test', 'offert à la main');
        $out = $reconciler->reconcileAll();
        $this->assertSame([0, 0], [$out['rightsFixed'], $out['failed']]);
        $this->assertSame('active', $s->find((int) $same['id'])['status'], 'le droit gratuit reste');
        $this->assertSame(['module.boussole: covered'], static::getContainer()->get(\App\Services\Platform\OfferFulfillmentService::class)->resync($this->fresh($active), MV_TEST_TENANT_CODE));
        $this->assertSame('active', $s->find((int) $same['id'])['status'], 'la reprise ne le remplace pas non plus');

        // un ESSAI qui finit avant la période payée ne couvre pas : la reprise accorde le droit de l'abonnement (le client payant le garde)
        $trial = $s->grant(MV_TEST_TENANT2_CODE, 'module.boussole', 'trial', (new \DateTimeImmutable())->modify('+3 days'), 'test', 'essai court');
        $this->assertSame(['module.boussole: granted'], static::getContainer()->get(\App\Services\Platform\OfferFulfillmentService::class)->resync($this->fresh($active), MV_TEST_TENANT_CODE));
        $this->assertSame('cancelled', $s->find((int) $trial['id'])['status'], 'l\'essai court est remplacé, comme par le chemin ordinaire');
    }

    /** Un abonnement branché, passé d'incomplet à actif (l'écouteur pose les droits du site 2) @return array{0: Subscription, 1: string} */
    private function activeSubscription(): array
    {
        $em = $this->provider->getEntityManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'admin@arkanoa-media.com']);
        $stripeId = 'sub_p3_' . bin2hex(random_bytes(4));
        $subscription = (new Subscription())->setUser($user)->setPlan($em->getRepository(SubscriptionPlan::class)->find($this->planId))->setQuantity(1)
            ->setFulfillmentTarget(MV_TEST_TENANT2_CODE)->setStripeSubscriptionId($stripeId);
        $em->persist($subscription);
        $em->flush();
        $subscription->setStatus(Subscription::STATUS_ACTIVE)->setCurrentPeriodEnd((new \DateTimeImmutable())->setTimestamp(time() + 30 * 86400));
        $em->flush();

        return [$subscription, $stripeId];
    }

    /** @return array{0: string, 1: ?int} statut et fin de période relus en base */
    private function state(Subscription $subscription): array
    {
        $row = $this->provider->getEntityManager()->getConnection()->fetchAssociative('SELECT status, current_period_end FROM subscription WHERE id = ?', [$subscription->getId()]);

        return [$row['status'], $row['current_period_end'] !== null ? (new \DateTimeImmutable($row['current_period_end']))->getTimestamp() : null];
    }

    private function webhook(string $id, int $created, string $type, array $object): array
    {
        $this->client->getCookieJar()->clear();
        $object['metadata'] = ['tenant_code' => MV_TEST_TENANT_CODE];
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/stripe/webhook', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['id' => $id, 'created' => $created, 'type' => $type, 'data' => ['object' => $object + ['object' => str_starts_with($type, 'invoice') ? 'invoice' : 'subscription']]]));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function loginAs(array $roles): void
    {
        $container = static::getContainer();
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $email = 'offres-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Offres')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class)->hashPassword($user, 'Mot-de-passe-de-test-1!'));
        $this->provider->getEntityManager()->persist($user);
        $this->provider->getEntityManager()->flush();
        $this->client->loginUser($user, 'main');
        \App\Tests\Functional\Support\OtpTestHelper::validateIfRequired($this->client, $user);
    }

    /** @return array{0: string, 1: string, 2: string} DSN, utilisateur, mot de passe de la base maître des essais */
    private static function masterDsn(): array
    {
        $u = parse_url((string) ($_SERVER['MASTER_DATABASE_URL'] ?? $_ENV['MASTER_DATABASE_URL'] ?? getenv('MASTER_DATABASE_URL')));

        return [sprintf('pgsql:host=%s;port=%d;dbname=%s', $u['host'], $u['port'] ?? 5432, MV_TEST_MASTER_DB), urldecode($u['user']), urldecode($u['pass'])];
    }

    private function fresh(Subscription $subscription): Subscription
    {
        $em = $this->provider->getEntityManager();
        $em->clear();

        return $em->find(Subscription::class, $subscription->getId());
    }

    private function retryRow(int $subscriptionId): ?array
    {
        $stmt = $this->master->prepare('SELECT * FROM platform_offer_retry WHERE tenant_code = ? AND subscription_id = ?');
        $stmt->execute([MV_TEST_TENANT_CODE, $subscriptionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function one(string $sql, array $params): mixed
    {
        $stmt = $this->master->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }

    private function cleanMaster(): void
    {
        foreach (['tenant_entitlement_events', 'tenant_entitlements', 'tenant_feature_requests'] as $table) {
            $this->master->prepare("DELETE FROM $table WHERE tenant_code IN (?, ?)")->execute([MV_TEST_TENANT_CODE, MV_TEST_TENANT2_CODE]);
        }
        $this->master->exec('DELETE FROM platform_offer_retry');
        $this->master->exec("DELETE FROM platform_incident_event WHERE incident_id IN (SELECT id FROM platform_incident WHERE route LIKE 'offres/abonnement/%')");
        $this->master->exec("DELETE FROM platform_incident WHERE route LIKE 'offres/abonnement/%'");
    }
}
