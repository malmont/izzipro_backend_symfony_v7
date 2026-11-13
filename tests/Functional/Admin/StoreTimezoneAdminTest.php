<?php

namespace App\Tests\Functional\Admin;

use App\Controller\Admin\CaisseCrudController;
use App\Controller\Admin\CommandeCrudController;
use App\Controller\Admin\FormSubmissionCrudController;
use App\Controller\Admin\InventoryMovementsCrudController;
use App\Controller\Admin\NoteDeFraisCrudController;
use App\Controller\Admin\OrderCrudController;
use App\Controller\Admin\PaymentsCrudController;
use App\Controller\Admin\ProductCrudController;
use App\Controller\Admin\SharedMediaCrudController;
use App\Entity\Commande;
use App\Entity\InventoryMovements;
use App\Entity\Order;
use App\Entity\OrderSource;
use App\Entity\Payments;
use App\Entity\Product;
use App\Entity\StatusCommande;
use App\Entity\User;
use App\Services\Admin\AdminStoreTimezone;
use App\Services\Admin\StoreTimezoneFieldConfigurator;
use App\Services\Admin\StoreTimezoneFilterConfigurator;
use App\Services\CaisseService\StoreClock;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TimeField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Form\Filter\Type\DateTimeFilterType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot EA-FUSEAU (12/11/2026, `docs/architecture.md`, « Dates et heures dans EasyAdmin ») : dans les écrans d'un site, tout champ date et
 * heure se lit et se saisit à l'heure du magasin, et reste en UTC en base. Le site de test est réglé sur America/Toronto (le serveur est en
 * UTC) : 3 h UTC le 12 novembre, c'est 22 h la veille au magasin.
 */
class StoreTimezoneAdminTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private const UTC = '2026-11-12 03:00:00';
    private const STORE_INPUT = '2026-11-11T22:00';
    private KernelBrowser $client;
    /** @var array<string, mixed> réglages de l'entreprise à remettre */
    private array $before;
    /** @var list<array<string, mixed>> pays et province de son adresse, à remettre */
    private array $address;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->before = $this->db()->fetchAssociative('SELECT id, pos_timezone FROM entreprise ORDER BY id LIMIT 1');
        $this->address = $this->db()->fetchAllAssociative('SELECT id, country, state FROM address_entreprise WHERE entreprise_id = ?', [$this->before['id']]);
        $this->db()->executeStatement("UPDATE entreprise SET pos_timezone = 'America/Toronto' WHERE id = ?", [$this->before['id']]);
        $this->db()->executeStatement('DELETE FROM shared_media');
    }

    protected function tearDown(): void
    {
        $this->db()->executeStatement('UPDATE entreprise SET pos_timezone = ? WHERE id = ?', [$this->before['pos_timezone'], $this->before['id']]);
        foreach ($this->address as $row) {
            $this->db()->executeStatement('UPDATE address_entreprise SET country = ?, state = ? WHERE id = ?', [$row['country'], $row['state'], $row['id']]);
        }
        parent::tearDown();
    }

    public function testADateAndTimeIsShownAndEnteredInStoreTimeAndKeptInUtc(): void
    {
        $id = $this->media(self::UTC, '2026-12-01 15:30:00');
        $this->loginAs(['ROLE_ADMIN']);
        $expires = fn () => $this->db()->fetchOne('SELECT expires_at FROM shared_media WHERE id = ?', [$id]);
        $label = fn () => trim($this->client->getCrawler()->filter('label[for="SharedMedia_expiresAt"]')->text());

        // Formulaire : l'heure du magasin (15 h 30 UTC = 10 h 30 à Toronto en hiver), et la mention dans le libellé
        $this->admin(SharedMediaCrudController::class, 'edit', $id);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame('2026-12-01T10:30', $this->input('SharedMedia[expiresAt]'));
        $this->assertSame('Date d\'expiration (optionnel) (heure du magasin)', $label());

        // Aller-retour : enregistrer sans rien changer ne déplace pas l'heure
        $this->submit('SharedMedia', []);
        $this->assertSame('2026-12-01 15:30:00', $expires(), 'ouvrir et enregistrer : même instant en base');

        // Saisie : lue à l'heure du magasin, enregistrée en UTC (été : UTC-4)
        $this->admin(SharedMediaCrudController::class, 'edit', $id);
        $this->submit('SharedMedia', ['SharedMedia[expiresAt]' => '2026-07-01T09:15']);
        $this->assertSame('2026-07-01 13:15:00', $expires(), '9 h 15 à Toronto en été = 13 h 15 UTC');

        // Changement d'heure, sans refus : une heure qui n'existe pas (8 mars 2026, 2 h 30) est lue une heure plus tard (3 h 30, UTC-4) ;
        // une heure qui existe deux fois (1er novembre 2026, 1 h 30) est lue comme la première (heure d'été, UTC-4)
        foreach (['2026-03-08T02:30' => '2026-03-08 07:30:00', '2026-11-01T01:30' => '2026-11-01 05:30:00', '2026-11-01T02:30' => '2026-11-01 07:30:00'] as $typed => $stored) {
            $this->admin(SharedMediaCrudController::class, 'edit', $id);
            $this->submit('SharedMedia', ['SharedMedia[expiresAt]' => $typed]);
            $this->assertSame($stored, $expires(), "saisie $typed");
        }
        $this->admin(SharedMediaCrudController::class, 'edit', $id);
        $this->assertSame('2026-11-01T02:30', $this->input('SharedMedia[expiresAt]'), 'relue telle que saisie');
        $this->submit('SharedMedia', ['SharedMedia[expiresAt]' => '']);
        $this->assertNull($expires(), 'champ vidé : date effacée');

        // Fiche : heure du magasin et mention ; liste : heure du magasin, en-tête de colonne inchangé
        $this->db()->executeStatement("UPDATE shared_media SET expires_at = '2026-12-01 15:30:00' WHERE id = ?", [$id]);
        $this->admin(SharedMediaCrudController::class, 'detail', $id);
        $detail = preg_replace('/\s+/u', ' ', $this->client->getCrawler()->filter('body')->text());
        $this->assertStringContainsString('Date d\'expiration (optionnel) (heure du magasin) Dec 1, 2026, 10:30:00 AM', $detail);
        $this->admin(SharedMediaCrudController::class, 'index');
        $this->assertContains('11/11/2026 22:00', $this->client->getCrawler()->filter('table tbody tr td')->each(fn ($n) => trim($n->text())), '« Créé le » : 3 h UTC le 12 = 22 h le 11 au magasin');
        $headers = $this->client->getCrawler()->filter('table thead th')->each(fn ($n) => trim($n->text()));
        $this->assertContains('Créé le', $headers, 'en-tête de liste sans mention');
        $this->assertStringNotContainsString('heure du magasin', implode(' ', $headers));
    }

    public function testEveryEditableDateAndTimeOfTheSiteScreensIsEnteredInStoreTime(): void
    {
        $status = fn (Order $o, EntityManagerInterface $em) => $o->setStatus($em->getRepository(StatusCommande::class)->findOneBy([]))->setOrderSource($em->find(OrderSource::class, 2));
        $cases = [ // écran, entité, table, colonnes date et heure modifiables => champ du formulaire
            [OrderCrudController::class, Order::class, '"order"', ['order_date' => 'Order[orderDate]', 'status_updated_at' => 'Order[statusUpdatedAt]'], $status],
            [PaymentsCrudController::class, Payments::class, 'payments', ['payment_date' => 'Payments[paymentDate]'], null],
            [InventoryMovementsCrudController::class, InventoryMovements::class, 'inventory_movements', ['movement_date' => 'InventoryMovements[movementDate]'], null],
            [ProductCrudController::class, Product::class, 'product', ['special_price_from' => 'Product[specialPriceFrom]', 'special_price_to' => 'Product[specialPriceTo]'], null],
        ];
        $made = [];
        foreach ($cases as [$controller, $class, $table, $columns, $configure]) {
            $entity = $this->create($class, [], $configure);
            $this->db()->executeStatement("UPDATE $table SET " . implode(', ', array_map(fn ($c) => "$c = '" . self::UTC . "'", array_keys($columns))) . ' WHERE id = ?', [$entity->getId()]);
            $made[] = [$controller, $entity->getId(), $columns];
        }
        $this->em()->clear();
        $this->loginAs(['ROLE_ADMIN']);
        foreach ($made as [$controller, $id, $columns]) {
            $this->admin($controller, 'edit', $id);
            $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $controller);
            foreach ($columns as $field) {
                $this->assertSame(self::STORE_INPUT, $this->input($field), "$field : 3 h UTC le 12 = 22 h le 11 au magasin");
                $label = trim($this->client->getCrawler()->filter('label[for="' . str_replace(['[', ']'], ['_', ''], $field) . '"]')->text());
                $this->assertSame(1, substr_count($label, AdminStoreTimezone::STORE_MENTION), "$field : mention une seule fois dans « $label »");
            }
        }

        // Dates de promotion d'un article : la saisie était lue en UTC malgré la mention (le fuseau n'était réglé que pour l'affichage)
        [$controller, $productId] = $made[3];
        $this->admin($controller, 'edit', $productId);
        $this->submit('Product', ['Product[specialPriceFrom]' => '2026-11-20T09:00', 'Product[specialPriceTo]' => '2026-11-27T21:00']);
        $this->assertSame(['special_price_from' => '2026-11-20 14:00:00', 'special_price_to' => '2026-11-28 02:00:00'],
            $this->db()->fetchAssociative('SELECT special_price_from, special_price_to FROM product WHERE id = ?', [$productId]), '9 h et 21 h à Toronto en hiver = 14 h et 2 h UTC');

        // L'article du test est supprimé : la base de test est partagée, et d'autres tests comptent les articles
        $this->db()->executeStatement('DELETE FROM product_translation WHERE product_id = ?', [$productId]); // créée par l'enregistrement de la fiche
        $this->db()->executeStatement('DELETE FROM product WHERE id = ?', [$productId]);

        // Création : même règle (mouvement de stock)
        $this->admin(InventoryMovementsCrudController::class, 'new');
        $this->assertStringContainsString(AdminStoreTimezone::STORE_MENTION, $this->client->getCrawler()->filter('label[for="InventoryMovements_movementDate"]')->text());
    }

    public function testAPureDateIsNeverShifted(): void
    {
        $commande = $this->create(Commande::class);
        $this->db()->executeStatement("UPDATE commande SET date = '2026-11-12 00:00:00' WHERE id = ?", [$commande->getId()]);
        $this->em()->clear();
        $this->loginAs(['ROLE_ADMIN']);
        $this->admin(CommandeCrudController::class, 'edit', $commande->getId());
        $this->assertSame('2026-11-12', $this->input('Commande[date]'), 'champ de date : pas de fuseau, pas de veille (minuit UTC serait la veille à Toronto)');
        $this->assertStringNotContainsString('heure du magasin', $this->client->getCrawler()->filter('label[for="Commande_date"]')->text());
        $this->admin(CommandeCrudController::class, 'detail', $commande->getId());
        $detail = $this->client->getCrawler()->filter('body')->text();
        $this->assertStringContainsString('Nov 12, 2026', $detail);
        $this->assertStringNotContainsString('Nov 11, 2026', $detail);
    }

    /**
     * Filtres de liste : la règle est vérifiée ici sur le formulaire du filtre (options posées, puis valeur lue). Son effet sur la liste
     * elle-même est vérifié par `AdminListsTest` depuis que le lot EA-LISTES a branché les filtres.
     */
    public function testDateAndTimeFiltersReadStoreTime(): void
    {
        $this->loginAs(['ROLE_ADMIN']);
        $configurator = fn () => static::getContainer()->get(StoreTimezoneFilterConfigurator::class);
        $options = function (string $controller, string $property, array $own = []) use ($configurator): array {
            $this->admin($controller, 'index');
            $this->assertSame(200, $this->client->getResponse()->getStatusCode());
            $context = $this->client->getRequest()->attributes->get(EA::CONTEXT_REQUEST_ATTRIBUTE);
            $filter = DateTimeFilter::new($property);
            foreach ($own as $name => $value) {
                $filter->setFormTypeOption($name, $value);
            }
            $dto = $filter->getAsDto();
            $this->assertTrue($configurator()->supports($dto, null, $context->getEntity(), $context));
            $configurator()->configure($dto, null, $context->getEntity(), $context);

            return $dto->getFormTypeOption('value_type_options') ?? [];
        };
        $store = ['widget' => 'single_text', 'view_timezone' => 'America/Toronto', 'model_timezone' => 'UTC'];
        $this->assertSame($store, $options(FormSubmissionCrudController::class, 'createdAt'), 'filtre déclaré sur une date et heure');
        $this->assertSame($store, $options(CaisseCrudController::class, 'createdAt'), 'filtre déduit de la colonne');
        $this->assertSame([], $options(NoteDeFraisCrudController::class, 'date'), 'colonne de date pure : aucun fuseau');
        $this->assertEqualsCanonicalizing(['widget' => 'single_text', 'view_timezone' => 'Europe/Paris', 'model_timezone' => 'UTC'],
            $own = $options(FormSubmissionCrudController::class, 'createdAt', ['value_type_options' => ['view_timezone' => 'Europe/Paris']]), 'un filtre qui fixe son fuseau garde le sien');
        $this->assertSame('Europe/Paris', $own['view_timezone']);

        // Avec ces options, « le 12 à 10 h » veut dire 10 h au magasin : la valeur comparée à la colonne est 15 h UTC
        $form = static::getContainer()->get('form.factory')->create(DateTimeFilterType::class, null, ['value_type_options' => $store, 'csrf_protection' => false]);
        $form->submit(['comparison' => '>', 'value' => '2026-11-12T10:00']);
        $this->assertTrue($form->isValid(), (string) $form->getErrors(true));
        $value = $form->getData()['value'];
        $this->assertSame(['2026-11-12 15:00:00', 'UTC'], [$value->format('Y-m-d H:i:s'), $value->getTimezone()->getName()]);
    }

    public function testASiteWithoutTimezoneSaysUtc(): void
    {
        $this->db()->executeStatement('UPDATE entreprise SET pos_timezone = NULL WHERE id = ?', [$this->before['id']]);
        $this->db()->executeStatement("UPDATE address_entreprise SET country = '', state = '' WHERE entreprise_id = ?", [$this->before['id']]);
        $this->em()->clear();
        $this->assertSame(['timezone' => 'UTC', 'source' => 'default'], static::getContainer()->get(StoreClock::class)->settings(), 'ni fuseau ni pays : l\'horloge retombe sur UTC');
        $id = $this->media(self::UTC, self::UTC);
        $this->loginAs(['ROLE_ADMIN']);
        $this->admin(SharedMediaCrudController::class, 'edit', $id);
        $this->assertSame('2026-11-12T03:00', $this->input('SharedMedia[expiresAt]'));
        $this->assertSame('Date d\'expiration (optionnel) (UTC)', trim($this->client->getCrawler()->filter('label[for="SharedMedia_expiresAt"]')->text()), 'le libellé ne prétend pas à l\'heure du magasin');

        // Champ qui fixe son propre fuseau (réservations : horloge des réservations) : sa mention reste « (heure du magasin) »
        $context = $this->client->getRequest()->attributes->get(EA::CONTEXT_REQUEST_ATTRIBUTE);
        $dto = DateTimeField::new('expiresAt', 'Début')->setTimezone('America/Toronto')->getAsDto();
        $dto->setFieldFqcn(DateTimeField::class);
        static::getContainer()->get(StoreTimezoneFieldConfigurator::class)->configure($dto, $context->getEntity(), $context);
        $this->assertSame('Début (heure du magasin)', $dto->getLabel());
    }

    public function testAFieldKeepsItsOwnTimezoneAndTheMentionIsNeverDoubled(): void
    {
        $id = $this->media(self::UTC, self::UTC);
        $this->loginAs(['ROLE_ADMIN']);
        $this->admin(SharedMediaCrudController::class, 'edit', $id);
        $context = $this->client->getRequest()->attributes->get(EA::CONTEXT_REQUEST_ATTRIBUTE);
        $configurator = static::getContainer()->get(StoreTimezoneFieldConfigurator::class);
        $configure = function (DateTimeField $field) use ($configurator, $context): array {
            $dto = $field->getAsDto();
            $dto->setFieldFqcn(DateTimeField::class); // posé par EasyAdmin quand il prépare les champs d'un écran
            $this->assertTrue($configurator->supports($dto, $context->getEntity()));
            $configurator->configure($dto, $context->getEntity(), $context);

            return [$dto->getLabel(), $dto->getCustomOption(DateTimeField::OPTION_TIMEZONE), $dto->getFormTypeOption('view_timezone'), $dto->getFormTypeOption('model_timezone')];
        };
        $this->assertSame(['Expire le (heure du magasin)', 'America/Toronto', 'America/Toronto', 'UTC'], $configure(DateTimeField::new('expiresAt', 'Expire le')));
        $this->assertSame(['Expire le (heure du magasin)', 'America/Toronto', 'America/Toronto', 'UTC'], $configure(DateTimeField::new('expiresAt', 'Expire le (heure du magasin)')), 'mention déjà écrite : pas de doublon');
        $this->assertSame(['Expire le (UTC)', 'America/Toronto', 'America/Toronto', 'UTC'], $configure(DateTimeField::new('expiresAt', 'Expire le (UTC)')));
        $this->assertSame(['Début (heure du magasin)', 'Europe/Paris', 'Europe/Paris', 'UTC'], $configure(DateTimeField::new('expiresAt', 'Début')->setTimezone('Europe/Paris')), 'fuseau propre au champ : gardé, à l\'affichage comme à la saisie');
        $this->assertSame(['Début (heure du magasin)', 'America/Toronto', 'Europe/Paris', 'America/Toronto'],
            $configure(DateTimeField::new('expiresAt', 'Début')->setFormTypeOptions(['view_timezone' => 'Europe/Paris', 'model_timezone' => 'America/Toronto'])), 'réglage de saisie propre à l\'écran : gardé');
        $this->assertSame([false, 'America/Toronto', 'America/Toronto', 'UTC'], $configure(DateTimeField::new('expiresAt', false)), 'libellé masqué : laissé masqué');
        foreach ([DateField::class => 'date pure', TimeField::class => 'heure seule'] as $class => $what) {
            $dto = $class::new('expiresAt')->getAsDto();
            $dto->setFieldFqcn($class);
            $this->assertFalse($configurator->supports($dto, $context->getEntity()), "$what : jamais");
        }

        // Écran hors de la classe de base des sites : rien n'est touché
        $context->getCrud()->setControllerFqcn(AbstractCrudController::class);
        $this->assertSame(['Expire le', null, null, null], $configure(DateTimeField::new('expiresAt', 'Expire le')));
    }

    public function testOnlyTheSiteScreensAreConcerned(): void
    {
        $this->assertTrue(AdminStoreTimezone::applies(SharedMediaCrudController::class));
        $this->assertFalse(AdminStoreTimezone::applies(AbstractCrudController::class), 'écran hors de la classe de base des sites');
        $this->assertFalse(AdminStoreTimezone::applies(null));
    }

    // ----- outils -----

    private function media(string $createdAt, string $expiresAt): int
    {
        $this->db()->executeStatement("INSERT INTO shared_media (titre, filename, original_filename, media_type, mime_type, file_size, visibility, access_key, download_count, created_at, expires_at)
            VALUES ('Document', 'doc_0011223344556677.pdf', 'doc.pdf', 'document', 'application/pdf', 1000, 'private', ?, 0, ?, ?)", [bin2hex(random_bytes(32)), $createdAt, $expiresAt]);

        return (int) $this->db()->fetchOne('SELECT MAX(id) FROM shared_media');
    }

    private function input(string $name): ?string
    {
        return $this->client->getCrawler()->filter('input[name="' . $name . '"]')->attr('value');
    }

    /** Envoie le formulaire de la page ouverte, avec ces valeurs en plus de celles qu'il porte */
    private function submit(string $form, array $values): void
    {
        $this->client->submit($this->client->getCrawler()->filter('form[name="' . $form . '"]')->form($values), [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $status = $this->client->getResponse()->getStatusCode();
        $this->assertSame(302, $status, "$form : enregistré ; reçu $status " . substr(preg_replace('/\s+/u', ' ', strip_tags((string) $this->client->getResponse()->getContent())), 0, 500));
    }

    private function admin(string $controller, string $action, ?int $id = null, array $more = []): void
    {
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(array_filter(['crudAction' => $action, 'crudControllerFqcn' => $controller, 'entityId' => $id]) + $more), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
    }

    private function loginAs(array $roles): void
    {
        $email = 'ea-fuseau-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Admin')->setLastname('Fuseau')->setRoles($roles)->setIsVerified(true)->setPassword('x');
        $em = $this->em();
        $em->persist($user);
        $em->flush();
        $this->client->loginUser($user, 'main');
    }

    protected function em(): EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        return $this->em()->getConnection();
    }
}
