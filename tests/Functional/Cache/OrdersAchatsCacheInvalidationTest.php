<?php

namespace App\Tests\Functional\Cache;

use App\Entity\Collections;
use App\Entity\Commande;
use App\Entity\Fournisseur;
use App\Entity\NoteDeFrais;
use App\Entity\Order;
use App\Entity\OrderSource;
use App\Entity\Payments;
use App\Entity\StatusCommande;
use App\Entity\TypeFournisseur;
use App\Entity\TypeNoteDeFrais;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Invalidation ciblée des commandes, des paiements et des achats (lot 4, 28/10/2026) : une vente ou un paiement vide
 * les listes de commandes et de paiements (300 s) et rien d'autre ; les achats (collections, commandes fournisseurs,
 * notes de frais, référentiels) suivent leurs écritures. Les lectures vives ou personnelles (sessions de caisse,
 * adresses et commandes d'un client) ne passent plus par le cache.
 */
class OrdersAchatsCacheInvalidationTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private const WITNESS = ['/api/presentations?locale=fr', 'presentations_all_fr'];
    private const PASSWORD = 'Cache-test-2026!';

    private KernelBrowser $client;
    private int $userId = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->login(['ROLE_ADMIN', 'ROLE_USER_INTERNET', 'ROLE_USER_POS']);
    }

    public function testASaleClearsTheOrderAndPaymentListsOnly(): void
    {
        [$witnessRoute, $witnessKey] = self::WITNESS;
        $this->get('/api/orders?orderSource=2');
        $this->get('/api/payments?orderSource=2');
        $this->get($witnessRoute);
        $this->assertTrue($this->cached('orders_source_2') && $this->cached('payments_orderSource_2_locale_en') && $this->cached($witnessKey));

        $order = $this->order();
        $this->assertFalse($this->cached('orders_source_2'), 'vente : la liste des commandes est vidée');
        $this->assertFalse($this->cached('payments_orderSource_2_locale_en'), 'vente : la liste des paiements (qui porte la commande) est vidée');
        $this->assertTrue($this->cached($witnessKey), 'vente : le reste du site reste en cache');

        $this->get('/api/orders?orderSource=2');
        $this->get('/api/payments?orderSource=2');
        $this->create(Payments::class, [], fn (Payments $payment, EntityManagerInterface $em) => $payment->setOrderPayment($em->find(Order::class, $order->getId())));
        $this->assertFalse($this->cached('payments_orderSource_2_locale_en'), 'paiement : la liste des paiements est vidée');
        $this->assertFalse($this->cached('orders_source_2'), 'paiement : la liste des commandes (qui porte les paiements) est vidée');
        $this->assertTrue($this->cached($witnessKey));
        $this->endOfWrite();
    }

    public function testLiveAndPersonalReadsAreNotCached(): void
    {
        $this->get('/api/caisse');
        $this->assertFalse($this->cached('caisse_data_all_all'), 'sessions de caisse : donnée vive, jamais en cache');
        $this->get('/api/adresses');
        $this->assertFalse($this->cached('adresses_user_' . $this->userId), 'adresses d\'un client : données personnelles, jamais en cache');
        $this->get('/api/ordersuser');
        $this->assertFalse($this->cached('orders_user_' . $this->userId . '_en'), 'commandes d\'un client : jamais en cache');
    }

    public function testPurchasesFollowTheirWrites(): void
    {
        [$witnessRoute, $witnessKey] = self::WITNESS;
        $this->get($witnessRoute);
        $collection = $this->create(Collections::class);
        $id = $collection->getId();
        $this->endOfWrite();

        $lists = [
            '/api/collections' => 'collections_all',
            "/api/collections/$id/commandes" => "commandes_collection_$id",
            "/api/collections/$id/notes-de-frais" => "notes_de_frais_collection_$id",
            '/api/fournisseurs' => 'fournisseurs_all',
            '/api/type-fournisseurs' => 'api_type_fournisseurs_all',
            '/api/type-note-de-frais' => 'api_type_note_de_frais_all',
        ];
        foreach ($lists as $route => $key) {
            $this->get($route);
            $this->assertTrue($this->cached($key), "$route est servi par le cache");
        }

        $commande = $this->create(Commande::class, [], fn (Commande $c, EntityManagerInterface $em) => $c->setCollections($em->find(Collections::class, $id)));
        $this->assertFalse($this->cached("commandes_collection_$id"), 'commande fournisseur : la liste de sa collection est vidée');
        $this->assertFalse($this->cached('collections_all'), 'commande fournisseur : la liste des collections (qui les compte) est vidée');
        $this->assertTrue($this->cached("notes_de_frais_collection_$id") && $this->cached('fournisseurs_all') && $this->cached($witnessKey), 'les autres listes restent en cache');

        $this->get("/api/commandes/{$commande->getId()}/products");
        $this->assertTrue($this->cached('products_by_commande_' . $commande->getId()));
        $this->create(NoteDeFrais::class, [], fn (NoteDeFrais $n, EntityManagerInterface $em) => $n->setCollection($em->find(Collections::class, $id)));
        $this->assertFalse($this->cached("notes_de_frais_collection_$id"), 'note de frais : la liste de sa collection est vidée');
        $this->assertTrue($this->cached('products_by_commande_' . $commande->getId()) && $this->cached('fournisseurs_all'), 'les autres listes restent en cache');

        $this->create(Fournisseur::class);
        $this->assertFalse($this->cached('fournisseurs_all'));
        $this->create(TypeFournisseur::class);
        $this->assertFalse($this->cached('api_type_fournisseurs_all'));
        $this->create(TypeNoteDeFrais::class);
        $this->assertFalse($this->cached('api_type_note_de_frais_all'));
        $this->assertTrue($this->cached($witnessKey));
        $this->endOfWrite();
    }

    private function order(): Order
    {
        return $this->create(Order::class, [], function (Order $order, EntityManagerInterface $em): void {
            $order->setStatus($em->getRepository(StatusCommande::class)->findOneBy([]));
            $order->setOrderSource($em->find(OrderSource::class, 2));
        });
    }

    private function get(string $route): string
    {
        $this->client->request('GET', $route, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 300));

        return (string) $this->client->getResponse()->getContent();
    }

    private function cached(string $key): bool
    {
        return static::getContainer()->get('cache.app')->hasItem(MV_TEST_TENANT_CODE . '.' . $key);
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

    /** Connexion web (cookies de session gardés par le client) d'un compte créé pour le test */
    private function login(array $roles): void
    {
        $email = 'cache-test-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Cache')->setLastname('Test')->setRoles($roles)->setIsVerified(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $em->persist($user);
        $em->flush();
        $this->userId = (int) $user->getId();
        $this->endOfWrite();

        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/login', [], [], [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'web']));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
    }
}
