<?php

namespace App\Tests\Functional\LossPrevention;

use App\Services\Platform\PlatformModeService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exemples de réponse de la prévention des pertes, un fichier JSON par route et par refus (`tests/Fixtures/pertes/`) : ils servent de jeux
 * d'essai aux écrans du web et du mobile, qui n'ont pas de constats sur le site de démonstration (rien n'y est écrit : le journal de caisse
 * est inaltérable). Ce test rejoue une journée de caisse sur la base de test et vérifie que chaque exemple gardé a toujours la FORME de la
 * réponse du serveur (mêmes clés, mêmes types) : un exemple ne peut pas dériver du code sans que ce test échoue.
 *
 * Régénérer après un changement voulu du contrat (les valeurs changent à chaque fois : identifiants, heures, empreintes) :
 * `docker exec -w /var/www -e UPDATE_LOSS_EXAMPLES=1 -e SYMFONY_DEPRECATIONS_HELPER=disabled symfony_app_v2 php vendor/bin/phpunit tests/Functional/LossPrevention/LossPreventionExamplesTest.php`
 */
class LossPreventionExamplesTest extends LossPreventionTestCase
{
    protected const FIXED_MARK = '';
    private const DIR = __DIR__ . '/../../Fixtures/pertes';
    /** @var array<string, array<string, mixed>> */
    private array $examples = [];

    public function testEveryStoredExampleStillHasTheShapeOfTheServerAnswer(): void
    {
        $this->drain();
        $this->loginPos(['ROLE_USER_POS', 'ROLE_POS_MANAGER']);
        $session = $this->openSession();
        foreach (['Monnaie pour un client', 'Monnaie', 'Contrôle du fond', 'Billet coincé'] as $reason) {
            $this->pos('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => $reason]);
        }
        $orders = [];
        foreach ([1, 2, 3] as $ignored) {
            $sale = $this->sellCash();
            $orders[] = (int) $sale['orderId'];
            $this->json($this->pos('POST', '/api/order/cancel/' . $sale['orderId'], ['paymentMethod' => 2, 'cashRegisterId' => $this->register]));
        }
        $this->sellCash();
        $report = $this->closeWith(-1200, 'Erreur de rendu de monnaie');
        $id = fn (string $key) => (int) $this->db->fetchOne('SELECT id FROM loss_finding WHERE dedup_key = ?', [$key]);
        $today = $this->today();

        $this->keep('analyse', 'POST', '/analyse');
        $this->keep('synthese', 'GET', "?from=$today&to=$today&analyse=0");
        $this->keep('constats', 'GET', "/constats?from=$today&to=$today&status=open,watch&sort=date&page=1&limit=20");
        $this->keep('constat-ecart-de-caisse', 'GET', '/constats/' . $id('CASH_DISCREPANCY:z_report:' . $report['id']));
        $this->keep('constat-tiroir-sans-vente', 'GET', '/constats/' . $id('DRAWER_NO_SALE:session:' . $session));
        $this->keep('constat-annulation-rapide', 'GET', '/constats/' . $id('VOID_AFTER_SALE:order:' . $orders[0]));
        $this->keep('constat-annulations-en-serie', 'GET', '/constats/' . $id('VOID_AFTER_SALE:session:' . $session));
        $decision = '/constats/' . $id('DRAWER_NO_SALE:session:' . $session) . '/decision';
        $this->keep('decision-a-suivre', 'PUT', $decision, ['status' => 'watch']);
        $this->keep('decision-expliquee', 'PUT', $decision, ['status' => 'explained', 'note' => 'Rouleaux de monnaie remis en caisse, vérifié avec le responsable.']);
        $this->keep('regles', 'GET', '/regles');
        $this->keep('regle-modifiee', 'PUT', '/regles/DRAWER_NO_SALE', ['params' => ['maxPerSession' => 5]]);
        $this->keep('reglages', 'GET', '/reglages');
        $this->keep('reglages-modifies', 'PUT', '/reglages', ['retention' => ['findingsMonths' => 18]]);
        $this->db->executeStatement('UPDATE loss_setting SET retention_findings_months = 24');

        // Refus
        $this->keep('refus-decision-sans-note', 'PUT', $decision, ['status' => 'confirmed'], 422);
        $this->keep('refus-constat-inconnu', 'GET', '/constats/999999999', null, 404);
        $this->keep('refus-regle-inconnue', 'PUT', '/regles/INCONNUE', ['enabled' => false], 404);
        $this->keep('refus-parametre-hors-bornes', 'PUT', '/regles/CASH_DISCREPANCY', ['params' => ['minAmount' => 99999999]], 422);
        $this->keep('refus-regle-par-personne-non-permise', 'PUT', '/reglages', ['personPatterns' => ['enabled' => true]], 422);
        $this->keep('refus-filtre-invalide', 'GET', '/constats?status=coupable', null, 422);
        $this->keep('refus-anonyme', 'GET', '', null, 401, null);
        $this->keep('refus-caissier', 'GET', '', null, 403, $this->posToken);

        // Québec : notice aux salariés exigée
        $this->place('CA', 'QC');
        $this->keep('reglages-notice-exigee', 'GET', '/reglages');
        $this->keep('synthese-notice-exigee', 'GET', "?from=$today&to=$today");
        $this->keep('refus-notice-requise', 'POST', '/analyse', null, 409);

        // Floride : règles par personne allumées d'office ; trois manques du même compte
        $this->place('US', 'FL');
        $closer = $this->loginPos(['ROLE_USER_POS']);
        foreach ([-900, -1100, -700] as $delta) {
            $this->openSession();
            $this->closeWith($delta, 'Manque à la fermeture');
        }
        $this->json($this->admin('POST', self::BASE . '/analyse'));
        $this->keep('reglages-floride', 'GET', '/reglages');
        $this->keep('regles-floride', 'GET', '/regles');
        $this->keep('constat-manques-repetes-par-personne', 'GET', '/constats/' . $this->db->fetchOne("SELECT id FROM loss_finding WHERE variant = 'person' AND subject_id = ? ORDER BY id DESC LIMIT 1", [$closer->getId()]));
        $this->place('CA', 'ON');

        // Analyse lancée par la lecture de la synthèse (la dernière a plus de dix minutes)
        $this->loginPos(['ROLE_USER_POS']);
        $this->openSession();
        $this->closeWith(-1500, 'Écart relevé à la lecture de la synthèse');
        $this->db->executeStatement('UPDATE loss_setting SET last_analysis_at = ?', [(new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')]);
        $this->keep('synthese-analyse-a-la-lecture', 'GET', "?from=$today&to=$today");
        // Droit du module non accordé, plateforme en mode « demo » : les écrans répondent, rien n'est analysé
        $this->flags(false, false);
        $this->keep('synthese-module-non-accorde', 'GET', "?from=$today&to=$today");
        $this->keep('refus-analyse-module-non-accorde', 'POST', '/analyse', null, 409);
        $this->flags(false, true);
        // Une opération de caisse qu'une règle n'a pas pu lire : la synthèse le dit (remarque en tête, total sur 30 jours)
        $this->openSession();
        $this->pos('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => 'Ouverture que la règle ne sait pas lire']);
        $this->closeWith(0);
        $this->assertSame(1, $this->analysisWith([self::failingDrawerRule()])->run()['skipped']);
        $this->keep('synthese-entrees-sautees', 'GET', "?from=$today&to=$today&analyse=0");

        // Mode live de la plateforme : les deux gardes de module, chacune avec sa clé dans `feature`
        static::getContainer()->get(PlatformModeService::class)->setMode('live', 'test');
        $this->flags(true, false);
        $this->keep('refus-module-inactif', 'GET', '', null, 403);
        $this->flags(false, true);
        $this->keep('refus-gestion-inactive', 'GET', '', null, 403);
        $this->flags(false, false);
        static::getContainer()->get(PlatformModeService::class)->setMode('demo', 'test');

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

    /** Joue un appel (administrateur par défaut, en français) et garde sa réponse comme exemple */
    private function keep(string $name, string $method, string $path, ?array $body = null, int $status = 200, ?string $token = ''): void
    {
        $path = self::BASE . $path;
        $path .= str_contains($path, 'locale=') ? '' : (str_contains($path, '?') ? '&' : '?') . 'locale=fr';
        $response = $this->call($method, $path, $body, $token === '' ? $this->adminToken : $token);
        $this->assertSame($status, $response->getStatusCode(), $name . ' : ' . $response->getContent());
        $this->examples[$name] = ['request' => ['method' => $method, 'path' => $path] + ($body !== null ? ['body' => $body] : []), 'status' => $status,
            'headers' => (object) array_filter(['X-Total-Count' => $response->headers->get('X-Total-Count'), 'X-Page' => $response->headers->get('X-Page'), 'X-Limit' => $response->headers->get('X-Limit')], fn ($v) => $v !== null),
            'body' => json_decode((string) $response->getContent(), true)];
    }

    /**
     * Différences de forme entre un exemple gardé et la réponse du jour : mêmes clés, mêmes types. Une valeur nulle d'un côté s'accorde avec
     * tout (champ facultatif) ; une liste se compare par son premier élément ; un entier et un décimal sont le même type.
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
