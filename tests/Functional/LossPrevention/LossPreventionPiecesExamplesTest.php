<?php

namespace App\Tests\Functional\LossPrevention;

use App\LossPrevention\Entity\LossPiece;
use Doctrine\DBAL\ArrayParameterType;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exemples de réponse des pièces et des puces (lots L2-a et L2-b), un fichier JSON par route et par refus
 * (`tests/Fixtures/pertes/pieces/`) : ils servent de jeux d'essai aux écrans du web et du mobile. Ce test rejoue le parcours sur la
 * base de test et vérifie que chaque exemple gardé a toujours la FORME de la réponse du serveur (mêmes clés, mêmes types) : un
 * exemple ne peut pas dériver du code sans que ce test échoue. Les PDF ne sont pas des exemples (réponses binaires).
 *
 * Régénérer après un changement voulu du contrat (les valeurs changent à chaque fois : identifiants, numéros, heures) :
 * `docker exec -w /var/www -e UPDATE_LOSS_EXAMPLES=1 -e SYMFONY_DEPRECATIONS_HELPER=disabled symfony_app_v2 php vendor/bin/phpunit tests/Functional/LossPrevention/LossPreventionPiecesExamplesTest.php`
 */
class LossPreventionPiecesExamplesTest extends LossPreventionTestCase
{
    private const DIR = __DIR__ . '/../../Fixtures/pertes/pieces';
    /** @var array<string, array<string, mixed>> */
    private array $examples = [];
    private string $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = strtoupper(bin2hex(random_bytes(3)));
        $this->flags(true, true);
    }

    protected function tearDown(): void
    {
        try {
            $this->db->beginTransaction();
            $this->provider->getEntityManager()->getRepository(LossPiece::class)->deleteExercise();
            $this->db->commit();
            $ids = $this->db->fetchFirstColumn('SELECT id FROM product WHERE name LIKE ?', ['% (exemple ' . $this->s . ')']);
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
        [$jacket, [$v1, $v2]] = $this->article('Veste en lin', ["VESTE-LIN-M-$this->s", "VESTE-LIN-L-$this->s"]);
        $this->db->executeStatement('UPDATE product SET barcode = ? WHERE id = ?', ["VESTE-LIN-$this->s", $jacket]);
        [, [$vBag]] = $this->article('Sac de plage', ['ABCDEF03' . $this->s . 'AA']); // un code-barres qui a la forme d'une puce
        $nfc = '04' . $this->s . 'C3D4E5';
        $uhf = 'E280' . $this->s . '00000000' . $this->s;

        // ----- L2-a : pièces, puces, association, recherche, retraits, synthèse
        $this->keep('pieces-creees', 'POST', '/pieces', ['variantId' => $v1, 'count' => 2], 201);
        [$a, $b] = array_column($this->examples['pieces-creees']['body']['pieces'], 'id');
        $this->keep('association-sur-une-piece', 'POST', '/puces/associer', ['pieceId' => $a, 'identifier' => implode(':', str_split(strtolower($nfc), 2)), 'technology' => 'nfc']);
        $this->keep('association-rejouee', 'POST', '/puces/associer', ['pieceId' => $a, 'identifier' => $nfc, 'technology' => 'nfc']);
        $this->keep('association-piece-creee', 'POST', '/puces/associer', ['barcode' => "VESTE-LIN-L-$this->s", 'identifier' => $uhf, 'technology' => 'uhf']);
        $this->keep('association-deuxieme-puce', 'POST', '/puces/associer', ['pieceId' => $a, 'identifier' => '01' . $this->s, 'technology' => 'nfc']);
        $this->keep('pieces', 'GET', "/pieces?productId=$jacket&page=1&limit=20");
        $this->keep('piece', 'GET', "/pieces/$a");
        $this->keep('recherche-trouvee', 'GET', '/puces/recherche?identifier=' . rawurlencode($nfc));
        $this->keep('recherche-inconnue', 'GET', '/puces/recherche?identifier=AABBCCDD');
        $this->keep('recherche-refusee', 'GET', '/puces/recherche?identifier=pas-une-puce');
        $this->keep('synthese', 'GET', "/pieces/synthese?productId=$jacket");
        // Refus, avec leurs détails
        $this->keep('refus-puce-deja-posee', 'POST', '/puces/associer', ['pieceId' => $b, 'identifier' => $nfc, 'technology' => 'nfc'], 409);
        $this->keep('refus-lecture-qui-est-un-code-barres', 'POST', '/puces/associer', ['pieceId' => $b, 'identifier' => 'ABCDEF03' . $this->s . 'AA', 'technology' => 'uhf'], 409);
        $this->keep('refus-code-barres-ambigu', 'POST', '/pieces', ['barcode' => "VESTE-LIN-$this->s"], 409);
        $this->keep('refus-code-barres-inconnu', 'POST', '/pieces', ['barcode' => "INCONNU-$this->s"], 404);
        $this->keep('refus-lecture-invalide', 'POST', '/puces/associer', ['pieceId' => $b, 'identifier' => 'XYZ', 'technology' => 'nfc'], 422);
        $this->keep('refus-validation', 'POST', '/puces/associer', ['identifier' => 12, 'technology' => 'simulated'], 422);
        foreach ([2, 3] as $n) {
            $this->json($this->admin('POST', self::BASE . '/puces/associer', ['pieceId' => $a, 'identifier' => sprintf('%02d', $n) . $this->s, 'technology' => 'nfc']));
        }
        $this->keep('refus-trop-de-puces', 'POST', '/puces/associer', ['pieceId' => $a, 'identifier' => '05' . $this->s, 'technology' => 'nfc'], 409);
        $tagId = $this->examples['association-sur-une-piece']['body']['tag']['id'];
        $this->keep('refus-retrait-sans-confirmation', 'POST', "/puces/$tagId/retirer", ['reason' => 'detached'], 409);
        $this->keep('puce-retiree', 'POST', "/puces/$tagId/retirer", ['reason' => 'detached', 'confirm' => true]);
        $this->keep('refus-puce-retiree', 'POST', '/puces/associer', ['pieceId' => $b, 'identifier' => $nfc, 'technology' => 'nfc'], 409);
        $this->keep('piece-retiree', 'POST', "/pieces/$a/retirer", ['reason' => 'damaged', 'confirm' => true]);
        $this->keep('refus-piece-retiree', 'POST', '/puces/associer', ['pieceId' => $a, 'identifier' => '06' . $this->s, 'technology' => 'nfc'], 409);
        $this->keep('refus-piece-inconnue', 'GET', '/pieces/2000000000', null, 404);

        // ----- L2-b : étiquettes (les refus seulement : le PDF est binaire), assistant d'installation, gamme de matériel
        $this->keep('refus-etiquettes-piece-retiree', 'POST', '/pieces/etiquettes/pdf', ['pieceIds' => [$a]], 409);
        $this->keep('refus-etiquettes-trop-nombreuses', 'POST', '/pieces/etiquettes/pdf', ['pieceIds' => range(1, 201), 'format' => 'roll-50x30'], 422);
        $this->keep('installation', 'GET', '/installation');
        $this->keep('installation-etape-verifiee', 'POST', '/installation/etapes/first_piece/verifier', []);
        $this->keep('installation-puce-d-essai-lue', 'POST', '/installation/puce-test', ['identifier' => '07' . $this->s, 'technology' => 'nfc']);
        $this->keep('installation-puce-d-essai-refusee', 'POST', '/installation/puce-test', ['identifier' => '0012345678', 'technology' => 'nfc']);
        $this->keep('materiel', 'GET', '/materiel');

        // ----- Mode exercice : pièces d'exercice, puce simulée, assistant depuis zéro, effacement
        $this->keep('exercices-pieces-vide', 'GET', '/exercices/pieces');
        $this->keep('exercices-pieces-creees', 'POST', '/exercices/pieces', ['variantId' => $vBag, 'count' => 1], 201);
        $exercise = $this->examples['exercices-pieces-creees']['body']['pieces'][0]['id'];
        $this->keep('exercices-installation-au-depart', 'GET', '/exercices/installation');
        $this->keep('exercices-lecture-simulee', 'POST', '/exercices/puces/associer', ['pieceId' => $exercise]);
        $this->keep('exercices-pieces', 'GET', '/exercices/pieces');
        $this->keep('exercices-installation-puce-d-essai', 'POST', '/exercices/installation/puce-test', []);
        $this->keep('refus-exercice-vraie-piece', 'GET', "/exercices/pieces/$b", null, 404);
        $this->keep('exercices-pieces-effacees', 'DELETE', '/exercices/pieces');

        if (getenv('UPDATE_LOSS_EXAMPLES') === '1') {
            foreach ($this->examples as $name => $example) {
                file_put_contents(self::DIR . "/$name.json", json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            }
            $this->addToAssertionCount(1);

            return;
        }
        foreach ($this->examples as $name => $example) {
            $file = self::DIR . "/$name.json";
            $this->assertFileExists($file, "exemple manquant : régénérer (voir l'en-tête de ce test)");
            $stored = json_decode((string) file_get_contents($file), true);
            $this->assertSame([$example['request']['method'], $example['status']], [$stored['request']['method'] ?? null, $stored['status'] ?? null], $name);
            $this->assertSame([], self::shapeDifferences($stored['body'], $example['body'], 'body'), "$name : la forme de l'exemple n'est plus celle du serveur");
        }
        $extra = array_diff(array_map(fn (string $f) => basename($f, '.json'), glob(self::DIR . '/*.json') ?: []), array_keys($this->examples));
        $this->assertSame([], array_values($extra), 'exemples gardés que plus aucun appel ne produit');
    }

    /** Joue un appel de l'administrateur, en français, et garde sa réponse comme exemple */
    private function keep(string $name, string $method, string $path, ?array $body = null, int $status = 200): void
    {
        $path = self::BASE . $path;
        $path .= (str_contains($path, '?') ? '&' : '?') . 'locale=fr';
        $response = $this->call($method, $path, $body, $this->adminToken);
        $this->assertSame($status, $response->getStatusCode(), $name . ' : ' . $response->getContent());
        $this->examples[$name] = ['request' => ['method' => $method, 'path' => $path] + ($body !== null ? ['body' => $body] : []), 'status' => $status,
            'headers' => (object) array_filter(['X-Total-Count' => $response->headers->get('X-Total-Count'), 'X-Page' => $response->headers->get('X-Page'), 'X-Limit' => $response->headers->get('X-Limit')], fn ($v) => $v !== null),
            'body' => json_decode((string) $response->getContent(), true)];
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

    /**
     * @param list<?string> $variantBarcodes
     *
     * @return array{int, list<int>}
     */
    private function article(string $name, array $variantBarcodes): array
    {
        $slug = 'l2x-' . bin2hex(random_bytes(4));
        $this->db->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, is_web, is_pos) VALUES (nextval('product_id_seq'), :n, 8900, false, 0, NOW(), :s, 'standard', false, true)",
            ['n' => "$name (exemple $this->s)", 's' => $slug]);
        $product = (int) $this->db->fetchOne('SELECT id FROM product WHERE slug = ?', [$slug]);
        $ids = [];
        foreach ($variantBarcodes as $barcode) {
            $ids[] = (int) $this->db->fetchOne("INSERT INTO product_variant (id, product_id, stock_quantity, barcode) VALUES (nextval('product_variant_id_seq'), ?, 6, ?) RETURNING id", [$product, $barcode]);
        }

        return [$product, $ids];
    }
}
