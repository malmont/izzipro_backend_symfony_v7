<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\GestionDocument;
use App\Entity\Order;
use App\Entity\OrderItems;
use App\Entity\OrderSource;
use App\Entity\OrderType;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\StatusCommande;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Services\CaisseService\StoreClock;
use App\Services\GestionService\GestionNotificationService;
use App\Services\NotificationService\PushSenderInterface;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakePushSender;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Notifications sur téléphone, lot N1 sans Firebase (11/11/2026, `docs/notifications-gestion.md`, contrat validé par l'application) :
 * appareils (validation, idempotence, rattachement à l'utilisateur, application réservée, retrait), préférences par gérant, passage
 * quotidien du stock bas (heure du magasin, seuil 7 jours, une par jour, pas de rappel de la même liste, type coupé, jeton refusé
 * désactivé, langue de l'appareil, statut not_configured), facture en double (heures du magasin), journal. Envoi simulé (FakePushSender).
 */
class GestionNotificationsApiTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private KernelBrowser $client;
    private string $token = '';
    private int $userId = 0;
    private string $email = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakePushSender::reset();
        $conn = $this->em()->getConnection();
        foreach (['push_device', 'notification_log', 'notification_preference'] as $table) { // tables propres à ce test
            $conn->executeStatement("DELETE FROM $table");
        }
        $this->login();
    }

    public function testDevicesRegistrationIsIdempotentAndScoped(): void
    {
        $tag = bin2hex(random_bytes(3));
        $this->request('POST', '/api/notifications/appareils', []);
        $this->assertSame([422, ['token' => 'REQUIRED', 'platform' => 'REQUIRED']], [$this->status(), $this->body()['fields']]);
        $this->request('POST', '/api/notifications/appareils', ['token' => "t-$tag", 'platform' => 'pigeon', 'locale' => 'de']);
        $this->assertSame(['platform' => 'INVALID_PLATFORM', 'locale' => 'INVALID_LOCALE'], $this->body()['fields']);

        $d = $this->send('POST', '/api/notifications/appareils', ['token' => "t-$tag", 'platform' => 'ADMIN', 'deviceName' => 'iPhone de test', 'locale' => 'en', 'appVersion' => '1.0'], 201);
        $this->assertSame(['admin', 'iPhone de test', 'en', '1.0', true], [$d['platform'], $d['deviceName'], $d['locale'], $d['appVersion'], $d['active']]);
        $again = $this->send('POST', '/api/notifications/appareils', ['token' => "t-$tag", 'platform' => 'admin'], 200);
        $this->assertSame([$d['id'], 'iPhone de test', 'en'], [$again['id'], $again['deviceName'], $again['locale']], 'même jeton : même inscription, champs gardés');
        $first = $this->userId;
        // Un autre gérant se connecte sur le même téléphone : le jeton passe à lui
        $this->login();
        $this->send('POST', '/api/notifications/appareils', ['token' => "t-$tag", 'platform' => 'admin'], 200);
        $conn = $this->em()->getConnection();
        $this->assertSame([$this->userId, 1], [(int) $conn->fetchOne('SELECT user_id FROM push_device WHERE token = ?', ["t-$tag"]), (int) $conn->fetchOne('SELECT COUNT(*) FROM push_device WHERE token = ?', ["t-$tag"])]);
        $this->assertNotSame($first, $this->userId);

        // Retrait : idempotent, l'inscription reste mais désactivée ; une nouvelle inscription la réactive
        $this->request('DELETE', "/api/notifications/appareils/t-$tag");
        $this->assertSame(204, $this->status());
        $this->request('DELETE', "/api/notifications/appareils/t-$tag");
        $this->assertSame(204, $this->status());
        $this->request('DELETE', '/api/notifications/appareils/inconnu');
        $this->assertSame(204, $this->status());
        $this->assertSame('logout', $conn->fetchOne('SELECT disabled_reason FROM push_device WHERE token = ?', ["t-$tag"]));
        // Le jeton d'un autre utilisateur ne se retire pas
        $this->send('POST', '/api/notifications/appareils', ['token' => "o-$tag", 'platform' => 'admin'], 201);
        $owner = $this->userId;
        $this->login();
        $this->request('DELETE', "/api/notifications/appareils/o-$tag");
        $this->assertSame([204, null], [$this->status(), $conn->fetchOne('SELECT disabled_at FROM push_device WHERE token = ?', ["o-$tag"])], 'retrait réservé au titulaire du jeton');
        $this->assertNotSame($owner, $this->userId);
        $this->assertTrue($this->send('POST', '/api/notifications/appareils', ['token' => "t-$tag", 'platform' => 'admin'], 200)['active']);

        // Client de la boutique : l'application de gestion lui est fermée, l'application mobile non
        $this->login(['ROLE_USER_INTERNET']);
        $this->request('POST', '/api/notifications/appareils', ['token' => "c-$tag", 'platform' => 'admin']);
        $this->assertSame([403, 'PLATFORM_FORBIDDEN'], [$this->status(), $this->body()['error']]);
        $this->request('POST', '/api/notifications/appareils', ['token' => "c-$tag", 'platform' => 'pos']);
        $this->assertSame([403, 'PLATFORM_FORBIDDEN'], [$this->status(), $this->body()['error']], 'la caisse non plus');
        $this->send('POST', '/api/notifications/appareils', ['token' => "c-$tag", 'platform' => 'mobile'], 201);
        $this->request('GET', '/api/gestion/notifications/preferences');
        $this->assertSame(403, $this->status(), 'préférences de la gestion : administrateurs seulement');

        // Sans connexion
        $this->client->request('POST', '/api/notifications/appareils', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json'], json_encode(['token' => 'x', 'platform' => 'admin']));
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testPreferences(): void
    {
        $p = $this->get('/api/gestion/notifications/preferences');
        $this->assertSame(['low_stock', 'duplicate_invoice', 'cash_critical', 'trip_budget'], array_column($p['kinds'], 'kind'));
        $this->assertSame([true, true, true, true], array_column($p['kinds'], 'enabled'), 'tout est actif par défaut');
        $this->assertSame([true, true, false, true], array_column($p['kinds'], 'available'), 'trip_budget disponible depuis B1a');
        $this->assertSame('Articles qui vont manquer', $p['kinds'][0]['label']);
        $this->assertSame('Items about to run out', $this->get('/api/gestion/notifications/preferences?locale=en')['kinds'][0]['label']);
        $u = $this->send('PATCH', '/api/gestion/notifications/preferences', ['kinds' => ['low_stock' => false, 'trip_budget' => false]], 200);
        $this->assertSame([false, true, true, false], array_column($u['kinds'], 'enabled'));
        $this->assertSame([true, true, true, false], array_column($this->send('PATCH', '/api/gestion/notifications/preferences', ['kinds' => ['low_stock' => true, 'duplicate_invoice' => true]], 200)['kinds'], 'enabled'), 'rétabli ; les autres types gardent leur réglage');
        $this->request('PATCH', '/api/gestion/notifications/preferences', ['kinds' => ['pigeon' => true, 'low_stock' => 'non']]);
        $this->assertSame([422, ['kinds.pigeon' => 'UNKNOWN_KIND', 'kinds.low_stock' => 'MUST_BE_BOOLEAN']], [$this->status(), $this->body()['fields']]);
        $this->request('PATCH', '/api/gestion/notifications/preferences', ['kinds' => ['a', 'b']]);
        $this->assertSame(['kinds' => 'MUST_BE_OBJECT'], $this->body()['fields']);
        $this->assertSame(true, $this->get('/api/gestion/notifications/preferences')['kinds'][0]['enabled'], 'refus : rien n\'est écrit');
    }

    public function testDailyLowStockQuotaRepeatAndDuplicateInvoice(): void
    {
        $tag = bin2hex(random_bytes(2));
        $service = static::getContainer()->get(GestionNotificationService::class);
        $tz = static::getContainer()->get(StoreClock::class)->timezone();
        $ten = (new \DateTimeImmutable('today 10:15', $tz))->setTimezone(new \DateTimeZone('UTC'));
        $now = new \DateTimeImmutable('-1 hour');
        // Stock 0 pour 3 vendus : moins de 7 jours ; stock 1 pour 3 vendus : 10 jours (vignette seulement)
        $out = $this->variant("Bonnet $tag", 0, ['is_web' => true]);
        $ten10 = $this->variant("Écharpe $tag", 1, ['is_web' => true]);
        $this->sale($out, 3, 1000, $now);
        $this->sale($ten10, 3, 1000, $now);
        $this->endOfWrite();
        $this->send('POST', '/api/notifications/appareils', ['token' => "a-$tag", 'platform' => 'admin', 'locale' => 'en'], 201);
        $this->send('POST', '/api/notifications/appareils', ['token' => "b-$tag", 'platform' => 'admin', 'locale' => 'fr'], 201);
        FakePushSender::$invalid = ["b-$tag"];

        // Hors de 10 h du magasin : rien
        $this->assertSame(['ran' => false, 'notified' => 0, 'skipped' => 0], $service->runDaily($ten->modify('-7 hours')));
        $r = $service->runDaily($ten);
        $this->assertSame([true, 1], [$r['ran'], $r['notified']]);
        $this->assertCount(1, FakePushSender::$sent, 'le jeton refusé ne reçoit rien');
        $sent = FakePushSender::$sent[0];
        $params = json_decode($sent['data']['params'], true);
        $this->assertSame(["a-$tag", 'Low stock', 'low_stock', 'stock.low'], [$sent['token'], $sent['title'], $sent['data']['kind'], $sent['data']['screen']], 'langue de l\'appareil');
        $this->assertContains($out, $params['variantIds']);
        $this->assertNotContains($ten10, $params['variantIds'], '10 jours de couverture : vignette, pas de notification');
        $conn = $this->em()->getConnection();
        $this->assertSame('invalid_token', $conn->fetchOne('SELECT disabled_reason FROM push_device WHERE token = ?', ["b-$tag"]), 'jeton refusé : appareil désactivé');
        $journal = $this->get('/api/gestion/notifications/journal');
        $this->assertSame(['low_stock', 'sent', 'stock.low'], [$journal[0]['kind'], $journal[0]['status'], $journal[0]['action']['screen']]);
        $this->assertSame($params['variantIds'], $journal[0]['action']['params']['variantIds']);

        // Même jour : limite atteinte ; lendemain : même liste déjà dite cette semaine → rien
        FakePushSender::$sent = [];
        $this->assertSame(0, $service->runDaily($ten)['notified']);
        $this->assertSame(0, $service->runDaily($ten->modify('+1 day'))['notified'], 'pas de rappel quotidien de la même liste');
        // Un nouvel article va manquer : notification le lendemain
        $new = $this->variant("Mitaines $tag", 0, ['is_pos' => true]);
        $this->sale($new, 2, 1000, $now);
        $this->endOfWrite();
        FakePushSender::$status = PushSenderInterface::NOT_CONFIGURED;
        $this->assertSame(1, $service->runDaily($ten->modify('+1 day'))['notified']);
        $this->assertSame('not_configured', $this->get('/api/gestion/notifications/journal')[0]['status'], 'sans fournisseur : noté, compté dans la limite');
        $this->assertSame(0, $service->runDaily($ten->modify('+1 day'))['notified']);
        // Type coupé : rien, même avec une nouvelle liste
        $this->send('PATCH', '/api/gestion/notifications/preferences', ['kinds' => ['low_stock' => false]], 200);
        $other = $this->variant("Gants $tag", 0, ['is_web' => true]);
        $this->sale($other, 2, 1000, $now);
        $this->endOfWrite();
        $this->assertSame(0, $service->runDaily($ten->modify('+2 days'))['notified']);
        $this->send('PATCH', '/api/gestion/notifications/preferences', ['kinds' => ['low_stock' => true]], 200);

        // Facture en double : à celui qui l'a envoyée, entre 8 h et 20 h du magasin
        FakePushSender::reset();
        $conn->executeStatement("INSERT INTO gestion_document (type, status, locale, files, created_by, created_at, expires_at) VALUES ('facture', 'extracted', 'fr', '{\"pages\": []}', :by, NOW(), NOW() + INTERVAL '30 days')", ['by' => $this->email]);
        $docId = (int) $conn->fetchOne('SELECT MAX(id) FROM gestion_document');
        $doc = $this->em()->find(GestionDocument::class, $docId);
        $x = ['supplier' => ['name' => "Atelier $tag"], 'duplicate' => ['documentId' => 1]];
        $noon = (new \DateTimeImmutable('today 12:00', $tz))->modify('+5 days')->setTimezone(new \DateTimeZone('UTC'));
        $this->assertNull($service->duplicateInvoice($doc, $x, $noon->modify('+9 hours')), '21 h : rien');
        $this->assertNull($service->duplicateInvoice($doc, ['duplicate' => null] + $x, $noon), 'pas de doublon : rien');
        $this->assertSame('sent', $service->duplicateInvoice($doc, $x, $noon));
        $this->assertSame(["Duplicate invoice", "This invoice from Atelier $tag seems to be entered already.", 'documents.review', ['documentId' => $docId]], [FakePushSender::$sent[0]['title'], FakePushSender::$sent[0]['body'], FakePushSender::$sent[0]['data']['screen'], json_decode(FakePushSender::$sent[0]['data']['params'], true)]);
        $this->assertNull($service->duplicateInvoice($doc, $x, $noon->modify('+1 hour')), 'une par jour');
    }

    /** Article standard à une variante, stock donné, colonnes de vente / visibilité données */
    private function variant(string $name, int $stock, array $flags): int
    {
        $conn = $this->em()->getConnection();
        $slug = 'vg-' . bin2hex(random_bytes(4));
        $conn->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type) VALUES (nextval('product_id_seq'), :n, 2000, false, :q, NOW(), :s, 'standard')", ['n' => $name, 'q' => $stock, 's' => $slug]);
        $id = (int) $conn->fetchOne('SELECT id FROM product WHERE slug = ?', [$slug]);
        foreach ($flags as $col => $value) {
            $conn->executeStatement("UPDATE product SET $col = :v WHERE id = :id", ['v' => $value, 'id' => $id], ['v' => \Doctrine\DBAL\ParameterType::BOOLEAN]);
        }
        $variant = (int) $this->create(ProductVariant::class, ['product' => $this->em()->find(Product::class, $id)])->getId();
        $conn->executeStatement('UPDATE product_variant SET stock_quantity = ? WHERE id = ?', [$stock, $variant]);

        return $variant;
    }

    private function sale(int $variant, int $quantity, int $unit, \DateTimeImmutable $at): void
    {
        $order = $this->create(Order::class, ['totalAmount' => (float) ($unit * $quantity)], function (Order $o, EntityManagerInterface $em) use ($at): void {
            $o->setOrderDate(\DateTime::createFromImmutable($at->setTimezone(new \DateTimeZone('UTC'))))->setStatus($em->find(StatusCommande::class, 3))->setOrderType($em->find(OrderType::class, 1))
                ->setOrderSource($em->find(OrderSource::class, 2))->setUserId($em->find(User::class, $this->userId));
        });
        $this->create(OrderItems::class, ['quantity' => $quantity, 'unitPrice' => (float) $unit, 'totalPrice' => (float) ($unit * $quantity)], fn (OrderItems $i, EntityManagerInterface $em) => $i->setOrderAssociated($em->find(Order::class, $order->getId()))->setProductVariant($em->find(ProductVariant::class, $variant)));
    }

    // ----- helpers -----

    private function status(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    private function body(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function request(string $method, string $route, ?array $body = null): void
    {
        $this->client->request($method, $route, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token], $body === null ? null : json_encode($body));
    }

    private function get(string $route): array
    {
        $this->request('GET', $route);
        $this->assertSame(200, $this->status(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 300));

        return $this->body();
    }

    private function send(string $method, string $route, array $body, int $expected): array
    {
        $this->request($method, $route, $body);
        $this->assertSame($expected, $this->status(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 400));

        return $this->body();
    }

    protected function em(): EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }

    private function endOfWrite(): void
    {
        static::getContainer()->get(CacheInvalidationSubscriber::class)->invalidateWrittenTenants();
    }

    private function login(array $roles = ['ROLE_ADMIN']): void
    {
        $email = 'gestion-n1-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('N1')->setRoles($roles)->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->userId = (int) $user->getId();
        $this->email = $email;
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
