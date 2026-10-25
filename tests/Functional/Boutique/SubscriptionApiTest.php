<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\Adress;
use App\Entity\User;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeSubscriptionStripeGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * § 11 : abonnements (formules, souscription, gestion, portail, webhooks Stripe Billing) avec Stripe simulé.
 */
class SubscriptionApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';

    private KernelBrowser $client;
    private array $session = [];
    private \Doctrine\DBAL\Connection $db;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeSubscriptionStripeGateway::reset();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-abonnement@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
    }

    public function testPlansAreListedPerProductInTheRequestedLanguage(): void
    {
        $product = $this->productId('DEMO-PANIER');
        $plans = $this->json($this->request('GET', "/api/subscription-plans?productId=$product"));
        $this->assertSame(['Panier hebdomadaire', 'Panier aux deux semaines', 'Panier mensuel'], array_column($plans, 'name'));
        $this->assertSame(['week', 1, 3500, 'CAD', 0, 0], [$plans[0]['interval'], $plans[0]['intervalCount'], $plans[0]['price'], $plans[0]['currency'], $plans[0]['trialDays'], $plans[0]['minimumTerms']]);
        $this->assertSame([7, 14], [$plans[1]['trialDays'], $plans[2]['trialDays']]);
        $this->assertSame('Weekly basket', $this->json($this->request('GET', "/api/subscription-plans?productId=$product&locale=en"))[0]['name']);
        $this->assertSame([], $this->json($this->request('GET', '/api/subscription-plans?productId=' . $this->productId('DEMO-CASQUETTE'))), 'produit sans formule');
    }

    public function testPlanGridFieldsAndOneHighlightedPlanPerProduct(): void
    {
        // Grille de formules (09/10/2026) : sans productId, toutes les formules actives ; avantages, sous-titre, badge par langue
        $all = $this->json($this->request('GET', '/api/subscription-plans'));
        $basket = array_values(array_filter($all, fn ($p) => $p['productId'] === $this->productId('DEMO-PANIER')));
        $this->assertCount(3, $basket);
        $featured = array_values(array_filter($basket, fn ($p) => $p['highlighted']));
        $this->assertCount(1, $featured, 'une seule formule recommandée');
        $this->assertSame(['Le juste milieu', 'Le plus choisi'], [$featured[0]['description'], $featured[0]['badge']]);
        $this->assertContains('Sans engagement', $featured[0]['features']);
        $this->assertSame('Panier bio de la semaine', $featured[0]['productName']);
        $en = $this->json($this->request('GET', '/api/subscription-plans?locale=en&productId=' . $this->productId('DEMO-PANIER')));
        $this->assertSame('Most popular', array_values(array_filter($en, fn ($p) => $p['highlighted']))[0]['badge']);

        // Texte en clair et bornes, une seule mise en avant par produit
        $em = static::getContainer()->get(\App\Services\TenantEntityManagerProvider::class)->getEntityManager();
        $plan = $em->getRepository(\App\Entity\SubscriptionPlan::class)->find($basket[0]['id']);
        $plan->setFeatures(['fr' => array_merge(['<b>Gras</b> & net', ''], array_fill(0, 30, 'Avantage'))])->setDescriptions(['fr' => '<script>x</script>Court'])->setHighlighted(true);
        $em->flush();
        $em->getRepository(\App\Entity\SubscriptionPlan::class)->keepOnlyHighlighted($plan);
        $this->assertSame(['Gras & net', 20, 'xCourt'], [$plan->getFeatureList()[0], count($plan->getFeatureList()), $plan->getDescription()]);
        $highlighted = (int) $this->db->fetchOne('SELECT COUNT(*) FROM subscription_plan WHERE product_id = ? AND highlighted', [$this->productId('DEMO-PANIER')]);
        $this->assertSame(1, $highlighted);
    }

    public function testCustomerSubscribesManagesAndReceivesOrdersFromPaidInvoices(): void
    {
        $this->loginAs(['ROLE_USER_INTERNET']);
        $product = $this->productId('DEMO-PANIER');
        $plans = $this->json($this->request('GET', "/api/subscription-plans?productId=$product"));
        $address = $this->address();

        $created = $this->request('POST', '/api/subscriptions', json_encode(['planId' => $plans[0]['id'], 'quantity' => 2, 'addressId' => $address, 'carrierId' => 2]));
        $this->assertSame(422, $created->getStatusCode(), 'transporteur EasyPost (tarif variable) refusé pour un abonnement');
        $created = $this->request('POST', '/api/subscriptions', json_encode(['planId' => $plans[0]['id'], 'quantity' => 2, 'addressId' => $address, 'carrierId' => 1]));
        $this->assertSame(201, $created->getStatusCode(), $created->getContent());
        $body = json_decode($created->getContent(), true);
        $this->assertSame('incomplete', $body['status']);
        $this->assertStringStartsWith('pi_test_secret_sub_test_', $body['clientSecret']);
        $this->assertSame('payment', $body['intentType'], 'première facture à payer : confirmPayment');
        $shipping = (int) round((float) $this->db->fetchOne('SELECT price FROM carrier WHERE id = 1'));
        $this->assertSame([2, 'incomplete', false, 1, $shipping], [$body['subscription']['quantity'], $body['subscription']['status'], $body['subscription']['cancelAtPeriodEnd'], $body['subscription']['carrier']['id'], $body['subscription']['shippingAmount']]);
        $create = FakeSubscriptionStripeGateway::$calls[array_search('create', array_column(FakeSubscriptionStripeGateway::$calls, 0), true)];
        $this->assertSame(MV_TEST_TENANT_CODE, $create[5]['tenant_code'], 'le site voyage dans les métadonnées Stripe');
        $this->assertSame(['txr_test_TPS', 'txr_test_TVQ'], $create[6], 'taux de taxe de la table pour l\'adresse (QC)');
        $this->assertFalse($create[7], 'fournisseur table : pas de taxe automatique Stripe');
        $this->assertSame("price_test_shipping_1_$shipping", $create[8], 'la livraison à prix fixe est une seconde ligne récurrente');
        $id = $body['subscriptionId'];

        // Facture payée (webhook) : abonnement actif, commande créée avec les montants de la facture
        $stripeId = $this->db->fetchOne('SELECT stripe_subscription_id FROM subscription WHERE id = ?', [$id]);
        $periodEnd = (new \DateTimeImmutable('+7 days'))->getTimestamp();
        $webhook = $this->webhook('invoice.paid', ['id' => 'in_test_1', 'subscription' => $stripeId, 'paid' => true, 'subtotal' => 7000 + $shipping, 'tax' => 1048, 'total' => 8048 + $shipping, 'amount_paid' => 8048 + $shipping,
            'payment_intent' => 'pi_test_invoice_1', 'lines' => ['data' => [['period' => ['end' => $periodEnd], 'metadata' => ['tenant_code' => MV_TEST_TENANT_CODE]]]],
            'subscription_details' => ['metadata' => ['tenant_code' => MV_TEST_TENANT_CODE]]]);
        $this->assertSame(200, $webhook->getStatusCode(), $webhook->getContent());
        $this->assertSame('processed', json_decode($webhook->getContent(), true)['status'], $webhook->getContent());
        $this->assertStringStartsWith('commande ', json_decode($webhook->getContent(), true)['detail'], $webhook->getContent());
        $detail = $this->json($this->request('GET', "/api/subscriptions/$id"));
        $this->assertSame('active', $detail['status']);
        $this->assertSame(1, count($detail['orders']), json_encode($detail));
        $this->assertSame(8048 + $shipping, $detail['orders'][0]['total']);
        $order = $this->db->fetchAssociative('SELECT sub_total, total_tax, total_amount, shipping_cost, stripe_invoice_id, subscription_id FROM "order" WHERE id = ?', [$detail['orders'][0]['id']]);
        $this->assertSame([7000.0 + $shipping, 1048.0, 8048.0 + $shipping, (float) $shipping, 'in_test_1', $id], [(float) $order['sub_total'], (float) $order['total_tax'], (float) $order['total_amount'], (float) $order['shipping_cost'], $order['stripe_invoice_id'], (int) $order['subscription_id']]);
        $this->assertSame(3500.0, (float) $this->db->fetchOne('SELECT unit_price FROM order_items WHERE order_associated_id = ?', [$detail['orders'][0]['id']]), 'prix unitaire sans la livraison');
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM payments WHERE stripe_payment_id = ?', ['pi_test_invoice_1']));
        $this->assertContains(['ensureDefaultPaymentMethod', $stripeId, 'in_test_1'], FakeSubscriptionStripeGateway::$calls, 'la carte qui a payé devient celle de l\'abonnement');
        $this->assertSame('pm_test_card', FakeSubscriptionStripeGateway::$subscriptions[$stripeId]['defaultPaymentMethod']);
        $this->webhook('invoice.paid', ['id' => 'in_test_1', 'subscription' => $stripeId, 'paid' => true, 'total' => 8048 + $shipping, 'amount_paid' => 8048 + $shipping, 'subscription_details' => ['metadata' => ['tenant_code' => MV_TEST_TENANT_CODE]]]);
        $this->assertSame(1, count($this->json($this->request('GET', "/api/subscriptions/$id"))['orders']), 'facture rejouée : pas de seconde commande');

        // Facture à 0 (essai, changement de formule sans montant) : pas de commande
        $zero = $this->webhook('invoice.paid', ['id' => 'in_test_zero', 'subscription' => $stripeId, 'paid' => true, 'subtotal' => 0, 'total' => 0, 'amount_paid' => 0, 'subscription_details' => ['metadata' => ['tenant_code' => MV_TEST_TENANT_CODE]]]);
        $this->assertStringContainsString('facture à 0', json_decode($zero->getContent(), true)['detail'] ?? '', $zero->getContent());
        $this->assertNotContains(['ensureDefaultPaymentMethod', $stripeId, 'in_test_zero'], FakeSubscriptionStripeGateway::$calls, 'facture à 0 : aucune carte à garder');
        $this->assertSame(1, count($this->json($this->request('GET', "/api/subscriptions/$id"))['orders']), 'facture à 0 : aucune commande');

        // Gestion : pause, reprise, changement de formule, annulation à la fin de la période, reprise, annulation immédiate
        $this->assertSame('paused', $this->json($this->request('POST', "/api/subscriptions/$id/pause"))['status']);
        $this->assertSame('active', $this->json($this->request('POST', "/api/subscriptions/$id/resume"))['status']);
        $changed = $this->json($this->request('POST', "/api/subscriptions/$id/change-plan", json_encode(['planId' => $plans[2]['id']])));
        $this->assertSame('Panier mensuel', $changed['plan']['name']);
        $scheduled = $this->json($this->request('POST', "/api/subscriptions/$id/cancel", '{}'));
        $this->assertSame([true, 'active'], [$scheduled['cancelAtPeriodEnd'], $scheduled['status']], 'par défaut : fin de période');
        $this->assertFalse($this->json($this->request('POST', "/api/subscriptions/$id/resume"))['cancelAtPeriodEnd']);
        $canceled = $this->json($this->request('POST', "/api/subscriptions/$id/cancel", json_encode(['atPeriodEnd' => false])));
        $this->assertSame('canceled', $canceled['status']);
        $this->assertNotNull($canceled['canceledAt']);
        $this->assertSame(409, $this->request('POST', "/api/subscriptions/$id/resume")->getStatusCode());

        $portal = $this->json($this->request('POST', '/api/subscriptions/portal-session', json_encode(['returnUrl' => 'https://mvtest.test/dashboard'])));
        $this->assertStringStartsWith('https://billing.stripe.test/session/cus_test_', $portal['url']);
        $this->assertSame($id, $this->json($this->request('GET', '/api/subscriptions'))[0]['id']);
    }

    public function testTrialSubscriptionAsksToSaveTheCardAndAPaidInvoiceKeepsIt(): void
    {
        $this->loginAs(['ROLE_USER_INTERNET']);
        $product = $this->productId('DEMO-PANIER');
        $plan = $this->json($this->request('GET', "/api/subscription-plans?productId=$product"))[0];
        $this->db->executeStatement('UPDATE subscription_plan SET trial_days = 14 WHERE id = ?', [$plan['id']]);
        try {
            $created = $this->request('POST', '/api/subscriptions', json_encode(['planId' => $plan['id'], 'addressId' => $this->address(), 'carrierId' => 1]));
            $this->assertSame(201, $created->getStatusCode(), $created->getContent());
            $body = json_decode($created->getContent(), true);
            $this->assertSame(['trialing', 'setup'], [$body['status'], $body['intentType']], 'essai gratuit : rien à payer, la carte s\'enregistre (confirmSetup)');
            $this->assertStringStartsWith('seti_test_secret_', $body['clientSecret']);
            $this->assertFalse($body['subscription']['hasPaymentMethod'], 'aucune carte encore enregistrée');

            // client parti sans enregistrer sa carte : la même souscription reprend l'essai et son SetupIntent (pas de 409),
            // quel que soit l'appareil ; une autre quantité reste refusée
            $again = $this->request('POST', '/api/subscriptions', json_encode(['planId' => $plan['id'], 'addressId' => $this->address(), 'carrierId' => 1]));
            $this->assertSame(200, $again->getStatusCode(), $again->getContent());
            $resumed = json_decode($again->getContent(), true);
            $this->assertSame([true, 'setup', $body['clientSecret'], $body['subscriptionId']], [$resumed['reused'], $resumed['intentType'], $resumed['clientSecret'], $resumed['subscriptionId']]);
            $this->assertSame(409, $this->request('POST', '/api/subscriptions', json_encode(['planId' => $plan['id'], 'quantity' => 2]))->getStatusCode(), 'autre quantité : refusée');

            // carte enregistrée (confirmSetup) : Stripe envoie customer.subscription.updated avec la carte par défaut
            $stripeIdForSetup = $this->db->fetchOne('SELECT stripe_subscription_id FROM subscription WHERE id = ?', [$body['subscriptionId']]);
            $updated = $this->webhook('customer.subscription.updated', ['id' => $stripeIdForSetup, 'status' => 'trialing', 'default_payment_method' => 'pm_test_setup', 'cancel_at_period_end' => false, 'metadata' => ['tenant_code' => MV_TEST_TENANT_CODE]]);
            $this->assertSame('processed', json_decode($updated->getContent(), true)['status'], $updated->getContent());
            $this->assertTrue($this->json($this->request('GET', '/api/subscriptions/' . $body['subscriptionId']))['hasPaymentMethod']);
            FakeSubscriptionStripeGateway::$subscriptions[$stripeIdForSetup]['hasPaymentMethod'] = true;
            $this->assertSame(409, $this->request('POST', '/api/subscriptions', json_encode(['planId' => $plan['id'], 'addressId' => $this->address(), 'carrierId' => 1]))->getStatusCode(), 'essai avec carte : déjà abonné');
            unset(FakeSubscriptionStripeGateway::$subscriptions[$stripeIdForSetup]['defaultPaymentMethod']);

            // fin d'essai sans carte : facture payée depuis le courriel d'impayé, la carte devient celle de l'abonnement ;
            // une erreur Stripe à ce moment ne bloque pas le webhook
            $stripeId = $this->db->fetchOne('SELECT stripe_subscription_id FROM subscription WHERE id = ?', [$body['subscriptionId']]);
            FakeSubscriptionStripeGateway::$subscriptions[$stripeId]['failDefaultPaymentMethod'] = true;
            $failing = $this->webhook('invoice.paid', ['id' => 'in_test_trial_end', 'subscription' => $stripeId, 'paid' => true, 'subtotal' => 3500, 'total' => 3500, 'amount_paid' => 3500, 'payment_intent' => 'pi_test_trial_end',
                'subscription_details' => ['metadata' => ['tenant_code' => MV_TEST_TENANT_CODE]]]);
            $this->assertSame('processed', json_decode($failing->getContent(), true)['status'], $failing->getContent());
            $this->assertSame('active', $this->json($this->request('GET', '/api/subscriptions/' . $body['subscriptionId']))['status']);
            unset(FakeSubscriptionStripeGateway::$subscriptions[$stripeId]['failDefaultPaymentMethod']);
            FakeSubscriptionStripeGateway::$subscriptions[$stripeId]['invoicePaymentMethod'] = 'pm_test_from_email';
            $this->webhook('invoice.paid', ['id' => 'in_test_trial_end_2', 'subscription' => $stripeId, 'paid' => true, 'subtotal' => 3500, 'total' => 3500, 'amount_paid' => 3500, 'payment_intent' => 'pi_test_trial_end_2',
                'subscription_details' => ['metadata' => ['tenant_code' => MV_TEST_TENANT_CODE]]]);
            $this->assertSame('pm_test_from_email', FakeSubscriptionStripeGateway::$subscriptions[$stripeId]['defaultPaymentMethod']);
        } finally {
            $this->db->executeStatement('UPDATE subscription_plan SET trial_days = 0 WHERE id = ?', [$plan['id']]);
        }
    }

    public function testFailedPaymentAndStripeDeletionUpdateTheSubscription(): void
    {
        $this->loginAs(['ROLE_USER_INTERNET']);
        $plan = $this->json($this->request('GET', '/api/subscription-plans?productId=' . $this->productId('DEMO-PANIER')))[1];
        $body = json_decode($this->request('POST', '/api/subscriptions', json_encode(['planId' => $plan['id']]))->getContent(), true);
        $this->assertSame('trialing', $body['status'], 'formule avec essai gratuit');
        $stripeId = $this->db->fetchOne('SELECT stripe_subscription_id FROM subscription WHERE id = ?', [$body['subscriptionId']]);

        // Échéance refusée (1er essai) : past_due, échéance impayée renvoyée au client
        $next = time() + 3 * 86400;
        $invoice = fn (int $attempt, ?int $nextAttempt) => ['id' => 'in_test_2', 'subscription' => $stripeId, 'billing_reason' => 'subscription_cycle', 'attempt_count' => $attempt,
            'next_payment_attempt' => $nextAttempt, 'amount_due' => 3500, 'currency' => 'cad', 'hosted_invoice_url' => 'https://invoice.stripe.test/i/in_test_2',
            'subscription_details' => ['metadata' => ['tenant_code' => MV_TEST_TENANT_CODE]]];
        $failed = $this->webhook('invoice.payment_failed', $invoice(1, $next));
        $this->assertSame('past_due, essai 1 sur 4', json_decode($failed->getContent(), true)['detail'] ?? null, $failed->getContent());
        $read = $this->json($this->request('GET', '/api/subscriptions/' . $body['subscriptionId']));
        $this->assertSame('past_due', $read['status']);
        $this->assertSame([3500, 'CAD', 'https://invoice.stripe.test/i/in_test_2', 1, 4], [$read['latestInvoice']['amountDue'], $read['latestInvoice']['currency'], $read['latestInvoice']['hostedInvoiceUrl'], $read['latestInvoice']['attemptCount'], $read['latestInvoice']['maxAttempts']]);
        $this->assertSame((new \DateTimeImmutable())->setTimestamp($next)->format(\DateTimeInterface::ATOM), $read['latestInvoice']['nextPaymentAttempt']);
        $this->assertSame(409, $this->request('POST', '/api/subscriptions', json_encode(['planId' => $plan['id']]))->getStatusCode(), 'impayé : toujours un abonnement en cours');

        // 4e essai refusé : résilié par le site (appel à Stripe), même si Stripe prévoyait encore un essai
        $last = $this->webhook('invoice.payment_failed', $invoice(4, $next));
        $this->assertSame('résilié après 4 essai(s) de paiement', json_decode($last->getContent(), true)['detail'] ?? null, $last->getContent());
        $this->assertContains(['cancel', $stripeId, false], FakeSubscriptionStripeGateway::$calls);
        $read = $this->json($this->request('GET', '/api/subscriptions/' . $body['subscriptionId']));
        $this->assertSame(['canceled', null], [$read['status'], $read['latestInvoice']]);

        $this->webhook('customer.subscription.deleted', ['id' => $stripeId, 'status' => 'canceled', 'canceled_at' => time(), 'metadata' => ['tenant_code' => MV_TEST_TENANT_CODE]]);
        $this->assertSame('canceled', $this->json($this->request('GET', '/api/subscriptions/' . $body['subscriptionId']))['status']);

        $this->assertSame(['status' => 'ignored', 'detail' => 'abonnement inconnu'], json_decode($this->webhook('customer.subscription.updated', ['id' => 'sub_inconnu', 'metadata' => ['tenant_code' => MV_TEST_TENANT_CODE]])->getContent(), true));
        $this->assertSame('ignored', json_decode($this->webhook('customer.subscription.updated', ['id' => $stripeId])->getContent(), true)['status'], 'sans tenant_code');
    }

    public function testRefusedFirstPaymentKeepsTheSubscriptionIncompleteAndRetryable(): void
    {
        $this->loginAs(['ROLE_USER_INTERNET']);
        $plan = $this->json($this->request('GET', '/api/subscription-plans?productId=' . $this->productId('DEMO-PANIER')))[0];
        $body = json_decode($this->request('POST', '/api/subscriptions', json_encode(['planId' => $plan['id']]))->getContent(), true);
        $this->assertSame('incomplete', $body['status'], 'formule sans essai : premier paiement à confirmer');
        $stripeId = $this->db->fetchOne('SELECT stripe_subscription_id FROM subscription WHERE id = ?', [$body['subscriptionId']]);

        $failed = $this->webhook('invoice.payment_failed', ['id' => 'in_test_first', 'subscription' => $stripeId, 'billing_reason' => 'subscription_create', 'attempt_count' => 1,
            'next_payment_attempt' => null, 'amount_due' => 3500, 'currency' => 'cad', 'subscription_details' => ['metadata' => ['tenant_code' => MV_TEST_TENANT_CODE]]]);
        $this->assertStringContainsString('premier paiement refusé', json_decode($failed->getContent(), true)['detail'] ?? '', $failed->getContent());
        $this->assertSame('incomplete', $this->db->fetchOne('SELECT status FROM subscription WHERE id = ?', [$body['subscriptionId']]), 'carte refusée : pas de past_due');
        $retry = $this->request('POST', '/api/subscriptions', json_encode(['planId' => $plan['id']]));
        $this->assertSame(200, $retry->getStatusCode(), 'nouvel essai : même abonnement');
        $retry = json_decode($retry->getContent(), true);
        $this->assertSame([$body['subscriptionId'], $body['clientSecret'], 'incomplete', true], [$retry['subscriptionId'], $retry['clientSecret'], $retry['status'], $retry['reused']]);
    }

    public function testRefusalsAndIsolation(): void
    {
        $this->assertContains($this->request('POST', '/api/subscriptions', json_encode(['planId' => 1]))->getStatusCode(), [401, 403], 'invité');
        $this->loginAs(['ROLE_USER_INTERNET']);
        $plan = $this->json($this->request('GET', '/api/subscription-plans?productId=' . $this->productId('DEMO-PANIER')))[0]['id'];
        $this->assertSame(404, $this->request('POST', '/api/subscriptions', json_encode(['planId' => 999999]))->getStatusCode());
        $this->assertSame(422, $this->request('POST', '/api/subscriptions', json_encode(['planId' => $plan, 'quantity' => 0]))->getStatusCode());
        $this->assertSame(422, $this->request('POST', '/api/subscriptions', json_encode(['planId' => $plan, 'addressId' => 999999]))->getStatusCode(), 'adresse d\'un autre client ou inconnue');
        $this->assertSame(422, $this->request('POST', '/api/subscriptions', json_encode(['planId' => 'x']))->getStatusCode());

        $id = json_decode($this->request('POST', '/api/subscriptions', json_encode(['planId' => $plan]))->getContent(), true)['subscriptionId'];
        $this->loginAs(['ROLE_USER_INTERNET']);
        $this->assertSame(404, $this->request('GET', "/api/subscriptions/$id")->getStatusCode(), 'abonnement d\'un autre client');
        $this->assertSame(404, $this->request('POST', "/api/subscriptions/$id/cancel", '{}')->getStatusCode());
        $this->assertSame([], $this->json($this->request('GET', '/api/subscriptions')));

        $this->db->executeStatement("INSERT INTO boutique_setting (configuration) VALUES ('{\"commerce\": {\"subscriptionsEnabled\": false}}')");
        try {
            $this->assertSame(403, $this->request('POST', '/api/subscriptions', json_encode(['planId' => $plan]))->getStatusCode(), 'module désactivé');
        } finally {
            $this->db->executeStatement('DELETE FROM boutique_setting');
        }
    }

    public function testAdministratorManagesASubscriptionFromEasyAdmin(): void
    {
        $this->loginAs(['ROLE_USER_INTERNET']);
        $plans = $this->json($this->request('GET', '/api/subscription-plans?productId=' . $this->productId('DEMO-PANIER')));
        $created = $this->request('POST', '/api/subscriptions', json_encode(['planId' => $plans[0]['id'], 'quantity' => 1, 'addressId' => $this->address(), 'carrierId' => 1]));
        $this->assertSame(201, $created->getStatusCode(), $created->getContent());
        $id = json_decode($created->getContent(), true)['subscriptionId'];
        $stripeId = (string) $this->db->fetchOne('SELECT stripe_subscription_id FROM subscription WHERE id = ?', [$id]);
        FakeSubscriptionStripeGateway::$subscriptions[$stripeId]['status'] = 'active';
        $this->db->executeStatement("UPDATE subscription SET status = 'active' WHERE id = ?", [$id]);

        $this->loginAdmin();
        $click = function (string $action) use ($id): string {
            $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(['crudAction' => 'detail', 'crudControllerFqcn' => \App\Controller\Admin\SubscriptionCrudController::class, 'entityId' => $id]), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
            $this->assertSame(200, $this->client->getResponse()->getStatusCode(), substr(strip_tags((string) $this->client->getResponse()->getContent()), 0, 600));
            $link = $this->client->getCrawler()->filter('a[href*="crudAction=' . $action . '"]');
            $this->assertCount(1, $link, "action $action affichée");
            $this->client->request('GET', $link->attr('href'), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
            $this->client->followRedirect();

            return (string) $this->client->getResponse()->getContent();
        };
        $status = fn () => $this->db->fetchAssociative('SELECT status, cancel_at_period_end, plan_id FROM subscription WHERE id = ?', [$id]);

        $this->assertStringContainsString('Abonnement en pause', $click('pauseSubscription'));
        $this->assertSame('paused', $status()['status']);
        $click('resumeSubscription');
        $this->assertSame('active', $status()['status']);

        // Changement de formule : page de choix, puis envoi du formulaire
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(['crudAction' => 'detail', 'crudControllerFqcn' => \App\Controller\Admin\SubscriptionCrudController::class, 'entityId' => $id]), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->client->request('GET', $this->client->getCrawler()->filter('a[href*="crudAction=changePlan"]')->attr('href'), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertCount(2, $this->client->getCrawler()->filter('select[name="planId"] option'), 'les deux autres formules du produit');
        $form = $this->client->getCrawler()->filter('form')->last()->form(['planId' => (string) $plans[2]['id']]);
        $this->client->submit($form, [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame($plans[2]['id'], (int) $status()['plan_id']);

        $this->assertStringContainsString('Aucun paiement encaissé', $click('refundLatest'), 'rien à rembourser : message, aucun changement');
        FakeSubscriptionStripeGateway::$subscriptions[$stripeId]['paidAmount'] = 3500;
        $this->assertStringContainsString('Remboursement effectué : 35,00 CAD', $click('refundLatest'));

        FakeSubscriptionStripeGateway::$subscriptions[$stripeId]['status'] = 'past_due';
        $click('syncSubscription');
        $this->assertSame('past_due', $status()['status'], 'état relu chez Stripe');

        $click('cancelAtPeriodEnd');
        $this->assertTrue((bool) $status()['cancel_at_period_end']);
        $click('cancelNow');
        $this->assertSame('canceled', $status()['status']);
        $this->assertSame(0, $this->client->getCrawler()->filter('a[href*="crudAction=cancelNow"]')->count(), 'abonnement résilié : plus d\'action de résiliation');

        // Lien sans jeton : refusé
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(['crudAction' => 'pauseSubscription', 'crudControllerFqcn' => \App\Controller\Admin\SubscriptionCrudController::class, 'entityId' => $id]), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->client->followRedirect();
        $this->assertStringContainsString('Lien invalide ou expiré', (string) $this->client->getResponse()->getContent());
    }

    private function webhook(string $type, array $object): Response
    {
        $this->session = $this->session; // les webhooks sont anonymes : aucun cookie n'est envoyé
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/stripe/webhook', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['id' => 'evt_' . bin2hex(random_bytes(4)), 'type' => $type, 'data' => ['object' => $object + ['object' => str_starts_with($type, 'invoice') ? 'invoice' : 'subscription']]]));

        return $this->client->getResponse();
    }

    private function productId(string $code): int
    {
        return (int) $this->db->fetchOne('SELECT id FROM product WHERE code = ?', [$code]);
    }

    private function address(): int
    {
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $this->session['email']]);
        $address = (new Adress())->setFirstname('Abo')->setLastname('Test')->setFullname('Abo Test')->setAddress('2 rue du Test')->setCity('Québec')
            ->setCodepostal('G1A 1A1')->setCountry('CA')->setPhone('+1 418 555 0000')->setProvince('QC')->setUserAdress($user);
        $em->persist($address);
        $em->flush();

        return (int) $address->getId();
    }

    private function json(Response $response): array
    {
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        return json_decode($response->getContent(), true);
    }

    private function request(string $method, string $path, ?string $body = null): Response
    {
        $jar = $this->client->getCookieJar();
        $jar->clear();
        foreach ($this->session as $name => $value) {
            if ($name !== 'email') {
                $jar->set(new \Symfony\Component\BrowserKit\Cookie($name, $value, null, '/', MV_TEST_TENANT_HOST, true));
            }
        }
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $this->session['XSRF-TOKEN_' . MV_TEST_TENANT_CODE] ?? null,
        ]), $body);

        return $this->client->getResponse();
    }

    private function loginAs(array $roles): void
    {
        $email = 'abo-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Abonné')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/login', [], [], [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'web']));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->session = ['email' => $email];
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            $this->session[$cookie->getName()] = $cookie->getValue();
        }
    }

    private function loginAdmin(): void
    {
        $email = 'abonnement-admin-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Admin')->setLastname('Abonnements')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('x');
        $em->persist($user);
        $em->flush();
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user, 'main');
    }
}
