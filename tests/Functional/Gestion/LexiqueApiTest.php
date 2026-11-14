<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Gestion\Analysis\AnalysisCatalog;
use App\Gestion\Lexique\LexiqueCatalog;
use App\Gestion\Lexique\LexiqueIllustration;
use App\Gestion\Lexique\LexiqueTexts;
use App\Gestion\Lexique\ScreenGuides;
use App\Gestion\Message\AnswerConsoleQuestionMessage;
use App\Gestion\MessageHandler\AnswerConsoleQuestionHandler;
use App\Gestion\Services\Console\ConsoleResponder;
use App\Gestion\Services\Console\ConsoleScreens;
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
        // RÈGLE (lot A-4) : le libellé d'une carte reprend le terme du lexique de sa notion (« Taux de marque », jamais « Taux de marge »
        // sous une leçon « Taux de marque »), dans les deux langues. Vérifié sur toute la table, puis sur les chiffres rendus.
        foreach (LexiqueCatalog::FIGURE_TERMS as $figure => $term) {
            $name = $figure === 'year.overview.netResult' ? 'businessResult' : (str_contains($figure, '.') ? substr($figure, (int) strrpos($figure, '.') + 1) : $figure);
            foreach (['fr' => $byKey, 'en' => $byKeyEn] as $locale => $entries) {
                $label = $translator->trans("console.figures.$name", ['%days%' => '30'], 'gestion', $locale);
                $this->assertNotSame("console.figures.$name", $label, "$figure ($locale) : libellé du chiffre");
                $this->assertNotFalse(mb_stripos($label, $entries[$term]['term']), "$figure ($locale) : le libellé « $label » doit reprendre le terme « {$entries[$term]['term']} » de sa notion");
            }
        }
        foreach (array_keys(AnalysisCatalog::DEFINITIONS) as $analysis) {
            foreach (['fr' => $byKey, 'en' => $byKeyEn] as $locale => $entries) {
                $params = $catalog->accept($analysis, ['term' => 'marge', 'screen' => 'tresorerie']);
                foreach ($params === null ? [] : $catalog->run($analysis, $params, $locale)['figures'] as $f) {
                    if (isset($f['term'])) {
                        $this->assertNotFalse(mb_stripos((string) $f['label'], $entries[$f['term']]['term']), "$analysis.{$f['id']} ($locale) : « {$f['label']} » / « {$entries[$f['term']]['term']} »");
                    }
                }
            }
        }
        $this->em();
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
        $this->assertMatchesRegularExpression('/^Votre taux de marque, ce mois-ci : [\d,]+ %\. Sur 100,00 \$ vendus, il vous reste [\d,]+ \$ après le coût d\'achat\.$/u', $plain($site['illustration']), 'le mois a des ventes : l\'illustration le dit (« ce mois-ci »)');
        $this->assertSame('ce mois-ci', $site['period']['phrase']);
        preg_match('/: ([\d,]+) %\. Sur 100,00 \$ vendus, il vous reste ([\d,]+) \$/u', $plain($site['illustration']), $m);
        $this->assertEqualsWithDelta((float) str_replace(',', '.', $m[1]), (float) str_replace(',', '.', $m[2]), 0.051, 'la part gardée sur 100 $ est le taux');
        $this->assertEqualsWithDelta($figures['grossMarginRate']['value'], (float) str_replace(',', '.', $m[1]), 0.051);
        $english = $this->get('/api/gestion/lexique/taux-de-marque?locale=en')['site'];
        $this->assertMatchesRegularExpression('/^Your margin rate, this month: [\d.]+%\. Out of \$100\.00 sold, you keep \$[\d.]+ after the purchase cost\.$/u', $plain($english['illustration']));
        // Une notion sans analyse liée : la définition seule
        $this->assertNull($this->get('/api/gestion/lexique/promotion')['site']);

        // Lot W2-d : « un prof a toujours un exemple quand des données existent ». Le mois, puis la période en cours, puis la dernière
        // période terminée qui a des ventes ; l'illustration dit toujours sa plage.
        $catalog = static::getContainer()->get(AnalysisCatalog::class);
        $conn = $this->em()->getConnection();
        $current = $calendar->current();
        $name = PeriodCalendar::displayName($current, 'fr');
        $this->assertSame([['range' => 'month'], ['periodId' => (int) $current->getId()]], array_slice($catalog->salesCandidates(), 0, 2), 'ordre des essais : le mois, la période en cours…');
        $this->em();
        $past = array_slice($catalog->salesCandidates(), 2);
        $this->em();
        $this->assertLessThanOrEqual(AnalysisCatalog::EXAMPLE_PAST_PERIODS, count($past));
        foreach ($past as $candidate) {
            $this->assertLessThan($current->getStartDateCollection()->format('Y-m-d'), (string) $conn->fetchOne('SELECT end_date_collection::date FROM collections WHERE id = ?', [$candidate['periodId']]), '… puis des périodes TERMINÉES');
        }
        // deux périodes à venir, vides, et une période passée qui a des ventes (la saison de l'an dernier, où un article s'est vendu)
        $season = PeriodCalendar::definition(\DateTimeImmutable::createFromInterface($current->getStartDateCollection())->modify('-1 year'), $calendar->frequency());
        [, $old] = $this->article($supplier, "Article ancien $tag", 1000, 2500, $season['start']->modify('+2 days')->format('Y-m-d'));
        $this->sale($old, 3, 2500, 2500, $season['start']->modify('+16 days')->format('Y-m-d'));
        $insert = "INSERT INTO collections (id, budget_collection, start_date_collection, end_date_collection, del, nom_collection, is_closed, kind, period_key) VALUES (nextval('collections_id_seq'), 0, ?, ?, false, ?, false, 'period', ?) RETURNING id";
        $emptyA = (int) $conn->fetchOne($insert, [(new \DateTimeImmutable('+800 days'))->format('Y-m-d'), (new \DateTimeImmutable('+830 days'))->format('Y-m-d'), "Vide A $tag", "LX-$tag-a"]);
        $emptyB = (int) $conn->fetchOne($insert, [(new \DateTimeImmutable('+900 days'))->format('Y-m-d'), (new \DateTimeImmutable('+930 days'))->format('Y-m-d'), "Vide B $tag", "LX-$tag-b"]);
        $finished = (int) $conn->fetchOne($insert, [$season['start']->format('Y-m-d'), $season['end']->format('Y-m-d'), "Passée $tag", "LX-$tag-p"]);
        $this->endOfWrite();
        try {
            $example = function (array $candidates, string $locale = 'fr') use ($catalog): array {
                $r = $catalog->salesExample($locale, $candidates);
                $this->em();

                return [(array) $r['source']['params'], $r['period']['phrase'], array_column($r['figures'], 'value', 'id')['revenue'] > 0];
            };
            $this->assertSame([['range' => 'month'], 'ce mois-ci', true], $example([['range' => 'month'], ['periodId' => (int) $current->getId()]]), 'le mois a des ventes : le mois');
            $this->assertSame([['periodId' => (int) $current->getId()], "sur la période $name", true], $example([['periodId' => $emptyA], ['periodId' => (int) $current->getId()], ['periodId' => $finished]]), 'premier essai vide : la période en cours');
            $this->assertSame([['periodId' => $finished], "sur la période Passée $tag", true], $example([['periodId' => $emptyA], ['periodId' => $emptyB], ['periodId' => $finished]]), 'les deux premiers vides : la dernière période terminée qui a des ventes');
            $this->assertSame([['periodId' => $emptyA], "sur la période Vide A $tag", false], $example([['periodId' => $emptyA], ['periodId' => $emptyB]]), 'aucune vente nulle part : le premier essai, sans exemple');
            $this->assertSame("over the period Passée $tag", $example([['periodId' => $emptyA], ['periodId' => $finished]], 'en')[1]);
            // et l'illustration le dit
            $fallback = $catalog->salesExample('fr', [['periodId' => $emptyA], ['periodId' => $finished]]);
            $this->em();
            $this->assertSame("Chez vous, sur la période Passée $tag : 75,00 $ de ventes hors taxes.", $plain(static::getContainer()->get(LexiqueIllustration::class)->of('ventes', $fallback['figures'], $fallback['period'], 'fr')));
            $empty = $catalog->salesExample('fr', [['periodId' => $emptyA]]);
            $this->em();
            $this->assertNull(static::getContainer()->get(LexiqueIllustration::class)->of('ventes', $empty['figures'], $empty['period'], 'fr'), 'sans aucune vente : pas d\'exemple, la définition suffit');
        } finally {
            $this->em()->getConnection()->executeStatement('DELETE FROM collections WHERE id IN (?, ?, ?)', [$emptyA, $emptyB, $finished]);
            $this->endOfWrite();
        }

        // L'illustration, sur des chiffres choisis : formes, accords, et silence quand la donnée manque
        $this->em();
        $illustration = static::getContainer()->get(LexiqueIllustration::class);
        $of = fn (string $key, array $values, string $locale = 'fr', string $label = 'du 1er au 13 novembre 2026'): ?string => $plain($illustration->of($key, array_map(fn (string $id, mixed $v) => ['id' => "a1.$id", 'value' => $v, 'unit' => 'x'], array_keys($values), $values), ['label' => $label], $locale));
        $this->assertSame("Votre taux de marque, du 1er au 13 novembre 2026 : 44,2 %. Sur 100,00 $ vendus, il vous reste 44,20 $ après le coût d'achat.", $of('taux-de-marque', ['grossMarginRate' => 44.2, 'revenue' => 100000]));
        // un nombre dérivé se calcule sur le nombre AFFICHÉ juste avant : la part gardée sur le taux à une décimale (vu sur demo : « 43,9 % … 43,92 $ »)
        $this->assertSame(["Votre taux de marque, sur la période Automne 2026 : 43,9 %. Sur 100,00 $ vendus, il vous reste 43,90 $ après le coût d'achat.", "Votre taux de marque, sur la période Automne 2026 : 44 %. Sur 100,00 $ vendus, il vous reste 44,00 $ après le coût d'achat."],
            [$plain($illustration->of('taux-de-marque', [['id' => 'grossMarginRate', 'value' => 43.92], ['id' => 'revenue', 'value' => 117070]], ['label' => 'Automne 2026', 'phrase' => 'sur la période Automne 2026'], 'fr')),
                $plain($illustration->of('taux-de-marque', [['id' => 'grossMarginRate', 'value' => 43.96], ['id' => 'revenue', 'value' => 117070]], ['label' => 'Automne 2026', 'phrase' => 'sur la période Automne 2026'], 'fr'))]);
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
        $this->assertMatchesRegularExpression('/^Votre taux de marque, ce mois-ci : [\d,]+ %\. Sur 100,00 \$ vendus, il vous reste [\d,]+ \$ après le coût d\'achat\.$/u', $plain($lesson['illustration']));
        $this->assertSame($plain($this->get('/api/gestion/lexique/taux-de-marque')['site']['illustration']), $plain($lesson['illustration']), 'la même leçon que la route du lexique');
        $this->assertSame([['a1.grossMarginRate', 'a1.revenue', 'a1.grossMargin'], 'taux-de-marque', 'statistiques'],
            [array_column($answer['blocks'][1]['items'], 'id'), $answer['blocks'][1]['items'][0]['term'], $answer['blocks'][1]['items'][0]['source']['screen']]);
        $this->assertSame([['lexique.term', ['term' => 'taux-de-marque'], 'Une notion expliquée']], array_map(fn (array $x) => [$x['key'], $x['params'], $x['title']], $answer['analyses']));
        $this->assertSame(1, (int) $conn()->fetchOne("SELECT COUNT(*) FROM gestion_conversation_message WHERE conversation_id = ? AND content::jsonb ->> 'lesson' = 'true'", [$a['conversationId']]), 'chaque leçon assemblée par le serveur est marquée : on peut les compter');
        $this->assertArrayNotHasKey('lesson', $answer, 'la marque reste au serveur');
        // Ce que le modèle a lu à l'appel 1 : le lexique (clé, terme, synonymes) et l'analyse lexique.term, 11e du catalogue
        $first = $payload(0);
        $this->assertSame([62, ['key' => 'taux-de-marque', 'term' => 'Taux de marque', 'synonymes' => ['taux de marge']], 12], [count($first['lexique']), array_column($first['lexique'], null, 'key')['taux-de-marque'], count($first['analyses'])]);
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

    /**
     * Lot W2-APPRENDRE A-4 (13/11/2026) : le guide de chaque écran (à quoi il sert, ce que veulent dire ses chiffres, quoi faire
     * ensuite), écrit par le serveur dans les deux langues, servi sans IA ; dans la console, « explique-moi cet écran » seul est
     * assemblé par le serveur (0 unité), l'écran venant toujours du contexte ; un état qui n'est pas un jugement se dit par une phrase.
     */
    public function testScreenGuidesAreServedForFreeAndTheConsoleExplainsTheScreen(): void
    {
        $conn = fn () => $this->em()->getConnection();
        $usage = fn (): array => array_column($conn()->fetchAllAssociative('SELECT status, COUNT(*) AS n FROM ai_usage WHERE id > ? GROUP BY status ORDER BY status', [$this->usageMark]), 'n', 'status');
        $none = ['periodId' => null, 'productId' => null, 'year' => null, 'days' => null, 'range' => null, 'term' => null];
        $last = fn (int $id): array => array_slice($this->get("/api/gestion/console/conversations/$id")['messages'], -1)[0];
        $payload = fn (int $i): array => json_decode(FakeLandingAiClient::$requests[$i]['messages'][0]['content'][0]['text'], true);

        // 1. La route : le guide d'un écran, sans IA
        $guide = $this->get('/api/gestion/ecrans/tresorerie/guide');
        $this->assertSame(['screen', 'title', 'theme', 'purpose', 'points', 'next'], array_keys($guide));
        $this->assertSame(['tresorerie', 'Trésorerie', ['key' => 'argent', 'label' => 'Argent']], [$guide['screen'], $guide['title'], $guide['theme']]);
        $this->assertStringStartsWith("Votre trésorerie : l'argent sur votre compte", $guide['purpose']);
        $this->assertSame([['label', 'text', 'term'], ['solde', 'point-bas', 'seuil', 'rapprochement'], 'Solde saisi'], [array_keys($guide['points'][0]), array_column($guide['points'], 'term'), $guide['points'][0]['label']]);
        $this->assertStringStartsWith('« Saisir un solde »', $guide['next']);
        $english = $this->get('/api/gestion/ecrans/tresorerie/guide?locale=en');
        $this->assertSame(['Entered balance', ['solde', 'point-bas', 'seuil', 'rapprochement'], 'Money'], [$english['points'][0]['label'], array_column($english['points'], 'term'), $english['theme']['label']]);
        $this->assertStringStartsWith('Your cash position: the money in your account', $english['purpose']);
        // un code du premier contrat reprend le guide de l'écran du web qui lui correspond ; un point sans notion du lexique rend term: null
        $alias = $this->get('/api/gestion/ecrans/periode.resultat/guide');
        $this->assertSame(['periode.resultat', $this->get('/api/gestion/ecrans/periode/guide')['purpose']], [$alias['screen'], $alias['purpose']]);
        $this->assertContains(null, array_column($this->get('/api/gestion/ecrans/a-recevoir/guide')['points'], 'term'), '« Reste à recevoir » n\'est pas le « reste » d\'un budget');
        $this->request('GET', '/api/gestion/ecrans/ecran.inconnu/guide');
        $this->assertSame([404, 'SCREEN_GUIDE_NOT_FOUND'], [$this->client->getResponse()->getStatusCode(), $this->body()['code']]);
        $admin = $this->token;
        $this->token = '';
        $this->request('GET', '/api/gestion/ecrans/tresorerie/guide');
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->login(['ROLE_USER']);
        $this->request('GET', '/api/gestion/ecrans/tresorerie/guide');
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->token = $admin;

        // 2. GARDE-FOU des textes : les 36 écrans de l'application web ont leur guide, dans les deux langues, et chaque point relié renvoie à une notion du lexique
        $this->em();
        $guides = static::getContainer()->get(ScreenGuides::class);
        $dir = static::getContainer()->getParameter('kernel.project_dir') . '/translations';
        $files = ['fr' => Yaml::parseFile("$dir/ecrans.fr.yaml"), 'en' => Yaml::parseFile("$dir/ecrans.en.yaml")];
        $this->assertSame(array_keys($files['fr']), array_keys($files['en']));
        $this->assertCount(36, $guides->codes(), 'les 36 écrans relevés dans l\'application web');
        $linked = 0;
        foreach (array_keys(ConsoleScreens::SCREENS) as $code) {
            $this->assertTrue($guides->has($code), "$code : un guide (le sien, ou celui de l'écran du web qu'il reprend)");
            $fr = $guides->guide($code, 'fr');
            $en = $guides->guide($code, 'en');
            $this->assertSame([$code, array_column($fr['points'], 'term')], [$fr['screen'], array_column($en['points'], 'term')], "$code : mêmes points dans les deux langues");
            $this->assertGreaterThanOrEqual(1, count($fr['points']), $code);
            $this->assertNotSame($fr['purpose'], $en['purpose'], "$code : le guide anglais n'est pas le texte français");
            foreach (['fr' => $fr, 'en' => $en] as $locale => $g) {
                foreach ([$g['purpose'], (string) $g['next'], ...array_column($g['points'], 'label'), ...array_column($g['points'], 'text')] as $text) {
                    $this->assertGreaterThan(1, mb_strlen($text), "$code ($locale) : un texte");
                    $this->assertDoesNotMatchRegularExpression('/\p{L}\((?:s|es|e|x|nt|ent)\)|\.points\.|\.purpose$|%\w+%/u', $text, "$code ($locale)");
                }
                $this->assertGreaterThan(40, mb_strlen($g['purpose']), "$code ($locale) : à quoi sert l'écran");
            }
            foreach (['fr' => $fr, 'en' => $en] as $locale => $g) {
                foreach ($g['points'] as $point) {
                    if ($point['term'] === null) {
                        continue;
                    }
                    $linked += $locale === 'fr' ? 1 : 0;
                    $this->assertTrue(LexiqueCatalog::has($point['term']), "$code : notion « {$point['term']} » inconnue du lexique");
                    // quand le libellé de l'écran diffère du terme du lexique, le texte fait le lien (relecture du chef de projet)
                    $term = static::getContainer()->get(LexiqueTexts::class)->term($point['term'], $locale);
                    $this->assertNotFalse(mb_stripos($point['label'] . ' ' . $point['text'], $term), "$code ($locale), point « {$point['label']} » : le libellé ou le texte doit dire « $term »");
                }
            }
        }
        $this->assertGreaterThanOrEqual(70, $linked, 'la plupart des points renvoient à une notion du lexique');
        foreach ($files['fr'] as $key => $texts) {
            $this->assertSame(array_keys($texts['points']), array_keys($files['en'][$key]['points']), "$key : mêmes points dans les deux fichiers");
        }

        // 3. Console : « Qu'est-ce que je dois regarder ici ? » depuis la Trésorerie. Le guide seul : assemblé par le serveur, 0 unité
        $balance = (int) $conn()->fetchOne('INSERT INTO gestion_cash_balance (balance_date, amount_cents, created_at, updated_at) VALUES (CURRENT_DATE - 2, 500000, NOW(), NOW()) RETURNING id');
        $this->endOfWrite();
        try {
            $treasury = ['module' => 'gestion', 'screen' => 'tresorerie', 'params' => []];
            // Lot A-4b : « explique-moi cet écran », sans autre demande, est reconnu par le SERVEUR. Aucun appel au modèle, aucune unité.
            // Les deux questions de l'essai réel du 13/11 (Trésorerie, puis l'écran d'une période), où le modèle ajoutait des analyses et rédigeait.
            // Réponse directe (14/11/2026) : le serveur répond dans la requête même. Le POST rend déjà « done », les blocs, les analyses et
            // les unités ; l'application n'attend pas le worker et n'a pas à relire l'échange.
            FakeLandingAiClient::$queue = [new \RuntimeException('le modèle ne doit pas être appelé')];
            $real = $this->post('/api/gestion/console/messages', ['text' => 'Explique-moi cet écran', 'context' => $treasury], 202);
            $this->assertSame(['done', null, ['guide', 'figures'], []], [$real['state'], $real['error'], array_column($real['blocks'], 'type'), $real['proposals']], 'le POST rend la réponse, pas une attente');
            $sentToWorker = fn (): int => count($this->client->getContainer()->get('messenger.transport.gestion_ai')->getSent());
            $this->assertSame(0, $sentToWorker(), 'rien n\'est confié au worker');
            $this->assertSame(['conversationId', 'questionMessageId', 'messageId', 'state', 'error', 'blocks', 'analyses', 'proposals', 'answeredAt', 'credits'], array_keys($real));
            $this->assertNotNull($real['answeredAt']);
            $this->em()->clear();
            $explained = $last((int) $real['conversationId']);
            $this->assertSame([$real['messageId'], $real['questionMessageId'], $real['blocks'], $real['analyses'], $real['answeredAt']], [$explained['messageId'], $explained['replyTo'], $explained['blocks'], $explained['analyses'], $explained['answeredAt']],
                'la réponse du POST est celle que garde l\'échange');
            $this->runWorker((int) $real['messageId']); // un worker qui passerait après ne change rien : la réponse est close
            $this->assertSame($explained, $last((int) $real['conversationId']));
            $this->assertSame([0, ['failed' => 1], 'done', ['guide', 'figures']], [count(FakeLandingAiClient::$requests), $usage(), $explained['state'], array_column($explained['blocks'], 'type')], 'ni appel au modèle, ni unité, ni texte');
            $this->assertSame([['screen.guide', ['screen' => 'tresorerie']], ['cash.projection', ['days' => 90]]], array_map(fn (array $x) => [$x['key'], $x['params']], $explained['analyses']));
            $periodId = (int) static::getContainer()->get(PeriodCalendar::class)->current()->getId();
            $this->em();
            $onPeriod = $this->post('/api/gestion/console/messages', ['text' => 'Explique-moi cet écran', 'context' => ['module' => 'gestion', 'screen' => 'periode', 'params' => ['periodId' => $periodId]]], 202);
            $this->assertSame(['done', 'guide'], [$onPeriod['state'], $onPeriod['blocks'][0]['type']]);
            $this->em()->clear();
            $period = $last((int) $onPeriod['conversationId']);
            $this->assertSame([0, 'done', 'guide', 'periode'], [count(FakeLandingAiClient::$requests), $period['state'], $period['blocks'][0]['type'], $period['blocks'][0]['screen']]);
            $this->assertSame([], array_values(array_filter(array_column($period['blocks'], 'type'), fn (string $t) => !in_array($t, ['guide', 'figures'], true))), 'le guide et des cartes, rien de rédigé');
            $this->assertSame([['screen.guide', ['screen' => 'periode']], ['period.result', ['periodId' => $periodId]], ['sales.overview', ['periodId' => $periodId]]], array_map(fn (array $x) => [$x['key'], $x['params']], $period['analyses']),
                'les chiffres du moment viennent des analyses de l\'écran, sur SA période');
            $this->assertSame(['failed' => 2], $usage());
            FakeLandingAiClient::$queue = [];
            // Ce que le serveur reconnaît comme « seulement expliquer l'écran », et ce qu'il laisse au modèle
            $only = fn (string $q): bool => ConsoleResponder::asksOnlyForTheScreen($q);
            $this->assertSame([true, true, true, true, true, true], array_map($only, ['Explique-moi cet écran', "Qu'est-ce que je dois regarder ici ?", 'À quoi sert cette page ?', 'Je ne comprends pas cette page', "C’est quoi cet écran ?", 'What should I look at here?']));
            $this->assertSame([false, false, false, false, false, false], array_map($only, ['Explique-moi cet écran : ma trésorerie va-t-elle tenir ?', 'Explique-moi cet écran et est-ce que ça va ?', "Montre-moi les ventes d'ici la fin du mois",
                'Combien ai-je vendu ici ?', 'Explique-moi le taux de marque', 'Explain this screen and tell me why sales are down']), 'une autre demande, une question d\'état, ou pas d\'écran désigné : le modèle choisit');
            // chaque raison de refus, seule : une question d'état sans seconde demande, une seconde demande sans question d'état
            $this->assertSame([false, false, false, false, false, false], array_map($only, ['Explique-moi pourquoi cet écran est vide', "Explique-moi combien j'ai vendu ici", 'Explique-moi cet écran : les achats du mois',
                'Explique-moi cet écran et est-il à jour', 'Explain this screen and tell me what to order', 'Explique-moi cet écran ; puis les budgets']));
            // Une question que le serveur ne reconnaît pas, mais où le modèle choisit le guide seul : assemblée par le serveur aussi (un appel, 0 unité)
            $a = $this->post('/api/gestion/console/messages', ['text' => 'Dis-m\'en plus sur ce que je vois', 'context' => $treasury], 202);
            $this->assertSame(['pending', [], [], 0], [$a['state'], $a['blocks'], $a['analyses'], count(FakeLandingAiClient::$requests)], 'il faut le modèle pour choisir : la réponse reste en attente, comme avant');
            $this->assertArrayNotHasKey('answeredAt', $a);
            $this->assertSame(1, $sentToWorker(), 'la réponse est confiée au worker, une seule fois');
            // le modèle nomme un autre écran : le serveur ne l'écoute pas, l'écran est celui du contexte
            FakeLandingAiClient::$queue = [$this->reply(['kind' => 'analyses', 'clarification' => '', 'analyses' => [['key' => 'screen.guide', 'screen' => 'reglages'] + $none]])];
            $this->runWorker((int) $a['messageId']);
            $answer = $last((int) $a['conversationId']);
            $this->assertSame([1, ['failed' => 3], 'done'], [count(FakeLandingAiClient::$requests), $usage(), $answer['state']], 'un seul appel au modèle, aucune unité');
            $this->assertSame(['guide', 'figures'], array_column($answer['blocks'], 'type'), 'le guide, puis les chiffres du moment de l\'écran');
            $block = $answer['blocks'][0];
            $this->assertSame(['type', 'screen', 'title', 'theme', 'purpose', 'points', 'next', 'source'], array_keys($block));
            $this->assertSame(['tresorerie', 'Trésorerie', $guide['purpose'], $guide['points'], ['screen' => 'ecran', 'route' => 'GET /api/gestion/ecrans/{screen}/guide', 'params' => ['screen' => 'tresorerie']]],
                [$block['screen'], $block['title'], $block['purpose'], $block['points'], $block['source']], 'le même guide que la route');
            $this->assertSame([['screen.guide', ['screen' => 'tresorerie']], ['cash.projection', ['days' => 90]]], array_map(fn (array $x) => [$x['key'], $x['params']], $answer['analyses']), 'les chiffres viennent de l\'analyse de l\'écran, avec son horizon');
            $ids = array_column($answer['blocks'][1]['items'], 'id');
            $this->assertLessThanOrEqual(6, count($ids));
            $this->assertContains('a2.balance', $ids);
            $this->assertSame(1, (int) $conn()->fetchOne("SELECT COUNT(*) FROM gestion_conversation_message WHERE conversation_id = ? AND content::jsonb ->> 'lesson' = 'true'", [$a['conversationId']]));
            $this->assertStringContainsString('« screen.guide » explique l\'écran', (string) FakeLandingAiClient::$requests[0]['system']);
            $this->assertStringContainsString('choisis « screen.guide » SEUL', (string) FakeLandingAiClient::$requests[0]['system']);
            $this->assertSame([12, true], [count($payload(0)['analyses']), in_array('screen.guide', array_column($payload(0)['analyses'], 'key'), true)]);
            // sans écran : pas de guide ; le serveur demande une précision
            $b = $this->post('/api/gestion/console/messages', ['text' => "C'est quoi cet écran ?"], 202);
            FakeLandingAiClient::$queue = [$this->reply(['kind' => 'analyses', 'clarification' => '', 'analyses' => [['key' => 'screen.guide'] + $none]])];
            $this->runWorker((int) $b['messageId']);
            $this->assertStringStartsWith('Pouvez-vous préciser votre question ?', $last((int) $b['conversationId'])['blocks'][0]['text']);
            // le guide ET une analyse : le guide passe devant, le modèle rédige le reste (1 unité) et lit le guide
            $c = $this->post('/api/gestion/console/messages', ['text' => 'Explique-moi cet écran : ma trésorerie va-t-elle tenir ?', 'context' => $treasury], 202);
            $requests = count(FakeLandingAiClient::$requests);
            FakeLandingAiClient::$queue = [$this->reply(['kind' => 'analyses', 'clarification' => '', 'analyses' => [['key' => 'screen.guide'] + $none, ['key' => 'cash.projection'] + $none]]),
                $this->reply(['blocks' => [['type' => 'text', 'text' => 'Votre trésorerie tient sur la durée regardée.', 'figureIds' => [], 'analysis' => '', 'items' => []]], 'proposals' => []])];
            $this->runWorker((int) $c['messageId']);
            $mixed = $last((int) $c['conversationId']);
            $this->assertSame([['guide', 'text'], 'tresorerie', ['failed' => 4, 'success' => 1]], [array_column($mixed['blocks'], 'type'), $mixed['blocks'][0]['screen'], $usage()], 'une question d\'état en plus : le modèle rédige (1 unité), le guide passe devant');
            $this->assertStringContainsString('Ne redis pas à quoi sert l\'écran', (string) FakeLandingAiClient::$requests[$requests + 1]['system']);
            $this->assertStringContainsString('n\'écris jamais « par rapport à du … »', (string) FakeLandingAiClient::$requests[$requests + 1]['system']);
            $sent = $payload($requests + 1)['resultats'][0]['guide'];
            $this->assertSame([['ecran', 'role', 'points'], 'Trésorerie', ['libelle', 'texte']], [array_keys($sent), $sent['ecran'], array_keys($sent['points'][0])]);
            $this->assertStringContainsString('« guide » décrit l\'écran', (string) FakeLandingAiClient::$requests[$requests + 1]['system']);
            // la question suivante lit le guide dans l'échange
            $d = $this->post('/api/gestion/console/messages', ['text' => 'Et le seuil ?', 'conversationId' => $a['conversationId'], 'context' => $treasury], 202);
            FakeLandingAiClient::$queue = [$this->reply(['kind' => 'analyses', 'clarification' => '', 'analyses' => [['key' => 'lexique.term', 'term' => 'seuil'] + $none]])];
            $this->runWorker((int) $d['messageId']);
            $this->assertStringStartsWith("Trésorerie : Votre trésorerie : l'argent sur votre compte", $payload(count(FakeLandingAiClient::$requests) - 1)['echange'][1]['text']);
        } finally {
            $conn()->executeStatement('DELETE FROM gestion_cash_balance WHERE id = ?', [$balance]);
        }

        // 4. Un état qui n'est pas un jugement est donné au modèle par une PHRASE entière ; un jugement, par son libellé en minuscule
        $responder = static::getContainer()->get(ConsoleResponder::class);
        $state = new \ReflectionMethod(ConsoleResponder::class, 'state');
        $state->setAccessible(true);
        $of = fn (string $id, string $code, string $lower, string $locale = 'fr'): string => $state->invoke($responder, ['id' => $id, 'code' => $code], $lower, $locale);
        $this->assertSame(["Aucun budget n'est prévu pour cette période", "Aucun solde n'a encore été saisi", 'correct', 'dépassé', 'à monter', 'No budget is planned for this period', 'No balance has been entered yet', 'fine'],
            [$of('a3.budgetStatus', 'none', 'sans prévu'), $of('a1.cashStatus', 'no_balance', 'aucun solde saisi'), $of('a1.cashStatus', 'ok', 'correct'), $of('a3.budgetStatus', 'over', 'dépassé'), $of('a1.verdict', 'raise', 'à monter'),
                $of('a3.budgetStatus', 'none', 'no plan', 'en'), $of('a1.cashStatus', 'no_balance', 'no balance entered', 'en'), $of('a1.cashStatus', 'ok', 'fine', 'en')]);
        $rules = new \ReflectionMethod(\App\Gestion\Services\Console\ConsoleModel::class, 'systemWrite');
        $rules->setAccessible(true);
        $this->assertStringContainsString('reprends cette phrase telle quelle', $rules->invoke(static::getContainer()->get(\App\Gestion\Services\Console\ConsoleModel::class), 'français'));
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
