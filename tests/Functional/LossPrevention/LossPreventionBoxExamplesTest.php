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
    use LossTagKeysTrait;

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
        static::getContainer()->get(\App\Services\TenantConnectionManager::class)->getPdoMaster()->exec('DELETE FROM loss_box_pairing');
        self::resetPairingSiteLimit();
    }

    protected function tearDown(): void
    {
        try {
            $this->tagKeys(null);
            static::getContainer()->get(\App\Services\TenantConnectionManager::class)->getPdoMaster()->exec('DELETE FROM loss_box_pairing');
            $this->db->executeStatement("UPDATE loss_print_job_line SET status = 'cancelled', done_at = NOW() WHERE status = 'waiting'");
            $this->db->executeStatement("UPDATE loss_print_job SET status = 'cancelled', cancelled_at = NOW() WHERE status IN ('waiting', 'running')");
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
        // dans chaque signe de vie, le boîtier renvoie l'heure du serveur de la dernière réponse qu'il a lue (inscription ou signe de vie)
        $this->channel('canal-signe-de-vie', 'POST', '/signe-de-vie', ['now' => gmdate('Y-m-d\TH:i:s\Z'), 'machine' => self::MACHINE, 'version' => '1.0.0',
            'lastServerTime' => $this->examples['canal-inscription']['body']['serverTime'], 'devices' => $devices], $token);
        $this->channel('canal-signe-de-vie-appareil-refuse', 'POST', '/signe-de-vie', ['now' => gmdate('Y-m-d\TH:i:s\Z'), 'machine' => self::MACHINE,
            'lastServerTime' => $this->examples['canal-signe-de-vie']['body']['serverTime'], 'devices' => [['serial' => 'LECTEUR-0001', 'kind' => 'camera', 'status' => 'ok'], $devices[1]]], $token);
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
        // ----- Lot L5-d : les sorties permises. La pièce de l'exemple passe « vendue » (comme l'analyse le ferait), le boîtier lit la liste
        // complète, puis les deltas ; la pièce revient à son état d'avant
        $piece = (int) $this->db->fetchOne('SELECT piece_id FROM loss_tag WHERE identifier = ? AND NOT exercise', [$nfc]);
        $before = (string) $this->db->fetchOne('SELECT status FROM loss_piece WHERE id = ?', [$piece]);
        $this->db->executeStatement("UPDATE loss_piece SET status = 'sold', sold_changed_at = clock_timestamp() WHERE id = ?", [$piece]);
        $this->channel('canal-sorties-permises', 'GET', '/sorties-permises', null, $token);
        $this->channel('canal-sorties-permises-deltas', 'GET', '/sorties-permises?since=' . rawurlencode($this->examples['canal-sorties-permises']['body']['serverTime']), null, $token);
        $this->channel('canal-refus-sorties-permises-depuis', 'GET', '/sorties-permises?since=hier', null, $token, 422);
        $this->db->executeStatement('UPDATE loss_piece SET status = ?, sold_changed_at = clock_timestamp() WHERE id = ?', [$before, $piece]);
        // ----- Lot L3-b1 : le tapis de comptoir, relié à un poste, et la question de la caisse avant un paiement
        $this->channel('canal-signe-de-vie-avec-tapis', 'POST', '/signe-de-vie', ['now' => gmdate('Y-m-d\TH:i:s\Z'), 'machine' => self::MACHINE,
            'lastServerTime' => $this->examples['canal-signe-de-vie-appareil-refuse']['body']['serverTime'], 'devices' => $devices], $token);
        $mat = array_column($this->examples['canal-reglages']['body']['devices'], 'id', 'serial')['TAPIS-0001'];
        $this->admin_('admin-sante-tapis-en-attente', 'GET', '/sante');
        $this->admin_('admin-tapis-relie-a-un-poste', 'PUT', "/boitiers/$id/appareils/$mat", ['cashRegisterId' => $this->register]);
        $this->admin_('admin-refus-appareil-validation', 'PUT', "/boitiers/$id/appareils/$mat", ['zone' => 'counter'], 422);
        $this->admin_('admin-postes', 'GET', '/postes'); // lot L3-b2a : les postes de caisse, leur tapis, leur tablette
        $this->loginPos(['ROLE_USER_POS']);
        $this->openSession();
        $basket = ['cashRegisterId' => $this->register, 'items' => [['productVariantId' => $variant, 'quantity' => 2]]];
        $this->till('caisse-articles-non-lus', $basket);
        $this->channel('canal-faits-tapis', 'POST', '/faits', ['facts' => [['key' => "exemple-$this->s-0010", 'type' => 'COUNTER_SEEN', 'at' => gmdate('Y-m-d\TH:i:s\Z'), 'device' => 'TAPIS-0001', 'data' => ['identifier' => $nfc]],
            ['key' => "exemple-$this->s-0011", 'type' => 'COUNTER_SEEN', 'at' => gmdate('Y-m-d\TH:i:s\Z'), 'device' => 'LECTEUR-0001', 'data' => ['identifier' => $nfc]]]], $token);
        $this->till('caisse-un-article-lu-un-manquant', $basket);
        // lot L5-a : le gérant allume l'alerte en caisse ; la caisse la montre, puis rend la vérification au paiement
        $this->json($this->admin('PUT', self::BASE . '/reglages', ['cashierAlerts' => ['enabled' => true]]));
        $this->till('caisse-alerte-montree', $basket);
        $this->remember('caisse-suite-alerte', 'POST', '/api/pos/pertes/articles-non-lus/suite', [], $suite = ['checkId' => $this->examples['caisse-alerte-montree']['body']['checkId'], 'proceeded' => true], 204,
            $this->pos('POST', '/api/pos/pertes/articles-non-lus/suite', $suite));
        $this->remember('caisse-refus-suite-invalide', 'POST', '/api/pos/pertes/articles-non-lus/suite', [], $wrong = ['checkId' => 'XYZ', 'proceeded' => 'oui'], 422,
            $this->pos('POST', '/api/pos/pertes/articles-non-lus/suite', $wrong));
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
        // « en ordre » pour de bon : l'imprimante annoncée en panne plus haut est retirée (une imprimante qui ne répond pas donnerait
        // « 1 appareil à vérifier » ; l'exemple d'une imprimante au tableau de santé est `admin-sante-imprimante`)
        $brokenPrinter = array_column($this->examples['canal-signe-de-vie']['body']['settings']['devices'], 'id', 'serial')['IMPR-0001'];
        $this->json($this->admin('POST', self::BASE . "/boitiers/$id/appareils/$brokenPrinter/retirer", ['confirm' => true]));
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

        // ----- Lot L3-a2 : le jumelage par code. Le boîtier, SANS site ni jeton, à l'adresse de la plateforme ; puis l'administrateur
        $code = 'KX7-42P-9QM';
        $deposit = ['code' => $code, 'machine' => 'machine-jumelee-d-exemple-0123456789', 'version' => '1.0.0', 'arch' => 'arm64'];
        $proof = ['code' => $code, 'machine' => $deposit['machine']];
        $this->pairing('canal-jumelage-depose', '', $deposit);
        $this->pairing('canal-jumelage-en-attente', '/etat', $proof);
        $this->pairing('canal-refus-jumelage-inconnu', '/etat', ['code' => 'ABC-DEF-GHJ'] + $proof, 404);
        $this->pairing('canal-refus-jumelage-invalide', '', ['code' => 'pas un code', 'machine' => $deposit['machine']], 422);
        $this->pairer('admin-refus-jumelage', ['code' => 'ABC-DEF-GHJ', 'name' => 'Boîtier de la réserve'], 404);
        $this->pairer('admin-boitier-jumele', ['code' => 'kx7 42p 9qm', 'name' => 'Boîtier de la réserve', 'place' => 'Réserve'], 201);
        $this->pairing('canal-jumelage-jumele', '/etat', $proof);
        $this->admin_('admin-boitier-jumele-inscrit', 'GET', '/boitiers/' . $this->examples['admin-boitier-jumele']['body']['box']['id']);
        $this->json($this->admin('POST', self::BASE . '/boitiers/' . $this->examples['admin-boitier-jumele']['body']['box']['id'] . '/revoquer', ['confirm' => true]));
        $this->pairing('canal-jumelage-ferme', '/etat', $proof);

        // ----- Lot L3-c : l'imprimante à puce. Un boîtier et son imprimante, un travail, ce que le boîtier vient chercher, ses résultats
        $this->tagKeys(self::tagKey(1)); // la clé d'essai commune aux classes de la suite, jamais une vraie
        $printBox = $this->json($this->admin('POST', self::BASE . '/boitiers', ['name' => 'Boîtier de l\'arrière-boutique']), 201);
        $printMachine = 'machine-de-l-imprimante-0123456789';
        $this->channel('tmp', 'POST', '/inscription', ['key' => $printBox['key'], 'machine' => $printMachine, 'version' => '1.0.0', 'arch' => 'amd64'], null);
        $printToken = $this->examples['tmp']['body']['token'];
        $this->channel('tmp', 'POST', '/signe-de-vie', ['now' => gmdate('Y-m-d\TH:i:s\Z'), 'machine' => $printMachine, 'devices' => [['serial' => 'IMPR-EXEMPLE', 'kind' => 'printer', 'status' => 'ok', 'model' => 'ZD621R']],
            'lastServerTime' => $this->examples['tmp']['body']['serverTime']], $printToken);
        $printer = array_column($this->examples['tmp']['body']['settings']['devices'], 'id', 'serial')['IMPR-EXEMPLE'];
        unset($this->examples['tmp']);
        $toPrint = array_column($this->json($this->admin('POST', self::BASE . '/pieces', ['variantId' => $variant, 'count' => 3]), 201)['pieces'], 'id');
        $this->admin_('admin-impressions-au-depart', 'GET', '/impressions');
        $this->admin_('admin-impression-creee', 'POST', '/impressions', ['deviceId' => $printer, 'pieceIds' => [$toPrint[0], $toPrint[1]], 'withPrice' => true], 201);
        $print = $this->examples['admin-impression-creee']['body'];
        $this->admin_('admin-refus-impression-deja-en-attente', 'POST', '/impressions', ['deviceId' => $printer, 'pieceIds' => [$toPrint[1], $toPrint[2]]], 409);
        $this->channel('canal-travaux', 'GET', '/travaux', null, $printToken);
        $this->channel('canal-faits-impression', 'POST', '/faits', ['facts' => [
            ['key' => "impr-$this->s-0001", 'type' => 'TAG_WRITTEN', 'at' => gmdate('Y-m-d\TH:i:s\Z'), 'device' => 'IMPR-EXEMPLE', 'data' => ['line' => $print['lineItems'][0]['id'], 'identifier' => $print['lineItems'][0]['identifier']]],
            ['key' => "impr-$this->s-0002", 'type' => 'TAG_WRITE_FAILED', 'at' => gmdate('Y-m-d\TH:i:s\Z'), 'device' => 'IMPR-EXEMPLE', 'data' => ['line' => $print['lineItems'][1]['id'], 'reason' => 'write_refused']],
        ]], $printToken);
        $this->admin_('admin-impression', 'GET', '/impressions/' . $print['id']);
        $this->admin_('admin-impressions', 'GET', '/impressions');
        $this->admin_('admin-impression-a-annuler', 'POST', '/impressions', ['deviceId' => $printer, 'pieceIds' => [$toPrint[2]]], 201);
        $this->admin_('admin-impression-annulee', 'POST', '/impressions/' . $this->examples['admin-impression-a-annuler']['body']['id'] . '/annuler');
        $this->admin_('admin-sante-imprimante', 'GET', '/sante');
        // la clé de chiffrement n'est pas posée : aucun vrai travail ne se crée, l'écran le dit
        $this->tagKeys(null);
        $this->admin_('admin-refus-impression-cle-absente', 'POST', '/impressions', ['deviceId' => $printer, 'pieceIds' => [$toPrint[2]]], 409);
        $this->admin_('admin-impressions-cle-absente', 'GET', '/impressions');

        // ----- Mode exercice : le boîtier simulé
        $this->admin_('admin-exercices-boitiers-au-depart', 'GET', '/exercices/boitiers');
        $this->admin_('admin-exercices-boitier-cree', 'POST', '/exercices/boitiers', null, 201);
        $simulated = $this->examples['admin-exercices-boitier-cree']['body']['box']['id'];
        $this->admin_('admin-exercices-refus-sans-piece', 'POST', "/exercices/boitiers/$simulated/scenarios", ['scenario' => 'spot_check'], 409);
        $this->json($this->admin('POST', self::BASE . '/exercices/puces/associer', ['variantId' => $variant]));
        $this->admin_('admin-exercices-scenario-lecture', 'POST', "/exercices/boitiers/$simulated/scenarios", ['scenario' => 'spot_check']);
        $this->admin_('admin-exercices-scenario-coupure', 'POST', "/exercices/boitiers/$simulated/scenarios", ['scenario' => 'network_cut']);
        $this->admin_('admin-exercices-refus-scenario', 'POST', "/exercices/boitiers/$simulated/scenarios", ['scenario' => 'incendie'], 422);
        // l'imprimante à puce simulée (lot L3-c) : des pièces d'exercice sans puce, puis le scénario
        $this->json($this->admin('POST', self::BASE . '/exercices/pieces', ['variantId' => $variant, 'count' => 3]), 201);
        $this->admin_('admin-exercices-scenario-impression', 'POST', "/exercices/boitiers/$simulated/scenarios", ['scenario' => 'print_refused']);
        $this->admin_('admin-exercices-impression', 'GET', '/exercices/impressions/' . $this->examples['admin-exercices-scenario-impression']['body']['printJobId']);
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

    /** Un appel du boîtier qui attend son jumelage (lot L3-a2) : à la plateforme, sans site ni jeton ; gardé comme exemple */
    private function pairing(string $name, string $path, array $body, int $status = 200): void
    {
        $path = '/api/pertes/boitier/jumelage' . $path;
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://plateforme.example.invalid' . $path, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => $this->ip], json_encode($body));
        $this->remember($name, 'POST', $path, [], $body, $status, $this->client->getResponse());
    }

    /** « Jumeler un boîtier » par l'administrateur, depuis l'adresse du test (ses essais sont plafonnés par adresse) ; gardé comme exemple */
    private function pairer(string $name, array $body, int $status): void
    {
        $path = self::BASE . '/boitiers/jumeler?locale=fr';
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->adminToken, 'REMOTE_ADDR' => $this->ip], json_encode($body));
        $this->remember($name, 'POST', $path, [], $body, $status, $this->client->getResponse());
    }

    /** @param array<string, string> $headers */
    private function remember(string $name, string $method, string $path, array $headers, ?array $body, int $status, Response $response): void
    {
        $this->assertSame($status, $response->getStatusCode(), $name . ' : ' . $response->getContent());
        $this->examples[$name] = ['request' => ['method' => $method, 'path' => $path] + ($headers !== [] ? ['headers' => $headers] : []) + ($body !== null ? ['body' => $body] : []), 'status' => $status,
            'body' => json_decode((string) $response->getContent(), true)];
    }

    /** Les clés de boîtier et les signatures de jeton d'un exemple, masquées : un fichier du dépôt n'en porte pas ; les durées de silence, arrondies à la minute */
    private static function masked(string $json): string
    {
        $json = (string) preg_replace('/IZ-BOX-(?!AAAA-AAAA-AAAA)[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/', 'IZ-BOX-XXXX-XXXX-XXXX', $json);
        $json = (string) preg_replace('/IZ-BOX-(?!XX)[A-Z0-9]{2}\*\*-\*{4}-\*{4}/', 'IZ-BOX-XX**-****-****', $json);
        // le mot de passe d'accès des puces (lot L3-c), que seul le boîtier reçoit : jamais dans un fichier du dépôt
        $json = (string) preg_replace('/("password": ?")[0-9A-F]{8}"/', '${1}XXXXXXXX"', $json);
        // une durée de silence dépend de la seconde où l'exemple est produit (719 ou 720 s) : dans le fichier, elle est arrondie à la
        // minute, pour que deux générations donnent le même exemple
        // la vérification d'une alerte en caisse (lot L5-a) est tirée au hasard à chaque question : dans le fichier, des zéros
        $json = (string) preg_replace('/("checkId": ?")[0-9a-f]{32}"/', '${1}' . str_repeat('0', 32) . '"', $json);
        $json = (string) preg_replace_callback('/("silentSeconds": )(\d+)/', static fn (array $m): string => $m[1] . (intdiv((int) $m[2] + 30, 60) * 60), $json);

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
