<?php

namespace App\Tests\Functional\LossPrevention;

use App\LossPrevention\Entity\LossBox;
use App\LossPrevention\Entity\LossPiece;
use Doctrine\DBAL\ArrayParameterType;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exemples du contrat du boîtier (lot L3-a), un fichier JSON par appel (`tests/Fixtures/pertes/boitier/`) : la demande et la
 * réponse. Trois familles : `admin-…` (ce que l'administrateur voit, pour les écrans du web et du mobile), `canal-…` (ce que le
 * boîtier envoie et reçoit : le logiciel du boîtier et son simulateur se règlent dessus) et `caisse-…` (la question de la caisse
 * avant un paiement, lot L3-b1). Ce test rejoue le parcours sur la base de
 * test et vérifie que chaque exemple gardé a toujours la FORME de la réponse du serveur (mêmes clés, mêmes types) : un changement de
 * contrat casse ce test. Les clés et les signatures des jetons sont masquées dans les fichiers.
 *
 * Régénérer après un changement voulu du contrat (les valeurs changent à chaque fois : identifiants, heures) :
 * `docker exec -w /var/www -e UPDATE_LOSS_EXAMPLES=1 -e SYMFONY_DEPRECATIONS_HELPER=disabled symfony_app_v2 php vendor/bin/phpunit tests/Functional/LossPrevention/LossPreventionBoxExamplesTest.php`
 */
class LossPreventionBoxExamplesTest extends LossPreventionTestCase
{
    private const DIR = __DIR__ . '/../../Fixtures/pertes/boitier';
    private const MACHINE = 'machine-d-exemple-0123456789abcdef';
    /** @var array<string, array<string, mixed>> */
    private array $examples = [];
    private string $s;
    private string $ip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = strtoupper(bin2hex(random_bytes(3)));
        $this->ip = '10.' . random_int(1, 250) . '.' . random_int(1, 250) . '.' . random_int(1, 250);
        $this->flags(true, true);
    }

    protected function tearDown(): void
    {
        try {
            $this->db->executeStatement("UPDATE loss_box SET status = 'revoked', revoked_at = NOW() WHERE NOT exercise AND status <> 'revoked'");
            $this->db->executeStatement("UPDATE loss_device SET status = 'retired', cash_register_id = NULL WHERE NOT exercise AND status <> 'retired'");
            $this->db->executeStatement('UPDATE loss_setting SET silent_after_seconds = NULL');
            $this->db->beginTransaction();
            $this->provider->getEntityManager()->getRepository(LossPiece::class)->deleteExercise();
            $this->provider->getEntityManager()->getRepository(LossBox::class)->deleteExercise();
            $this->db->commit();
            $ids = $this->db->fetchFirstColumn('SELECT id FROM product WHERE name LIKE ?', ['% (exemple boîtier ' . $this->s . ')']);
            if ($ids !== []) {
                $this->db->executeStatement('DELETE FROM product_variant WHERE product_id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
                $this->db->executeStatement('DELETE FROM product WHERE id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testEveryStoredExampleStillHasTheShapeOfTheServerAnswer(): void
    {
        $slug = 'l3a-ex-' . bin2hex(random_bytes(4));
        $this->db->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, is_web, is_pos) VALUES (nextval('product_id_seq'), :n, 4500, false, 0, NOW(), :s, 'standard', false, true)",
            ['n' => "Veste en lin (exemple boîtier $this->s)", 's' => $slug]);
        $variant = (int) $this->db->fetchOne("INSERT INTO product_variant (id, product_id, stock_quantity, barcode) VALUES (nextval('product_variant_id_seq'), (SELECT id FROM product WHERE slug = ?), 5, ?) RETURNING id", [$slug, "VESTE-$this->s"]);
        $nfc = '04' . $this->s . 'C3D4E5';
        $this->json($this->admin('POST', self::BASE . '/puces/associer', ['variantId' => $variant, 'identifier' => $nfc, 'technology' => 'nfc']));

        // ----- Ce que l'administrateur voit
        $this->admin_('admin-sante-sans-boitier', 'GET', '/sante');
        $this->admin_('admin-boitier-cree', 'POST', '/boitiers', ['name' => 'Boîtier du comptoir', 'place' => 'Près de la caisse'], 201);
        $this->admin_('admin-sante-boitier-en-attente', 'GET', '/sante');
        $id = $this->examples['admin-boitier-cree']['body']['box']['id'];
        $key = $this->examples['admin-boitier-cree']['body']['key'];
        $this->admin_('admin-refus-validation', 'POST', '/boitiers', ['place' => 12], 422);
        $this->admin_('admin-boitier-renomme', 'PUT', "/boitiers/$id", ['name' => 'Boîtier de la caisse 1']);

        // ----- Ce que le boîtier envoie et reçoit
        $enrol = ['key' => $key, 'machine' => self::MACHINE, 'version' => '1.0.0', 'arch' => 'amd64'];
        $this->channel('canal-inscription', 'POST', '/inscription', $enrol, null);
        $token = $this->examples['canal-inscription']['body']['token'];
        $this->channel('canal-refus-cle-inconnue', 'POST', '/inscription', ['key' => 'IZ-BOX-AAAA-AAAA-AAAA'] + $enrol, null, 404);
        $this->channel('canal-refus-autre-machine', 'POST', '/inscription', ['machine' => 'une-autre-machine-0123456789'] + $enrol, null, 409);
        $this->channel('canal-refus-sans-jeton', 'GET', '/reglages', null, null, 401);
        $devices = [['serial' => 'LECTEUR-0001', 'kind' => 'reader', 'status' => 'ok', 'model' => 'Lecteur de bureau (USB)'], ['serial' => 'IMPR-0001', 'kind' => 'printer', 'status' => 'down'],
            ['serial' => 'TAPIS-0001', 'kind' => 'counter_mat', 'status' => 'ok', 'model' => 'Tapis de comptoir']];
        $this->channel('canal-signe-de-vie', 'POST', '/signe-de-vie', ['now' => gmdate('Y-m-d\TH:i:s\Z'), 'machine' => self::MACHINE, 'version' => '1.0.0', 'devices' => $devices], $token);
        $this->channel('canal-signe-de-vie-appareil-refuse', 'POST', '/signe-de-vie', ['now' => gmdate('Y-m-d\TH:i:s\Z'), 'machine' => self::MACHINE, 'devices' => [['serial' => 'LECTEUR-0001', 'kind' => 'camera', 'status' => 'ok'], $devices[1]]], $token);
        $this->channel('canal-refus-signe-de-vie-invalide', 'POST', '/signe-de-vie', ['now' => 'hier', 'machine' => self::MACHINE, 'devices' => [['serial' => 'LECTEUR-0001', 'kind' => 'antenne', 'status' => 'ok']]], $token, 422);
        $this->channel('canal-reglages', 'GET', '/reglages', null, $token);
        $at = gmdate('Y-m-d\TH:i:s\Z', time() - 30);
        $facts = [
            ['key' => "exemple-$this->s-0001", 'type' => 'SPOT_CHECK', 'at' => $at, 'device' => 'LECTEUR-0001', 'seq' => 1, 'data' => ['identifier' => $nfc, 'technology' => 'nfc']],
            ['key' => "exemple-$this->s-0002", 'type' => 'ZONE_SEEN', 'at' => $at, 'device' => 'LECTEUR-0001', 'zone' => 'exit', 'seq' => 2, 'data' => ['identifier' => $nfc]],
            ['key' => "exemple-$this->s-0003", 'type' => 'ZONE_SEEN', 'at' => $at, 'device' => 'LECTEUR-0001', 'zone' => 'exit', 'data' => ['identifier' => 'AABBCCDD', 'technology' => 'nfc']],
            ['key' => "exemple-$this->s-0004", 'type' => 'SPOT_CHECK', 'at' => $at, 'device' => 'IMPR-0001', 'data' => ['identifier' => $nfc]],
        ];
        $this->channel('canal-faits', 'POST', '/faits', ['facts' => $facts], $token);
        $this->channel('canal-faits-rejoues', 'POST', '/faits', ['facts' => $facts], $token);
        // ----- Lot L3-b1 : le tapis de comptoir, relié à un poste, et la question de la caisse avant un paiement
        $this->channel('canal-signe-de-vie-avec-tapis', 'POST', '/signe-de-vie', ['now' => gmdate('Y-m-d\TH:i:s\Z'), 'machine' => self::MACHINE, 'devices' => $devices], $token);
        $mat = array_column($this->examples['canal-reglages']['body']['devices'], 'id', 'serial')['TAPIS-0001'];
        $this->admin_('admin-sante-tapis-en-attente', 'GET', '/sante');
        $this->admin_('admin-tapis-relie-a-un-poste', 'PUT', "/boitiers/$id/appareils/$mat", ['cashRegisterId' => $this->register]);
        $this->admin_('admin-refus-appareil-validation', 'PUT', "/boitiers/$id/appareils/$mat", ['zone' => 'counter'], 422);
        $this->loginPos(['ROLE_USER_POS']);
        $this->openSession();
        $basket = ['cashRegisterId' => $this->register, 'items' => [['productVariantId' => $variant, 'quantity' => 2]]];
        $this->till('caisse-articles-non-lus', $basket);
        $this->channel('canal-faits-tapis', 'POST', '/faits', ['facts' => [['key' => "exemple-$this->s-0010", 'type' => 'COUNTER_SEEN', 'at' => gmdate('Y-m-d\TH:i:s\Z'), 'device' => 'TAPIS-0001', 'data' => ['identifier' => $nfc]],
            ['key' => "exemple-$this->s-0011", 'type' => 'COUNTER_SEEN', 'at' => gmdate('Y-m-d\TH:i:s\Z'), 'device' => 'LECTEUR-0001', 'data' => ['identifier' => $nfc]]]], $token);
        $this->till('caisse-un-article-lu-un-manquant', $basket);
        $this->till('caisse-refus-panier-invalide', ['items' => [['productVariantId' => $variant, 'quantity' => 0]]], 422);
        $this->till('caisse-refus-poste-inconnu', ['cashRegisterId' => 999999999, 'items' => []], 404);
        $this->admin_('admin-sante-tapis-en-ordre', 'GET', '/sante');
        $this->admin_('admin-boitier-avec-tapis', 'GET', "/boitiers/$id");
        $this->admin_('admin-installation-tapis-fait', 'GET', '/installation');
        $this->admin_('admin-tapis-retire', 'POST', "/boitiers/$id/appareils/$mat/retirer", ['confirm' => true]);
        $this->till('caisse-pas-de-controle', $basket);
        $this->channel('canal-refus-lot-vide', 'POST', '/faits', ['facts' => []], $token, 422);
        $this->channel('canal-refus-lot-trop-grand', 'POST', '/faits', ['facts' => array_fill(0, 201, $facts[0])], $token, 422);
        $this->channel('canal-jeton-echange', 'POST', '/jeton', ['machine' => self::MACHINE], $token);
        $this->channel('canal-refus-jeton-invalide', 'POST', '/jeton', ['machine' => 'une-autre-machine-0123456789'], $token, 401);

        // ----- L'administrateur, après : la liste, la fiche, la clé remise à zéro, la révocation
        $this->admin_('admin-boitiers', 'GET', '/boitiers');
        $this->admin_('admin-sante-en-ordre', 'GET', '/sante');
        $this->db->executeStatement("UPDATE loss_box SET last_seen_at = NOW() AT TIME ZONE 'UTC' - INTERVAL '12 minutes' WHERE id = ?", [$id]);
        $this->admin_('admin-sante-boitier-silencieux', 'GET', '/sante');
        $this->admin_('admin-installation-boitier-silencieux', 'GET', '/installation');
        $this->admin_('admin-sante-seuil-regle', 'PUT', '/sante/seuil', ['silentAfterSeconds' => 900]);
        $this->admin_('admin-sante-refus-seuil', 'PUT', '/sante/seuil', ['silentAfterSeconds' => 30], 422);
        $this->admin_('admin-sante-seuil-par-defaut', 'PUT', '/sante/seuil', ['silentAfterSeconds' => null]);
        $this->admin_('admin-boitier', 'GET', "/boitiers/$id");
        $this->admin_('admin-cle-remise-a-zero', 'POST', "/boitiers/$id/cle", ['confirm' => true]);
        $this->channel('canal-refus-jeton-perime', 'GET', '/reglages', null, $token, 401);
        $this->admin_('admin-refus-confirmation', 'POST', "/boitiers/$id/revoquer", [], 422);
        $this->admin_('admin-boitier-revoque', 'POST', "/boitiers/$id/revoquer", ['confirm' => true]);
        $this->channel('canal-refus-boitier-revoque', 'POST', '/inscription', ['key' => $this->examples['admin-cle-remise-a-zero']['body']['key']] + $enrol, null, 403);
        $this->admin_('admin-refus-boitier-revoque', 'POST', "/boitiers/$id/cle", ['confirm' => true], 409);
        $this->admin_('admin-refus-introuvable', 'GET', '/boitiers/999999999', null, 404);
        $this->admin_('admin-installation', 'GET', '/installation');

        // ----- Mode exercice : le boîtier simulé
        $this->admin_('admin-exercices-boitiers-au-depart', 'GET', '/exercices/boitiers');
        $this->admin_('admin-exercices-boitier-cree', 'POST', '/exercices/boitiers', null, 201);
        $simulated = $this->examples['admin-exercices-boitier-cree']['body']['box']['id'];
        $this->admin_('admin-exercices-refus-sans-piece', 'POST', "/exercices/boitiers/$simulated/scenarios", ['scenario' => 'spot_check'], 409);
        $this->json($this->admin('POST', self::BASE . '/exercices/puces/associer', ['variantId' => $variant]));
        $this->admin_('admin-exercices-scenario-lecture', 'POST', "/exercices/boitiers/$simulated/scenarios", ['scenario' => 'spot_check']);
        $this->admin_('admin-exercices-scenario-coupure', 'POST', "/exercices/boitiers/$simulated/scenarios", ['scenario' => 'network_cut']);
        $this->admin_('admin-exercices-refus-scenario', 'POST', "/exercices/boitiers/$simulated/scenarios", ['scenario' => 'incendie'], 422);
        $this->admin_('admin-exercices-boitier', 'GET', "/exercices/boitiers/$simulated");
        $this->admin_('admin-exercices-installation', 'GET', '/exercices/installation');
        $this->admin_('admin-exercices-sante', 'GET', '/exercices/sante');
        $this->admin_('admin-exercices-boitiers-effaces', 'DELETE', '/exercices/boitiers');

        if (getenv('UPDATE_LOSS_EXAMPLES') === '1') {
            if (!is_dir(self::DIR)) {
                mkdir(self::DIR, 0775, true);
            }
            foreach ($this->examples as $name => $example) {
                file_put_contents(self::DIR . "/$name.json", self::masked(json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n");
            }
            $this->addToAssertionCount(1);

            return;
        }
        foreach ($this->examples as $name => $example) {
            $file = self::DIR . "/$name.json";
            $this->assertFileExists($file, "exemple manquant : régénérer (voir l'en-tête de ce test)");
            $content = (string) file_get_contents($file);
            $this->assertSame($content, self::masked($content), "$name : une clé ou une signature de jeton en clair dans un exemple");
            $stored = json_decode($content, true);
            $this->assertSame([$example['request']['method'], $example['status']], [$stored['request']['method'] ?? null, $stored['status'] ?? null], $name);
            $this->assertSame([], self::shapeDifferences($stored['body'], $example['body'], 'body'), "$name : la forme de l'exemple n'est plus celle du serveur");
        }
        $extra = array_diff(array_map(fn (string $f) => basename($f, '.json'), glob(self::DIR . '/*.json') ?: []), array_keys($this->examples));
        $this->assertSame([], array_values($extra), 'exemples gardés que plus aucun appel ne produit');
    }

    /** Un appel de l'administrateur, en français, gardé comme exemple */
    private function admin_(string $name, string $method, string $path, ?array $body = null, int $status = 200): void
    {
        $path = self::BASE . $path . '?locale=fr';
        $this->remember($name, $method, $path, [], $body, $status, $this->call($method, $path, $body, $this->adminToken));
    }

    /** Un appel de la caisse (un caissier connecté), gardé comme exemple */
    private function till(string $name, array $body, int $status = 200): void
    {
        $path = '/api/pos/pertes/articles-non-lus';
        $this->remember($name, 'POST', $path, [], $body, $status, $this->pos('POST', $path, $body));
    }

    /** Un appel du boîtier (en-tête X-Loss-Box), gardé comme exemple */
    private function channel(string $name, string $method, string $path, ?array $body, ?string $token, int $status = 200): void
    {
        $path = '/api/pertes/boitier' . $path;
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_LOSS_BOX' => $token, 'REMOTE_ADDR' => $this->ip,
        ]), $body !== null ? json_encode($body) : null);
        if (isset($body['facts']) && count($body['facts']) > 4) {
            $body = ['facts' => '(201 faits)'];
        }
        $this->remember($name, $method, $path, $token !== null ? ['X-Loss-Box' => $token] : [], $body, $status, $this->client->getResponse());
    }

    /** @param array<string, string> $headers */
    private function remember(string $name, string $method, string $path, array $headers, ?array $body, int $status, Response $response): void
    {
        $this->assertSame($status, $response->getStatusCode(), $name . ' : ' . $response->getContent());
        $this->examples[$name] = ['request' => ['method' => $method, 'path' => $path] + ($headers !== [] ? ['headers' => $headers] : []) + ($body !== null ? ['body' => $body] : []), 'status' => $status,
            'body' => json_decode((string) $response->getContent(), true)];
    }

    /** Les clés de boîtier et les signatures de jeton d'un exemple, masquées : un fichier du dépôt n'en porte pas */
    private static function masked(string $json): string
    {
        $json = (string) preg_replace('/IZ-BOX-(?!AAAA-AAAA-AAAA)[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/', 'IZ-BOX-XXXX-XXXX-XXXX', $json);
        $json = (string) preg_replace('/IZ-BOX-(?!XX)[A-Z0-9]{2}\*\*-\*{4}-\*{4}/', 'IZ-BOX-XX**-****-****', $json);

        return (string) preg_replace('/(b\d+\.\d+\.\d{10}\.)(?!0{64})[0-9a-f]{64}/', '${1}' . str_repeat('0', 64), $json);
    }

    /**
     * Différences de forme entre un exemple gardé et la réponse du jour : mêmes clés, mêmes types. Une valeur nulle d'un côté
     * s'accorde avec tout (champ facultatif) ; une liste se compare par son premier élément ; un entier et un décimal sont le même type.
     *
     * @return list<string>
     */
    private static function shapeDifferences(mixed $stored, mixed $live, string $path): array
    {
        if ($stored === null || $live === null) {
            return [];
        }
        if (is_array($stored) && is_array($live)) {
            if ($stored === [] || $live === []) {
                return [];
            }
            if (array_is_list($stored) !== array_is_list($live)) {
                return ["$path : liste d'un côté, objet de l'autre"];
            }
            if (array_is_list($stored)) {
                return self::shapeDifferences($stored[0], $live[0], $path . '[0]');
            }
            $out = [];
            foreach (array_diff(array_keys($stored), array_keys($live)) as $key) {
                $out[] = "$path.$key : absent de la réponse du serveur";
            }
            foreach (array_diff(array_keys($live), array_keys($stored)) as $key) {
                $out[] = "$path.$key : absent de l'exemple";
            }
            foreach (array_intersect(array_keys($stored), array_keys($live)) as $key) {
                $out = array_merge($out, self::shapeDifferences($stored[$key], $live[$key], "$path.$key"));
            }

            return $out;
        }
        $type = fn (mixed $v) => is_int($v) || is_float($v) ? 'number' : gettype($v);

        return $type($stored) === $type($live) ? [] : ["$path : " . $type($stored) . ' dans l\'exemple, ' . $type($live) . ' dans la réponse'];
    }
}
