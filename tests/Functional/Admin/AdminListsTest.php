<?php

namespace App\Tests\Functional\Admin;

use App\Controller\Admin\BaseTenantCrudController;
use App\Controller\Admin\FormSubmissionCrudController;
use App\Controller\Admin\FournisseurCrudController;
use App\Controller\Admin\SharedMediaCrudController;
use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot EA-LISTES (13/11/2026, `docs/architecture.md`, « Listes d'EasyAdmin ») : la liste d'un écran de site applique la recherche, les
 * filtres et le tri d'EasyAdmin, sur la base du site. Avant, la requête de liste de `BaseTenantCrudController` les ignorait tous.
 */
class AdminListsTest extends WebTestCase
{
    use \App\Tests\Functional\Cache\CacheTestEntityFactory;

    /**
     * Écran dont la liste échoue pour une raison étrangère aux listes : « classe » => raison. À retirer quand c'est corrigé (le test
     * le signale).
     */
    private const KNOWN_FAILURES = [
        // MemoireQuestionCrudController retiré le 13/11/2026 : le choix « Tous / Transversal » à valeur null est devenu le placeholder du champ
    ];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /**
     * Sonde : sur CHAQUE écran de site, la liste s'ouvre, une recherche répond, et un tri sur chaque colonne triable répond. Un champ
     * qui ne se cherche ou ne se trie pas ferait une erreur 500 : le test nomme l'écran et la requête.
     */
    public function testEveryListOpensSearchesAndSortsWithoutError(): void
    {
        $this->loginAs(['ROLE_ADMIN']);
        $failures = [];
        $screens = $sorts = 0;
        foreach ($this->siteScreens() as $controller) {
            [$status, $html] = $this->index($controller);
            if ($status === 403 || $status === 302) {
                continue; // écran réservé (super administrateur, module non accordé)
            }
            ++$screens;
            if ($status !== 200) {
                $failures[] = "$controller liste $status";
                continue;
            }
            foreach (['é a', '12'] as $term) { // du texte, puis un nombre (les colonnes numériques ne sont cherchées que pour un nombre)
                [$status] = $this->index($controller, ['query' => $term]);
                if ($status !== 200) {
                    $failures[] = "$controller recherche « $term » $status";
                }
            }
            preg_match_all('/sort%5B([A-Za-z0-9_.]+)%5D=(?:ASC|DESC)|sort\[([A-Za-z0-9_.]+)\]=(?:ASC|DESC)/', $html, $m);
            foreach (array_unique(array_filter(array_merge($m[1], $m[2]))) as $field) {
                ++$sorts; // un seul sens : une colonne qui ne se trie pas échoue dans les deux
                [$status] = $this->index($controller, ['sort' => [$field => 'DESC']]);
                if ($status !== 200) {
                    $failures[] = "$controller tri $field DESC $status";
                }
            }
        }
        $known = array_values(array_filter($failures, fn (string $f) => array_filter(array_keys(self::KNOWN_FAILURES), fn (string $c) => str_starts_with($f, $c . ' ')) !== []));
        $failures = array_values(array_diff($failures, $known));
        foreach (array_keys(self::KNOWN_FAILURES) as $class) {
            $this->assertNotSame([], array_filter($known, fn (string $f) => str_starts_with($f, $class . ' ')), "$class ne fait plus d'erreur : à retirer de KNOWN_FAILURES");
        }
        $this->assertGreaterThan(80, $screens, 'la sonde a bien parcouru les écrans de site');
        $this->assertGreaterThan(150, $sorts, 'la sonde a bien trié des colonnes');
        $this->assertSame([], $failures, "Liste, recherche ou tri en erreur :\n" . implode("\n", $failures));
    }

    public function testSearchSortAndDefaultSortAreApplied(): void
    {
        $db = $this->em()->getConnection();
        $tag = bin2hex(random_bytes(3));
        $type = (int) $db->fetchOne("INSERT INTO type_fournisseur (id, name) VALUES (nextval('type_fournisseur_id_seq'), ?) RETURNING id", ["Type $tag"]);
        $ids = [];
        foreach (["Beta $tag" => 'Laval', "alpha $tag" => 'Québec', "Gamma $tag" => 'Laval'] as $name => $ville) {
            $ids[$name] = (int) $db->fetchOne("INSERT INTO fournisseur (id, name, ville, type_fournisseur_id) VALUES (nextval('fournisseur_id_seq'), ?, ?, ?) RETURNING id", [$name, $ville, $type]);
        }
        $this->loginAs(['ROLE_ADMIN']);
        $rows = function (array $query) use ($ids): array {
            [$status] = $this->index(FournisseurCrudController::class, $query);
            $this->assertSame(200, $status);

            return array_values(array_intersect(array_map('intval', $this->client->getCrawler()->filter('table tbody tr[data-id]')->each(fn ($n) => $n->attr('data-id'))), array_values($ids)));
        };
        // Recherche : seules les lignes qui portent le mot (avant : toute la table)
        $this->assertSame([$ids["Beta $tag"], $ids["alpha $tag"], $ids["Gamma $tag"]], $rows(['query' => $tag]), 'les trois fournisseurs du test, par identifiant (tri par défaut de la classe de base)');
        $this->assertSame([$ids["alpha $tag"]], $rows(['query' => "alpha $tag"]));
        $this->assertSame([], $rows(['query' => 'aucun-fournisseur-' . $tag]));
        $this->assertSame([$ids["Beta $tag"], $ids["Gamma $tag"]], $rows(['query' => 'Laval']), 'la recherche porte sur toutes les colonnes de texte de l\'écran');
        // Tri par une colonne (avant : sans effet)
        $this->assertSame([$ids["Gamma $tag"], $ids["alpha $tag"], $ids["Beta $tag"]], $rows(['query' => $tag, 'sort' => ['id' => 'DESC']]));
        $byVille = $rows(['query' => $tag, 'sort' => ['ville' => 'DESC']]);
        $this->assertSame($ids["alpha $tag"], $byVille[0], 'Québec avant Laval en ordre décroissant');

        // Colonnes sans ordre possible (JSON, association vue du côté inverse) : l'en-tête n'est pas un lien de tri, et un tri forcé
        // dans l'adresse ne casse pas la page
        foreach ([[\App\Controller\Admin\UserCrudController::class, 'roles'], [\App\Controller\Admin\EntrepriseCrudController::class, 'addressEntreprise'], [\App\Controller\Admin\LandingPageSettingCrudController::class, 'configuration']] as [$controller, $field]) {
            [$status, $html] = $this->index($controller);
            $this->assertSame(200, $status);
            $this->assertStringNotContainsString('sort%5B' . $field . '%5D', $html, "$controller : pas de lien de tri sur $field");
            $this->assertStringNotContainsString("sort[$field]", $html);
            [$status] = $this->index($controller, ['sort' => [$field => 'ASC']]);
            $this->assertSame(200, $status, "$controller : tri forcé sur $field");
        }
    }

    public function testADeclaredDefaultSortAndADateFilterAreApplied(): void
    {
        $db = $this->em()->getConnection();
        $db->executeStatement('DELETE FROM shared_media');
        $ids = [];
        foreach (['2026-11-10 12:00:00', '2026-11-12 12:00:00', '2026-11-11 12:00:00'] as $i => $createdAt) {
            $ids[] = (int) $db->fetchOne("INSERT INTO shared_media (titre, filename, original_filename, media_type, mime_type, file_size, visibility, access_key, download_count, created_at)
                VALUES (?, ?, 'doc.pdf', 'document', 'application/pdf', 1000, 'private', ?, 0, ?) RETURNING id", ["Média $i", "doc_$i" . bin2hex(random_bytes(4)) . '.pdf', bin2hex(random_bytes(32)), $createdAt]);
        }
        $before = $db->fetchOne('SELECT pos_timezone FROM entreprise ORDER BY id LIMIT 1');
        $db->executeStatement("UPDATE entreprise SET pos_timezone = 'America/Toronto'");
        $submissions = [];
        foreach (['2026-11-12 03:00:00', '2026-11-12 15:00:00', '2026-11-13 03:30:00'] as $at) { // 22 h le 11, 10 h le 12, 22 h 30 le 12 au magasin
            $submissions[$at] = (int) $db->fetchOne("INSERT INTO form_submission (id, reference, form_name, app, section_uid, form_id, answers, locale, status, target, created_at, updated_at)
                VALUES (nextval('form_submission_id_seq'), ?, 'Contact', 'site', 'section', 'formulaire', '{}', 'fr', 'new', 'generic', ?, ?) RETURNING id", ['EL-' . bin2hex(random_bytes(6)), $at, $at]);
        }
        try {
            $this->loginAs(['ROLE_ADMIN']);
            $listed = function (string $controller, array $query, array $among): array {
                [$status] = $this->index($controller, $query);
                $this->assertSame(200, $status);

                return array_values(array_intersect(array_map('intval', $this->client->getCrawler()->filter('table tbody tr[data-id]')->each(fn ($n) => $n->attr('data-id'))), $among));
            };
            // Tri par défaut déclaré par l'écran : la médiathèque du plus récent au plus ancien (avant : par identifiant croissant)
            $this->assertSame([$ids[1], $ids[2], $ids[0]], $listed(SharedMediaCrudController::class, [], $ids), 'createdAt décroissant');
            // Filtre de date : la journée du 12 AU MAGASIN (et non la journée UTC, qui rendrait 3 h et 15 h le 12)
            $day = ['createdAt' => ['comparison' => 'between', 'value' => '2026-11-12T00:00', 'value2' => '2026-11-12T23:59']];
            $found = $listed(FormSubmissionCrudController::class, ['filters' => $day], array_values($submissions));
            sort($found);
            $this->assertSame([$submissions['2026-11-12 15:00:00'], $submissions['2026-11-13 03:30:00']], $found);
        } finally {
            $this->em()->getConnection()->executeStatement('UPDATE entreprise SET pos_timezone = ?', [$before ?: null]);
        }
    }

    /** Dates qui parlent au commerçant : à l'heure du magasin, sur la fiche de commande et la liste des transactions de caisse */
    public function testMerchantFacingDatesAreShownInStoreTime(): void
    {
        $db = $this->em()->getConnection();
        $before = $db->fetchOne('SELECT pos_timezone FROM entreprise ORDER BY id LIMIT 1');
        $db->executeStatement("UPDATE entreprise SET pos_timezone = 'America/Toronto'");
        try {
            // 2 h 15 UTC le 12 novembre = 21 h 15 le 11 à Toronto
            $extension = static::getContainer()->get(\App\Twig\StoreTimeExtension::class);
            $this->assertSame('11/11/2026 21:15', $extension->format(new \DateTimeImmutable('2026-11-12 02:15:00', new \DateTimeZone('UTC')), 'd/m/Y H:i'));
            $this->assertSame('11/11/2026', $extension->format(new \DateTime('2026-11-12 02:15:00', new \DateTimeZone('UTC')), 'd/m/Y'), 'le jour du magasin, pas le jour UTC');
            $this->assertSame('', $extension->format(null, 'd/m/Y'));
            $this->assertSame('2026-11-11 21:15', $extension->format('2026-11-12 02:15', 'Y-m-d H:i'), 'chaîne sans décalage, telle que les services la rendent : lue comme de l\'UTC');
            $this->assertSame('2026-11-11 21:15', $extension->format('2026-11-12T03:15:00+01:00', 'Y-m-d H:i'), 'chaîne avec décalage : son instant');
            $this->assertSame('pas une date', $extension->format('pas une date', 'Y-m-d H:i'));

            $order = $this->create(\App\Entity\Order::class, [], fn (\App\Entity\Order $o, EntityManagerInterface $em) => $o->setStatus($em->getRepository(\App\Entity\StatusCommande::class)->findOneBy([]))->setOrderSource($em->find(\App\Entity\OrderSource::class, 1)));
            $this->em()->getConnection()->executeStatement("UPDATE \"order\" SET order_date = '2026-11-12 02:15:00' WHERE id = ?", [$order->getId()]);
            $this->em()->clear();
            $this->loginAs(['ROLE_ADMIN']);
            $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/commandes/' . $order->getId(), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
            $this->assertSame(200, $this->client->getResponse()->getStatusCode(), substr(strip_tags((string) $this->client->getResponse()->getContent()), 0, 300));
            $this->assertStringContainsString('Passée le 11/11/2026 à 21:15 (heure du magasin)', preg_replace('/\s+/u', ' ', $this->client->getCrawler()->filter('body')->text()), 'fiche de commande');
            $this->assertStringContainsString('2026-11-11 21:15', $this->client->getCrawler()->filter('body')->text(), 'chronologie : « Commande passée » à l\'heure du magasin');
            $this->assertStringNotContainsString('2026-11-12 02:15', $this->client->getCrawler()->filter('body')->text(), 'plus aucune heure UTC sur la fiche');

            // Transaction de caisse : un instant (écrit à l'heure courante en UTC). Les deux listes la montrent à l'heure du magasin ;
            // avant, elles montraient le jour UTC, donc le lendemain pour une vente du soir
            $register = $this->create(\App\Entity\CashRegister::class);
            $session = $this->create(\App\Entity\Caisse::class, [], fn ($c, EntityManagerInterface $em) => $c->setCashRegister($em->find(\App\Entity\CashRegister::class, $register->getId())));
            if ($this->em()->getRepository(\App\Entity\TransactionType::class)->findOneBy([]) === null) {
                $this->create(\App\Entity\TransactionType::class);
            }
            $transaction = $this->create(\App\Entity\TransactionCaisse::class, [], fn ($t, EntityManagerInterface $em) => $t->setCaisse($em->find(\App\Entity\Caisse::class, $session->getId()))
                ->setUserCaisse($em->getRepository(User::class)->findOneBy([]))->setTransactionType($em->getRepository(\App\Entity\TransactionType::class)->findOneBy([])));
            $this->em()->getConnection()->executeStatement("UPDATE transaction_caisse SET transaction_date = '2026-11-12 02:15:00' WHERE id = ?", [$transaction->getId()]);
            $this->em()->clear();
            foreach ([\App\Controller\Admin\TransactionCaisseCrudController::class, \App\Controller\Admin\TransactionCaisseListController::class] as $controller) {
                [$status] = $this->index($controller, ['sort' => ['id' => 'DESC'], 'caisseId' => $session->getId()]);
                $this->assertSame(200, $status, $controller);
                $cells = $this->client->getCrawler()->filter('table tbody tr td')->each(fn ($n) => trim($n->text()));
                $this->assertContains('11/11/2026 21:15', $cells, "$controller : 2 h 15 UTC le 12 = 21 h 15 le 11 au magasin");
            }
        } finally {
            $this->em()->getConnection()->executeStatement('UPDATE entreprise SET pos_timezone = ?', [$before ?: null]);
        }
    }

    // ----- outils -----

    /** @return list<class-string> écrans de site, c'est-à-dire les classes concrètes qui étendent BaseTenantCrudController */
    private function siteScreens(): array
    {
        $root = dirname(__DIR__, 3) . '/src/';
        $classes = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . 'Controller/Admin', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $class = 'App\\' . str_replace('/', '\\', substr($file->getPathname(), strlen($root), -4));
            if (class_exists($class) && is_subclass_of($class, BaseTenantCrudController::class) && !(new \ReflectionClass($class))->isAbstract()) {
                $classes[] = $class;
            }
        }
        sort($classes);

        return $classes;
    }

    /** @return array{0: int, 1: string} statut et page ; une exception du noyau compte comme une erreur 500 */
    private function index(string $controller, array $query = []): array
    {
        try {
            $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(['crudAction' => 'index', 'crudControllerFqcn' => $controller] + $query), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);

            return [$this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent()];
        } catch (\Throwable $e) {
            return [500, $e::class . ' : ' . $e->getMessage()];
        }
    }

    private function loginAs(array $roles): void
    {
        $email = 'ea-listes-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Admin')->setLastname('Listes')->setRoles($roles)->setIsVerified(true)->setPassword('x');
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
}
