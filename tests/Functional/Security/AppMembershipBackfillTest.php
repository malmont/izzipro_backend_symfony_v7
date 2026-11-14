<?php

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Security\AppFamilies;
use App\Services\AppMembershipService\AppMembershipService;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * APPS-MEMBRES, lot M2 (14/11/2026, docs/apps-membres.md § 8) : reprise de l'historique des appartenances par
 * `app:members:backfill <site>`. Simulation par défaut (aucune écriture, un rapport de nombres), `--apply --confirm=<site>` pour
 * écrire, rejouable. Chaque trace et chaque exclusion a son compte d'essai ; les comptes déjà présents sur le site de test sont
 * neutralisés en comparant le rapport à celui d'avant.
 */
class AppMembershipBackfillTest extends KernelTestCase
{
    private \Doctrine\DBAL\Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-reprise@example.invalid', false, false);
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    public function testTheBackfillSimulatesThenAppliesOnceAndNeverGuesses(): void
    {
        $before = $this->run_(['--json' => true])['report'];
        $this->assertNull($before['backfilledAt']);
        $memberships = (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_app_membership');

        // Living Memory : inscription par l'application ; livres mobile et leur impression ; paiement de chapitre. Mémoires Vivantes WEB n'est pas une
        // famille : un compte invité par la console, ses livres web et l'impression d'un livre web ne sont pas des traces
        $living = $this->account('living', ['signup_channel' => 'mobile', 'created_at' => '2024-02-03 04:05:06']);
        $invited = $this->account('invite', ['signup_channel' => 'web_invite', 'created_at' => '2024-06-01 00:00:00']);
        $book = $this->db->fetchOne("INSERT INTO mv_book (id, user_id, created_at, updated_at, type, title, format, status) VALUES (gen_random_uuid(), ?, '2024-03-01 10:00:00', NOW(), 'individuel', 'Reprise', 'livre_s', 'draft') RETURNING id", [$invited]);
        $this->db->executeStatement("INSERT INTO mv_book (id, user_id, created_at, updated_at, type, title, format, status) VALUES (gen_random_uuid(), ?, '2025-08-08 00:00:00', NOW(), 'individuel', 'Reprise, second livre', 'livre_s', 'draft')", [$invited]);
        // un compte de l'application : deux livres mobile (la date du plus ancien) et l'impression de l'un d'eux
        $mobileOwner = $this->account('livres-mobile');
        $mobileBook = $this->db->fetchOne("INSERT INTO mv_book (id, user_id, created_at, updated_at, type, title, format, status, channel) VALUES (gen_random_uuid(), ?, '2024-03-01 10:00:00', NOW(), 'famille', 'Reprise mobile', 'livre_s', 'draft', 'mobile') RETURNING id", [$mobileOwner]);
        $this->db->executeStatement("INSERT INTO mv_book (id, user_id, created_at, updated_at, type, title, format, status, channel) VALUES (gen_random_uuid(), ?, '2025-08-08 00:00:00', NOW(), 'famille', 'Reprise mobile, second livre', 'livre_s', 'draft', 'mobile')", [$mobileOwner]);
        $this->db->executeStatement("INSERT INTO mv_book_print_order (id, book_id, user_id, recipient_name, street1, city, postal_code, created_at, updated_at) VALUES (gen_random_uuid(), ?, ?, 'R', '1 rue', 'Ville', 'H1A 1A1', '2024-10-10 00:00:00', NOW())", [$mobileBook, $mobileOwner]);
        $payer = $this->account('payeur');
        $this->db->executeStatement("INSERT INTO mv_chapter_payment (user_id, store, product_id, transaction_id, purchased_at, created_at) VALUES (?, 'app_store', 'chapitre', ?, '2024-07-08 09:00:00', '2024-07-09 00:00:00')", [$payer, 'reprise-' . $payer]);
        $this->db->executeStatement("INSERT INTO mv_book_print_order (id, book_id, user_id, recipient_name, street1, city, postal_code, created_at, updated_at) VALUES (gen_random_uuid(), ?, ?, 'R', '1 rue', 'Ville', 'H1A 1A1', '2024-09-01 00:00:00', NOW())", [$book, $payer]);
        // Boutique : commande en ligne (annulée ou non) et avis ; inscription par l'application mobile ; panier de paiement ; panier enregistré avec un article
        $buyer = $this->account('acheteur');
        $this->order($buyer, 'Ecommerce', '2023-01-02 03:04:05');
        $this->order($buyer, 'Mobile_app', '2023-05-05 00:00:00');
        $this->db->executeStatement("INSERT INTO reviews_product (id, user_review_id, product_reviews_id, note, created_at) SELECT nextval('reviews_product_id_seq'), ?, MIN(id), 5, '2022-12-31 00:00:00' FROM product", [$buyer]);
        $shopApp = $this->account('appli', ['signup_channel' => 'mobile_shop', 'created_at' => '2025-01-01 00:00:00']);
        $checkout = $this->account('paiement');
        $this->db->executeStatement("INSERT INTO checkout_session (user_id, cart, amount, created_at, updated_at, payment_intent_id, currency) VALUES (?, '[]', 100, '2025-02-02 00:00:00', NOW(), ?, 'cad')", [$checkout, 'pi_reprise_' . $checkout]);
        $cartOwner = $this->account('panier');
        $this->cart($cartOwner, true);
        $appBuyer = $this->account('acheteur-appli');
        $this->order($appBuyer, 'Mobile_app', '2023-06-06 00:00:00');
        // Caisse : fiche rattachée avec son consentement, à SA version et SA date
        $posLinked = $this->account('caisse');
        $this->posFile($posLinked, true);
        // Deux familles pour un même compte : une commande en ligne et un livre de l'application
        $both = $this->account('deux');
        $this->order($both, 'Ecommerce', '2023-03-03 00:00:00');
        $this->db->executeStatement("INSERT INTO mv_book (id, user_id, created_at, updated_at, type, title, format, status) VALUES (gen_random_uuid(), ?, '2024-04-04 00:00:00', NOW(), 'individuel', 'Reprise 2', 'livre_s', 'draft')", [$both]);
        $this->db->executeStatement("UPDATE mv_book SET channel = 'mobile' WHERE user_id = ?", [$both]);

        // JAMAIS repris. Une vente de caisse (elle porte le compte du caissier) ; une adresse seule ; un panier vide ; une fiche de caisse au consentement retiré
        $posSale = $this->account('ex-caissier');
        $this->order($posSale, 'Pos', '2023-01-01 00:00:00');
        $this->order($posSale, null, '2023-01-01 00:00:00');
        $address = $this->account('adresse');
        foreach ([$address, $buyer] as $withAddress) { // l'acheteur a une adresse ET des traces : elle ne change rien pour lui
            $this->db->executeStatement("INSERT INTO adress (id, user_adress_id, firstname, lastname, fullname, address, phone, city, codepostal, country) VALUES (nextval('adress_id_seq'), ?, 'A', 'B', 'A B', '1 rue', '-', 'Ville', 'H1A 1A1', 'CA')", [$withAddress]);
        }
        $emptyCart = $this->account('panier-vide');
        $this->cart($emptyCart, false);
        $withdrawn = $this->account('retrait');
        $this->posFile($withdrawn, false);
        $nothing = $this->account('rien', ['signup_channel' => 'web_register']);
        $this->db->executeStatement("INSERT INTO pos_customer (user_id, first_name, last_name, created_at, updated_at) VALUES (?, 'Reprise', 'Sans consentement', NOW(), NOW())", [$nothing]); // rattachée sans aucun consentement enregistré
        // un compte d'équipe, un compte effacé, un compte né d'une commande en invité sans mot de passe choisi : avec des traces, et pourtant rien
        $team = $this->account('equipe', ['roles' => '["ROLE_ADMIN","ROLE_USER_INTERNET"]', 'signup_channel' => 'mobile']);
        $this->order($team, 'Ecommerce', '2023-01-01 00:00:00');
        $erased = $this->account('efface', ['deleted_at' => '2025-01-01 00:00:00', 'signup_channel' => 'mobile_shop']);
        $this->order($erased, 'Ecommerce', '2023-01-01 00:00:00');
        $guest = $this->account('invite-commande', ['password_set' => 'false']);
        $this->order($guest, 'Ecommerce', '2023-01-01 00:00:00');
        // déjà membre : jamais modifié, jamais reproposé
        $member = $this->account('membre');
        $this->order($member, 'Ecommerce', '2023-01-01 00:00:00');
        static::getContainer()->get(AppMembershipService::class)->grant($this->user($member), AppFamilies::BOUTIQUE, AppFamilies::SOURCE_REGISTER, 'v-inscription', new \DateTimeImmutable('2026-01-01 00:00:00'));

        // 1. SIMULATION : rien n'est écrit, le rapport ne porte que des nombres
        $simulation = $this->run_(['--json' => true]);
        $report = $simulation['report'];
        $this->assertSame('simulation', $simulation['mode']);
        $this->assertSame([$memberships + 1, null], [(int) $this->db->fetchOne('SELECT COUNT(*) FROM user_app_membership'), $this->db->fetchOne('SELECT app_membership_backfilled_at FROM entreprise ORDER BY id LIMIT 1')], 'la simulation n\'écrit rien');
        $delta = fn (array $path): int => self::num($report, $path) - self::num($before, $path);
        $this->assertSame(['total' => 20, 'erased' => 1, 'team' => 1, 'withoutPassword' => 1, 'eligible' => 17], $this->minus($report['accounts'], $before['accounts']));
        $this->assertSame([4, 6, 1, 1], [$delta(['families', 'livingmemory', 'proposed']), $delta(['families', 'boutique', 'proposed']), $delta(['families', 'pos', 'proposed']), $delta(['families', 'boutique', 'members'])]);
        $this->assertSame(['signup_living_memory' => 1, 'mobile_book' => 2, 'chapter_payment' => 1, 'print_order' => 1], $this->minus($report['families']['livingmemory']['byTrace'], $before['families']['livingmemory']['byTrace']));
        $this->assertSame(['signup_mobile_shop' => 1, 'order' => 3, 'review' => 1, 'subscription' => 0, 'checkout_session' => 1, 'cart' => 1], $this->minus($report['families']['boutique']['byTrace'], $before['families']['boutique']['byTrace']), 'la commande du compte déjà membre, de l\'équipe, du compte effacé ou sans mot de passe ne compte pas');
        $this->assertSame(['pos_link' => 1], $this->minus($report['families']['pos']['byTrace'], $before['families']['pos']['byTrace']));
        $this->assertSame([6, 1], [$delta(['withoutTrace']), $delta(['addressOnly'])], 'vente de caisse, adresse seule, panier vide, consentement retiré, rien, invité du web : six comptes sans trace, dont un avec une adresse');
        $this->assertSame(['posLinkedWithoutConsent' => 2, 'withoutPasswordWithOrders' => 1, 'ordersWithoutSource' => 1], $this->minus($report['anomalies'], $before['anomalies']));
        $this->assertLessThanOrEqual('2022-12-31 00:00:00', $report['families']['boutique']['oldest'], 'la plus ancienne entrée proposée : au plus tard l\'avis de 2022');
        // le détail par compte : des identifiants, jamais un nom ni un courriel
        $detail = (string) file_get_contents($simulation['detail']);
        $this->assertStringContainsString('/var/members-backfill/' . MV_TEST_TENANT_CODE . '-', $simulation['detail']);
        $this->assertStringNotContainsString('@', $detail);
        $proposals = [];
        foreach (json_decode($detail, true)['proposals'] as $p) {
            $proposals[$p['userId']][$p['app']] = [$p['joinedAt'], $p['traces'], $p['termsVersion'], $p['termsAcceptedAt']];
        }
        $this->assertSame(['livingmemory' => ['2024-02-03 04:05:06', ['signup_living_memory'], null, null]], $proposals[$living]);
        $this->assertSame(['livingmemory' => ['2024-03-01 10:00:00', ['mobile_book', 'print_order'], null, null]], $proposals[$mobileOwner], 'deux traces, deux livres : la date de la plus ancienne');
        $this->assertSame(['livingmemory' => ['2024-07-08 09:00:00', ['chapter_payment'], null, null]], $proposals[$payer], 'la date de l\'achat ; l\'impression d\'un livre WEB n\'est pas une trace');
        $this->assertSame(['boutique' => ['2022-12-31 00:00:00', ['order', 'review'], null, null]], $proposals[$buyer]);
        $this->assertSame(['pos' => ['2025-01-02 03:04:05', ['pos_link'], '2025-01', '2025-01-02 03:04:05']], $proposals[$posLinked], 'le vrai consentement : sa version et sa date');
        $this->assertSame(['boutique', 'livingmemory'], array_keys($proposals[$both]), 'deux familles pour un même compte');
        $this->assertSame(['boutique' => ['2023-06-06 00:00:00', ['order'], null, null]], $proposals[$appBuyer], 'une commande par l\'application mobile de la boutique');
        foreach ([$posSale, $address, $emptyCart, $withdrawn, $nothing, $team, $erased, $guest, $member, $invited] as $id) {
            $this->assertArrayNotHasKey($id, $proposals, "compte $id : rien à reprendre");
        }

        // 2. --apply demande que le code du site soit redit : sans lui, ou avec un autre, rien n'est écrit
        foreach ([['--apply' => true], ['--apply' => true, '--confirm' => 'demo']] as $arguments) {
            [$status, $display] = $this->execute($arguments);
            $this->assertSame(1, $status);
            $this->assertStringContainsString('Rien n\'a été écrit', $display);
        }
        $this->assertSame($memberships + 1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_app_membership'));
        [$status, $display] = $this->execute([], 'site-inexistant');
        $this->assertSame([1, true], [$status, str_contains($display, 'absent de la table tenants')]);

        // 3. APPLICATION : exactement ce que la simulation proposait ; source backfill, aucune version sauf la caisse ; au journal avec ses traces
        $applied = $this->run_(['--apply' => true, '--confirm' => MV_TEST_TENANT_CODE, '--json' => true]);
        $this->assertSame(['apply', array_map(fn (array $f) => $f['proposed'], $report['families'])], [$applied['mode'], $applied['created']]);
        $this->assertNotNull($applied['report']['backfilledAt']);
        $row = fn (int $id, string $app) => $this->db->fetchAssociative('SELECT source, joined_at, terms_version, terms_accepted_at FROM user_app_membership WHERE user_id = ? AND app = ?', [$id, $app]);
        $this->assertSame(['source' => 'backfill', 'joined_at' => '2022-12-31 00:00:00', 'terms_version' => null, 'terms_accepted_at' => null], $row($buyer, 'boutique'));
        $this->assertSame(['source' => 'backfill', 'joined_at' => '2024-03-01 10:00:00', 'terms_version' => null, 'terms_accepted_at' => null], $row($mobileOwner, 'livingmemory'));
        $this->assertSame(['source' => 'backfill', 'joined_at' => '2025-01-02 03:04:05', 'terms_version' => '2025-01', 'terms_accepted_at' => '2025-01-02 03:04:05'], $row($posLinked, 'pos'));
        $this->assertSame(['source' => 'register', 'joined_at' => '2026-01-01 00:00:00', 'terms_version' => 'v-inscription', 'terms_accepted_at' => '2026-01-01 00:00:00'], $row($member, 'boutique'), 'déjà membre : rien n\'est modifié');
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_app_membership WHERE user_id IN (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$posSale, $address, $emptyCart, $withdrawn, $nothing, $team, $erased, $guest, $invited]));
        $journal = $this->db->fetchAssociative("SELECT action, fields, after_state FROM content_audit_log WHERE resource = 'user_app' AND resource_id = ? ORDER BY id DESC LIMIT 1", [(string) $buyer]);
        $this->assertSame(['grant', 'boutique,backfill', ['order', 'review']], [$journal['action'], $journal['fields'], json_decode($journal['after_state'], true)['traces']]);
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_app_membership');
        $this->assertSame($memberships + 1 + array_sum($applied['created']), $total);

        // 4. REJOUÉE : zéro ligne de plus, la date de la PREMIÈRE reprise ne bouge pas ; la simulation suivante ne propose plus rien
        $this->db->executeStatement("UPDATE entreprise SET app_membership_backfilled_at = '2026-01-01 00:00:00'");
        $again = $this->run_(['--apply' => true, '--confirm' => MV_TEST_TENANT_CODE, '--json' => true]);
        $this->assertSame([['boutique' => 0, 'livingmemory' => 0, 'pos' => 0], '2026-01-01 00:00:00', $total], [$again['created'], $again['report']['backfilledAt'], (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_app_membership')]);
        $after = $this->run_(['--json' => true])['report'];
        $this->assertSame([0, 0, 0], array_values(array_map(fn (array $f) => $f['proposed'], $after['families'])));
        $this->assertSame($report['withoutTrace'], $after['withoutTrace'], 'les comptes sans trace le restent : ils passeront par « Rejoindre »');
        // le rapport lisible : des nombres, la mention de la simulation
        [$status, $display] = $this->execute([]);
        $this->assertSame(0, $status);
        $this->assertStringContainsString('simulation (aucune écriture)', $display);
        $this->assertStringContainsString('boutique : ' . $after['families']['boutique']['members'] . ' déjà membres ; 0 proposées', $display);
        $this->assertStringContainsString('Reprise du site : faite le ', $display);
    }

    /**
     * Lot M2-b : un site neuf est une copie du modèle, sans fiche Entreprise ni historique. La fiche créée ensuite naît « reprise
     * faite » (valeur par défaut de la colonne) ; une fiche qui existait garde sa valeur.
     */
    public function testAnEntrepriseFileCreatedFromNowOnIsBornWithItsBackfillDone(): void
    {
        $this->assertSame('now()', $this->db->fetchOne("SELECT column_default FROM information_schema.columns WHERE table_name = 'entreprise' AND column_name = 'app_membership_backfilled_at'"));
        $first = (int) $this->db->fetchOne('SELECT MIN(id) FROM entreprise');
        $this->assertNull($this->db->fetchOne('SELECT app_membership_backfilled_at FROM entreprise WHERE id = ?', [$first]), 'la fiche du site de test existait : reprise pas faite');
        $this->db->beginTransaction();
        try {
            $created = (int) $this->db->fetchOne("INSERT INTO entreprise (id, name) VALUES (nextval('entreprise_id_seq'), 'Fiche née après la reprise') RETURNING id");
            $this->assertNotNull($this->db->fetchOne('SELECT app_membership_backfilled_at FROM entreprise WHERE id = ?', [$created]), 'fiche créée maintenant : reprise faite, sans rien écrire de plus');
            $this->assertNull($this->db->fetchOne('SELECT app_membership_backfilled_at FROM entreprise WHERE id = ?', [$first]), 'la fiche existante n\'est pas touchée');
        } finally {
            $this->db->rollBack();
        }
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM entreprise WHERE id >= ?', [$first]), 'la fiche d\'essai n\'est pas restée');
    }

    // ---------------------------------------------------------------- aides

    /** @param list<string> $path */
    private static function num(array $report, array $path): int
    {
        foreach ($path as $key) {
            $report = $report[$key];
        }

        return (int) $report;
    }

    /** @return array<string, int> */
    private function minus(array $after, array $before): array
    {
        $out = [];
        foreach ($after as $key => $value) {
            $out[$key] = $value - $before[$key];
        }

        return $out;
    }

    /** @return array<string, mixed> le rapport JSON de la commande */
    private function run_(array $arguments): array
    {
        [$status, $display] = $this->execute($arguments);
        $this->assertSame(0, $status, $display);

        return json_decode($display, true);
    }

    /** @return array{0: int, 1: string} */
    private function execute(array $arguments, string $site = MV_TEST_TENANT_CODE): array
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:members:backfill'));
        $status = $tester->execute(['site' => $site] + $arguments);
        static::getContainer()->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return [$status, $tester->getDisplay()];
    }

    /** Compte client d'essai (mot de passe choisi), par SQL ; rend son identifiant */
    private function account(string $name, array $columns = []): int
    {
        $columns += ['roles' => '["ROLE_USER","ROLE_USER_INTERNET"]', 'password_set' => 'true', 'signup_channel' => null, 'created_at' => '2025-06-01 00:00:00', 'deleted_at' => null];
        $email = 'reprise-m2-' . $name . '-' . bin2hex(random_bytes(3)) . '@example.invalid';

        return (int) $this->db->fetchOne('INSERT INTO "user" (id, email, username, firstname, lastname, password, roles, is_verified, otp_enabled, password_set, signup_channel, created_at, deleted_at)
            VALUES (nextval(\'user_id_seq\'), ?, ?, \'Reprise\', \'Essai\', \'inutilisable\', ?, true, false, ?, ?, ?, ?) RETURNING id',
            [$email, $email, $columns['roles'], $columns['password_set'], $columns['signup_channel'], $columns['created_at'], $columns['deleted_at']]);
    }

    private function user(int $id): User
    {
        return static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager()->find(User::class, $id);
    }

    private function order(int $userId, ?string $source, string $date): void
    {
        $this->db->executeStatement('INSERT INTO "order" (id, status_id, order_date, total_amount, reference, user_id_id, order_source_id)
            VALUES (nextval(\'order_id_seq\'), (SELECT MIN(id) FROM status_commande), ?, 10, ?, ?, (SELECT id FROM order_source WHERE name = ?))', [$date, 'REPRISE-' . bin2hex(random_bytes(4)), $userId, $source]);
    }

    private function cart(int $userId, bool $withItem): void
    {
        $id = (int) $this->db->fetchOne('INSERT INTO "Cart" (id, user_cart_id, quantity, sub_total_ht, taxe, sub_total_ttc, carrierprice, ispaid, created_at, reference, fullname, carriername, deleveryaddress)
            VALUES (nextval(\'"Cart_id_seq"\'), ?, 1, 10, 1, 11, 0, false, \'2025-03-03 00:00:00\', ?, \'R\', \'-\', \'-\') RETURNING id', [$userId, 'REPRISE-' . bin2hex(random_bytes(4))]);
        if ($withItem) {
            $this->db->executeStatement('INSERT INTO cart_details (id, carts_id, sub_total_ttc, producprice, quantity, sub_total_ht, taxe, productname) VALUES (nextval(\'cart_details_id_seq\'), ?, 11, 10, 1, 10, 1, \'Article\')', [$id]);
        }
    }

    /** Fiche de caisse rattachée au compte : consentement account_link accordé en 2025 ; `$kept` faux : retiré ensuite */
    private function posFile(int $userId, bool $kept): void
    {
        $file = (int) $this->db->fetchOne("INSERT INTO pos_customer (user_id, first_name, last_name, created_at, updated_at) VALUES (?, 'Reprise', 'Essai', NOW(), NOW()) RETURNING id", [$userId]);
        $this->db->executeStatement("INSERT INTO pos_customer_consent (customer_id, purpose, granted, at, text_version) VALUES (?, 'account_link', true, '2025-01-02 03:04:05', '2025-01')", [$file]);
        if (!$kept) {
            $this->db->executeStatement("INSERT INTO pos_customer_consent (customer_id, purpose, granted, at, text_version) VALUES (?, 'account_link', false, '2025-02-02 00:00:00', '2025-01')", [$file]);
        }
    }

    /** Retire les comptes d'essai et tout ce qui s'y rattache ; remet le site dans l'état « reprise pas faite » */
    private function clean(): void
    {
        $users = 'SELECT id FROM "user" WHERE email LIKE \'reprise-m2-%@example.invalid\''; // préfixe propre à ce test : jamais les comptes d'un autre
        foreach ([
            "DELETE FROM pos_customer_consent WHERE customer_id IN (SELECT id FROM pos_customer WHERE user_id IN ($users))", "DELETE FROM pos_customer WHERE user_id IN ($users)",
            "DELETE FROM cart_details WHERE carts_id IN (SELECT id FROM \"Cart\" WHERE user_cart_id IN ($users))", "DELETE FROM \"Cart\" WHERE user_cart_id IN ($users)",
            "DELETE FROM checkout_session WHERE user_id IN ($users)", "DELETE FROM reviews_product WHERE user_review_id IN ($users)", "DELETE FROM \"order\" WHERE user_id_id IN ($users)",
            "DELETE FROM adress WHERE user_adress_id IN ($users)", "DELETE FROM mv_chapter_payment WHERE user_id IN ($users)", "DELETE FROM mv_book_print_order WHERE user_id IN ($users)",
            "DELETE FROM mv_book WHERE user_id IN ($users)", "DELETE FROM \"user\" WHERE id IN ($users)",
            "DELETE FROM user_app_membership WHERE source = 'backfill'", 'UPDATE entreprise SET app_membership_backfilled_at = NULL',
        ] as $sql) {
            $this->db->executeStatement($sql);
        }
    }
}
