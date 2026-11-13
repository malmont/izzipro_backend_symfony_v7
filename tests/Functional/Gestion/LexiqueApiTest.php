<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Gestion\Analysis\AnalysisCatalog;
use App\Gestion\Lexique\LexiqueCatalog;
use App\Gestion\Lexique\LexiqueIllustration;
use App\Gestion\Lexique\LexiqueTexts;
use App\Gestion\Message\AnswerConsoleQuestionMessage;
use App\Gestion\MessageHandler\AnswerConsoleQuestionHandler;
use App\Gestion\Services\Console\ConsoleResponder;
use App\Gestion\Services\PeriodCalendar;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeLandingAiClient;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use App\Tests\Functional\Support\AiRightTestHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Lexique de la gestion et console « prof » (lot W2-APPRENDRE, A-1 à A-3, 13/11/2026, `docs/lexique-gestion.md`). Le lexique est
 * servi par le serveur, sans IA : définitions, chiffres du site par une analyse nommée, illustration écrite par le serveur, questions
 * de vérification corrigées par le serveur. Dans la console : une question de définition seule est assemblée sans rédaction (0 unité) ;
 * avec une autre analyse, la leçon passe devant la réponse rédigée ; le mode « apprendre » ajoute les leçons des chiffres montrés.
 */
class LexiqueApiTest extends WebTestCase
{
    use CacheTestEntityFactory;
    use PricingFixtures;

    /** Les 54 clés du glossaire du mobile, avec leurs tirets : elles ne changent JAMAIS (les applications s'y relient) */
    private const MOBILE_KEYS = [
        'ventes', 'ventes-sans-cout', 'cout-articles-vendus', 'marge', 'taux-de-marque', 'coefficient', 'frais', 'resultat',
        'point-mort', 'investi-stock', 'autres-revenus', 'periode', 'budget-global', 'prevu', 'reel', 'reste',
        'solde', 'point-bas', 'seuil', 'ventes-a-venir', 'rapprochement', 'frais-fixes', 'resultat-apres-frais-fixes', 'point-mort-apres-frais-fixes',
        'exercice', 'resultat-entreprise', 'frais-variables', 'projection', 'leviers', 'mouvement-prevu', 'decouvert', 'budget-voyage',
        'budget-a-ce-jour', 'arrondi', 'sous-le-cout', 'promotion', 'promotion-variante', 'variante-prix-propre', 'confiance', 'effet',
        'mesure', 'panier-moyen', 'valeur-stock', 'commandes', 'taux-retour', 'evolution', 'gain-marge', 'grille-location',
        'promotion-location', 'revenu-location', 'revenu-date-location', 'occupation', 'recurrent', 'taux-depart',
    ];
    private const ADDED_KEYS = ['tresorerie', 'taxes', 'stock-dormant', 'budget', 'cout-d-achat', 'stock-bas', 'semblables', 'verdict'];

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
        $this->usageMark = (int) $this->em()->getConnection()->fetchOne('SELECT COALESCE(MAX(id), 0) FROM ai_usage');
    }

    protected function tearDown(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('DELETE FROM ai_usage WHERE id > ?', [$this->usageMark]);
        $conn->executeStatement("DELETE FROM gestion_conversation WHERE kind = 'console'");
        $this->removePricingFixtures();
        AiRightTestHelper::restoreFullAi(static::getContainer());
        parent::tearDown();
    }

    public function testTheLexiconIsServedWithoutAiWithItsTextsStructureAndVersion(): void
    {
        $conn = fn () => $this->em()->getConnection();
        $counts = fn (): array => [(int) $conn()->fetchOne('SELECT COUNT(*) FROM ai_usage'), (int) $conn()->fetchOne('SELECT COUNT(*) FROM gestion_conversation'), (int) $conn()->fetchOne('SELECT COUNT(*) FROM content_audit_log')];
        $before = $counts();

        // La liste : { version, entries }, dans l'ordre du lexique ; les clés du mobile telles quelles, puis les ajouts
        $fr = $this->get('/api/gestion/lexique');
        $en = $this->get('/api/gestion/lexique?locale=en');
        $this->assertSame(['version', 'entries'], array_keys($fr));
        $this->assertSame(array_merge(self::MOBILE_KEYS, self::ADDED_KEYS), array_column($fr['entries'], 'key'));
        $this->assertSame([54, 62, LexiqueCatalog::keys()], [count(self::MOBILE_KEYS), count($fr['entries']), array_column($en['entries'], 'key')]);
        foreach (LexiqueCatalog::keys() as $key) {
            $this->assertMatchesRegularExpression('/^[a-z]+(-[a-z]+)*$/', $key, 'forme des clés : minuscules et tirets');
        }
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $fr['version']);
        $this->assertSame([$fr['version'], $fr['version']], [$en['version'], $this->get('/api/gestion/lexique?locale=de')['version']], 'une seule version pour les deux langues');
        $this->assertSame($fr['entries'], $this->get('/api/gestion/lexique?locale=de')['entries'], 'langue inconnue : français');
        $byKey = array_column($fr['entries'], null, 'key');
        $byKeyEn = array_column($en['entries'], null, 'key');
        $this->assertSame([
            'key' => 'taux-de-marque', 'term' => 'Taux de marque', 'short' => 'La part de marge dans le prix de vente.',
            'definition' => "La part de marge dans le prix de vente. À 40 %, sur 100 $ vendus, il vous reste 40 $ après le coût d'achat.", 'synonyms' => ['taux de marge'],
            'related' => [['key' => 'marge', 'term' => 'Marge brute'], ['key' => 'coefficient', 'term' => 'Coefficient'], ['key' => 'point-mort', 'term' => 'Point mort']],
            'analysis' => ['key' => 'sales.overview', 'params' => ['range' => 'month'], 'figures' => ['grossMarginRate', 'revenue', 'grossMargin']], 'screens' => ['statistiques'], 'quiz' => 2,
        ], $byKey['taux-de-marque']);
        $this->assertSame(['Margin rate', 'The share of margin in the selling price.', ['gross margin percentage', 'markup rate'], 'Gross margin'],
            [$byKeyEn['taux-de-marque']['term'], $byKeyEn['taux-de-marque']['short'], $byKeyEn['taux-de-marque']['synonyms'], $byKeyEn['taux-de-marque']['related'][0]['term']]);
        $this->assertSame([null, [], 0, []], [$byKey['promotion-variante']['analysis'], $byKey['promotion-variante']['screens'], $byKey['promotion-variante']['quiz'], $byKey['decouvert']['synonyms']], 'une notion sans chiffre du site ni question');

        // GARDE-FOU des textes : chaque notion a son terme et sa définition dans les deux langues ; notions liées, analyses et chiffres existent
        $translator = static::getContainer()->get(TranslatorInterface::class);
        $catalog = static::getContainer()->get(AnalysisCatalog::class);
        $dir = static::getContainer()->getParameter('kernel.project_dir') . '/translations';
        $files = ['fr' => Yaml::parseFile("$dir/lexique.fr.yaml"), 'en' => Yaml::parseFile("$dir/lexique.en.yaml")];
        $this->assertSame([LexiqueCatalog::keys(), LexiqueCatalog::keys()], [array_keys($files['fr']), array_keys($files['en'])], 'les deux fichiers portent les mêmes notions, dans l\'ordre de la structure');
        $quizzed = 0;
        foreach (LexiqueCatalog::ENTRIES as $key => $structure) {
            foreach (['fr' => $byKey, 'en' => $byKeyEn] as $locale => $entries) {
                $e = $entries[$key];
                $this->assertSame(['key', 'term', 'short', 'definition', 'synonyms', 'related', 'analysis', 'screens', 'quiz'], array_keys($e));
                $this->assertGreaterThan(1, mb_strlen($e['term']), "$key ($locale) : terme");
                $this->assertGreaterThan(30, mb_strlen($e['definition']), "$key ($locale) : définition");
                $this->assertNotContains("$key.term", [$e['term'], $e['definition']], "$key ($locale) : la clé de traduction n'est pas rendue à la place du texte");
                $this->assertNotSame("$key.definition", $e['definition']);
                $this->assertStringStartsWith($e['short'], $e['definition']);
                $fields = fn (string $l): array => array_values(array_diff(array_keys($files[$l][$key]), ['synonyms'])); // les synonymes sont propres à chaque langue
                $this->assertSame($fields($locale), $fields($locale === 'fr' ? 'en' : 'fr'), "$key : mêmes champs dans les deux langues");
            }
            $this->assertNotSame($byKey[$key]['definition'], $byKeyEn[$key]['definition'], "$key : la définition anglaise n'est pas le texte français");
            $this->assertNotSame([], $structure['related'], "$key : au moins une notion liée");
            foreach ($structure['related'] as $related) {
                $this->assertTrue(LexiqueCatalog::has($related), "$key : notion liée « $related » inconnue");
                $this->assertNotSame($key, $related);
            }
            if ($structure['analysis'] !== null) {
                $this->assertTrue($catalog->has($structure['analysis']['key']), "$key : analyse liée inconnue");
                $this->assertNotSame('lexique.term', $structure['analysis']['key']);
                $this->assertNotNull($catalog->accept($structure['analysis']['key'], $structure['analysis']['params']), "$key : paramètres de l'analyse liée refusés");
                foreach ($structure['analysis']['figures'] as $figure) {
                    $this->assertNotSame("console.figures.$figure", $translator->trans("console.figures.$figure", [], 'gestion', 'fr'), "$key : chiffre « $figure » inconnu des analyses");
                }
            }
            // Questions de vérification : trois choix, une bonne réponse parmi eux, une explication, dans les deux langues
            foreach ($structure['quiz'] as $question => $right) {
                ++$quizzed;
                $this->assertContains($right, LexiqueCatalog::CHOICES, "$key.$question");
                foreach (['fr', 'en'] as $locale) {
                    $q = $files[$locale][$key]['quiz'][$question] ?? null;
                    $this->assertSame(['question', 'a', 'b', 'c', 'explanation'], array_keys((array) $q), "$key.$question ($locale)");
                    foreach ($q as $field => $text) {
                        $this->assertGreaterThan(0, mb_strlen(trim((string) $text)), "$key.$question.$field ($locale)");
                    }
                    $this->assertCount(3, array_unique([$q['a'], $q['b'], $q['c']]), "$key.$question ($locale) : trois choix différents");
                }
            }
            $this->assertSame(array_keys($structure['quiz']), array_keys($files['fr'][$key]['quiz'] ?? []), "$key : les questions écrites sont celles de la structure");
        }
        $this->assertGreaterThanOrEqual(20, $quizzed);
        foreach (LexiqueCatalog::FIGURE_TERMS as $figure => $term) {
            $this->assertTrue(LexiqueCatalog::has($term), "chiffre $figure : notion « $term » inconnue");
        }
        // Chaque chiffre des analyses porte la notion qui l'explique ; le résultat de l'exercice n'est pas celui d'une période
        $terms = fn (string $analysis): array => array_column($catalog->run($analysis, $catalog->accept($analysis, []), 'fr')['figures'], 'term', 'id');
        $sales = $terms('sales.overview');
        $this->assertSame(['ventes', 'commandes', 'taux-de-marque', null], [$sales['revenue'], $sales['orders'], LexiqueCatalog::termOf('sales.overview', 'grossMarginRate'), LexiqueCatalog::termOf('articles.new', 'newItems')]);
        $this->assertSame(['resultat', 'resultat-entreprise'], [$terms('period.result')['netResult'], $terms('year.overview')['netResult']]);
        $this->assertArrayNotHasKey('adviceCount', $terms('advice.current'), 'un chiffre sans notion ne porte pas `term`');
        $this->em();

        // La version change dès qu'un texte change
        $texts = static::getContainer()->get(LexiqueTexts::class);
        $changed = new LexiqueTexts(new class($translator) implements TranslatorInterface {
            public function __construct(private readonly TranslatorInterface $inner)
            {
            }

            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id === 'verdict.definition' && $locale === 'en' ? 'Changed.' : $this->inner->trans($id, $parameters, $domain, $locale);
            }

            public function getLocale(): string
            {
                return $this->inner->getLocale();
            }
        });
        $this->assertSame($fr['version'], $texts->version());
        $this->assertNotSame($texts->version(), $changed->version(), 'un seul texte changé, dans une seule langue : la version change');

        // Refus et accès ; aucune IA, rien d'écrit
        $this->request('GET', '/api/gestion/lexique/notion-inconnue');
        $this->assertSame([404, 'LEXIQUE_TERM_NOT_FOUND'], [$this->client->getResponse()->getStatusCode(), $this->body()['code']]);
        $admin = $this->token;
        $this->token = '';
        foreach ([['GET', '/api/gestion/lexique'], ['GET', '/api/gestion/lexique/marge'], ['GET', '/api/gestion/lexique/marge/quiz'], ['POST', '/api/gestion/lexique/marge/quiz/q1']] as [$method, $route]) {
            $this->request($method, $route, $method === 'POST' ? ['choice' => 'b'] : null);
            $this->assertSame(401, $this->client->getResponse()->getStatusCode(), "$method $route sans jeton");
        }
        $this->login(['ROLE_USER']);
        foreach ([['GET', '/api/gestion/lexique'], ['POST', '/api/gestion/lexique/marge/quiz/q1']] as [$method, $route]) {
            $this->request($method, $route, $method === 'POST' ? ['choice' => 'b'] : null);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), "$method $route sans le rôle d'administrateur");
        }
        $this->token = $admin;
        $this->assertSame([$before, 0], [$counts(), count(FakeLandingAiClient::$requests)], 'le lexique ne demande rien à l\'IA et n\'écrit rien');
    }

    public function testANotionShowsTheSiteFiguresWithAServerWrittenIllustration(): void
    {
        $tag = $this->tag;
        $plain = fn (?string $v): ?string => $v === null ? null : preg_replace('/[\x{00A0}\x{202F}]/u', ' ', $v);
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $today = $calendar->today()->format('Y-m-d');
        // Une vente ce mois-ci : article acheté 10,00, vendu 25,00
        $supplier = $this->supplier("Grossiste lexique $tag");
        [, $variant] = $this->article($supplier, "Article lexique $tag", 1000, 2500, $today);
        $this->sale($variant, 2, 2500, 2500, $today);
        $this->endOfWrite();

        $entry = $this->get('/api/gestion/lexique/taux-de-marque');
        $this->assertSame(['key', 'term', 'short', 'definition', 'synonyms', 'related', 'analysis', 'screens', 'quiz', 'site'], array_keys($entry));
        $site = $entry['site'];
        $this->assertSame(['analysis', 'period', 'figures', 'illustration'], array_keys($site));
        $this->assertSame(['key' => 'sales.overview', 'title' => 'Ventes et marge'], $site['analysis']);
        $this->assertSame(['grossMarginRate', 'revenue', 'grossMargin'], array_column($site['figures'], 'id'), 'les chiffres que le lexique désigne, dans son ordre');
        $figures = array_column($site['figures'], null, 'id');
        $this->assertSame(['taux-de-marque', 'percent', 'statistiques', 'ventes'], [$figures['grossMarginRate']['term'], $figures['grossMarginRate']['unit'], $figures['grossMarginRate']['source']['screen'], $figures['revenue']['term']]);
        $this->assertGreaterThan(0, $figures['revenue']['value']);
        $this->assertSame(substr($today, 0, 8) . '01', $site['period']['from']);
        // L'illustration : écrite par le serveur ; ses nombres sont le taux, 100, et la part gardée sur 100 (= le taux)
        $this->assertMatchesRegularExpression('/^Votre taux de marque, (le|du) .+ \d{4} : [\d,]+ %\. Sur 100,00 \$ vendus, il vous reste [\d,]+ \$ après le coût d\'achat\.$/u', $plain($site['illustration']));
        preg_match('/: ([\d,]+) %\. Sur 100,00 \$ vendus, il vous reste ([\d,]+) \$/u', $plain($site['illustration']), $m);
        $this->assertEqualsWithDelta((float) str_replace(',', '.', $m[1]), (float) str_replace(',', '.', $m[2]), 0.051, 'la part gardée sur 100 $ est le taux');
        $this->assertEqualsWithDelta($figures['grossMarginRate']['value'], (float) str_replace(',', '.', $m[1]), 0.051);
        $english = $this->get('/api/gestion/lexique/taux-de-marque?locale=en')['site'];
        $this->assertMatchesRegularExpression('/^Your margin rate, [A-Z][a-z]+ \d.* \d{4}: [\d.]+%\. Out of \$100\.00 sold, you keep \$[\d.]+ after the purchase cost\.$/u', $plain($english['illustration']));
        // Une notion sans analyse liée : la définition seule
        $this->assertNull($this->get('/api/gestion/lexique/promotion')['site']);

        // L'illustration, sur des chiffres choisis : formes, accords, et silence quand la donnée manque
        $this->em();
        $illustration = static::getContainer()->get(LexiqueIllustration::class);
        $of = fn (string $key, array $values, string $locale = 'fr', string $label = 'du 1er au 13 novembre 2026'): ?string => $plain($illustration->of($key, array_map(fn (string $id, mixed $v) => ['id' => "a1.$id", 'value' => $v, 'unit' => 'x'], array_keys($values), $values), ['label' => $label], $locale));
        $this->assertSame("Votre taux de marque, du 1er au 13 novembre 2026 : 44,2 %. Sur 100,00 $ vendus, il vous reste 44,20 $ après le coût d'achat.", $of('taux-de-marque', ['grossMarginRate' => 44.2, 'revenue' => 100000]));
        $this->assertSame([null, null, null], [$of('taux-de-marque', ['grossMarginRate' => 44.2, 'revenue' => 0]), $of('taux-de-marque', ['revenue' => 100000]), $of('promotion', ['revenue' => 100000])], 'aucune vente, chiffre absent, notion sans illustration : rien');
        $this->assertSame(['Chez vous, du 1er au 13 novembre 2026 : 50,00 $ de ventes en 1 commande, soit un panier moyen de 50,00 $.', 'Chez vous, du 1er au 13 novembre 2026 : 3 000,00 $ de ventes en 60 commandes, soit un panier moyen de 50,00 $.', null],
            [$of('panier-moyen', ['averageBasket' => 5000, 'revenue' => 5000, 'orders' => 1]), $of('panier-moyen', ['averageBasket' => 5000, 'revenue' => 300000, 'orders' => 60]), $of('panier-moyen', ['averageBasket' => 0, 'revenue' => 0, 'orders' => 0])]);
        $this->assertSame(["1 article sur 52 n'a pas de coût d'achat : sa marge reste inconnue.", "7 articles sur 52 n'ont pas de coût d'achat : leur marge reste inconnue.", "Tous vos articles ont un coût d'achat : leur marge est connue.", null],
            [$of('cout-d-achat', ['missingCostItems' => 1, 'items' => 52]), $of('cout-d-achat', ['missingCostItems' => 7, 'items' => 52]), $of('cout-d-achat', ['missingCostItems' => 0, 'items' => 52]), $of('cout-d-achat', ['missingCostItems' => 0, 'items' => 0])]);
        $this->assertSame(['Sur la période Automne 2026 : 2 000,00 $ prévus, 1 400,00 $ dépensés, il reste 600,00 $.', null],
            [$of('reste', ['planned' => 200000, 'actual' => 140000, 'remaining' => 60000], 'fr', 'Automne 2026'), $of('budget', ['planned' => 0, 'actual' => 140000, 'remaining' => -140000], 'fr', 'Automne 2026')]);
        $this->assertSame(['Sur la période Automne 2026 : 6 000,00 $ de marge brute, moins 4 500,00 $ de frais, soit un résultat de 1 500,00 $.', 'Exercice 2026 : votre point mort est de 10 000,00 $ de ventes ; vous en êtes à 7 250,00 $.'],
            [$of('resultat', ['netResult' => 150000, 'grossMargin' => 600000, 'expenses' => 450000], 'fr', 'Automne 2026'), $of('point-mort', ['breakEvenSales' => 1000000, 'sales' => 725000, 'gap' => 275000], 'fr', 'Exercice 2026')]);
        $this->assertSame(['2 items are low on stock right now.', 'No item is low on stock right now.', 'For you, November 1 to 13, 2026: $50.00 of sales in 1 order, an average order value of $50.00.'],
            [$of('stock-bas', ['lowStockItems' => 2], 'en'), $of('stock-bas', ['lowStockItems' => 0], 'en'), $of('panier-moyen', ['averageBasket' => 5000, 'revenue' => 5000, 'orders' => 1], 'en', 'November 1 to 13, 2026')]);
        // L'accord est fait par le serveur, sur 0, 1 et 2 (relecture du chef de projet) : jamais de parenthèse d'accord ni de « a/ont »
        $this->assertSame([null, 'Chez vous, du 1er au 13 novembre 2026 : 50,00 $ de ventes en 1 commande, soit un panier moyen de 50,00 $.', 'Chez vous, du 1er au 13 novembre 2026 : 100,00 $ de ventes en 2 commandes, soit un panier moyen de 50,00 $.'],
            array_map(fn (int $n) => $of('panier-moyen', ['averageBasket' => $n === 0 ? 0 : 5000, 'revenue' => 5000 * $n, 'orders' => $n]), [0, 1, 2]));
        $this->assertSame(["Tous vos articles ont un coût d'achat : leur marge est connue.", "1 article sur 52 n'a pas de coût d'achat : sa marge reste inconnue.", "2 articles sur 52 n'ont pas de coût d'achat : leur marge reste inconnue."],
            array_map(fn (int $n) => $of('cout-d-achat', ['missingCostItems' => $n, 'items' => 52]), [0, 1, 2]));
        $this->assertSame(["Aucun article n'est en stock bas en ce moment.", '1 article est en stock bas en ce moment.', '2 articles sont en stock bas en ce moment.'], array_map(fn (int $n) => $of('stock-bas', ['lowStockItems' => $n]), [0, 1, 2]));
        $this->assertSame(['No item is low on stock right now.', '1 item is low on stock right now.', '2 items are low on stock right now.'], array_map(fn (int $n) => $of('stock-bas', ['lowStockItems' => $n], 'en'), [0, 1, 2]));
        $everything = ['grossMarginRate' => 44.2, 'revenue' => 100000, 'grossMargin' => 44200, 'orders' => 2, 'averageBasket' => 50000, 'netResult' => 1000, 'expenses' => 43200, 'planned' => 5000, 'actual' => 2000, 'remaining' => 3000,
            'breakEvenSales' => 90000, 'sales' => 100000, 'balance' => 500000, 'balanceDate' => '2026-11-11', 'lowestAmount' => 100000, 'lowestDate' => '2026-12-01', 'missingCostItems' => 2, 'items' => 52, 'lowStockItems' => 2, 'stockSaleValue' => 900000, 'stockCostValue' => 400000];
        $illustrated = 0;
        foreach (LexiqueCatalog::keys() as $key) {
            foreach (['fr', 'en'] as $locale) {
                $sentence = $of($key, $everything, $locale);
                if ($sentence !== null) {
                    ++$illustrated;
                    $this->assertDoesNotMatchRegularExpression('/\p{L}\((?:s|es|e|x|nt|ent)\)|\p{L}\/\p{L}|%\w+%|\|/u', $sentence, "$key ($locale) : une phrase accordée, sans gabarit ni forme double");
                }
            }
        }
        $this->assertSame(30, $illustrated, 'quinze notions illustrées, dans les deux langues');
        $this->assertSame(0, count(FakeLandingAiClient::$requests));
    }

    public function testVerificationQuestionsAreCorrectedByTheServerForFree(): void
    {
        $conn = fn () => $this->em()->getConnection();
        $rows = fn (): array => [(int) $conn()->fetchOne('SELECT COUNT(*) FROM ai_usage'), (int) $conn()->fetchOne('SELECT COUNT(*) FROM gestion_conversation'), (int) $conn()->fetchOne('SELECT COUNT(*) FROM gestion_conversation_message')];
        $before = $rows();
        $quiz = $this->get('/api/gestion/lexique/taux-de-marque/quiz');
        $this->assertSame(['key', 'term', 'questions'], array_keys($quiz));
        $this->assertSame(['taux-de-marque', 'Taux de marque', ['q1', 'q2']], [$quiz['key'], $quiz['term'], array_column($quiz['questions'], 'id')]);
        $this->assertSame(['id' => 'q1', 'question' => "Votre taux de marque est de 40 %. Sur 100 $ de ventes, combien vous reste-t-il après le coût d'achat ?",
            'choices' => [['id' => 'a', 'label' => '40 $'], ['id' => 'b', 'label' => '60 $'], ['id' => 'c', 'label' => '140 $']]], $quiz['questions'][0]);
        $raw = (string) $this->client->getResponse()->getContent();
        foreach (['correct', 'explanation', 'answer'] as $leak) {
            $this->assertStringNotContainsString($leak, $raw, 'la liste des questions ne rend jamais la bonne réponse');
        }
        $this->assertSame('Margin rate', $this->get('/api/gestion/lexique/taux-de-marque/quiz?locale=en')['term']);
        $this->assertSame([], $this->get('/api/gestion/lexique/promotion-variante/quiz')['questions'], 'une notion sans question');

        // La réponse, par bouton : corrigée par le serveur
        $right = $this->post('/api/gestion/lexique/taux-de-marque/quiz/q1', ['choice' => 'a'], 200);
        $this->assertSame(['correct' => true, 'correctChoice' => 'a', 'explanation' => "À 40 %, sur 100 $ vendus, 40 $ sont de la marge et 60 $ payaient le coût d'achat."], $right);
        $wrong = $this->post('/api/gestion/lexique/taux-de-marque/quiz/q1', ['choice' => 'b'], 200);
        $this->assertSame([false, 'a', $right['explanation']], [$wrong['correct'], $wrong['correctChoice'], $wrong['explanation']]);
        $this->assertSame([true, 'b'], [$this->post('/api/gestion/lexique/taux-de-marque/quiz/q2', ['choice' => 'b'], 200)['correct'], $this->post('/api/gestion/lexique/taux-de-marque/quiz/q2', ['choice' => 'c'], 200)['correctChoice']]);
        $this->assertStringStartsWith('At 40%, out of $100 sold', $this->post('/api/gestion/lexique/taux-de-marque/quiz/q1?locale=en', ['choice' => 'a'], 200)['explanation']);
        // Toutes les questions du lexique : la bonne réponse de la structure est reconnue, une autre ne l'est pas
        foreach (LexiqueCatalog::ENTRIES as $key => $structure) {
            $this->assertCount(count($structure['quiz']), $this->get("/api/gestion/lexique/$key/quiz")['questions'], $key);
            foreach ($structure['quiz'] as $question => $answer) {
                $other = $answer === 'a' ? 'b' : 'a';
                $this->assertSame([true, false], [$this->post("/api/gestion/lexique/$key/quiz/$question", ['choice' => $answer], 200)['correct'], $this->post("/api/gestion/lexique/$key/quiz/$question", ['choice' => $other], 200)['correct']], "$key.$question");
            }
        }
        // Refus
        foreach ([[[], 'REQUIRED'], [['choice' => 'd'], 'INVALID_CHOICE'], [['choice' => 1], 'INVALID_CHOICE'], [['choice' => 'A'], 'INVALID_CHOICE']] as [$body, $code]) {
            $this->assertSame($code, $this->post('/api/gestion/lexique/marge/quiz/q1', $body, 422)['fields']['choice'] ?? null, json_encode($body));
        }
        $this->assertSame(['LEXIQUE_QUESTION_NOT_FOUND', 'LEXIQUE_TERM_NOT_FOUND', 'LEXIQUE_TERM_NOT_FOUND'], [$this->post('/api/gestion/lexique/marge/quiz/q9', ['choice' => 'a'], 404)['code'],
            $this->post('/api/gestion/lexique/notion-inconnue/quiz/q1', ['choice' => 'a'], 404)['code'], $this->get404('/api/gestion/lexique/notion-inconnue/quiz')]);
        $this->assertSame([$before, 0], [$rows(), count(FakeLandingAiClient::$requests)], 'gratuit : aucun appel au modèle, aucune unité, rien d\'enregistré');
    }

    public function testTheConsoleTeachesADefinitionForFreeAndTheLearnModeAddsLessons(): void
    {
        $tag = $this->tag;
        $conn = fn () => $this->em()->getConnection();
        $plain = fn (?string $v): ?string => $v === null ? null : preg_replace('/[\x{00A0}\x{202F}]/u', ' ', $v);
        $usage = fn (): array => array_column($conn()->fetchAllAssociative('SELECT status, COUNT(*) AS n FROM ai_usage WHERE id > ? GROUP BY status ORDER BY status', [$this->usageMark]), 'n', 'status');
        $none = ['periodId' => null, 'productId' => null, 'year' => null, 'days' => null, 'range' => null, 'term' => null];
        $text = fn (string $t, array $ids = []): array => ['type' => 'text', 'text' => $t, 'figureIds' => $ids, 'analysis' => '', 'items' => []];
        $payload = fn (int $i): array => json_decode(FakeLandingAiClient::$requests[$i]['messages'][0]['content'][0]['text'], true);
        $last = fn (int $id): array => array_slice($this->get("/api/gestion/console/conversations/$id")['messages'], -1)[0];
        $today = static::getContainer()->get(PeriodCalendar::class)->today()->format('Y-m-d');
        $supplier = $this->supplier("Grossiste leçons $tag");
        [, $variant] = $this->article($supplier, "Article leçons $tag", 1000, 2500, $today);
        $this->sale($variant, 2, 2500, 2500, $today);
        $this->endOfWrite();

        // 1. « C'est quoi le taux de marque ? » : le modèle ne fait que désigner la notion ; le serveur assemble la leçon et ses chiffres, sans appel 2 : 0 unité
        $a = $this->post('/api/gestion/console/messages', ['text' => "C'est quoi le taux de marque ?"], 202);
        $this->assertSame(['reserved' => 1], $usage());
        FakeLandingAiClient::$queue = [$this->reply(['kind' => 'analyses', 'clarification' => '', 'analyses' => [['key' => 'lexique.term', 'term' => 'taux-de-marque'] + $none]])];
        $this->runWorker((int) $a['messageId']);
        $this->assertCount(1, FakeLandingAiClient::$requests, 'un seul appel au modèle : choisir ; rien n\'est rédigé');
        $this->assertSame(['failed' => 1], $usage(), 'l\'unité réservée est rendue : une définition seule est gratuite');
        $answer = $last((int) $a['conversationId']);
        $this->assertSame(['done', ['lesson', 'figures'], []], [$answer['state'], array_column($answer['blocks'], 'type'), $answer['proposals']]);
        $lesson = $answer['blocks'][0];
        $this->assertSame(['type', 'key', 'term', 'definition', 'illustration', 'related', 'quiz', 'source'], array_keys($lesson));
        $this->assertSame(['taux-de-marque', 'Taux de marque', "La part de marge dans le prix de vente. À 40 %, sur 100 $ vendus, il vous reste 40 $ après le coût d'achat.", 2,
            ['screen' => 'lexique', 'route' => 'GET /api/gestion/lexique/{term}', 'params' => ['term' => 'taux-de-marque']], [['key' => 'marge', 'term' => 'Marge brute'], ['key' => 'coefficient', 'term' => 'Coefficient'], ['key' => 'point-mort', 'term' => 'Point mort']]],
            [$lesson['key'], $lesson['term'], $lesson['definition'], $lesson['quiz'], $lesson['source'], $lesson['related']]);
        $this->assertMatchesRegularExpression('/^Votre taux de marque, (le|du) .+ : [\d,]+ %\. Sur 100,00 \$ vendus, il vous reste [\d,]+ \$ après le coût d\'achat\.$/u', $plain($lesson['illustration']));
        $this->assertSame($plain($this->get('/api/gestion/lexique/taux-de-marque')['site']['illustration']), $plain($lesson['illustration']), 'la même leçon que la route du lexique');
        $this->assertSame([['a1.grossMarginRate', 'a1.revenue', 'a1.grossMargin'], 'taux-de-marque', 'statistiques'],
            [array_column($answer['blocks'][1]['items'], 'id'), $answer['blocks'][1]['items'][0]['term'], $answer['blocks'][1]['items'][0]['source']['screen']]);
        $this->assertSame([['lexique.term', ['term' => 'taux-de-marque'], 'Une notion expliquée']], array_map(fn (array $x) => [$x['key'], $x['params'], $x['title']], $answer['analyses']));
        $this->assertSame(1, (int) $conn()->fetchOne("SELECT COUNT(*) FROM gestion_conversation_message WHERE conversation_id = ? AND content::jsonb ->> 'lesson' = 'true'", [$a['conversationId']]), 'chaque leçon assemblée par le serveur est marquée : on peut les compter');
        $this->assertArrayNotHasKey('lesson', $answer, 'la marque reste au serveur');
        // Ce que le modèle a lu à l'appel 1 : le lexique (clé, terme, synonymes) et l'analyse lexique.term, 11e du catalogue
        $first = $payload(0);
        $this->assertSame([62, ['key' => 'taux-de-marque', 'term' => 'Taux de marque', 'synonymes' => ['taux de marge']], 11], [count($first['lexique']), array_column($first['lexique'], null, 'key')['taux-de-marque'], count($first['analyses'])]);
        $this->assertContains('lexique.term', array_column($first['analyses'], 'key'));
        $this->assertStringContainsString('une question de DÉFINITION', (string) FakeLandingAiClient::$requests[0]['system']);
        $schema = FakeLandingAiClient::$requests[0]['output_config']['format']['schema']['properties']['analyses']['items'];
        $this->assertSame([LexiqueCatalog::keys(), true], [$schema['properties']['term']['anyOf'][0]['enum'], in_array('term', $schema['required'], true)], 'le modèle ne peut désigner qu\'une notion du lexique');

        // 2. La question suivante du même échange reçoit la leçon dans l'échange (texte seul)
        $b = $this->post('/api/gestion/console/messages', ['text' => 'Et la marge brute ?', 'conversationId' => $a['conversationId']], 202);
        FakeLandingAiClient::$queue = [$this->reply(['kind' => 'analyses', 'clarification' => '', 'analyses' => [['key' => 'lexique.term', 'term' => 'marge'] + $none, ['key' => 'lexique.term', 'term' => 'notion-inventee'] + $none, ['key' => 'lexique.term'] + $none]])];
        $this->runWorker((int) $b['messageId']);
        $this->assertStringStartsWith('Taux de marque : La part de marge dans le prix de vente.', $payload(1)['echange'][1]['text']);
        $second = $last((int) $a['conversationId']);
        $this->assertSame([['lesson', 'figures'], 'marge'], [array_column($second['blocks'], 'type'), $second['blocks'][0]['key']], 'une notion inconnue ou absente est écartée par le serveur');
        $this->assertSame(['failed' => 2], $usage());

        // 3. Une notion inconnue seule : le serveur demande une précision, gratuitement
        $c = $this->post('/api/gestion/console/messages', ['text' => "C'est quoi le goodwill ?"], 202);
        FakeLandingAiClient::$queue = [$this->reply(['kind' => 'analyses', 'clarification' => '', 'analyses' => [['key' => 'lexique.term', 'term' => 'goodwill'] + $none]])];
        $this->runWorker((int) $c['messageId']);
        $this->assertStringStartsWith('Pouvez-vous préciser votre question ?', $last((int) $c['conversationId'])['blocks'][0]['text']);
        $this->assertSame(['failed' => 3], $usage());

        // 4. Une définition ET un état : la leçon passe devant, le modèle rédige le reste (1 unité) ; les nombres de la définition sont admis
        $d = $this->post('/api/gestion/console/messages', ['text' => "C'est quoi la marge, et où en est la mienne ce mois-ci ?"], 202);
        $margin = array_column(static::getContainer()->get(AnalysisCatalog::class)->run('sales.overview', ['range' => 'month'], 'fr')['figures'], 'value', 'id')['grossMargin'];
        $this->em();
        FakeLandingAiClient::$queue = [
            $this->reply(['kind' => 'analyses', 'clarification' => '', 'analyses' => [['key' => 'lexique.term', 'term' => 'marge'] + $none, ['key' => 'sales.overview', 'range' => 'month'] + $none]]),
            $this->reply(['blocks' => [$text('Comme dans l\'exemple, un article acheté 20 $ et vendu 50 $ donne 30 $ de marge. La vôtre est de ' . number_format($margin / 100, 2, ',', ' ') . ' $ ce mois-ci.', ['a2.grossMargin'])], 'proposals' => []]),
        ];
        $requests = count(FakeLandingAiClient::$requests);
        $this->runWorker((int) $d['messageId']);
        $mixed = $last((int) $d['conversationId']);
        $this->assertSame([$requests + 2, ['failed' => 3, 'success' => 1]], [count(FakeLandingAiClient::$requests), $usage()], 'deux appels, 1 unité : une réponse rédigée');
        // la marge du mois est rendue par la leçon (a1) et par les ventes du mois (a2) : c'est un seul chiffre, celui de la première analyse (lot W2-c)
        $this->assertSame([['lesson', 'text', 'figures'], 'marge', ['a1.grossMargin'], ['a1.grossMargin']], [array_column($mixed['blocks'], 'type'), $mixed['blocks'][0]['key'], $mixed['blocks'][1]['figureIds'], array_column($mixed['blocks'][2]['items'], 'id')]);
        $sent = $payload($requests + 1)['resultats'][0]['lecon'];
        $this->assertSame(['terme', 'definition', 'illustration'], array_keys($sent));
        $this->assertSame(['Marge brute', "Ventes moins le coût d'achat des articles vendus. Exemple : un article acheté 20 $ et vendu 50 $ donne 30 $ de marge."], [$sent['terme'], $sent['definition']]);
        $this->assertStringContainsString('« lecon »', (string) FakeLandingAiClient::$requests[$requests + 1]['system']);
        $this->assertSame(0, (int) $conn()->fetchOne("SELECT COUNT(*) FROM gestion_conversation_message WHERE conversation_id = ? AND content::jsonb ->> 'lesson' = 'true'", [$d['conversationId']]), 'réponse rédigée : pas la marque d\'une leçon gratuite');

        // 5. Mode « apprendre » : les leçons des notions des chiffres montrés, dans l'ordre du lexique, 3 au plus, sans appel ni unité de plus
        $figures = ['type' => 'figures', 'text' => '', 'figureIds' => ['a1.averageBasket', 'a1.orders', 'a1.grossMarginRate', 'a1.revenue', 'a1.grossMargin'], 'analysis' => '', 'items' => []];
        $ask = function (array $more) use ($none, $text, &$figures, $last): array {
            $asked = $this->post('/api/gestion/console/messages', ['text' => 'Où en sont les ventes de ce mois ?'] + $more, 202);
            FakeLandingAiClient::$queue = [$this->reply(['kind' => 'analyses', 'clarification' => '', 'analyses' => [['key' => 'sales.overview', 'range' => 'month'] + $none]]), $this->reply(['blocks' => [$text('Voici vos ventes.'), $figures], 'proposals' => []])];
            $this->runWorker((int) $asked['messageId']);

            return [$last((int) $asked['conversationId']), $this->get("/api/gestion/console/conversations/{$asked['conversationId']}")['messages'][0]];
        };
        $requests = count(FakeLandingAiClient::$requests);
        [$learned, $question] = $ask(['mode' => 'learn']);
        $this->assertSame(['text', 'figures', 'lesson', 'lesson', 'lesson'], array_column($learned['blocks'], 'type'));
        $this->assertSame(['ventes', 'marge', 'taux-de-marque'], array_column(array_slice($learned['blocks'], 2), 'key'), 'cinq notions montrées (ventes, marge, taux de marque, panier moyen, commandes) : les trois premières du lexique');
        $this->assertSame(ConsoleResponder::MAX_LESSONS, count(array_filter($learned['blocks'], fn (array $b) => $b['type'] === 'lesson')));
        $this->assertMatchesRegularExpression('/^Chez vous, (le|du) .+ : [\d ,]+ \$ de ventes hors taxes\.$/u', $plain($learned['blocks'][2]['illustration']), 'illustrée par les chiffres de la réponse');
        $this->assertSame([$requests + 2, ['failed' => 3, 'success' => 2]], [count(FakeLandingAiClient::$requests), $usage()], 'ni appel ni unité de plus');
        $this->assertSame(['messageId', 'role', 'text', 'context', 'createdAt'], array_keys($question), 'le mode n\'est pas rendu : le serveur ne le retient pas, l\'application l\'envoie à chaque question');
        // Sans le mode, ou avec une valeur inconnue : aucune leçon ajoutée
        foreach ([[], ['mode' => 'cours'], ['mode' => true]] as $more) {
            $this->assertSame(['text', 'figures'], array_column($ask($more)[0]['blocks'], 'type'), json_encode($more));
        }
        // Avec une notion demandée : elle compte dans les trois
        $e = $this->post('/api/gestion/console/messages', ['text' => "C'est quoi la marge, et où en sont mes ventes ?", 'mode' => 'learn'], 202);
        FakeLandingAiClient::$queue = [$this->reply(['kind' => 'analyses', 'clarification' => '', 'analyses' => [['key' => 'lexique.term', 'term' => 'marge'] + $none, ['key' => 'sales.overview', 'range' => 'month'] + $none]]),
            $this->reply(['blocks' => [$text('Voici vos ventes.'), ['type' => 'figures', 'text' => '', 'figureIds' => ['a2.revenue', 'a2.grossMargin', 'a2.grossMarginRate', 'a2.averageBasket'], 'analysis' => '', 'items' => []]], 'proposals' => []])];
        $this->runWorker((int) $e['messageId']);
        $both = $last((int) $e['conversationId']);
        $this->assertSame([['lesson', 'text', 'figures', 'lesson', 'lesson'], ['marge', 'ventes', 'taux-de-marque']], [array_column($both['blocks'], 'type'), array_column(array_values(array_filter($both['blocks'], fn (array $b) => $b['type'] === 'lesson')), 'key')],
            'la leçon demandée d\'abord, puis deux autres : trois en tout, jamais deux fois la même (la marge est aussi un chiffre montré)');
        // L'ordre des leçons est celui du lexique, pas celui des cartes : le panier moyen passe avant les commandes
        $figures['figureIds'] = ['a1.orders', 'a1.averageBasket'];
        $this->assertSame(['panier-moyen', 'commandes'], array_column(array_values(array_filter($ask(['mode' => 'learn'])[0]['blocks'], fn (array $b) => $b['type'] === 'lesson')), 'key'));
    }

    // ---- outils --------------------------------------------------------------------------------------------------------------------

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

    private function get404(string $route): ?string
    {
        $this->request('GET', $route);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode(), $route);

        return $this->body()['code'] ?? null;
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
        $email = 'gestion-lexique-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('Lexique')->setRoles($roles)->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->fixtureUserId = $this->fixtureUserId ?: (int) $user->getId();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
