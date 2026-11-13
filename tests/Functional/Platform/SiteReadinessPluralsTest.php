<?php

namespace App\Tests\Functional\Platform;

use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\User;
use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\SiteReadinessService;
use App\Services\TenantCacheService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Accord des textes du cheminement du site (demande du chef de projet, 13/11/2026) : « 1 produit avec un prix », « 2 produits avec un
 * prix », jamais une terminaison entre parenthèses. Le cheminement est PARTAGÉ : la gestion y lit son groupe, « Mon site » tous les autres.
 * Ce test vérifie donc que la boutique, la caisse, l'assistance et le socle lisent toujours leurs textes (rien de vide, aucun gabarit
 * resté tel quel), que le nombre commande la forme, et que l'anglais des groupes traduits s'accorde lui aussi.
 */
class SiteReadinessPluralsTest extends WebTestCase
{
    private const FEATURES = ['module.boutique', 'module.pos', 'module.support', 'app.management'];
    private const ENDING = '/\p{L}\((?:s|es|e|x|nt|en|ent|ies)\)/u';

    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private \PDO $master;
    /** @var array<string, mixed> */
    private array $flags = [];
    /** @var list<int> */
    private array $users = [];
    /** @var list<int> */
    private array $products = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        $this->master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $this->flags = $this->db->fetchAssociative('SELECT ' . implode(', ', FeatureCatalog::GOVERNED) . ' FROM entreprise ORDER BY id LIMIT 1');
        $this->cleanMaster();
        foreach (self::FEATURES as $feature) {
            static::getContainer()->get(EntitlementService::class)->grant(MV_TEST_TENANT_CODE, $feature, 'manual', null, 'test', 'PLURIELS', null, null, false);
        }
    }

    protected function tearDown(): void
    {
        if ($this->products !== []) {
            $this->db->executeStatement('DELETE FROM product_variant WHERE product_id IN (?)', [$this->products], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
            $this->db->executeStatement('DELETE FROM product WHERE id IN (?)', [$this->products], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
        }
        if ($this->users !== []) {
            $this->db->executeStatement('DELETE FROM "user" WHERE id IN (?)', [$this->users], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
        }
        $this->cleanMaster();
        $sets = implode(', ', array_map(fn (string $column) => "$column = ?", array_keys($this->flags)));
        $this->db->executeStatement("UPDATE entreprise SET $sets WHERE id = (SELECT id FROM entreprise ORDER BY id LIMIT 1)", array_map(fn ($v) => $v === null ? null : (filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 't' : 'f'), array_values($this->flags)));
        static::getContainer()->get(TenantCacheService::class)->invalidateTenant();
        parent::tearDown();
    }

    public function testEveryModuleStillReadsItsTextsAndTheNumberCommandsTheForm(): void
    {
        $fr = $this->steps();
        $en = $this->steps('?locale=en');
        $this->assertSame(array_keys($fr), array_keys($en), 'les mêmes étapes dans les deux langues');

        // 1. Chaque module lit toujours ses textes : des étapes pour chaque groupe, un titre et une description, aucun gabarit resté tel quel
        $perGroup = array_count_values(array_column(array_filter($fr, fn (array $s) => !str_starts_with($s['key'], 'obtain.')), 'group'));
        foreach (['site' => 5, 'module.boutique' => 5, 'module.pos' => 5, 'module.support' => 3, 'app.management' => 10, 'ai' => 2] as $group => $atLeast) {
            $this->assertGreaterThanOrEqual($atLeast, $perGroup[$group] ?? 0, "groupe $group : ses étapes sont rendues");
        }
        foreach (['fr' => $fr, 'en' => $en] as $locale => $steps) {
            foreach ($steps as $key => $step) {
                foreach (['title', 'description'] as $field) {
                    $text = $step[$field];
                    $this->assertIsString($text, "$key.$field ($locale)");
                    $this->assertNotSame('', trim($text), "$key.$field ($locale) : un texte");
                    $this->assertDoesNotMatchRegularExpression(self::ENDING, $text, "$key.$field ($locale) : accordé avec son nombre, pas de terminaison entre parenthèses");
                    $this->assertDoesNotMatchRegularExpression('/%[ds]|%\w+%|\{\d\}|\]1,Inf\[|\|/u', $text, "$key.$field ($locale) : aucun gabarit ni forme de pluriel resté tel quel");
                    $this->assertStringNotContainsString('steps.', $text, "$key.$field ($locale) : la clé de traduction n'est pas rendue à la place du texte");
                }
            }
        }

        // 2. Le nombre commande la forme, dans tous les groupes : chaque « N nom » rendu s'accorde (français : 0 et 1 au singulier)
        $checked = 0;
        foreach ($fr as $key => $step) {
            preg_match_all('/(?<![\d,.])(\d+) (produits?|comptes?|crédits?|articles?|fournisseurs?|appareils?)(?![\p{L}])/u', $step['description'], $found, PREG_SET_ORDER);
            foreach ($found as [$phrase, $count, $noun]) {
                $this->assertSame((int) $count > 1, str_ends_with($noun, 's'), "$key : « $phrase »");
                $checked++;
            }
        }
        $this->assertGreaterThanOrEqual(4, $checked, 'des textes chiffrés ont bien été contrôlés (boutique, caisse, assistance, gestion)');

        // 3. Textes exacts, d'après les comptes de la base, dans les modules qui ne sont pas la gestion
        $count = fn (string $sql): int => (int) $this->db->fetchOne($sql);
        $n = fn (int $c, string $one, string $many): string => sprintf($c <= 1 ? $one : $many, $c);
        $sellable = $count('SELECT COUNT(*) FROM product p WHERE EXISTS (SELECT 1 FROM product_variant v WHERE v.product_id = p.id AND v.price > 0)');
        $this->assertSame($sellable > 0 ? $n($sellable, '%d produit avec un prix.', '%d produits avec un prix.') : 'Créez au moins un produit avec une variante et un prix supérieur à zéro (vente, location ou abonnement).',
            $fr['boutique.product']['description'], 'boutique');
        $agents = $count("SELECT COUNT(*) FROM \"user\" WHERE roles::text LIKE '%ROLE_SUPPORT%' OR roles::text LIKE '%ROLE_ADMIN%'");
        $this->assertGreaterThanOrEqual(1, $agents);
        $this->assertSame($n($agents, '%d compte administrateur ou Assistance répond aux demandes.', '%d comptes administrateur ou Assistance répondent aux demandes.'), $fr['support.agent']['description'], 'assistance');
        $this->assertSame($n($agents, '%d administrator or Support account answers requests.', '%d administrator or Support accounts answer requests.'), $en['support.agent']['description'], 'assistance, en anglais');
        $admins = $count("SELECT COUNT(*) FROM \"user\" WHERE roles::text LIKE '%ROLE_ADMIN%'");
        $this->assertSame($n($admins, '%d compte administrateur peut se connecter à l\'application de gestion.', '%d comptes administrateur peuvent se connecter à l\'application de gestion.'), $fr['management.admin']['description'], 'gestion');
        $this->assertSame($n($admins, '%d administrator account can sign in to the management app.', '%d administrator accounts can sign in to the management app.'), $en['management.admin']['description'], 'gestion, en anglais');
        // seuls la gestion, l'assistance et l'IA sont traduits : la boutique et la caisse rendent le même texte français quelle que soit la langue
        foreach (['boutique.product', 'boutique.taxes', 'pos.cashiers', 'pos.register', 'site.identity'] as $key) {
            $this->assertSame($fr[$key]['description'], $en[$key]['description'], "$key : texte de « Mon site », inchangé par la langue");
        }

        // 4. Les deux formes, par la vraie route, dans trois modules : un compte de caisse, un administrateur et un produit avec prix de plus
        //    à chaque tour. Le deuxième tour rend forcément un pluriel ; le premier rend le singulier quand le site n'avait rien.
        $cashiers = fn (): int => $count("SELECT COUNT(*) FROM \"user\" WHERE roles::text LIKE '%ROLE_USER_POS%' OR roles::text LIKE '%ROLE_POS_MANAGER%'");
        $cashierText = fn (int $c): string => $c > 0 ? $n($c, '%d compte de caisse (caissier ou responsable).', '%d comptes de caisse (caissier ou responsable).')
            : 'Créez un compte utilisateur avec le rôle caissier (ou responsable de caisse) : c\'est lui qui ouvre la session dans l\'application.';
        $base = ['cashiers' => $cashiers(), 'admins' => $admins, 'agents' => $agents, 'sellable' => $sellable];
        $this->assertSame($cashierText($base['cashiers']), $fr['pos.cashiers']['description']);
        foreach ([1, 2] as $more) {
            $this->user(['ROLE_USER_POS']);
            $this->user(['ROLE_USER', 'ROLE_ADMIN']);
            $this->product();
            $this->assertSame([$base['cashiers'] + $more, $base['sellable'] + $more], [$cashiers(), $count('SELECT COUNT(*) FROM product p WHERE EXISTS (SELECT 1 FROM product_variant v WHERE v.product_id = p.id AND v.price > 0)')]);
            $now = $this->steps();
            $nowEn = $this->steps('?locale=en');
            $this->assertSame($cashierText($base['cashiers'] + $more), $now['pos.cashiers']['description'], 'caisse');
            $this->assertSame($n($base['sellable'] + $more, '%d produit avec un prix.', '%d produits avec un prix.'), $now['boutique.product']['description'], 'boutique');
            $this->assertSame($n($base['agents'] + $more, '%d compte administrateur ou Assistance répond aux demandes.', '%d comptes administrateur ou Assistance répondent aux demandes.'), $now['support.agent']['description'], 'assistance');
            $this->assertSame($n($base['admins'] + $more, '%d compte administrateur peut se connecter à l\'application de gestion.', '%d comptes administrateur peuvent se connecter à l\'application de gestion.'), $now['management.admin']['description'], 'gestion');
            $this->assertSame($n($base['agents'] + $more, '%d administrator or Support account answers requests.', '%d administrator or Support accounts answer requests.'), $nowEn['support.agent']['description']);
            $this->assertSame($n($base['admins'] + $more, '%d administrator account can sign in to the management app.', '%d administrator accounts can sign in to the management app.'), $nowEn['management.admin']['description']);
            if ($more === 2) { // au moins deux de chaque : le pluriel est rendu, écrit en toutes lettres ici
                $this->assertStringContainsString(' comptes de caisse (caissier ou responsable).', $now['pos.cashiers']['description']);
                $this->assertStringContainsString(' produits avec un prix.', $now['boutique.product']['description']);
                $this->assertStringContainsString(' comptes administrateur ou Assistance répondent aux demandes.', $now['support.agent']['description']);
                $this->assertStringContainsString(' comptes administrateur peuvent se connecter', $now['management.admin']['description']);
                $this->assertStringContainsString(' administrator accounts can sign in', $nowEn['management.admin']['description']);
            } else {
                foreach (['cashiers' => ['pos.cashiers', '1 compte de caisse (caissier ou responsable).'], 'sellable' => ['boutique.product', '1 produit avec un prix.']] as $what => [$key, $singular]) {
                    if ($base[$what] === 0) {
                        $this->assertSame($singular, $now[$key]['description'], 'un seul : singulier');
                    }
                }
            }
        }

        // 5. Fiches traduites : l'autre langue du site est NOMMÉE (« en anglais », « en français »), jamais écrite par son code (« en en »)
        $this->db->executeStatement('UPDATE product SET is_web = TRUE WHERE id IN (?)', [$this->products], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
        $siteLocale = $this->db->fetchOne('SELECT locale FROM entreprise ORDER BY id LIMIT 1');
        try {
            foreach (['fr' => 'anglais', 'en' => 'français'] as $site => $name) {
                $this->db->executeStatement('UPDATE entreprise SET locale = ?', [$site]);
                $description = $this->steps()['boutique.translations']['description'];
                $this->assertMatchesRegularExpression("/^\d+ produits en ligne sans traduction en $name : « IA en lot » les traduit en un clic\.$/u", $description, "site en $site : les deux produits du test, sans traduction");
                $this->assertDoesNotMatchRegularExpression('/ en (en|fr)\b/u', $description);
            }
        } finally {
            $this->db->executeStatement('UPDATE entreprise SET locale = ?', [$siteLocale === false ? null : $siteLocale]);
        }
    }

    /** L'aide commune du cheminement : zéro et un au singulier, les autres valeurs à leur place ; plus aucune terminaison entre parenthèses dans le service. */
    public function testTheSharedHelperAgreesZeroOneAndManyAndTheServiceHoldsNoParenthesisedEnding(): void
    {
        $n = new \ReflectionMethod(SiteReadinessService::class, 'n');
        $n->setAccessible(true);
        $call = fn (int $count, string $one, string $many, int|string ...$more): string => $n->invoke(null, $count, $one, $many, ...$more);
        $this->assertSame(['0 produit avec un prix.', '1 produit avec un prix.', '2 produits avec un prix.'],
            array_map(fn (int $c) => $call($c, '%d produit avec un prix.', '%d produits avec un prix.'), [0, 1, 2]));
        $this->assertSame(['1 article sur 12 sans coût d\'achat : sa marge reste inconnue.', '5 articles sur 12 sans coût d\'achat : leur marge reste inconnue.'],
            array_map(fn (int $c) => $call($c, '%d article sur %d sans coût d\'achat : sa marge reste inconnue.', '%d articles sur %d sans coût d\'achat : leur marge reste inconnue.', 12), [1, 5]));
        $this->assertSame('3 produits en ligne sans traduction en anglais', $call(3, '%d produit en ligne sans traduction en %s', '%d produits en ligne sans traduction en %s', 'anglais'));

        $source = (string) file_get_contents((new \ReflectionClass(SiteReadinessService::class))->getFileName());
        $this->assertDoesNotMatchRegularExpression(self::ENDING, $source, 'SiteReadinessService : chaque texte chiffré passe par n() avec ses deux formes');
        $this->assertGreaterThanOrEqual(19, substr_count($source, 'self::n('), 'les textes chiffrés du cheminement sont accordés');
    }

    /** @param list<string> $roles */
    private function user(array $roles): void
    {
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $email = 'pluriels-' . bin2hex(random_bytes(4)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Compte')->setLastname('Pluriels')->setRoles($roles)->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->users[] = (int) $user->getId();
    }

    /** Un produit avec une variante à prix positif (« vendable » pour l'étape de la boutique), retiré en fin de test */
    private function product(): void
    {
        $slug = 'pluriels-' . bin2hex(random_bytes(5));
        $this->db->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type) VALUES (nextval('product_id_seq'), 'Article Pluriels', 1000, false, 0, NOW(), ?, 'standard')", [$slug]);
        $id = $this->products[] = (int) $this->db->fetchOne('SELECT id FROM product WHERE slug = ?', [$slug]);
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $variant = (new ProductVariant())->setProduct($em->find(Product::class, $id));
        $em->persist($variant);
        $em->flush();
        $this->db->executeStatement('UPDATE product_variant SET price = 1000 WHERE id = ?', [(int) $variant->getId()]);
    }

    /** @return array<string, array<string, mixed>> étapes du cheminement, par clé */
    private function steps(string $query = ''): array
    {
        $container = static::getContainer();
        $container->get(TenantCacheService::class)->invalidateTenant();
        $user = $container->get(TenantEntityManagerProvider::class)->getEntityManager()->getRepository(User::class)->findOneBy(['email' => 'admin@arkanoa-media.com']);
        $this->assertNotNull($user);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/site/readiness' . $query, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $container->get('lexik_jwt_authentication.jwt_manager')->create($user)]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return array_column(json_decode((string) $this->client->getResponse()->getContent(), true)['steps'], null, 'key');
    }

    private function cleanMaster(): void
    {
        $in = implode(', ', array_map(fn (string $f) => $this->master->quote($f), self::FEATURES));
        $this->master->prepare("DELETE FROM tenant_entitlement_events WHERE entitlement_id IN (SELECT id FROM tenant_entitlements WHERE tenant_code = ? AND feature_key IN ($in))")->execute([MV_TEST_TENANT_CODE]);
        $this->master->prepare("DELETE FROM tenant_entitlements WHERE tenant_code = ? AND feature_key IN ($in)")->execute([MV_TEST_TENANT_CODE]);
    }
}
