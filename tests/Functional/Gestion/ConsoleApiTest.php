<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Gestion\Analysis\AnalysisCatalog;
use App\Gestion\Message\AnswerConsoleQuestionMessage;
use App\Gestion\MessageHandler\AnswerConsoleQuestionHandler;
use App\Gestion\Services\Conversation\GestionConversationService;
use App\Gestion\Services\PeriodCalendar;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeLandingAiClient;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use App\Tests\Functional\Support\AiRightTestHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Console IA générale de la gestion (lot W2, 13/11/2026, `docs/console-gestion.md`) : une question reçoit tout de suite une réponse
 * « en préparation » ; le worker fait choisir des analyses au modèle, les exécute, fait rédiger des blocs, et n'y laisse aucun nombre
 * que le serveur n'a pas fourni. Verdicts du serveur, propositions contrôlées, unités, délai, effacement, périmètre. Le modèle est simulé.
 */
class ConsoleApiTest extends WebTestCase
{
    use CacheTestEntityFactory;
    use PricingFixtures;

    private KernelBrowser $client;
    private string $token = '';
    private string $tag = '';
    private int $usageMark = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeLandingAiClient::reset();
        AiRightTestHelper::restoreFullAi(static::getContainer());
        $this->tag = bin2hex(random_bytes(3));
        $this->login(['ROLE_ADMIN']);
        $conn = $this->em()->getConnection();
        $this->usageMark = (int) $conn->fetchOne('SELECT COALESCE(MAX(id), 0) FROM ai_usage');
        $conn->executeStatement('UPDATE entreprise SET gestion_default_coefficient = NULL, gestion_min_coefficient = NULL, gestion_price_rounding = NULL');
    }

    protected function tearDown(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('DELETE FROM ai_usage WHERE id > ?', [$this->usageMark]);
        $conn->executeStatement("DELETE FROM collections WHERE period_key LIKE 'C-%' AND kind = 'period' AND start_date_collection > NOW() + INTERVAL '700 days'"); // la période d'essai, si le test s'est arrêté avant de la retirer
        $conn->executeStatement("DELETE FROM gestion_conversation WHERE kind = 'console'"); // base partagée : les échanges de ce test ne comptent pas dans le plafond du jour des suivants
        $this->removePricingFixtures();
        AiRightTestHelper::restoreFullAi(static::getContainer());
        parent::tearDown();
    }

    public function testTheReviewOfNewItemsIsBuiltFromServerAnalysesVerdictsAndCheckedProposals(): void
    {
        $tag = $this->tag;
        $conn = fn () => $this->em()->getConnection();
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $period = (int) $calendar->current()->getId();
        $today = $calendar->today()->format('Y-m-d');
        $season = PeriodCalendar::definition(\DateTimeImmutable::createFromInterface($calendar->current()->getStartDateCollection())->modify('-1 year'), $calendar->frequency());
        // Un fournisseur dont 5 articles semblables (coût 10,00, affichés à 2,5) se sont vendus à 90 % la saison comparable : le serveur propose 2,7
        $supplier = $this->supplier("Grossiste console $tag");
        $history = [];
        foreach (range(1, 5) as $i) {
            $history[] = $this->article($supplier, "Historique console $i $tag", 1000, 2500, $season['start']->modify('+2 days')->format('Y-m-d'));
            $this->sale($history[$i - 1][1], 9, 2500, 2500, $season['start']->modify('+16 days')->format('Y-m-d'));
        }
        // Trois articles nouveaux de la période : à 2,5 (à monter), à 2,7 (à garder), à 3,2 (à baisser). Leur nom les classe en tête de la liste.
        [$raise] = $this->article($supplier, "000 Console monter $tag", 1000, 2500, $today);
        [$keep] = $this->article($supplier, "000 Console garder $tag", 1000, 2700, $today);
        [$lower] = $this->article($supplier, "000 Console baisser $tag", 1000, 3200, $today);
        $this->endOfWrite();
        $usage = fn (): array => array_column($conn()->fetchAllAssociative('SELECT status, COUNT(*) AS n FROM ai_usage WHERE id > ? GROUP BY status ORDER BY status', [$this->usageMark]), 'n', 'status');
        $otherPeriod = (int) $conn()->fetchOne("INSERT INTO collections (id, budget_collection, start_date_collection, end_date_collection, del, nom_collection, is_closed, kind, period_key) VALUES (nextval('collections_id_seq'), 0, NOW() + INTERVAL '800 days', NOW() + INTERVAL '830 days', false, ?, false, 'period', ?) RETURNING id", ["Période à venir $tag", "C-$tag"]); // ouverte : le fil d'un budget l'accepterait

        // Le catalogue : dix analyses, en liste blanche
        $catalog = $this->get('/api/gestion/console/analyses');
        $this->assertSame(array_keys(AnalysisCatalog::DEFINITIONS), array_column($catalog, 'key'));
        $this->assertSame(['key' => 'articles.new', 'title' => 'Bilan des nouveaux articles', 'params' => [['name' => 'periodId', 'type' => 'id', 'required' => false]], 'source' => ['screen' => 'articles', 'route' => null]], array_column($catalog, null, 'key')['articles.new']);
        $this->assertSame('Review of the new items', array_column($this->get('/api/gestion/console/analyses?locale=en'), 'title', 'key')['articles.new']);

        // La question : gardée tout de suite, réponse « en préparation », 1 unité réservée, aucun appel au modèle dans la requête
        $asked = $this->post('/api/gestion/console/messages', ['text' => 'Fais le bilan de mes nouveaux articles', 'context' => ['module' => 'gestion', 'screen' => 'articles', 'params' => ['periodId' => $period, 'productId' => 2147483000, 'autre' => 'x']]], 202);
        $this->assertSame(['conversationId', 'questionMessageId', 'messageId', 'state', 'blocks', 'analyses', 'proposals', 'credits'], array_keys($asked));
        $this->assertSame(['pending', [], [], []], [$asked['state'], $asked['blocks'], $asked['analyses'], $asked['proposals']]);
        $this->assertSame([['reserved' => 1], 0], [$usage(), count(FakeLandingAiClient::$requests)]);
        $id = (int) $asked['conversationId'];
        $exchange = $this->get("/api/gestion/console/conversations/$id");
        $this->assertSame(['id', 'title', 'createdAt', 'updatedAt', 'pending', 'messages', 'credits'], array_keys($exchange));
        $this->assertSame([$id, 'Fais le bilan de mes nouveaux articles', true], [$exchange['id'], $exchange['title'], $exchange['pending']]);
        $this->assertSame(['messageId' => $asked['questionMessageId'], 'role' => 'user', 'text' => 'Fais le bilan de mes nouveaux articles', 'context' => ['module' => 'gestion', 'screen' => 'articles', 'params' => ['periodId' => $period]]],
            array_diff_key($exchange['messages'][0], ['createdAt' => 1]), 'le contexte est contrôlé : seuls les identifiants existants du site sont gardés');
        $this->assertSame(['messageId' => $asked['messageId'], 'role' => 'assistant', 'replyTo' => $asked['questionMessageId'], 'state' => 'pending', 'error' => null, 'blocks' => [], 'analyses' => [], 'proposals' => [], 'answeredAt' => null],
            array_diff_key($exchange['messages'][1], ['createdAt' => 1]));
        // Une réponse à la fois par échange : ni autre question, ni effacement, tant qu'elle est en préparation
        $this->assertSame(['CONSOLE_ANSWER_PENDING', $asked['messageId']], [$this->post('/api/gestion/console/messages', ['text' => 'Et la marge ?', 'conversationId' => $id], 409)['code'], $this->post('/api/gestion/console/messages', ['text' => 'Et la marge ?', 'conversationId' => $id], 409)['messageId']]);
        $this->request('DELETE', "/api/gestion/console/conversations/$id");
        $this->assertSame([409, 'CONSOLE_ANSWER_PENDING'], [$this->client->getResponse()->getStatusCode(), $this->body()['code']]);
        $this->assertSame(['reserved' => 1], $usage(), 'un refus ne réserve rien de plus');

        // Le worker : appel 1, le modèle choisit (une clé inconnue et un paramètre faux sont écartés par le serveur) ; appel 2, il rédige
        $newItems = array_column(static::getContainer()->get(AnalysisCatalog::class)->run('articles.new', ['periodId' => $period], 'fr')['figures'], 'value', 'id')['newItems'];
        $this->em();
        FakeLandingAiClient::$queue[] = $this->reply(['kind' => 'analyses', 'clarification' => '', 'analyses' => [
            ['key' => 'articles.new', 'periodId' => null, 'productId' => null, 'year' => null, 'days' => null, 'range' => null],
            ['key' => 'comptes.clients', 'periodId' => null, 'productId' => null, 'year' => null, 'days' => null, 'range' => null],
            ['key' => 'articles.vs_similar', 'periodId' => null, 'productId' => 2147483000, 'year' => null, 'days' => null, 'range' => null],
        ]]);
        FakeLandingAiClient::$queue[] = $this->reply(['blocks' => [
            ['type' => 'text', 'text' => "Vous avez $newItems nouveaux articles sur la période. Voici leur bilan.", 'figureIds' => ['a1.newItems', 'a9.inconnu'], 'analysis' => '', 'items' => []],
            ['type' => 'verdicts', 'text' => '', 'figureIds' => [], 'analysis' => 'a1', 'items' => [
                ['id' => $raise, 'why' => 'Ses semblables se sont vendus à 90 % sans rabais.', 'figureIds' => ['a1.verdictRaise']],
                ['id' => $keep, 'why' => 'Son prix est déjà au bon niveau.', 'figureIds' => []],
                ['id' => $lower, 'why' => 'Baissez-le de 17 % pour le vendre.', 'figureIds' => []],
                ['id' => 2147483000, 'why' => 'Article inventé.', 'figureIds' => []],
            ]],
            ['type' => 'figures', 'text' => '', 'figureIds' => ['a1.verdictRaise', 'a1.verdictLower'], 'analysis' => '', 'items' => []],
            ['type' => 'table', 'text' => '', 'figureIds' => [], 'analysis' => 'a1', 'items' => []],
            ['type' => 'table', 'text' => '', 'figureIds' => [], 'analysis' => 'a7', 'items' => []],
        ], 'proposals' => [
            ['action' => 'price.set', 'productIds' => [$raise, 2147483000], 'periodId' => null, 'label' => 'Monter le prix des articles bien partis'],
            ['action' => 'comptes.supprimer', 'productIds' => [$raise], 'periodId' => null, 'label' => 'Hors catalogue'],
            ['action' => 'price.set', 'productIds' => [2147483000], 'periodId' => null, 'label' => 'Articles inventés'],
            ['action' => 'price.set', 'productIds' => [$lower], 'periodId' => null, 'label' => 'Baisser de 10 %'],
            ['action' => 'period.close', 'productIds' => [], 'periodId' => 2147483000, 'label' => 'Clôturer'],
            ['action' => 'period.close', 'productIds' => [], 'periodId' => $period, 'label' => 'Clôturer la période'], // identifiant présent, mais le fil le refuserait : période en cours
            ['action' => 'budget.put', 'productIds' => [], 'periodId' => $otherPeriod, 'label' => 'Budget d\'une autre période'], // période réelle, que le fil accepterait, mais absente des résultats
        ]]);
        $this->runWorker((int) $asked['messageId']);
        $this->assertCount(2, FakeLandingAiClient::$requests, 'deux appels au modèle par réponse : choisir, puis rédiger');
        $this->assertSame(['success' => 1], $usage(), 'une réponse rédigée par le modèle : 1 unité');

        $exchange = $this->get("/api/gestion/console/conversations/$id");
        $answer = $exchange['messages'][1];
        $this->assertSame([false, 'done', null], [$exchange['pending'], $answer['state'], $answer['error']]);
        $this->assertNotNull($answer['answeredAt']);
        $this->assertSame(['text', 'verdicts', 'figures', 'table'], array_column($answer['blocks'], 'type'), 'le tableau d\'une analyse qui n\'a pas été lancée est écarté');
        $this->assertSame(['type' => 'text', 'text' => "Vous avez $newItems nouveaux articles sur la période. Voici leur bilan.", 'figureIds' => ['a1.newItems']], $answer['blocks'][0], 'un identifiant de chiffre inconnu est retiré');
        // Verdicts : ceux du serveur (règle du coefficient), jamais ceux du modèle ; une explication qui porte un nombre inconnu est remplacée par celle du serveur
        $verdicts = array_column($answer['blocks'][1]['items'], null, 'verdict');
        $this->assertSame(['raise', 'keep', 'lower'], array_column($answer['blocks'][1]['items'], 'verdict'));
        $this->assertSame([['type' => 'product', 'id' => $raise, 'name' => "000 Console monter $tag"], 'Ses semblables se sont vendus à 90 % sans rabais.', ['a1.verdictRaise']], [$verdicts['raise']['subject'], $verdicts['raise']['why'], $verdicts['raise']['figureIds']]);
        $this->assertSame('Son prix est déjà au bon niveau.', $verdicts['keep']['why']);
        $this->assertStringStartsWith('2,7 : 5 semblables de ce fournisseur affichés à 2,5, vendus à 90 %', $verdicts['lower']['why'], '« 17 % » n\'est dans aucun résultat : l\'explication chiffrée du serveur prend sa place');
        // Chiffres et tableau : assemblés par le serveur, avec leur source
        $this->assertSame(['a1.verdictRaise', 'a1.verdictLower'], array_column($answer['blocks'][2]['items'], 'id'));
        $this->assertSame(['id', 'label', 'value', 'unit', 'source'], array_keys($answer['blocks'][2]['items'][0]));
        $this->assertSame(['screen' => 'articles', 'route' => null, 'params' => ['periodId' => $period]], $answer['blocks'][2]['items'][0]['source']);
        $rows = array_column($answer['blocks'][3]['rows'], null, 'productId');
        $this->assertArrayNotHasKey($history[0][0], $rows, 'un article acheté la saison passée n\'est pas un article nouveau de la période');
        $this->assertSame([[1000, 2500, 2.5, 2.7, 'similar', 'raise', 5], [1000, 2700, 2.7, 2.7, 'similar', 'keep', 5], [1000, 3200, 3.2, 2.7, 'similar', 'lower', 5]],
            array_map(fn (int $p) => [$rows[$p]['cost'], $rows[$p]['price'], $rows[$p]['coefficient'], $rows[$p]['proposedCoefficient'], $rows[$p]['proposalSource'], $rows[$p]['verdict'], $rows[$p]['similarCount']], [$raise, $keep, $lower]));
        $this->assertSame([['key' => 'articles.new', 'ref' => 'a1', 'title' => 'Bilan des nouveaux articles', 'params' => ['periodId' => $period]]], array_map(fn (array $a) => array_intersect_key($a, array_flip(['key', 'ref', 'title', 'params'])), $answer['analyses']),
            'une seule analyse lancée : la clé inconnue et l\'article inexistant sont écartés');
        // Propositions : action du catalogue, identifiants présents dans les résultats, contrôle d'ouverture du fil ; un libellé chiffré est remplacé
        $this->assertSame([
            ['action' => 'price.set', 'params' => ['scope' => ['productIds' => [$raise]]], 'label' => 'Monter le prix des articles bien partis'],
            ['action' => 'price.set', 'params' => ['scope' => ['productIds' => [$lower]]], 'label' => 'Changer le prix de vente d\'articles'],
        ], $answer['proposals']);
        // Ce que le modèle a lu à l'appel 2 : des écritures (« 25,00 $ »), pas des cents ; à l'appel 1 : la question, l'écran et le catalogue
        $second = json_decode(FakeLandingAiClient::$requests[1]['messages'][0]['content'][0]['text'], true);
        $sent = array_column($second['resultats'][0]['rows'], null, 'productId')[$raise];
        $plain = fn (string $v) => preg_replace('/[\x{00A0}\x{202F}]/u', ' ', $v); // l'espace devant le symbole de la devise est insécable
        $this->assertSame(['10,00 $', '25,00 $', 'raise'], [$plain($sent['cost']), $plain($sent['price']), $sent['verdict']]);
        $first = json_decode(FakeLandingAiClient::$requests[0]['messages'][0]['content'][0]['text'], true);
        $this->assertSame(['Fais le bilan de mes nouveaux articles', ['module' => 'gestion', 'screen' => 'articles', 'params' => ['periodId' => $period]], [], 10], [$first['question'], $first['ecran'], $first['echange'], count($first['analyses'])]);
        $this->assertContains($period, array_column($first['periodes'], 'id'));
        $this->assertSame(['claude-sonnet-5-5', 'claude-sonnet-5-5'], array_column(FakeLandingAiClient::$requests, 'model'));
        foreach (FakeLandingAiClient::$requests as $request) {
            $this->assertSame('json_schema', $request['output_config']['format']['type']);
            $this->assertArrayNotHasKey('tool_choice', $request, 'sortie structurée, jamais d\'outil imposé');
        }
        // La proposition ouvre le fil d'action tel quel
        $thread = $this->post('/api/gestion/conversations', ['action' => 'price.set', 'params' => $answer['proposals'][0]['params']], 201);
        $this->assertSame(['price.set', ['productIds' => [$raise]]], [$thread['kind'], array_column($thread['questions'], 'answer', 'id')['scope']]);
        // La console n'a rien écrit dans les données : les prix sont ceux d'avant
        $this->assertSame([2500, 2700, 3200], array_map(fn (int $p) => (int) round((float) $conn()->fetchOne('SELECT price FROM product WHERE id = ?', [$p])), [$raise, $keep, $lower]));

        // Deuxième question du même échange : le modèle reçoit les derniers messages (texte seul) ; un nombre inconnu vaut un nouvel essai
        $again = $this->post('/api/gestion/console/messages', ['text' => 'Combien faut-il en monter ?', 'conversationId' => $id, 'context' => ['module' => 'boutique', 'screen' => 'articles']], 202);
        $this->assertSame($id, $again['conversationId']);
        $choose = ['kind' => 'analyses', 'clarification' => '', 'analyses' => [['key' => 'articles.new', 'periodId' => $period, 'productId' => null, 'year' => null, 'days' => null, 'range' => null]]];
        $block = fn (string $text) => ['blocks' => [['type' => 'text', 'text' => $text, 'figureIds' => [], 'analysis' => '', 'items' => []]], 'proposals' => []];
        FakeLandingAiClient::$queue = [$this->reply($choose), $this->reply($block('Il faut en monter 987654.')), $this->reply($block('Regardez les articles à monter.'))];
        $this->runWorker((int) $again['messageId']);
        $this->assertCount(5, FakeLandingAiClient::$requests, 'un nouvel essai de rédaction, pas plus');
        $third = json_decode(FakeLandingAiClient::$requests[2]['messages'][0]['content'][0]['text'], true);
        $this->assertSame([null, ['gérant', 'console']], [$third['ecran'], array_column($third['echange'], 'role')], 'un contexte d\'un autre module est ignoré, sans erreur ; l\'échange précédent est donné');
        $this->assertStringContainsString('Voici leur bilan.', $third['echange'][1]['text']);
        $this->assertArrayHasKey('correction', json_decode(FakeLandingAiClient::$requests[4]['messages'][0]['content'][0]['text'], true));
        $exchange = $this->get("/api/gestion/console/conversations/$id");
        $this->assertSame([4, 'done', [['type' => 'text', 'text' => 'Regardez les articles à monter.', 'figureIds' => []]]], [count($exchange['messages']), $exchange['messages'][3]['state'], $exchange['messages'][3]['blocks']]);
        $this->assertSame(['success' => 2], $usage());

        // Deux essais avec un nombre inconnu : texte de repli du serveur et chiffres de l'analyse ; le nombre inconnu n'est rendu nulle part
        $third = $this->post('/api/gestion/console/messages', ['text' => 'Et la marge ?', 'conversationId' => $id], 202);
        FakeLandingAiClient::$queue = [$this->reply($choose), $this->reply($block('La marge est de 987654 CAD.')), $this->reply($block('Elle vaut 876543 CAD.'))];
        $this->runWorker((int) $third['messageId']);
        $fallback = $this->get("/api/gestion/console/conversations/$id")['messages'][5];
        $this->assertSame(['done', ['text', 'figures'], 'Voici les chiffres calculés par votre application pour cette question.'], [$fallback['state'], array_column($fallback['blocks'], 'type'), $fallback['blocks'][0]['text']]);
        $this->assertStringNotContainsString('987654', json_encode($fallback));
        $this->assertStringNotContainsString('876543', json_encode($fallback));
        $this->assertContains('a1.newItems', array_column($fallback['blocks'][1]['items'], 'id'));
        // Une réponse de repli est gratuite (le gérant ne paie pas une réponse que l'IA n'a pas su écrire) et comptée
        $this->assertFalse(\App\Gestion\Services\Console\ConsoleResponder::CONSOLE_FALLBACK_CHARGED);
        $this->assertSame(['failed' => 1, 'success' => 2], $usage(), 'l\'unité réservée pour la réponse de repli est rendue');
        $this->assertSame(1, (int) $conn()->fetchOne("SELECT COUNT(*) FROM gestion_conversation_message WHERE conversation_id = ? AND content::jsonb ->> 'fallback' = 'true'", [$id]), 'chaque repli est marqué sur sa réponse : on peut les compter par jour');
        $this->assertArrayNotHasKey('fallback', $fallback, 'la marque reste au serveur');

        // L'historique : un échange, trois questions ; la liste ne rend aucun bloc
        $this->request('GET', '/api/gestion/console/conversations?limit=5');
        $listed = array_column($this->body(), null, 'id')[$id];
        $this->assertSame(['id' => $id, 'title' => 'Fais le bilan de mes nouveaux articles', 'questions' => 3, 'pending' => false, 'lastState' => 'done'], array_diff_key($listed, ['createdAt' => 1, 'updatedAt' => 1]));
        $this->assertSame(['1', '5'], [$this->client->getResponse()->headers->get('X-Page'), $this->client->getResponse()->headers->get('X-Limit')]);
        $this->assertGreaterThanOrEqual(1, (int) $this->client->getResponse()->headers->get('X-Total-Count'));
        // Les échanges de la console ne sont pas des fils : ni dans leur liste, ni par leur identifiant
        $this->assertNotContains($id, array_column($this->get('/api/gestion/conversations?limit=100'), 'id'));
        $this->request('GET', '/api/gestion/conversations?kind=console');
        $this->assertSame([422, 'UNKNOWN_KIND'], [$this->client->getResponse()->getStatusCode(), $this->body()['fields']['kind'] ?? null], '« console » n\'est pas un type de fil');
        $this->request('GET', "/api/gestion/conversations/$id");
        $this->assertSame([404, 'CONVERSATION_NOT_FOUND'], [$this->client->getResponse()->getStatusCode(), $this->body()['code']]);
        // Effacement : 204, l'échange ne se lit plus, c'est au journal
        $this->request('DELETE', "/api/gestion/console/conversations/$id");
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('GET', "/api/gestion/console/conversations/$id");
        $this->assertSame([404, 'CONSOLE_CONVERSATION_NOT_FOUND'], [$this->client->getResponse()->getStatusCode(), $this->body()['code']]);
        $this->assertSame('CONSOLE_CONVERSATION_NOT_FOUND', $this->post('/api/gestion/console/messages', ['text' => 'Encore ?', 'conversationId' => $id], 404)['code']);
        $audit = $conn()->fetchAssociative("SELECT action, before_state AS before FROM content_audit_log WHERE resource = 'gestion-console' AND resource_id = ?", [(string) $id]);
        $this->assertSame(['delete', 'Fais le bilan de mes nouveaux articles', 3], [$audit['action'], json_decode((string) $audit['before'], true)['title'], json_decode((string) $audit['before'], true)['questions']]);
        $this->assertSame(0, (int) $conn()->fetchOne('SELECT COUNT(*) FROM gestion_conversation_message WHERE conversation_id = ?', [$id]));
        $conn()->executeStatement('DELETE FROM collections WHERE id = ?', [$otherPeriod]);
    }

    public function testUnitsRefusalsTimeoutAndScope(): void
    {
        $conn = fn () => $this->em()->getConnection();
        $usage = fn (): array => array_column($conn()->fetchAllAssociative('SELECT status, COUNT(*) AS n FROM ai_usage WHERE id > ? GROUP BY status ORDER BY status', [$this->usageMark]), 'n', 'status');
        $ask = fn (string $text, ?int $conversation = null): array => $this->post('/api/gestion/console/messages', ['text' => $text] + ($conversation === null ? [] : ['conversationId' => $conversation]), 202);
        $last = fn (int $id): array => array_slice($this->get("/api/gestion/console/conversations/$id")['messages'], -1)[0];
        $none = ['periodId' => null, 'productId' => null, 'year' => null, 'days' => null, 'range' => null];

        // Hors périmètre : le serveur répond lui-même, aucune unité, un seul appel au modèle
        $a = $ask('Écris la description de mes articles');
        FakeLandingAiClient::$queue[] = $this->reply(['kind' => 'out_of_scope', 'analyses' => [], 'clarification' => '']);
        $this->runWorker((int) $a['messageId']);
        $answer = $last((int) $a['conversationId']);
        $this->assertSame(['done', ['text'], [], []], [$answer['state'], array_column($answer['blocks'], 'type'), $answer['analyses'], $answer['proposals']]);
        $this->assertStringStartsWith('Je réponds aux questions sur la gestion de votre commerce', $answer['blocks'][0]['text']);
        $this->assertSame([['failed' => 1], 1], [$usage(), count(FakeLandingAiClient::$requests)], 'l\'unité réservée est rendue');

        // Demande de précision rédigée par le modèle : 1 unité ; avec un nombre de son cru, ou sans analyse valable : celle du serveur, gratuite
        $b = $ask('Et pour 2025 ?');
        FakeLandingAiClient::$queue[] = $this->reply(['kind' => 'clarification', 'analyses' => [], 'clarification' => 'Voulez-vous les ventes ou le résultat de 2025 ?']);
        $this->runWorker((int) $b['messageId']);
        $this->assertSame('Voulez-vous les ventes ou le résultat de 2025 ?', $last((int) $b['conversationId'])['blocks'][0]['text']);
        $this->assertSame(['failed' => 1, 'success' => 1], $usage());
        $c = $ask('Et alors ?');
        FakeLandingAiClient::$queue[] = $this->reply(['kind' => 'clarification', 'analyses' => [], 'clarification' => 'Parlez-vous des 12 derniers mois ?']);
        $this->runWorker((int) $c['messageId']);
        $this->assertStringStartsWith('Pouvez-vous préciser votre question ?', $last((int) $c['conversationId'])['blocks'][0]['text']);
        $d = $ask('Montre-moi cet article');
        FakeLandingAiClient::$queue[] = $this->reply(['kind' => 'analyses', 'clarification' => '', 'analyses' => [['key' => 'articles.vs_similar'] + $none, ['key' => 'inconnue'] + $none]]);
        $this->runWorker((int) $d['messageId']);
        $this->assertStringStartsWith('Pouvez-vous préciser votre question ?', $last((int) $d['conversationId'])['blocks'][0]['text'], 'aucune analyse valable (paramètre obligatoire absent) : le serveur demande une précision');
        $this->assertSame(['failed' => 3, 'success' => 1], $usage());

        // L'IA échoue : réponse en échec, aucune unité ; reposer la question est une nouvelle question du même échange
        $e = $ask('Quelle est ma marge ?');
        FakeLandingAiClient::$queue[] = new \RuntimeException('panne simulée');
        $this->runWorker((int) $e['messageId']);
        $failed = $last((int) $e['conversationId']);
        $this->assertSame(['failed', 'AI_UNAVAILABLE', []], [$failed['state'], $failed['error']['code'], $failed['blocks']]);
        $this->assertSame(['failed' => 4, 'success' => 1], $usage());
        $this->assertSame((int) $e['conversationId'], $ask('Quelle est ma marge ?', (int) $e['conversationId'])['conversationId']);

        // Délai dépassé : à la lecture, la réponse passe en échec et son unité est rendue ; une réponse tardive du worker ne l'écrase pas
        $f = (int) $conn()->fetchOne("SELECT MAX(id) FROM gestion_conversation_message WHERE kind = 'answer'");
        $conn()->executeStatement("UPDATE gestion_conversation_message SET created_at = NOW() - INTERVAL '121 seconds' WHERE id = ?", [$f]);
        $timedOut = $last((int) $e['conversationId']);
        $this->assertSame([$f, 'failed', 'CONSOLE_TIMEOUT'], [$timedOut['messageId'], $timedOut['state'], $timedOut['error']['code']]);
        $this->assertSame(['failed' => 5, 'success' => 1], $usage());
        FakeLandingAiClient::$queue[] = $this->reply(['kind' => 'out_of_scope', 'analyses' => [], 'clarification' => '']);
        $requests = count(FakeLandingAiClient::$requests);
        $this->runWorker($f);
        $this->assertSame([$requests, 'CONSOLE_TIMEOUT'], [count(FakeLandingAiClient::$requests), $last((int) $e['conversationId'])['error']['code']], 'le worker ne répond pas à une question déjà en échec');
        FakeLandingAiClient::$queue = [];
        // 119 secondes : encore en préparation
        $g = $ask('Et le stock ?');
        $conn()->executeStatement("UPDATE gestion_conversation_message SET created_at = NOW() - INTERVAL '115 seconds' WHERE id = ?", [$g['messageId']]);
        $this->assertSame('pending', $last((int) $g['conversationId'])['state']);

        // Écran inconnu de la gestion : le contexte est ignoré, sans erreur
        $unknownScreen = $this->post('/api/gestion/console/messages', ['text' => 'Et ici ?', 'context' => ['module' => 'gestion', 'screen' => 'ecran.inconnu', 'params' => []]], 202);
        $this->assertNull($this->get("/api/gestion/console/conversations/{$unknownScreen['conversationId']}")['messages'][0]['context']);

        // Plus d'unités : 402 sur la route de la console, aucun échange ni réponse créés, rien de réservé ni de décompté
        $monthly = $conn()->fetchOne('SELECT monthly_credits FROM ai_credit_setting ORDER BY id LIMIT 1');
        $counts = fn (): array => [(int) $conn()->fetchOne("SELECT COUNT(*) FROM gestion_conversation WHERE kind = 'console'"), (int) $conn()->fetchOne('SELECT COUNT(*) FROM gestion_conversation_message'), $usage()];
        $beforeRefusal = $counts();
        try {
            $monthly === false ? $conn()->executeStatement('INSERT INTO ai_credit_setting (monthly_credits) VALUES (0)') : $conn()->executeStatement('UPDATE ai_credit_setting SET monthly_credits = 0');
            static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
            $refused = $this->post('/api/gestion/console/messages', ['text' => 'Quelle est ma marge ?'], 402);
            $this->assertSame(['AI_CREDITS_EXHAUSTED', 'AI_CREDITS_EXHAUSTED'], [$refused['error'], $refused['code']]);
            $this->assertSame($beforeRefusal, $counts());
        } finally {
            $monthly === false ? $conn()->executeStatement('DELETE FROM ai_credit_setting') : $conn()->executeStatement('UPDATE ai_credit_setting SET monthly_credits = ?', [$monthly]);
            static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        }

        // Refus de la question
        foreach ([[['text' => '  '], 'text', 'REQUIRED'], [['text' => str_repeat('a', 1001)], 'text', 'TOO_LONG'], [['text' => 'Bonjour', 'conversationId' => 'douze'], 'conversationId', 'INVALID_ID'], [[], 'text', 'REQUIRED']] as [$body, $field, $code]) {
            $this->assertSame($code, $this->post('/api/gestion/console/messages', $body, 422)['fields'][$field] ?? null, json_encode($body));
        }
        $this->assertSame('CONSOLE_CONVERSATION_NOT_FOUND', $this->post('/api/gestion/console/messages', ['text' => 'Bonjour', 'conversationId' => 2147483000], 404)['code']);
        $this->request('DELETE', '/api/gestion/console/conversations/2147483000');
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        // un fil d'action n'est pas un échange de la console
        $thread = $this->post('/api/gestion/conversations', ['action' => 'expense.create'], 201);
        $this->request('GET', "/api/gestion/console/conversations/{$thread['id']}");
        $this->assertSame([404, 'CONSOLE_CONVERSATION_NOT_FOUND'], [$this->client->getResponse()->getStatusCode(), $this->body()['code']]);
        $this->assertSame('CONSOLE_CONVERSATION_NOT_FOUND', $this->post('/api/gestion/console/messages', ['text' => 'Bonjour', 'conversationId' => (int) $thread['id']], 404)['code']);

        // Plafond du jour, commun avec la phrase libre du fil
        $reservedBefore = $usage();
        $filler = (int) $conn()->fetchOne("INSERT INTO gestion_conversation (kind, status, locale, created_at, updated_at) VALUES ('expense.create', 'abandoned', 'fr', NOW(), NOW()) RETURNING id");
        $conn()->executeStatement("INSERT INTO gestion_conversation_message (conversation_id, role, kind, content, created_at) SELECT ?, 'assistant', 'text', '{}', NOW() FROM generate_series(1, ?)", [$filler, GestionConversationService::AI_MESSAGES_PER_DAY]);
        $limited = $this->post('/api/gestion/console/messages', ['text' => 'Une de trop'], 429);
        $this->assertSame(['AI_DAILY_LIMIT', GestionConversationService::AI_MESSAGES_PER_DAY], [$limited['code'], $limited['limit']]);
        $this->assertSame($reservedBefore, $usage(), 'un refus ne réserve aucune unité');
        $conn()->executeStatement('DELETE FROM gestion_conversation WHERE id = ?', [$filler]);
        // … dans les deux sens : les réponses de la console comptent aussi pour la phrase libre d'un fil
        $filler = (int) $conn()->fetchOne("INSERT INTO gestion_conversation (kind, status, locale, created_at, updated_at) VALUES ('console', 'open', 'fr', NOW(), NOW()) RETURNING id");
        $conn()->executeStatement("INSERT INTO gestion_conversation_message (conversation_id, role, kind, content, created_at) SELECT ?, 'assistant', 'answer', '{\"state\":\"done\"}', NOW() FROM generate_series(1, ?)", [$filler, GestionConversationService::AI_MESSAGES_PER_DAY]);
        $this->assertSame('AI_DAILY_LIMIT', $this->post("/api/gestion/conversations/{$thread['id']}/messages", ['text' => 'Une note de 12 dollars'], 429)['code']);
        $conn()->executeStatement('DELETE FROM gestion_conversation WHERE id = ?', [$filler]);

        // Palier d'IA du site, puis accès : administrateur du site seulement
        $master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $master->prepare("DELETE FROM tenant_entitlements WHERE tenant_code = ? AND feature_key IN ('ai.full', 'ai.help')")->execute([MV_TEST_TENANT_CODE]);
        $this->assertSame('AI_NOT_INCLUDED', $this->post('/api/gestion/console/messages', ['text' => 'Bonjour'], 403)['code']);
        AiRightTestHelper::restoreFullAi(static::getContainer());
        $admin = $this->token;
        $this->token = '';
        foreach ([['POST', '/api/gestion/console/messages'], ['GET', '/api/gestion/console/conversations'], ['GET', '/api/gestion/console/analyses'], ['DELETE', '/api/gestion/console/conversations/1']] as [$method, $route]) {
            $this->request($method, $route, $method === 'POST' ? ['text' => 'Bonjour'] : null);
            $this->assertSame(401, $this->client->getResponse()->getStatusCode(), "$method $route sans jeton");
        }
        $this->login(['ROLE_USER']);
        foreach ([['POST', '/api/gestion/console/messages'], ['GET', '/api/gestion/console/analyses']] as [$method, $route]) {
            $this->request($method, $route, $method === 'POST' ? ['text' => 'Bonjour'] : null);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), "$method $route sans le rôle d'administrateur");
        }
        $this->token = $admin;
    }

    public function testEveryAnalysisOfTheCatalogueRunsAndStaysWithinItsLimits(): void
    {
        $tag = $this->tag;
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $supplier = $this->supplier("Grossiste analyses $tag");
        [$product] = $this->article($supplier, "Analyse $tag", 1000, 2500, $calendar->today()->format('Y-m-d'));
        $catalog = static::getContainer()->get(AnalysisCatalog::class);
        $this->em();
        $period = (int) $calendar->current()->getId();
        // Contrôle des paramètres : seuls les paramètres déclarés et vrais sont gardés ; un obligatoire manquant ou faux refuse l'analyse
        $this->assertSame(['periodId' => $period], $catalog->accept('period.result', ['periodId' => $period, 'productId' => $product, 'year' => 2026]));
        $this->assertSame([], $catalog->accept('period.result', ['periodId' => 2147483000]), 'un paramètre facultatif faux est écarté : la période en cours sera prise');
        $this->assertSame([null, null, ['productId' => $product]], [$catalog->accept('articles.vs_similar', []), $catalog->accept('articles.vs_similar', ['productId' => 2147483000]), $catalog->accept('articles.vs_similar', ['productId' => $product])]);
        $this->assertSame([['range' => '7d'], [], ['days' => 30], [], [], ['year' => 2025], []], [$catalog->accept('sales.overview', ['range' => '7d']), $catalog->accept('sales.overview', ['range' => 'siecle']), $catalog->accept('cash.projection', ['days' => 30]),
            $catalog->accept('cash.projection', ['days' => 6]), $catalog->accept('cash.projection', ['days' => '30']), $catalog->accept('year.overview', ['year' => 2025]), $catalog->accept('year.overview', ['year' => 1999])]);
        $this->assertNull($catalog->accept('comptes.clients', []));
        $this->assertFalse($catalog->has('comptes.clients'));
        // Contrôle d'ouverture d'un fil, sans rien créer : celui que passent les propositions
        $threads = static::getContainer()->get(\App\Gestion\Services\Conversation\ActionThreadService::class);
        $this->assertSame([true, false, false, false, true], [$threads->accepts('price.set', ['scope' => ['productIds' => [$product]]], 'fr'), $threads->accepts('price.set', ['inconnu' => 1], 'fr'),
            $threads->accepts('price.set', ['scope' => ['productIds' => [2147483000]]], 'fr'), $threads->accepts('comptes.supprimer', [], 'fr'), $threads->accepts('expense.create', [], 'fr')]);
        $this->assertSame(0, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM gestion_conversation WHERE kind = 'price.set' AND created_at > NOW() - INTERVAL '5 seconds' AND answers::text LIKE ?", ["%$product%"]), 'le contrôle ne crée aucun fil');
        // Un nombre est reconnu par sa valeur, dans toutes ses écritures
        $numbers = fn (string $t): array => \App\Gestion\Services\Console\ConsoleResponder::numbers($t);
        $this->assertSame([[25.0], [25.0], [25.0], [1234.56], [1234.56], [1234.56], [1234.0], [2.7, 90.0], [2026.0, 11.0, 13.0]],
            [$numbers('25,00 $'), $numbers('25 $'), $numbers('25,00'), $numbers('1 234,56 $'), $numbers("1\u{00A0}234,56\u{202F}\$"), $numbers('$1,234.56'), $numbers('1,234 items'), $numbers('2,7 pour 90 %'), $numbers('2026-11-13')]);
        $unknown = fn (string $t, array $allowed): array => \App\Gestion\Services\Console\ConsoleResponder::unknown($t, $allowed);
        $this->assertSame([[], [], [], [26.0], [25.5]], [$unknown('Le prix est de 25 $.', [25.0]), $unknown('Le prix est de 25,00.', [25.0]), $unknown('Soit 1 234,56 $ de ventes', [1234.56]), $unknown('Le prix est de 26 $.', [25.0]), $unknown('Le prix est de 25,50 $.', [25.0])]);

        foreach (array_keys(AnalysisCatalog::DEFINITIONS) as $key) {
            foreach (['fr', 'en'] as $locale) {
                $r = $catalog->run($key, $catalog->accept($key, $key === 'articles.vs_similar' ? ['productId' => $product] : []), $locale);
                $this->assertSame(['key', 'title', 'period', 'figures', 'rows', 'more', 'source'], array_keys($r), $key);
                $this->assertSame([$key, AnalysisCatalog::DEFINITIONS[$key]['screen'], AnalysisCatalog::DEFINITIONS[$key]['route']], [$r['key'], $r['source']['screen'], $r['source']['route']]);
                $this->assertNotSame('console.analyses.' . str_replace('.', '_', $key), $r['title'], "$key : titre traduit en $locale");
                $this->assertLessThanOrEqual(AnalysisCatalog::MAX_FIGURES, count($r['figures']));
                $this->assertLessThanOrEqual(AnalysisCatalog::MAX_ROWS, count($r['rows']));
                $this->assertSame(count($r['figures']), count(array_unique(array_column($r['figures'], 'id'))), "$key : identifiants de chiffres uniques");
                foreach ($r['figures'] as $f) {
                    $this->assertSame(['id', 'label', 'value', 'unit'], array_slice(array_keys($f), 0, 4));
                    $this->assertContains($f['unit'], ['money', 'percent', 'count', 'days', 'date', 'ratio', 'text'], "$key {$f['id']}");
                    $this->assertNotNull($f['value'], "$key {$f['id']} : un chiffre sans valeur n'est pas rendu");
                    $this->assertStringStartsNotWith('console.figures.', $f['label'], "$key {$f['id']} : libellé traduit en $locale");
                }
            }
        }
        // Chaque analyse rend au moins ses chiffres essentiels (lus dans le service de l'écran qu'elle cite)
        $expected = ['period.result' => ['revenue', 'orders', 'cogs', 'expenses', 'grossMargin', 'netResult'], 'sales.overview' => ['revenue', 'grossMargin', 'orders', 'stockValue'],
            'year.overview' => ['sales', 'grossMargin', 'netResult'], 'cash.projection' => ['cashStatus'], 'budget.status' => ['planned', 'actual', 'remaining', 'budgetStatus'],
            'stock.overview' => ['stockSaleValue', 'stockCostValue', 'lowStockItems'], 'articles.missing_cost' => ['missingCostItems'], 'articles.vs_similar' => ['articleName', 'cost', 'price', 'coefficient', 'proposedCoefficient', 'verdict'],
            'articles.new' => ['newItems', 'verdictKeep', 'verdictRaise', 'verdictLower', 'keepUnder'], 'advice.current' => ['adviceCount']];
        foreach ($expected as $key => $ids) {
            $got = array_column($catalog->run($key, $catalog->accept($key, $key === 'articles.vs_similar' ? ['productId' => $product] : []), 'fr')['figures'], 'id');
            $this->assertSame([], array_values(array_diff($ids, $got)), "$key : chiffres manquants (rendus : " . implode(', ', $got) . ')');
        }
        // GARDE-FOU : les analyses, les appels au modèle et l'assemblage de la réponse n'écrivent RIEN dans les données du site. Seul
        // `ConsoleService` écrit, et seulement ses propres échanges (tables du fil) et le journal de leur effacement.
        $root = static::getContainer()->getParameter('kernel.project_dir') . '/src/Gestion/';
        foreach (['Analysis/AnalysisCatalog.php', 'Analysis/SimilarArticlesAnalysis.php', 'Analysis/CoefficientSuggestion.php', 'Services/Console/ConsoleModel.php', 'Services/Console/ConsoleResponder.php'] as $file) {
            $source = (string) file_get_contents($root . $file);
            foreach (['->persist(', '->remove(', '->flush(', 'executeStatement(', 'INSERT INTO', 'DELETE FROM', 'beginTransaction(', '->execute(', '->undo(', '->confirm(', '->put(', '->apply('] as $write) {
                $this->assertStringNotContainsString($write, $source, "$file : « $write » : la console et ses analyses sont en lecture seule");
            }
            $this->assertSame(0, preg_match('/\bUPDATE\s+[a-z_"]+\s+SET\b/i', $source), "$file : aucune écriture SQL");
        }
        $service = (string) file_get_contents($root . 'Services/Console/ConsoleService.php');
        preg_match_all('/(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+"?([a-z_]+)"?\s/i', $service, $m);
        $tables = array_values(array_unique(array_map('strtolower', $m[1])));
        sort($tables);
        $this->assertSame(['gestion_conversation', 'gestion_conversation_message'], $tables, 'ConsoleService n\'écrit que dans les tables de ses échanges');

        // Un article face à ses semblables : les chiffres de l'article et le verdict du serveur
        $figures = array_column($catalog->run('articles.vs_similar', ['productId' => $product], 'fr')['figures'], 'value', 'id');
        $this->assertSame(["Analyse $tag", 1000, 2500, 2.5], [$figures['articleName'], $figures['cost'], $figures['price'], $figures['coefficient']]);
        $this->assertContains($figures['verdict'], ['keep', 'raise', 'lower']);
        // Ventes : les comparaisons sont fournies par l'analyse
        $sales = array_column($catalog->run('sales.overview', ['range' => 'month'], 'fr')['figures'], null, 'id');
        $this->assertArrayHasKey('revenue', $sales);
        $this->assertSame('money', $sales['revenue']['unit']);
    }

    // ---- outils --------------------------------------------------------------------------------------------------------------------

    /** Réponse du faux modèle, en sortie structurée */
    private function reply(array $output): object
    {
        return json_decode(json_encode([
            'id' => 'msg_' . bin2hex(random_bytes(4)), 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => json_encode($output)]],
            'usage' => ['input_tokens' => 3000, 'output_tokens' => 500, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
        ]), false);
    }

    private function runWorker(int $messageId): void
    {
        static::getContainer()->get(AnswerConsoleQuestionHandler::class)(new AnswerConsoleQuestionMessage($messageId, MV_TEST_TENANT_CODE));
        $this->em()->clear();
        $this->endOfWrite();
    }

    private function body(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function request(string $method, string $route, ?array $body = null): void
    {
        $this->client->request($method, $route, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'] + ($this->token !== '' ? ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token] : []), $body === null ? null : json_encode($body));
    }

    private function get(string $route): array
    {
        $this->request('GET', $route);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 500));

        return $this->body();
    }

    private function post(string $route, array $body, int $expected): array
    {
        $this->request('POST', $route, $body);
        $this->assertSame($expected, $this->client->getResponse()->getStatusCode(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 800));

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

    private function login(array $roles): void
    {
        $email = 'gestion-console-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('Console')->setRoles($roles)->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->fixtureUserId = $this->fixtureUserId ?: (int) $user->getId();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
