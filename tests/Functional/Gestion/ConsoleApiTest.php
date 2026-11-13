<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Gestion\Analysis\AnalysisCatalog;
use App\Gestion\Analysis\RangeLabel;
use App\Gestion\Message\AnswerConsoleQuestionMessage;
use App\Gestion\MessageHandler\AnswerConsoleQuestionHandler;
use App\Gestion\Services\Console\ConsoleResponder;
use App\Gestion\Services\Console\ConsoleScreens;
use App\Gestion\Services\Console\ConsoleService;
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
use Symfony\Contracts\Translation\TranslatorInterface;

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
        // Lot W2-b : le chiffre dont le texte parle (« a1.newItems ») reçoit sa carte à la suite du texte, ajoutée par le serveur
        $this->assertSame(['text', 'figures', 'verdicts', 'figures', 'table'], array_column($answer['blocks'], 'type'), 'le tableau d\'une analyse qui n\'a pas été lancée est écarté');
        $this->assertSame(['a1.newItems'], array_column($answer['blocks'][1]['items'], 'id'), 'la carte du chiffre cité par le texte');
        $this->assertSame($newItems, $answer['blocks'][1]['items'][0]['value']);
        $this->assertSame(['type' => 'text', 'text' => "Vous avez $newItems nouveaux articles sur la période. Voici leur bilan.", 'figureIds' => ['a1.newItems']], $answer['blocks'][0], 'un identifiant de chiffre inconnu est retiré');
        // Verdicts : ceux du serveur (règle du coefficient), jamais ceux du modèle ; une explication qui porte un nombre inconnu est remplacée par celle du serveur
        $verdicts = array_column($answer['blocks'][2]['items'], null, 'verdict');
        $this->assertSame(['raise', 'keep', 'lower'], array_column($answer['blocks'][2]['items'], 'verdict'));
        $this->assertSame(['type', 'items', 'groups', 'source'], array_keys($answer['blocks'][2]), 'le bloc des verdicts porte ses groupes (lot W2-b)');
        $this->assertSame([['type' => 'product', 'id' => $raise, 'name' => "000 Console monter $tag"], 'Ses semblables se sont vendus à 90 % sans rabais.', ['a1.verdictRaise']], [$verdicts['raise']['subject'], $verdicts['raise']['why'], $verdicts['raise']['figureIds']]);
        $this->assertSame('Son prix est déjà au bon niveau.', $verdicts['keep']['why']);
        $this->assertStringStartsWith('2,7 : 5 semblables de ce fournisseur affichés à 2,5, vendus à 90 %', $verdicts['lower']['why'], '« 17 % » n\'est dans aucun résultat : l\'explication chiffrée du serveur prend sa place');
        // Chiffres et tableau : assemblés par le serveur, avec leur source
        $this->assertSame(['a1.verdictRaise', 'a1.verdictLower'], array_column($answer['blocks'][3]['items'], 'id'));
        $this->assertSame(['id', 'label', 'value', 'unit', 'term', 'source'], array_keys($answer['blocks'][3]['items'][0]));
        $this->assertSame('verdict', $answer['blocks'][3]['items'][0]['term'], 'chaque chiffre porte la notion du lexique qui l\'explique (lot W2-APPRENDRE)');
        $this->assertSame(['screen' => 'articles', 'route' => null, 'params' => ['periodId' => $period]], $answer['blocks'][3]['items'][0]['source']);
        $rows = array_column($answer['blocks'][4]['rows'], null, 'productId');
        $this->assertArrayNotHasKey($history[0][0], $rows, 'un article acheté la saison passée n\'est pas un article nouveau de la période');
        // Lot W2-d : le tableau rend ses colonnes libellées et ses cellules rédigées (« À monter »), le code à part (`verdictCode`)
        $this->assertSame([[1000, 2500, 2.5, 2.7, 'similar', 'À monter', 'raise', 5], [1000, 2700, 2.7, 2.7, 'similar', 'À garder', 'keep', 5], [1000, 3200, 3.2, 2.7, 'similar', 'À baisser', 'lower', 5]],
            array_map(fn (int $p) => [$rows[$p]['cost'], $rows[$p]['price'], $rows[$p]['coefficient'], $rows[$p]['proposedCoefficient'], $rows[$p]['proposalSource'], $rows[$p]['verdict'], $rows[$p]['verdictCode'], $rows[$p]['similarCount']], [$raise, $keep, $lower]));
        $this->assertSame([['key' => 'name', 'label' => 'Article', 'unit' => 'text'], ['key' => 'cost', 'label' => 'Coût d\'achat', 'unit' => 'money']], array_slice($answer['blocks'][4]['columns'], 0, 2));
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
        $this->assertSame(['10,00 $', '25,00 $', 'à monter'], [$plain($sent['cost']), $plain($sent['price']), $sent['verdict']], 'le modèle lit le verdict rédigé, jamais son code (lot W2-c), en minuscule pour l\'écrire dans une phrase (lot W2-d)');
        $first = json_decode(FakeLandingAiClient::$requests[0]['messages'][0]['content'][0]['text'], true);
        // Lot W2-b : l'écran est traduit par le serveur (titre, thème, analyses par défaut avec les paramètres de l'écran)
        $this->assertSame(['Fais le bilan de mes nouveaux articles', ['code' => 'articles', 'titre' => 'Articles', 'theme' => 'Achats et stock', 'params' => ['periodId' => $period],
            'analysesParDefaut' => [['key' => 'articles.missing_cost'], ['key' => 'articles.new', 'periodId' => $period]]], [], 12], [$first['question'], $first['ecran'], $first['echange'], count($first['analyses'])]);
        $this->assertSame(['titre' => 'Articles', 'theme' => 'Achats et stock'], $second['ecran'], 'l\'appel 2 peut nommer l\'écran');
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

        $given = fn (string $key): array => match ($key) { 'articles.vs_similar' => ['productId' => $product], 'lexique.term' => ['term' => 'marge'], 'screen.guide' => ['screen' => 'tresorerie'], default => [] };
        foreach (array_keys(AnalysisCatalog::DEFINITIONS) as $key) {
            foreach (['fr', 'en'] as $locale) {
                $r = $catalog->run($key, $catalog->accept($key, $given($key)), $locale);
                $this->assertSame(['key', 'title', 'period', 'figures', 'rows', 'groups', 'lesson', 'guide', 'more', 'source'], array_keys($r), $key);
                // la leçon d'une notion garde la source de l'analyse qui calcule ses chiffres (ici la marge : les statistiques)
                $from = $key === 'lexique.term' ? 'sales.overview' : $key;
                $this->assertSame([$key, AnalysisCatalog::DEFINITIONS[$from]['screen'], AnalysisCatalog::DEFINITIONS[$from]['route'], $key === 'lexique.term'], [$r['key'], $r['source']['screen'], $r['source']['route'], $r['lesson'] !== null]);
                $this->assertSame($key === 'screen.guide', $r['guide'] !== null, $key);
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
            'articles.new' => ['newItems', 'verdictKeep', 'verdictRaise', 'verdictLower', 'keepUnder'], 'advice.current' => ['adviceCount'], 'lexique.term' => ['grossMargin', 'revenue']];
        foreach ($expected as $key => $ids) {
            $got = array_column($catalog->run($key, $catalog->accept($key, $given($key)), 'fr')['figures'], 'id');
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
        $this->assertContains($figures['verdict'], ['À garder', 'À monter', 'À baisser'], 'un état se lit rédigé (lot W2-c)');
        $this->assertContains(array_column($catalog->run('articles.vs_similar', ['productId' => $product], 'fr')['figures'], 'code', 'id')['verdict'], ['keep', 'raise', 'lower'], '… et garde son code à part');
        // Ventes : les comparaisons sont fournies par l'analyse
        $sales = array_column($catalog->run('sales.overview', ['range' => 'month'], 'fr')['figures'], null, 'id');
        $this->assertArrayHasKey('revenue', $sales);
        $this->assertSame('money', $sales['revenue']['unit']);
    }

    /**
     * Lot W2-b (13/11/2026, retouches après le premier essai réel de la console) : l'écran d'où vient la question est traduit par le
     * serveur (codes de l'application web, thème, analyses par défaut, paramètres) ; une question qui dit « période » lit les ventes sur
     * la période ; les libellés de plage sont rédigés et ceux des chiffres disent ce qu'ils comptent ; tout chiffre dont un texte parle
     * a sa carte à la suite ; un bilan d'articles porte ses groupes comptés par le serveur ; unités : 1 pour un administrateur du site,
     * 0 pour le propriétaire de la plateforme.
     */
    public function testScreensPeriodLabelsCitedFiguresGroupsAndUnits(): void
    {
        $tag = $this->tag;
        $conn = fn () => $this->em()->getConnection();
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $current = $calendar->current();
        $period = (int) $current->getId();
        $periodName = PeriodCalendar::displayName($current, 'fr');
        $today = $calendar->today()->format('Y-m-d');
        $usage = fn (): array => array_column($conn()->fetchAllAssociative('SELECT status, COUNT(*) AS n FROM ai_usage WHERE id > ? GROUP BY status ORDER BY status', [$this->usageMark]), 'n', 'status');
        $none = ['periodId' => null, 'productId' => null, 'year' => null, 'days' => null, 'range' => null];
        $text = fn (string $t, array $ids = []): array => ['type' => 'text', 'text' => $t, 'figureIds' => $ids, 'analysis' => '', 'items' => []];
        $payload = fn (int $i): array => json_decode(FakeLandingAiClient::$requests[$i]['messages'][0]['content'][0]['text'], true);
        $system = fn (int $i): string => (string) FakeLandingAiClient::$requests[$i]['system'];
        $money = fn (int $cents): string => number_format($cents / 100, 2, ',', ' ') . ' $';
        $last = fn (int $id): array => array_slice($this->get("/api/gestion/console/conversations/$id")['messages'], -1)[0];
        $ran = fn (array $answer): array => array_map(fn (array $x) => [$x['key'], $x['params']], $answer['analyses']);

        // Cinq articles semblables vendus à 90 % la saison comparable (le serveur propose 2,7) ; trois articles nouveaux : deux à garder, un à monter, vendu aujourd'hui
        $season = PeriodCalendar::definition(\DateTimeImmutable::createFromInterface($current->getStartDateCollection())->modify('-1 year'), $calendar->frequency());
        $supplier = $this->supplier("Grossiste écrans $tag");
        foreach (range(1, 5) as $i) {
            [, $variant] = $this->article($supplier, "Historique écrans $i $tag", 1000, 2500, $season['start']->modify('+2 days')->format('Y-m-d'));
            $this->sale($variant, 9, 2500, 2500, $season['start']->modify('+16 days')->format('Y-m-d'));
        }
        [$keep1] = $this->article($supplier, "000 Écrans garder un $tag", 1000, 2700, $today);
        [$keep2] = $this->article($supplier, "000 Écrans garder deux $tag", 1000, 2700, $today);
        [$raise, $raiseVariant] = $this->article($supplier, "000 Écrans monter $tag", 1000, 2500, $today);
        $this->sale($raiseVariant, 2, 2500, 2500, $today);
        $commande = (int) $this->fixtureCommandeIds[0];
        $this->endOfWrite();

        // ---- 1. La table des écrans ---------------------------------------------------------------------------------------------------
        // Les codes de l'application web (relevés dans son code le 13/11/2026), un de chaque thème et tous les autres
        $web = ['gestion.home', 'sequences', 'sequences.detail', 'conseils', 'conversations', 'conversation', 'theme',
            'commandes', 'a-recevoir', 'a-classer', 'fournisseurs', 'frais-de-port', 'transporteurs', 'voyages', 'voyages.suivi', 'articles', 'documents.scan',
            'prix', 'promotions', 'locations.prix', 'locations', 'publicite', 'publicite.campagne', 'campagnes.new',
            'tresorerie', 'frais-fixes', 'notes-de-frais',
            'periodes', 'periode', 'periode.budget', 'periode.simulation', 'preparation', 'statistiques', 'statistiques.annee',
            'reglages', 'reprise'];
        $catalog = static::getContainer()->get(AnalysisCatalog::class);
        $translator = static::getContainer()->get(TranslatorInterface::class);
        $this->assertSame([], array_values(array_diff($web, array_keys(ConsoleScreens::SCREENS))), 'chaque code de l\'application web est connu');
        foreach (array_keys(ConsoleScreens::SCREENS) as $code) {
            $this->assertTrue(ConsoleScreens::known($code));
            $this->assertContains(ConsoleScreens::theme($code, []), ConsoleScreens::THEMES, $code);
            $defaults = ConsoleScreens::defaults($code, []);
            $this->assertNotSame([], $defaults, "$code : au moins les analyses de son thème");
            foreach ($defaults as $d) {
                $this->assertTrue($catalog->has($d['key']), "$code : {$d['key']} est une analyse du catalogue");
            }
            foreach (['fr', 'en'] as $locale) {
                $id = 'console.screens.' . str_replace(['.', '-'], '_', $code);
                $this->assertNotSame($id, $translator->trans($id, [], 'gestion', $locale), "$code : titre de l'écran en $locale");
            }
        }
        foreach (ConsoleScreens::THEMES as $theme) {
            $this->assertNotSame("console.themes.$theme", $translator->trans("console.themes.$theme", [], 'gestion', 'en'));
            $this->assertNotSame([], ConsoleScreens::THEME_ANALYSES[$theme]);
        }
        $this->assertSame(['accueil', 'achats', 'ventes', 'argent', 'resultats', 'reglages'], array_map(fn (string $c) => ConsoleScreens::theme($c, []), ['gestion.home', 'fournisseurs', 'promotions', 'tresorerie', 'periode', 'reprise']), 'un code de chaque thème');
        $this->assertFalse(ConsoleScreens::known('ecran.inconnu'));
        $this->assertFalse(ConsoleScreens::known(12));
        // Un écran sans analyse propre reçoit celles de son thème ; la page d'un thème, celles du thème qu'elle montre
        $keys = fn (string $code, array $params = []): array => array_column(ConsoleScreens::defaults($code, $params), 'key');
        $this->assertSame([['budget.status', 'articles.new', 'articles.missing_cost'], ['budget.status', 'articles.new', 'articles.missing_cost'], ['cash.projection', 'budget.status'], ['period.result', 'advice.current'], ['cash.projection']],
            [$keys('fournisseurs'), $keys('transporteurs'), $keys('theme', ['theme' => 'argent']), $keys('theme'), $keys('tresorerie')]);
        $this->assertSame(['argent', 'accueil'], [ConsoleScreens::theme('theme', ['theme' => 'argent']), ConsoleScreens::theme('theme', [])]);
        // Les paramètres de l'écran passent aux analyses qui les déclarent ; une période l'emporte sur une plage ; une plage que l'analyse ne connaît pas est écartée
        $this->assertSame([[['key' => 'sales.overview', 'params' => ['range' => '7d']]], [['key' => 'sales.overview', 'params' => ['periodId' => 5]]], [['key' => 'sales.overview', 'params' => []]], [['key' => 'year.overview', 'params' => ['year' => 2025]]]],
            [ConsoleScreens::defaults('statistiques', ['range' => '7d', 'source' => 'pos']), ConsoleScreens::defaults('statistiques', ['range' => 'period', 'periodId' => 5]), ConsoleScreens::defaults('statistiques', ['range' => 'dates']),
                ConsoleScreens::defaults('statistiques.annee', ['year' => 2025, 'pricePercent' => 5])]);
        $this->assertSame([['range' => 'today'], [['key' => 'period.result', 'params' => ['periodId' => 7]], ['key' => 'sales.overview', 'params' => ['periodId' => 7]]]], [ConsoleScreens::defaults('gestion.home', [])[0]['params'], ConsoleScreens::defaults('periode', ['periodId' => 7])]);
        $this->assertSame([['key' => 'sales.overview', 'params' => ['periodId' => 5]]], ConsoleScreens::defaults('statistiques', ['range' => '7d', 'periodId' => 5]), 'une plage ET une période : la période seule');

        // Le contexte gardé avec la question : paramètres déclarés pour l'écran et bien formés ; identifiants du site vérifiés ; le reste est écarté
        $this->em();
        $console = static::getContainer()->get(ConsoleService::class);
        $ctx = fn (string $screen, mixed $params = []): ?array => $console->context(['module' => 'gestion', 'screen' => $screen, 'params' => $params]);
        $kept = fn (string $screen, mixed $params = []): ?array => $ctx($screen, $params)['params'] ?? null;
        $this->assertSame(['module' => 'gestion', 'screen' => 'gestion.home', 'params' => []], $ctx('gestion.home', ['periodId' => 2147483000, 'theme' => 'ventes', 'autre' => 'x']), 'période inexistante, paramètre d\'un autre écran, paramètre inconnu : écartés');
        $this->assertSame([['theme' => 'ventes'], []], [$kept('theme', ['theme' => 'ventes']), $kept('theme', ['theme' => 'comptes'])]);
        $this->assertSame([['periodId' => $period, 'commandeId' => $commande], ['periodId' => $period]], [$kept('commandes', ['commandeId' => $commande, 'periodId' => $period]), $kept('commandes', ['periodId' => $period, 'commandeId' => 2147483000])]);
        $this->assertSame([['range' => 'period', 'source' => 'pos', 'periodId' => $period], []], [$kept('statistiques', ['periodId' => $period, 'source' => 'pos', 'range' => 'period']), $kept('statistiques', ['range' => 'siecle', 'source' => 'caisse', 'periodId' => '12'])]);
        $this->assertSame([['filter' => 'missing-cost', 'from' => '2026-01-01'], ['type' => 'facture'], ['year' => 2026, 'pricePercent' => 5.5], ['key' => 'period.prepare', 'instance' => '2026-Q4']], [
            $kept('articles', ['filter' => 'missing-cost', 'from' => '2026-01-01', 'to' => '2026-13-45']), $kept('documents.scan', ['type' => 'facture', 'documentId' => 'abc']),
            $kept('statistiques.annee', ['year' => 2026, 'pricePercent' => 5.5]), $kept('sequences.detail', ['key' => 'period.prepare', 'instance' => '2026-Q4', 'page' => 2])]);
        $this->assertSame([[], [], [], [], []], [$kept('tresorerie'), $kept('reglages', 'pas un tableau'), $kept('documents.scan', ['documentId' => 0, 'type' => 'devis']), $kept('sequences.detail', ['key' => 'a b', 'instance' => str_repeat('x', 61)]),
            $kept('statistiques.annee', ['year' => 1999, 'pricePercent' => '5'])]);
        $this->assertSame([null, null, null], [$console->context(['module' => 'boutique', 'screen' => 'tresorerie']), $ctx('ecran.inconnu'), $console->context('gestion.home')], 'autre module, code inconnu, contexte mal formé : ignorés, sans erreur');
        // Les codes du premier contrat restent acceptés
        $this->assertSame([['productId' => $raise], [['key' => 'articles.vs_similar', 'params' => ['productId' => $raise]]]], [$kept('article', ['productId' => $raise]), ConsoleScreens::defaults('article', ['productId' => $raise])]);

        // ---- 2. Libellés de plage rédigés par le serveur --------------------------------------------------------------------------------
        $this->assertSame(['le 13 novembre 2026', 'du 1er au 13 novembre 2026', 'du 28 octobre au 13 novembre 2026', 'du 15 décembre 2025 au 13 janvier 2026'],
            [RangeLabel::of('2026-11-13', '2026-11-13', 'fr'), RangeLabel::of('2026-11-01', '2026-11-13', 'fr'), RangeLabel::of('2026-10-28', '2026-11-13', 'fr'), RangeLabel::of('2025-12-15', '2026-01-13', 'fr')]);
        $this->assertSame(['November 13, 2026', 'November 1 to 13, 2026', 'October 28 to November 13, 2026', 'December 15, 2025 to January 13, 2026'],
            [RangeLabel::of('2026-11-13', '2026-11-13', 'en'), RangeLabel::of('2026-11-01', '2026-11-13', 'en'), RangeLabel::of('2026-10-28', '2026-11-13', 'en'), RangeLabel::of('2025-12-15', '2026-01-13', 'en')]);

        // ---- 3. « Comment se passe la période en cours ? » depuis l'écran d'une période ---------------------------------------------------
        $result = array_column($catalog->run('period.result', ['periodId' => $period], 'fr')['figures'], null, 'id');
        $budget = array_column($catalog->run('budget.status', ['periodId' => $period], 'fr')['figures'], null, 'id');
        $this->em();
        $this->assertGreaterThan(0, $result['revenue']['value']);
        $this->assertGreaterThan(0, $budget['actual']['value']);
        $this->assertNotSame($result['revenue']['value'], $budget['actual']['value']);
        // Les libellés des chiffres disent ce qu'ils comptent : frais hors achats d'un côté, dépenses réelles achats compris de l'autre
        $this->assertSame(['Frais de la période (port, notes de frais, publicité), hors achats de marchandise', 'Budget prévu, achats de marchandise compris', 'Dépensé (réel), achats de marchandise compris', 'Reste du budget'],
            [$result['expenses']['label'], $budget['planned']['label'], $budget['actual']['label'], $budget['remaining']['label']]);
        $english = array_column($catalog->run('budget.status', ['periodId' => $period], 'en')['figures'], 'label', 'id') + array_column($catalog->run('period.result', ['periodId' => $period], 'en')['figures'], 'label', 'id');
        $this->em();
        $this->assertSame(['Period expenses (shipping, expense reports, advertising), excluding merchandise purchases', 'Spent (actual), merchandise purchases included'], [$english['expenses'], $english['actual']]);

        $screen = ['module' => 'gestion', 'screen' => 'periode', 'params' => ['periodId' => $period, 'autre' => 1]];
        $a = $this->post('/api/gestion/console/messages', ['text' => 'Comment se passe la période en cours ?', 'context' => $screen], 202);
        $id = (int) $a['conversationId'];
        $this->assertSame(['reserved' => 1], $usage(), 'administrateur du site : 1 unité réservée');
        $this->assertSame(['module' => 'gestion', 'screen' => 'periode', 'params' => ['periodId' => $period]], $this->get("/api/gestion/console/conversations/$id")['messages'][0]['context'], 'le contexte apparaît dans le message');
        // Le modèle choisit les ventes sur le MOIS et laisse les périodes vides ; il parle d'un chiffre sans le citer, et d'un autre qu'il montre lui-même
        FakeLandingAiClient::$queue = [
            $this->reply(['kind' => 'analyses', 'clarification' => '', 'analyses' => [['key' => 'period.result'] + $none, ['key' => 'sales.overview', 'range' => 'month'] + $none, ['key' => 'budget.status'] + $none]]),
            $this->reply(['blocks' => [
                $text('Les ventes de la période sont de ' . $money($result['revenue']['value']) . '.'),
                $text('Les dépenses réelles du budget sont de ' . $money($budget['actual']['value']) . ', achats de marchandise compris.', ['a3.actual']),
                ['type' => 'figures', 'text' => '', 'figureIds' => ['a3.actual'], 'analysis' => '', 'items' => []],
            ], 'proposals' => []]),
        ];
        $this->runWorker((int) $a['messageId']);
        $answer = $last($id);
        $this->assertSame('done', $answer['state'], json_encode($answer, JSON_UNESCAPED_UNICODE));
        $this->assertSame([['period.result', ['periodId' => $period]], ['sales.overview', ['periodId' => $period]], ['budget.status', ['periodId' => $period]]], $ran($answer),
            'la période de l\'écran complète ce que le modèle laisse vide ; la question dit « période » : les ventes sont lues sur la période, pas sur le mois');
        $this->assertSame($periodName, $answer['analyses'][1]['period']['label'], 'une période se nomme');
        $this->assertMatchesRegularExpression('/^du .+ au .+ \d{4}$/u', $answer['analyses'][1]['period']['previousLabel'], 'la plage comparée est rédigée');
        // Tout chiffre dont un texte parle a sa carte à la suite : celui que le modèle n'a pas cité est retrouvé par sa valeur ; celui qu'un bloc `figures` montre déjà n'est pas doublé
        $this->assertSame(['text', 'figures', 'text', 'figures'], array_column($answer['blocks'], 'type'));
        $this->assertSame([['a1.revenue'], ['a1.revenue'], ['a3.actual'], ['a3.actual']],
            [$answer['blocks'][0]['figureIds'], array_column($answer['blocks'][1]['items'], 'id'), $answer['blocks'][2]['figureIds'], array_column($answer['blocks'][3]['items'], 'id')]);
        $this->assertSame([$result['revenue']['value'], 'Dépensé (réel), achats de marchandise compris'], [$answer['blocks'][1]['items'][0]['value'], $answer['blocks'][3]['items'][0]['label']]);
        // Ce que le modèle a lu : l'écran traduit par le serveur à l'appel 1 ; à l'appel 2 son titre, la période nommée, les plages comparées rédigées
        $first = $payload(0);
        $second = $payload(1);
        $this->assertSame(['code' => 'periode', 'titre' => 'Détail d\'une période', 'theme' => 'Périodes et résultats', 'params' => ['periodId' => $period],
            'analysesParDefaut' => [['key' => 'period.result', 'periodId' => $period], ['key' => 'sales.overview', 'periodId' => $period]]], $first['ecran']);
        $this->assertSame(['titre' => 'Détail d\'une période', 'theme' => 'Périodes et résultats'], $second['ecran']);
        $this->assertSame([$periodName, 'Dépensé (réel), achats de marchandise compris'], [$second['resultats'][1]['period'], array_column($second['resultats'][2]['figures'], 'label', 'id')['a3.actual']]);
        $this->assertMatchesRegularExpression('/^du .+ au .+ \d{4}$/u', $second['resultats'][1]['previousLabel']);
        foreach (['qui dit « période »', '« analysesParDefaut »', '« range » ne sert que si la question nomme une durée'] as $rule) {
            $this->assertStringContainsString($rule, $system(0), 'consigne de l\'appel 1');
        }
        foreach (['ne les rapproche jamais sans dire ce que chacun compte', '« groupes » réunit les articles de même verdict', 'CHAQUE chiffre dont le texte parle', 'recopié tel quel'] as $rule) {
            $this->assertStringContainsString($rule, $system(1), 'consigne de l\'appel 2');
        }
        $this->assertSame(['success' => 1], $usage(), 'administrateur du site : une réponse rédigée = 1 unité');
        $this->assertSame(1, (int) $conn()->fetchOne("SELECT COALESCE(SUM(credits), 0) FROM ai_usage WHERE id > ? AND status = 'success'", [$this->usageMark]));

        // Une durée nommée (« ce mois ») garde la plage choisie, même depuis l'écran d'une période et même si la question dit aussi « période » ; son libellé est rédigé, jamais « 1-13 nov. »
        $b = $this->post('/api/gestion/console/messages', ['text' => 'Et les ventes de ce mois, dans la période ?', 'conversationId' => $id, 'context' => $screen], 202);
        FakeLandingAiClient::$queue = [$this->reply(['kind' => 'analyses', 'clarification' => '', 'analyses' => [['key' => 'sales.overview', 'range' => 'month'] + $none]]), $this->reply(['blocks' => [$text('Voici les ventes du mois.')], 'proposals' => []])];
        $this->runWorker((int) $b['messageId']);
        $month = $last($id);
        $this->assertSame([['sales.overview', ['range' => 'month']]], $ran($month));
        $range = $month['analyses'][0]['period'];
        $this->assertSame([substr($today, 0, 8) . '01', $today, RangeLabel::of($range['from'], $range['to'], 'fr')], [$range['from'], $range['to'], $range['label']]);
        $this->assertMatchesRegularExpression('/^(le|du) .+ \d{4}$/u', $range['label']);
        $this->assertDoesNotMatchRegularExpression('/\d-\d|\./', $range['label'], 'jamais la forme brute « 1-13 nov. »');
        $this->assertSame($range['label'], $payload(3)['resultats'][0]['period'], 'le modèle reçoit le libellé rédigé');

        // Sans écran : « période » dans la question, aucune période choisie par le modèle : la période en cours
        $c = $this->post('/api/gestion/console/messages', ['text' => 'Où en sont les ventes de la période ?'], 202);
        FakeLandingAiClient::$queue = [$this->reply(['kind' => 'analyses', 'clarification' => '', 'analyses' => [['key' => 'sales.overview'] + $none]]), $this->reply(['blocks' => [$text('Voici les ventes de la période.')], 'proposals' => []])];
        $this->runWorker((int) $c['messageId']);
        $this->assertSame([[['sales.overview', ['periodId' => $period]]], null], [$ran($last((int) $c['conversationId'])), $payload(4)['ecran']]);
        $this->assertArrayNotHasKey('ecran', $payload(5), 'sans écran connu, l\'appel 2 n\'en reçoit pas');

        // ---- 4. Bilan d'articles : les groupes (même verdict, même raison), comptés par le serveur ---------------------------------------
        $d = $this->post('/api/gestion/console/messages', ['text' => 'Fais le bilan des nouveaux articles', 'context' => ['module' => 'gestion', 'screen' => 'prix', 'params' => ['adviceId' => 3]]], 202);
        FakeLandingAiClient::$queue = [$this->reply(['kind' => 'analyses', 'clarification' => '', 'analyses' => [['key' => 'articles.new'] + $none]]), $this->reply(['blocks' => [
            $text('Voici le bilan, groupe par groupe.'),
            ['type' => 'verdicts', 'text' => '', 'figureIds' => [], 'analysis' => 'a1', 'items' => array_map(fn (int $p) => ['id' => $p, 'why' => 'Vu avec ses semblables.', 'figureIds' => []], [$keep1, $keep2, $raise])],
        ], 'proposals' => []])];
        $this->runWorker((int) $d['messageId']);
        $review = $last((int) $d['conversationId']);
        $this->assertSame(['text', 'verdicts'], array_column($review['blocks'], 'type'));
        $groups = $review['blocks'][1]['groups'];
        $newItems = array_column($catalog->run('articles.new', ['periodId' => $period], 'fr')['figures'], 'value', 'id')['newItems'];
        $this->em();
        foreach ($groups as $g) {
            $this->assertSame(['verdict', 'source', 'count', 'label', 'productIds'], array_keys($g));
            $this->assertSame($g['count'], count($g['productIds']), 'le nombre du groupe est celui de ses articles');
        }
        $this->assertSame($newItems, array_sum(array_column($groups, 'count')), 'chaque article nouveau est dans un groupe, un seul');
        $of = fn (int $product): array => array_values(array_filter($groups, fn (array $g) => in_array($product, $g['productIds'], true)))[0];
        $keepGroup = $of($keep1);
        $raiseGroup = $of($raise);
        $this->assertSame(['keep', 'similar', sprintf('%d articles au coefficient de leurs semblables : à garder', $keepGroup['count'])], [$keepGroup['verdict'], $keepGroup['source'], $keepGroup['label']], 'l\'intertitre est une phrase complète (lot W2-c)');
        $this->assertContains($keep2, $keepGroup['productIds'], 'les deux articles à garder pour la même raison sont réunis');
        $this->assertGreaterThanOrEqual(2, $keepGroup['count']);
        $this->assertSame(['raise', 'similar', $raiseGroup['count'] > 1 ? sprintf('%d articles sous le coefficient de leurs semblables : à monter', $raiseGroup['count']) : '1 article sous le coefficient de ses semblables : à monter'], [$raiseGroup['verdict'], $raiseGroup['source'], $raiseGroup['label']]);
        $rank = array_map(fn (array $g) => ['raise' => 0, 'lower' => 1, 'keep' => 2, '' => 3][(string) $g['verdict']], $groups);
        $sorted = $rank;
        sort($sorted);
        $this->assertSame($sorted, $rank, 'à monter, à baisser, à garder, sans verdict');
        // Le modèle reçoit les groupes avec leur nombre : il n'a rien à compter
        $sent = $payload(7)['resultats'][0]['groupes'];
        $this->assertSame(count($groups), count($sent));
        $sentKeep = array_values(array_filter($sent, fn (array $g) => in_array("000 Écrans garder un $tag", $g['articles'], true)))[0];
        $this->assertSame(['verdict', 'raison', 'nombre', 'articles'], array_keys($sentKeep));
        $this->assertSame(['keep', $keepGroup['label'], (string) $keepGroup['count']], [$sentKeep['verdict'], $sentKeep['raison'], $sentKeep['nombre']]);
        $this->assertLessThanOrEqual(AnalysisCatalog::GROUP_NAMES, count($sentKeep['articles']));
        $this->assertSame(['module' => 'gestion', 'screen' => 'prix', 'params' => ['adviceId' => 3]], $this->get("/api/gestion/console/conversations/{$d['conversationId']}")['messages'][0]['context']);
        $englishGroups = $catalog->run('articles.new', ['periodId' => $period], 'en')['groups'];
        $this->em();
        $this->assertContains(sprintf('%d items at the coefficient of their similar items: keep', $keepGroup['count']), array_column($englishGroups, 'label'));
        $this->assertSame(['success' => 4], $usage(), 'quatre réponses rédigées pour l\'administrateur du site : 4 unités');

        // Une AUTRE période que la période en cours : celle de l'écran complète un choix laissé vide ; pour une question qui dit « période »,
        // les ventes prennent la période des autres analyses de la réponse, à défaut celle de l'écran
        $other = (int) $conn()->fetchOne("INSERT INTO collections (id, budget_collection, start_date_collection, end_date_collection, del, nom_collection, is_closed, kind, period_key) VALUES (nextval('collections_id_seq'), 0, NOW() + INTERVAL '800 days', NOW() + INTERVAL '830 days', false, ?, false, 'period', ?) RETURNING id", ["Période à venir $tag", "C-$tag"]);
        $this->endOfWrite();
        $otherScreen = ['module' => 'gestion', 'screen' => 'periode', 'params' => ['periodId' => $other]];
        $run = function (string $question, ?array $context, array $analyses) use ($text, $last, $ran): array {
            $asked = $this->post('/api/gestion/console/messages', ['text' => $question] + ($context === null ? [] : ['context' => $context]), 202);
            FakeLandingAiClient::$queue = [$this->reply(['kind' => 'analyses', 'clarification' => '', 'analyses' => $analyses]), $this->reply(['blocks' => [$text('Voici.')], 'proposals' => []])];
            $this->runWorker((int) $asked['messageId']);

            return $ran($last((int) $asked['conversationId']));
        };
        $this->assertSame([['period.result', ['periodId' => $other]]], $run('Explique-moi ce résultat', $otherScreen, [['key' => 'period.result'] + $none]), 'la période de l\'écran, pas la période en cours');
        $this->assertSame([['period.result', ['periodId' => $other]], ['sales.overview', ['periodId' => $other]]],
            $run('Compare les ventes de cette période', null, [['key' => 'period.result', 'periodId' => $other] + $none, ['key' => 'sales.overview', 'range' => 'month'] + $none]), 'la période des autres analyses de la réponse');
        $this->assertSame([['sales.overview', ['periodId' => $other]]], $run('Et les ventes de la période ?', $otherScreen, [['key' => 'sales.overview', 'range' => 'month'] + $none]), 'à défaut, la période de l\'écran');
        $conn()->executeStatement('DELETE FROM collections WHERE id = ?', [$other]);
        $this->endOfWrite();

        // ---- 5. Un chiffre retrouvé par sa valeur : seul candidat, ou celui dont le libellé est dans le texte ; sinon aucun -----------------
        $responder = static::getContainer()->get(ConsoleResponder::class);
        $method = new \ReflectionMethod(ConsoleResponder::class, 'citedByValue');
        $method->setAccessible(true);
        $figures = [['figures' => [['id' => 'a1.sales', 'label' => 'Ventes', 'value' => 1100, 'unit' => 'count'], ['id' => 'a1.orders', 'label' => 'Commandes', 'value' => 1100, 'unit' => 'count'],
            ['id' => 'a1.day', 'label' => 'Date du solde', 'value' => '2026-11-13', 'unit' => 'date'], ['id' => 'a1.margin', 'label' => 'Marge brute', 'value' => 43600, 'unit' => 'money'],
            ['id' => 'a1.note', 'label' => 'Note du mois', 'value' => '13', 'unit' => 'text']]]];
        $by = fn (string $t, array $already = []): array => $method->invoke($responder, $t, $figures, $already, 'fr');
        $this->assertSame([['a1.orders'], [], ['a1.margin'], [], [], ['a1.orders', 'a1.margin']],
            [$by('Il y a 1100 commandes.'), $by('Cela fait 1100.'), $by('La marge est de 436,00 $.'), $by('Le 13 du mois.'), $by('Il y a 1100 commandes.', ['a1.sales']), $by('1 100 commandes pour 436 $ de marge.')],
            'deux chiffres de même valeur : celui dont le libellé est dans le texte, sinon aucun ; une date ou un texte ne sont pas des nombres cités ; un nombre déjà couvert par un chiffre cité n\'ajoute rien');
        // Une analyse par défaut dont le paramètre obligatoire manque n'est pas proposée au modèle ; un écran inconnu n'est pas traduit
        $screenOf = new \ReflectionMethod(ConsoleResponder::class, 'screen');
        $screenOf->setAccessible(true);
        $this->assertSame([[], [['key' => 'articles.vs_similar', 'productId' => $raise]], null], [
            $screenOf->invoke($responder, ['module' => 'gestion', 'screen' => 'article', 'params' => []], 'fr')['analysesParDefaut'],
            $screenOf->invoke($responder, ['module' => 'gestion', 'screen' => 'article', 'params' => ['productId' => $raise]], 'fr')['analysesParDefaut'],
            $screenOf->invoke($responder, ['module' => 'gestion', 'screen' => 'ecran.inconnu', 'params' => []], 'fr')]);

        // ---- 6. Unités : le propriétaire de la plateforme n'est pas décompté --------------------------------------------------------------
        $before = $usage();
        $this->login(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $e = $this->post('/api/gestion/console/messages', ['text' => 'Où en sont les ventes ?'], 202);
        $this->assertSame($before, $usage(), 'propriétaire de la plateforme : aucune unité réservée');
        FakeLandingAiClient::$queue = [$this->reply(['kind' => 'analyses', 'clarification' => '', 'analyses' => [['key' => 'sales.overview'] + $none]]), $this->reply(['blocks' => [$text('Voici les ventes.')], 'proposals' => []])];
        $this->runWorker((int) $e['messageId']);
        $owner = $last((int) $e['conversationId']);
        $this->assertSame(['done', [['sales.overview', ['range' => 'month']]]], [$owner['state'], $ran($owner)], 'sans « période » ni plage : le mois, comme l\'écran des statistiques');
        $this->assertSame($before, $usage(), '… ni décomptée');
    }

    /**
     * Lot W2-c (13/11/2026, retouches après l'essai de comparaison) : une carte ne suit que le texte qui parle vraiment du chiffre ;
     * une même grandeur de même valeur sur la même plage n'a qu'une carte ; les états et les dates sont rédigés (« Correct »,
     * « 11 novembre 2026 ») ; l'intertitre d'un groupe est une phrase complète ; l'écran Trésorerie donne son horizon de 90 jours ;
     * le libellé du panier moyen dit ce qu'il divise ; un contexte sans paramètre rend `params: {}`.
     */
    public function testCardsFollowTheTextThatSpeaksOfThemValuesAreWrittenAndScreensGiveTheirHorizon(): void
    {
        $tag = $this->tag;
        $conn = fn () => $this->em()->getConnection();
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $period = (int) $calendar->current()->getId();
        $today = $calendar->today()->format('Y-m-d');
        $catalog = static::getContainer()->get(AnalysisCatalog::class);
        $translator = static::getContainer()->get(TranslatorInterface::class);
        $none = ['periodId' => null, 'productId' => null, 'year' => null, 'days' => null, 'range' => null];
        $text = fn (string $t, array $ids = []): array => ['type' => 'text', 'text' => $t, 'figureIds' => $ids, 'analysis' => '', 'items' => []];
        $money = fn (int $cents): string => number_format($cents / 100, 2, ',', ' ') . ' $';
        $last = fn (int $id): array => array_slice($this->get("/api/gestion/console/conversations/$id")['messages'], -1)[0];
        $supplier = $this->supplier("Grossiste cartes $tag");
        [, $variant] = $this->article($supplier, "Article cartes $tag", 1000, 2500, $today);
        $this->sale($variant, 2, 2500, 2500, $today);
        [$unpriced] = $this->article($supplier, "Article sans prix $tag", 1000, 0, $today); // nouveau, sans prix de vente : pas de verdict
        $balance = (int) $conn()->fetchOne('INSERT INTO gestion_cash_balance (balance_date, amount_cents, created_at, updated_at) VALUES (?, 500000, NOW(), NOW()) RETURNING id', [$calendar->today()->modify('-2 days')->format('Y-m-d')]);
        $this->endOfWrite();
        try {
            // ---- B et C, par la vraie réponse : depuis l'écran d'une période, le résultat et les ventes de la même période ----------------
            $result = array_column($catalog->run('period.result', ['periodId' => $period], 'fr')['figures'], null, 'id');
            $sales = array_column($catalog->run('sales.overview', ['periodId' => $period], 'fr')['figures'], null, 'id');
            $this->em();
            $this->assertSame([$result['revenue']['value'], $result['orders']['value']], [$sales['revenue']['value'], $sales['orders']['value']], 'les deux analyses rendent les mêmes ventes et les mêmes commandes sur la période');
            $this->assertSame('Panier moyen (ventes avant retours ÷ commandes, hors taxes)', $sales['averageBasket']['label'], 'D : le libellé dit ce que le panier divise');
            $a = $this->post('/api/gestion/console/messages', ['text' => 'Comment se passe la période en cours ?', 'context' => ['module' => 'gestion', 'screen' => 'periode', 'params' => ['periodId' => $period]]], 202);
            FakeLandingAiClient::$queue = [
                $this->reply(['kind' => 'analyses', 'clarification' => '', 'analyses' => [['key' => 'period.result'] + $none, ['key' => 'sales.overview'] + $none, ['key' => 'budget.status'] + $none]]),
                $this->reply(['blocks' => [
                    // le modèle cite un chiffre dont le texte ne parle pas (unités sans coût), et deux fois les ventes (une par analyse)
                    $text('Les ventes de la période sont de ' . $money($result['revenue']['value']) . '.', ['a1.revenue', 'a1.unknownCostItems', 'a2.revenue']),
                    // … et le budget prévu sous un texte qui parle des commandes, sans citer les commandes
                    $text('Vous avez reçu ' . $result['orders']['value'] . ' commandes.', ['a3.planned']),
                    $text('Votre budget prévu n\'est pas encore saisi.', ['a3.planned']),
                    ['type' => 'figures', 'text' => '', 'figureIds' => ['a1.revenue', 'a2.revenue', 'a2.orders'], 'analysis' => '', 'items' => []],
                ], 'proposals' => []]),
            ];
            $this->runWorker((int) $a['messageId']);
            $answer = $last((int) $a['conversationId']);
            $this->assertSame('done', $answer['state'], json_encode($answer, JSON_UNESCAPED_UNICODE));
            // Lot W2-d : chaque carte suit le texte qui évoque son chiffre ; le bloc de cartes du modèle, tout placé, disparaît
            $this->assertSame(['text', 'figures', 'text', 'figures', 'text', 'figures'], array_column($answer['blocks'], 'type'));
            $this->assertSame([['a1.revenue'], ['a1.orders'], ['a3.planned']], [$answer['blocks'][0]['figureIds'], $answer['blocks'][2]['figureIds'], $answer['blocks'][4]['figureIds']],
                'B : chaque texte ne garde que les chiffres dont il parle (valeur ou libellé) ; C : les ventes des deux analyses sont un seul chiffre, celui de la première');
            $this->assertSame([['a1.revenue'], ['a1.orders'], ['a3.planned']], [array_column($answer['blocks'][1]['items'], 'id'), array_column($answer['blocks'][3]['items'], 'id'), array_column($answer['blocks'][5]['items'], 'id')],
                'la carte de chaque chiffre suit le texte qui en parle ; les ventes et les commandes ont une seule carte chacune');
            $cards = array_merge(...array_map(fn (array $b) => $b['type'] === 'figures' ? array_column($b['items'], 'id') : [], $answer['blocks']));
            $this->assertSame($cards, array_values(array_unique($cards)), 'jamais deux cartes du même chiffre');
            $this->assertNotContains('a1.unknownCostItems', $cards, 'un chiffre cité sans que le texte en parle n\'a pas de carte');

            // ---- B et C sur des chiffres choisis ---------------------------------------------------------------------------------------------
            $responder = static::getContainer()->get(ConsoleResponder::class);
            $speaks = new \ReflectionMethod(ConsoleResponder::class, 'speaksOf');
            $speaks->setAccessible(true);
            $of = fn (string $t, array $figure): bool => $speaks->invoke($responder, $t, $figure, 'fr');
            $unknown = ['id' => 'a1.unknownCostItems', 'label' => 'Unités vendues sans coût connu', 'value' => 2, 'unit' => 'count'];
            $planned = ['id' => 'a3.planned', 'label' => 'Budget prévu, achats de marchandise compris', 'value' => 0, 'unit' => 'money'];
            $day = ['id' => 'a1.balanceDate', 'label' => 'Date du solde', 'value' => '2026-11-11', 'unit' => 'date'];
            $status = ['id' => 'a1.cashStatus', 'label' => 'État de la trésorerie', 'value' => 'Sous le seuil', 'unit' => 'text', 'code' => 'warning'];
            $this->assertSame([false, true, true, false, true, true], [
                $of('Vos ventes sont de 1 170,70 $ sur la période.', $unknown), $of('Dont 2 unités sans coût connu.', $unknown), $of('Des unités vendues sans coût connu restent à rattacher.', $unknown),
                $of('Vous avez 33 commandes, pour un panier de 40,32 $.', $planned), $of('Votre budget prévu est de 0,00 $.', $planned), $of('Aucun budget prévu pour cette période.', $planned)]);
            $this->assertSame([true, false, true, true, false], [$of('Solde saisi le 11 novembre 2026.', $day), $of('Solde saisi le 12 novembre 2026.', $day), $of('La date du solde est ancienne.', $day),
                $of('Votre compte passe sous le seuil dans 14 jours.', $status), $of('Tout va bien.', $status)], 'une date et un état sont reconnus sous leur forme rédigée');
            $canonical = new \ReflectionMethod(ConsoleResponder::class, 'canonical');
            $canonical->setAccessible(true);
            $f = fn (string $id, mixed $value, string $unit = 'money'): array => ['id' => $id, 'label' => 'x', 'value' => $value, 'unit' => $unit];
            $this->assertSame(['a1.revenue' => 'a1.revenue', 'a1.orders' => 'a1.orders', 'a2.revenue' => 'a1.revenue', 'a2.orders' => 'a1.orders', 'a2.averageBasket' => 'a2.averageBasket', 'a3.revenue' => 'a3.revenue', 'a4.revenue' => 'a4.revenue'],
                $canonical->invoke(null, [
                    ['period' => ['from' => '2026-10-01', 'to' => '2026-12-31'], 'figures' => [$f('a1.revenue', 117070), $f('a1.orders', 33, 'count')]],
                    ['period' => ['from' => '2026-10-01', 'to' => '2026-12-31'], 'figures' => [$f('a2.revenue', 117070), $f('a2.orders', 33, 'count'), $f('a2.averageBasket', 4032)]],
                    ['period' => ['from' => '2026-11-01', 'to' => '2026-11-13'], 'figures' => [$f('a3.revenue', 117070)]],
                    ['period' => ['from' => '2026-10-01', 'to' => '2026-12-31'], 'figures' => [$f('a4.revenue', 117071)]],
                ]), 'même grandeur, même valeur, même plage : un seul chiffre ; une autre plage ou une autre valeur : un autre chiffre');

            // ---- E : états et dates rédigés --------------------------------------------------------------------------------------------------
            $this->em();
            $cash = array_column($catalog->run('cash.projection', ['days' => 90], 'fr')['figures'], null, 'id');
            $this->assertContains($cash['cashStatus']['value'], ['Correct', 'Sous le seuil', 'Découvert prévu'], 'la carte dit ce que dit l\'écran Trésorerie');
            $this->assertContains($cash['cashStatus']['code'], ['ok', 'warning', 'critical']);
            $this->assertSame($translator->trans('console.values.cash.' . $cash['cashStatus']['code'], [], 'gestion', 'fr'), $cash['cashStatus']['value']);
            $this->assertSame(['id', 'label', 'value', 'unit', 'code'], array_slice(array_keys($cash['cashStatus']), 0, 5), 'le code est rendu à part du libellé');
            $this->assertSame(['Correct', 'Sous le seuil', 'Découvert prévu', 'Aucun solde saisi', 'Fine', 'Dans le budget', 'Dépassé', 'À monter', 'Lower', 'code_inconnu'], [
                AnalysisCatalog::valueLabel($translator, 'cash', 'ok', 'fr'), AnalysisCatalog::valueLabel($translator, 'cash', 'warning', 'fr'), AnalysisCatalog::valueLabel($translator, 'cash', 'critical', 'fr'),
                AnalysisCatalog::valueLabel($translator, 'cash', 'no_balance', 'fr'), AnalysisCatalog::valueLabel($translator, 'cash', 'ok', 'en'), AnalysisCatalog::valueLabel($translator, 'budget', 'ok', 'fr'),
                AnalysisCatalog::valueLabel($translator, 'budget', 'over', 'fr'), AnalysisCatalog::valueLabel($translator, 'verdict', 'raise', 'fr'), AnalysisCatalog::valueLabel($translator, 'verdict', 'lower', 'en'),
                AnalysisCatalog::valueLabel($translator, 'cash', 'code_inconnu', 'fr')]);
            $budget = array_column($catalog->run('budget.status', ['periodId' => $period], 'fr')['figures'], null, 'id');
            $this->assertSame($translator->trans('console.values.budget.' . $budget['budgetStatus']['code'], [], 'gestion', 'fr'), $budget['budgetStatus']['value']);
            $this->em();
            $display = new \ReflectionMethod(ConsoleResponder::class, 'display');
            $display->setAccessible(true);
            $this->assertSame(['11 novembre 2026', '1er novembre 2026', 'November 11, 2026', 'Correct', 'pas-une-date'], [$display->invoke($responder, '2026-11-11', 'date', 'fr'), $display->invoke($responder, '2026-11-01', 'date', 'fr'),
                $display->invoke($responder, '2026-11-11', 'date', 'en'), $display->invoke($responder, 'Correct', 'text', 'fr'), $display->invoke($responder, 'pas-une-date', 'date', 'fr')]);
            $this->assertSame([[], [12.0]], [ConsoleResponder::unknown('Solde saisi le 11 novembre 2026.', ConsoleResponder::numbers('11 novembre 2026')), ConsoleResponder::unknown('Solde saisi le 12 novembre 2026.', ConsoleResponder::numbers('11 novembre 2026'))],
                'la vérification des nombres admet la date rédigée, pas une autre');

            // ---- F : depuis l'écran Trésorerie, l'horizon de l'écran (90 jours, et le point bas sur 14 jours) ------------------------------------
            $this->assertSame([[['key' => 'cash.projection', 'params' => ['days' => 90]]], ['days' => 90], [], ['range' => 'today'], []],
                [ConsoleScreens::defaults('tresorerie', []), ConsoleScreens::fixed('tresorerie', 'cash.projection'), ConsoleScreens::fixed('fournisseurs', 'cash.projection'), ConsoleScreens::fixed('gestion.home', 'sales.overview'), ConsoleScreens::fixed('tresorerie', 'budget.status')]);
            $this->assertSame(['Point bas sur 14 jours', 'Point bas sur 90 jours', 'Solde prévu dans 90 jours', 'Date du point bas sur 90 jours'], [$cash['lowest14Amount']['label'], $cash['lowestAmount']['label'], $cash['endAmount']['label'], $cash['lowestDate']['label']]);
            $short = array_column($catalog->run('cash.projection', ['days' => 14], 'fr')['figures'], 'label', 'id');
            $this->em();
            $this->assertSame(['Point bas sur 14 jours', false], [$short['lowestAmount'], isset($short['lowest14Amount'])], 'sur 14 jours, un seul point bas');
            $this->assertSame('Low point over 30 days', array_column($catalog->run('cash.projection', [], 'en')['figures'], 'label', 'id')['lowestAmount'], 'sans écran ni durée : 30 jours, et le libellé le dit');
            $this->em();
            $ran = function (array $context, array $analysis) use ($text, $last): array {
                $asked = $this->post('/api/gestion/console/messages', ['text' => 'Ma trésorerie va-t-elle tenir ?', 'context' => $context], 202);
                FakeLandingAiClient::$queue = [$this->reply(['kind' => 'analyses', 'clarification' => '', 'analyses' => [$analysis]]), $this->reply(['blocks' => [$text('Voici votre trésorerie.')], 'proposals' => []])];
                $this->runWorker((int) $asked['messageId']);

                return [array_map(fn (array $x) => [$x['key'], $x['params']], $last((int) $asked['conversationId'])['analyses']), (string) $this->client->getResponse()->getContent()];
            };
            $treasury = ['module' => 'gestion', 'screen' => 'tresorerie', 'params' => []];
            [$fromScreen, $raw] = $ran($treasury, ['key' => 'cash.projection'] + $none);
            $this->assertSame([['cash.projection', ['days' => 90]]], $fromScreen, 'le modèle laisse la durée vide : celle de l\'écran');
            $this->assertStringContainsString('"context":{"module":"gestion","screen":"tresorerie","params":{}}', $raw, 'un contexte sans paramètre rend params: {} (un objet)');
            $this->assertSame([['cash.projection', ['days' => 30]]], $ran($treasury, ['key' => 'cash.projection', 'days' => 30] + $none)[0], 'une durée nommée par le modèle l\'emporte');
            $this->assertSame([['cash.projection', ['days' => 30]]], $ran(['module' => 'gestion', 'screen' => 'frais-fixes', 'params' => []], ['key' => 'cash.projection'] + $none)[0], 'un autre écran : 30 jours');
            // Le modèle reçoit l'état et les dates rédigés
            $sent = array_column(json_decode(FakeLandingAiClient::$requests[count(FakeLandingAiClient::$requests) - 1]['messages'][0]['content'][0]['text'], true)['resultats'][0]['figures'], 'display', 'id');
            $this->assertSame([mb_strtolower($cash['cashStatus']['value']), 1], [$sent['a1.cashStatus'], preg_match('/^(1er|\d{1,2}) \p{L}+ \d{4}$/u', $sent['a1.balanceDate'])], 'jamais « ok » ni « 2026-11-11 » ; l\'état en minuscule pour le modèle (lot W2-d), la carte garde sa majuscule');

            // ---- A : l'intertitre d'un groupe est une phrase complète, accordée (les deux exemples du chef de projet) -------------------------
            $group = fn (string $id, int $count, string $locale = 'fr'): string => $translator->trans("console.groups.$id", ['%count%' => $count], 'gestion', $locale);
            $this->assertSame(['5 articles au coefficient constaté chez leur fournisseur : à garder', '1 article sous le coefficient de son fournisseur : à monter', '2 articles au-dessus du coefficient de leurs semblables : à baisser',
                '1 article sans coût d\'achat ou sans prix de vente : pas de verdict', '5 items at the coefficient observed with their supplier: keep', '1 item below the default coefficient of the site: raise'],
                [$group('keep.supplier', 5), $group('raise.supplier', 1), $group('lower.similar', 2), $group('none', 1), $group('keep.supplier', 5, 'en'), $group('raise.site', 1, 'en')]);
            // Un article nouveau sans prix de vente : dans le groupe « pas de verdict », avec sa phrase
            $noVerdict = array_values(array_filter($catalog->run('articles.new', ['periodId' => $period], 'fr')['groups'], fn (array $g) => in_array($unpriced, $g['productIds'], true)))[0];
            $this->em();
            $this->assertSame([null, null, $noVerdict['count'] > 1 ? sprintf('%d articles sans coût d\'achat ou sans prix de vente : pas de verdict', $noVerdict['count']) : '1 article sans coût d\'achat ou sans prix de vente : pas de verdict'],
                [$noVerdict['verdict'], $noVerdict['source'], $noVerdict['label']]);
            // Un nombre sans libellé dans le texte, rendu par deux analyses sur la même plage : c'est un seul chiffre, donc retrouvé
            $cited = new \ReflectionMethod(ConsoleResponder::class, 'citedByValue');
            $cited->setAccessible(true);
            $twice = [['period' => ['from' => '2026-10-01', 'to' => '2026-12-31'], 'figures' => [['id' => 'a1.orders', 'label' => 'Commandes', 'value' => 33, 'unit' => 'count']]],
                ['period' => ['from' => '2026-10-01', 'to' => '2026-12-31'], 'figures' => [['id' => 'a2.orders', 'label' => 'Commandes', 'value' => 33, 'unit' => 'count']]]];
            $this->assertSame([['a1.orders'], []], [$cited->invoke($responder, 'Cela fait 33 commandes en tout.', $twice, [], 'fr'), $cited->invoke($responder, 'Cela fait 33 en tout.', $twice, [], 'fr')],
                'les deux analyses ne font qu\'un candidat ; un nombre nu ne le désigne qu\'avec un mot de son libellé dans la phrase (lot W2-d)');
            foreach (['keep', 'raise', 'lower'] as $verdict) {
                foreach (['similar', 'supplier', 'site'] as $source) {
                    foreach (['fr', 'en'] as $locale) {
                        $this->assertMatchesRegularExpression('/^7 (articles|items) .+ ?: .+$/u', $group("$verdict.$source", 7, $locale), "$verdict.$source ($locale)");
                    }
                }
            }
        } finally {
            $conn()->executeStatement('DELETE FROM gestion_cash_balance WHERE id = ?', [$balance]);
        }
    }

    /**
     * Lot W2-d (13/11/2026) : l'échange RÉEL de demo où les cartes étaient mal placées, rejoué avec ses textes et ses chiffres. « du 2
     * juin » faisait garder la carte d'un compte de 2, « 0 % » celle d'un budget de 0,00 $, et les vrais chiffres du premier texte
     * partaient en fin de réponse. Aussi : tableau à colonnes libellées et cellules rédigées, état en minuscule pour le modèle, consignes.
     */
    public function testARealExchangePlacesEachCardUnderTheTextThatSpeaksOfIt(): void
    {
        $this->em();
        $responder = static::getContainer()->get(ConsoleResponder::class);
        $catalog = static::getContainer()->get(AnalysisCatalog::class);
        $translator = static::getContainer()->get(TranslatorInterface::class);
        $f = fn (string $id, string $label, mixed $value, string $unit): array => ['id' => $id, 'label' => $label, 'value' => $value, 'unit' => $unit];
        $range = ['from' => '2026-09-01', 'to' => '2026-11-30', 'label' => 'Automne 2026'];
        $result = fn (string $ref, string $key, array $figures): array => ['ref' => $ref, 'key' => $key, 'title' => $key, 'period' => $range, 'figures' => $figures, 'rows' => [], 'groups' => [], 'lesson' => null, 'more' => null, 'source' => ['screen' => 'x', 'route' => null, 'params' => []]];
        $results = [
            $result('a1', 'period.result', [$f('a1.revenue', 'Ventes', 117070, 'money'), $f('a1.orders', 'Commandes', 33, 'count'), $f('a1.expenses', 'Frais de la période (port, notes de frais, publicité), hors achats de marchandise', 95700, 'money'),
                $f('a1.grossMargin', 'Marge brute', 51225, 'money'), $f('a1.netResult', 'Résultat', -44475, 'money'), $f('a1.unknownCostRevenue', 'Ventes sans coût connu', 450, 'money'), $f('a1.unknownCostItems', 'Unités vendues sans coût connu', 2, 'count')]),
            $result('a2', 'sales.overview', [$f('a2.revenue', 'Ventes', 117070, 'money'), $f('a2.grossMargin', 'Marge brute', 51225, 'money'), $f('a2.grossMarginRate', 'Taux de marge', 43.92, 'percent'), $f('a2.orders', 'Commandes', 33, 'count'),
                $f('a2.averageBasket', 'Panier moyen (ventes avant retours ÷ commandes, hors taxes)', 4032, 'money'), $f('a2.returnRate', 'Taux de retour', 12.02, 'percent')]),
            $result('a3', 'budget.status', [$f('a3.planned', 'Budget prévu, achats de marchandise compris', 0, 'money'), $f('a3.actual', 'Dépensé (réel), achats de marchandise compris', 2683825, 'money'), $f('a3.remaining', 'Reste du budget', -2683825, 'money')]),
        ];
        // Les quatre textes de la réponse, mot pour mot, avec les chiffres que le modèle citait ; puis son bloc de cartes final
        $texts = [
            ["Sur Automne 2026, les ventes progressent (1 170,70 $ contre 977,00 $ du 2 juin au 31 août 2026) et la marge brute aussi (512,25 $), mais la période reste en perte : le résultat est de -444,75 $. Les frais de la période (port, notes de frais, publicité, hors achats de marchandise) s'élèvent à 957,00 $, ce qui dépasse la marge brute.",
                ['a1.revenue', 'a1.grossMargin', 'a1.netResult', 'a1.expenses', 'a1.unknownCostItems']],
            ['Les commandes passent à 33 contre 6, mais le panier moyen tombe à 40,32 $ contre 162,83 $. Le taux de retour est de 12 %, contre 0 % sur la période précédente. Le taux de marge est de 43,9 % contre 45 %.',
                ['a1.orders', 'a2.averageBasket', 'a2.returnRate', 'a2.grossMarginRate', 'a3.planned']],
            ["Côté budget, rien n'est prévu pour cette période (0,00 $, achats de marchandise compris), alors que les dépenses réelles, achats de marchandise compris, atteignent 26 838,25 $. Le poste Stock en représente 25 881,25 $. Ce chiffre n'est pas comparable aux frais de 957,00 $ ci-dessus, qui excluent les achats de marchandise.",
                ['a3.planned', 'a3.actual', 'a1.expenses']],
            ["Pour 2 unités vendues, le coût n'est pas connu (4,50 $ de ventes) : la marge est donc légèrement imprécise.", ['a1.unknownCostItems', 'a1.unknownCostRevenue']],
        ];
        $written = array_map(fn (array $t) => ['type' => 'text', 'text' => $t[0], 'figureIds' => $t[1], 'analysis' => '', 'items' => []], $texts);
        $written[] = ['type' => 'figures', 'text' => '', 'figureIds' => ['a1.revenue', 'a1.expenses', 'a1.grossMargin', 'a1.netResult'], 'analysis' => '', 'items' => []];
        $assemble = new \ReflectionMethod(ConsoleResponder::class, 'blocks');
        $assemble->setAccessible(true);
        $run = fn (array $blocks): array => $assemble->invoke($responder, $blocks, $results, ConsoleResponder::numbers(implode(' ', array_column($blocks, 'text'))), 'fr');
        [$blocks, $rejected] = $run($written);
        $this->assertSame(0, $rejected);
        $this->assertSame(['text', 'figures', 'text', 'figures', 'text', 'figures', 'text', 'figures'], array_column($blocks, 'type'), 'chaque texte est suivi de ses cartes ; le bloc final du modèle, tout placé, disparaît');
        $cards = fn (int $i): array => array_column($blocks[$i]['items'], 'id');
        $this->assertSame([['a1.revenue', 'a1.grossMargin', 'a1.netResult', 'a1.expenses'], ['a1.revenue', 'a1.expenses', 'a1.grossMargin', 'a1.netResult']], [$blocks[0]['figureIds'], $cards(1)],
            'texte 1 : ses quatre chiffres sont sous lui ; le compte de 2 unités, cité à tort (« du 2 juin »), est retiré');
        $this->assertSame([['a1.orders', 'a2.averageBasket', 'a2.returnRate', 'a2.grossMarginRate'], ['a1.orders', 'a2.grossMarginRate', 'a2.averageBasket', 'a2.returnRate']], [$blocks[2]['figureIds'], $cards(3)],
            'texte 2 : le budget prévu de 0,00 $, cité à tort (« contre 0 % »), est retiré');
        $this->assertSame([['a3.planned', 'a3.actual', 'a1.expenses'], ['a3.planned', 'a3.actual']], [$blocks[4]['figureIds'], $cards(5)], 'texte 3 : budget prévu et dépenses réelles ; les frais, déjà sous le texte 1, ne sont pas répétés');
        $this->assertSame([['a1.unknownCostItems', 'a1.unknownCostRevenue'], ['a1.unknownCostRevenue', 'a1.unknownCostItems']], [$blocks[6]['figureIds'], $cards(7)], 'texte 4 : « 2 unités vendues » désigne bien le compte, ici');
        $all = array_merge($cards(1), $cards(3), $cards(5), $cards(7));
        $this->assertSame($all, array_values(array_unique($all)), 'une carte par chiffre');

        // Sans citation du modèle : chaque chiffre est retrouvé par sa valeur, selon sa nature, et rattaché au texte qui l'écrit
        $uncited = array_map(fn (array $b) => ['figureIds' => []] + $b, array_slice($written, 0, 4));
        [$found] = $run($uncited);
        $sorted = function (array $ids): array {
            sort($ids);

            return $ids;
        };
        $this->assertSame([$sorted(['a1.revenue', 'a1.grossMargin', 'a1.netResult', 'a1.expenses']), $sorted(['a1.orders', 'a2.averageBasket', 'a2.returnRate', 'a2.grossMarginRate']), $sorted(['a3.planned', 'a3.actual', 'a1.expenses']), $sorted(['a1.unknownCostItems', 'a1.unknownCostRevenue'])],
            [$sorted($found[0]['figureIds']), $sorted($found[2]['figureIds']), $sorted($found[4]['figureIds']), $sorted($found[6]['figureIds'])], 'les frais de 957,00 $ sont écrits dans les textes 1 et 3 : leur carte reste sous le premier');
        $this->assertSame([['a1.revenue', 'a1.expenses', 'a1.grossMargin', 'a1.netResult'], ['a3.planned', 'a3.actual']], [array_column($found[1]['items'], 'id'), array_column($found[5]['items'], 'id')]);
        // Un chiffre qu'aucun texte n'évoque reste dans le bloc du modèle, à sa place ; un chiffre évoqué plus bas quitte ce bloc pour son texte
        [$mixed] = $run([['type' => 'figures', 'text' => '', 'figureIds' => ['a3.remaining', 'a1.revenue'], 'analysis' => '', 'items' => []], $written[0]]);
        $this->assertSame([['figures', ['a3.remaining']], ['text', null], ['figures', ['a1.revenue', 'a1.expenses', 'a1.grossMargin', 'a1.netResult']]],
            array_map(fn (array $b) => [$b['type'], $b['type'] === 'figures' ? array_column($b['items'], 'id') : null], $mixed));

        // La nature des nombres d'un texte
        $kinds = fn (string $t): array => array_map(fn (array $x) => $x['value'] . ' ' . $x['kind'], ConsoleResponder::tokens($t));
        $this->assertSame([['1170.7 money', '977 money', '512.25 money'], ['33 plain', '6 plain', '12 percent', '0 percent'], ['1170.7 money', '25 money', '3 plain'], ['7 plain'], []], [
            $kinds('Les ventes progressent (1 170,70 $ contre 977,00 $ du 2 juin au 31 août 2026) et la marge aussi (512,25 $).'), $kinds('Les commandes passent à 33 contre 6. Le taux de retour est de 12 %, contre 0 %.'),
            $kinds('Sales of $1,170.70 on November 11, 2026, 25.00 CAD and 3 items.'), $kinds('Le 1er novembre 2026, puis 7 articles le 2026-11-11.'), $kinds('En novembre 2026.')],
            'un montant, un pourcentage, un nombre nu ; le jour et l\'année d\'une date ne sont pas des nombres cités');
        // Un même montant porté par deux chiffres différents : le libellé dans la phrase tranche, sinon aucun rattachement
        $cited = new \ReflectionMethod(ConsoleResponder::class, 'citedByValue');
        $cited->setAccessible(true);
        $twins = [$result('a1', 'x', [$f('a1.shipping', 'Frais de port', 95700, 'money'), $f('a1.notes', 'Notes de frais', 95700, 'money'), $f('a1.share', 'Part vendue', 95700, 'percent'), $f('a1.units', 'Unités vendues', 957, 'count')])];
        $by = fn (string $t): array => $cited->invoke($responder, $t, $twins, [], 'fr');
        $this->assertSame([['a1.notes'], ['a1.shipping'], [], [], ['a1.units'], [], []], [$by('Les notes de frais atteignent 957,00 $.'), $by('Le port vous a coûté 957,00 $ de frais.'), $by('Vous avez dépensé 957,00 $.'), $by('Vos frais atteignent 957,00 $.'),
            $by('Vous avez vendu 957 unités.'), $by('Cela fait 957.'), $by('Vos frais sont de 957 %.')],
            'le libellé le mieux écrit dans la phrase tranche ; à égalité ou sans libellé, rien ; un montant n\'est ni un compte ni un taux');
        // « le mieux écrit » = la PART des mots du libellé lus dans la phrase (« Frais » en entier bat « Frais de port » à moitié), et seul le
        // début du libellé compte, pas les mots de sa formule entre parenthèses
        $shares = [$result('a1', 'x', [$f('a1.fees', 'Frais', 12300, 'money'), $f('a1.port', 'Frais de port', 12300, 'money'),
            $f('a1.basket', 'Panier moyen (ventes avant retours ÷ commandes, hors taxes)', 4032, 'money'), $f('a1.day', 'Ventes nettes du jour après remises', 4032, 'money')])];
        $this->assertSame([['a1.fees'], ['a1.day']], [$cited->invoke($responder, 'Vos frais sont de 123,00 $.', $shares, [], 'fr'), $cited->invoke($responder, 'Vos ventes avant retours : 40,32 $.', $shares, [], 'fr')]);
        // Un chiffre cité par le modèle : un nombre nu ne suffit pas sans un mot du libellé dans la phrase
        $speaks = new \ReflectionMethod(ConsoleResponder::class, 'speaksOf');
        $speaks->setAccessible(true);
        $units = $f('a1.unknownCostItems', 'Unités vendues sans coût connu', 2, 'count');
        $this->assertSame([false, true, false, false], [$speaks->invoke($responder, 'Vous avez 2 nouveautés ce mois-ci.', $units, 'fr'), $speaks->invoke($responder, 'Pour 2 unités, le coût manque.', $units, 'fr'),
            $speaks->invoke($responder, 'Du 2 juin au 31 août 2026, tout va bien.', $units, 'fr'), $speaks->invoke($responder, 'Les unités comptent. Il y en a 2.', $units, 'fr')],
            'le mot du libellé doit être dans la MÊME phrase que le nombre');
        // Deux blocs de cartes du modèle : un chiffre déjà montré par le premier n'est pas répété par le second
        [$repeated] = $run([['type' => 'figures', 'text' => '', 'figureIds' => ['a3.remaining'], 'analysis' => '', 'items' => []], ['type' => 'figures', 'text' => '', 'figureIds' => ['a3.remaining', 'a2.grossMarginRate'], 'analysis' => '', 'items' => []]]);
        $this->assertSame([['a3.remaining'], ['a2.grossMarginRate']], array_map(fn (array $b) => array_column($b['items'], 'id'), $repeated));

        // H. Tableau : colonnes libellées avec leur unité, cellules rédigées, code à part
        $period = (int) static::getContainer()->get(PeriodCalendar::class)->current()->getId();
        $budget = $catalog->run('budget.status', ['periodId' => $period], 'fr');
        $this->em();
        $budget['ref'] = 'a1';
        [$table] = $assemble->invoke($responder, [['type' => 'table', 'text' => '', 'figureIds' => [], 'analysis' => 'a1', 'items' => []]], [$budget], [], 'fr');
        $this->assertSame([['key' => 'label', 'label' => 'Poste', 'unit' => 'text'], ['key' => 'planned', 'label' => 'Prévu', 'unit' => 'money'], ['key' => 'actual', 'label' => 'Réel', 'unit' => 'money'],
            ['key' => 'remaining', 'label' => 'Reste', 'unit' => 'money'], ['key' => 'status', 'label' => 'État', 'unit' => 'text']], $table[0]['columns']);
        $stock = array_column($table[0]['rows'], null, 'category')['purchases'];
        $this->assertSame('Stock', $stock['label'], 'le poste se lit rédigé ; son code reste dans category');
        $this->assertContains($stock['status'], ['Sans prévu', 'Dans le budget', 'Attention', 'Dépassé']);
        $this->assertSame($translator->trans('console.values.budget.' . $stock['statusCode'], [], 'gestion', 'fr'), $stock['status']);
        $this->assertIsInt($stock['actual']);
        $written = new \ReflectionMethod(ConsoleResponder::class, 'writtenRow');
        $written->setAccessible(true);
        $this->assertSame([['month' => 'novembre 2026', 'sales' => 100, 'monthKey' => '2026-11'], ['month' => 'November 2026', 'sales' => 100, 'monthKey' => '2026-11'], ['verdict' => 'À monter', 'confidence' => 'Prudente', 'name' => 'x', 'verdictCode' => 'raise', 'confidenceCode' => 'low']],
            [$written->invoke($responder, ['month' => '2026-11', 'sales' => 100], 'fr'), $written->invoke($responder, ['month' => '2026-11', 'sales' => 100], 'en'), $written->invoke($responder, ['verdict' => 'raise', 'confidence' => 'low', 'name' => 'x'], 'fr')]);
        // chaque tableau du catalogue a ses colonnes, libellées dans les deux langues, et chaque colonne existe dans les lignes
        foreach (AnalysisCatalog::COLUMNS as $key => $columns) {
            $this->assertTrue($catalog->has($key));
            foreach (['fr', 'en'] as $locale) {
                foreach ($catalog->columns($key, $locale) as $column) {
                    $this->assertSame(['key', 'label', 'unit'], array_keys($column));
                    $this->assertStringStartsNotWith('console.columns.', $column['label'], "$key.{$column['key']} ($locale)");
                    $this->assertContains($column['unit'], ['money', 'percent', 'count', 'days', 'ratio', 'text', 'month']);
                }
            }
        }
        $this->assertSame([], $catalog->columns('period.result', 'fr'), 'une analyse sans tableau');
        $rows = $catalog->run('articles.missing_cost', [], 'fr')['rows'];
        $this->em();
        if ($rows !== []) {
            $this->assertSame([], array_diff(array_keys(AnalysisCatalog::COLUMNS['articles.missing_cost']), array_keys($rows[0])));
        }

        // I et J. Consignes de rédaction
        $system = (new \ReflectionMethod(\App\Gestion\Services\Console\ConsoleModel::class, 'systemWrite'));
        $system->setAccessible(true);
        $rules = $system->invoke(static::getContainer()->get(\App\Gestion\Services\Console\ConsoleModel::class), 'français');
        foreach (['ne recopie pas leurs phrases', 'donne seulement le bilan', 'jamais de majuscule en cours de phrase'] as $rule) {
            $this->assertStringContainsString($rule, $rules);
        }
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
