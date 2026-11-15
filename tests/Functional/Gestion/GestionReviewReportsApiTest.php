<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Gestion\Entity\GestionConversationMessage;
use App\Gestion\Message\GenerateGestionReportMessage;
use App\Gestion\Services\Console\ConsoleService;
use App\Gestion\Services\PeriodCalendar;
use App\Gestion\Services\Report\GestionReportService;
use App\Gestion\Services\Report\GestionReportStore;
use App\Gestion\Services\Report\ReportRenderer;
use App\Gestion\Services\Report\ReportValue;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\TenantCacheService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeLandingAiClient;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use App\Tests\Functional\Support\AiRightTestHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Rapports PDF de la gestion, lot DOC-2 (15/11/2026, `docs/rapports-gestion.md`) : bilans de période et d'année. Les chiffres du
 * bilan sont ceux des écrans, au cent ; une période non clôturée porte la mention « provisoire » ; sans avis, aucun modèle n'est
 * appelé et aucune unité n'est décomptée ; la même période sans changement de ses chiffres rend le fichier déjà prêt. Avec
 * `withAdvice`, le bilan se termine par l'avis de la console : 1 unité, rendue quand l'avis ne peut pas être rédigé.
 */
class GestionReviewReportsApiTest extends WebTestCase
{
    use CacheTestEntityFactory;
    use PricingFixtures;

    private KernelBrowser $client;
    private string $token = '';
    private string $tag = '';
    private int $usageMark = 0;
    private int $period = 0;
    private int $variant = 0;
    private string $today = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeLandingAiClient::reset();
        AiRightTestHelper::restoreFullAi(static::getContainer());
        $this->tag = bin2hex(random_bytes(3));
        $this->login(['ROLE_ADMIN']);
        $conn = $this->em()->getConnection();
        $this->usageMark = (int) $conn->fetchOne('SELECT COALESCE(MAX(id), 0) FROM ai_usage');
        $conn->executeStatement('DELETE FROM gestion_report');
        $conn->executeStatement("DELETE FROM gestion_conversation WHERE kind = 'console'");
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $this->today = $calendar->today()->format('Y-m-d');
        $this->period = (int) $calendar->current()->getId();
        // la période en cours : un article acheté 10,00 $, vendu 25,00 $ trois fois aujourd'hui, et un budget d'achats de 3 000,00 $
        [, $this->variant] = $this->article($this->supplier("Grossiste bilans {$this->tag}"), "Bilan {$this->tag}", 1000, 2500, $this->today);
        $this->sale($this->variant, 3, 2500, 2500, $this->today);
        $conn = $this->em()->getConnection();
        $conn->executeStatement('DELETE FROM gestion_budget_line WHERE period_id = ?', [$this->period]);
        $conn->executeStatement("INSERT INTO gestion_budget_line (period_id, category, planned_cents, updated_at) VALUES (?, 'purchases', 300000, NOW())", [$this->period]);
        $this->endOfWrite();
    }

    protected function tearDown(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('DELETE FROM gestion_report');
        $conn->executeStatement('DELETE FROM ai_usage WHERE id > ?', [$this->usageMark]);
        $conn->executeStatement("DELETE FROM gestion_conversation WHERE kind = 'console'");
        $conn->executeStatement('DELETE FROM gestion_budget_line WHERE period_id = ?', [$this->period]);
        $this->removePricingFixtures();
        foreach (glob(static::getContainer()->getParameter('kernel.project_dir') . '/var/storage/private_media/gestion-rapports/' . MV_TEST_TENANT_CODE . '/*.pdf') ?: [] as $file) {
            @unlink($file);
        }
        AiRightTestHelper::restoreFullAi(static::getContainer());
        $this->endOfWrite();
        parent::tearDown();
    }

    public function testAPeriodReviewCarriesTheFiguresOfTheScreensWithoutAnyModelCall(): void
    {
        $conn = fn () => $this->em()->getConnection();
        $pid = $this->period;
        $counts = fn (): array => [(int) $conn()->fetchOne('SELECT COUNT(*) FROM gestion_report'), (int) $conn()->fetchOne("SELECT COUNT(*) FROM gestion_conversation WHERE kind = 'console'"), (int) $conn()->fetchOne('SELECT COUNT(*) FROM ai_usage WHERE id > ?', [$this->usageMark])];

        // Demandes refusées : rien n'est créé
        foreach ([[['kind' => 'period'], ['periodId' => 'REQUIRED']], [['kind' => 'period', 'periodId' => 'x'], ['periodId' => 'INVALID_ID']], [['kind' => 'period', 'periodId' => 0], ['periodId' => 'INVALID_ID']],
            [['kind' => 'year', 'year' => 1999], ['year' => 'OUT_OF_RANGE']], [['kind' => 'year', 'year' => '2026'], ['year' => 'OUT_OF_RANGE']], [['kind' => 'year', 'year' => (int) substr($this->today, 0, 4) + 2], ['year' => 'OUT_OF_RANGE']], [['kind' => 'year', 'year' => (int) substr($this->today, 0, 4) + 1], ['year' => 'OUT_OF_RANGE']], [['kind' => 'year', 'year' => 2025.5], ['year' => 'OUT_OF_RANGE']],
            [['kind' => 'period', 'periodId' => $pid, 'withAdvice' => 'oui'], ['withAdvice' => 'MUST_BE_BOOLEAN']], [['kind' => 'period', 'periodId' => $pid, 'locale' => 'de'], ['locale' => 'INVALID_VALUE']],
            [['kind' => 'livre', 'withAdvice' => true], ['kind' => 'INVALID_VALUE']]] as [$body, $fields]) {
            $this->request('POST', '/api/gestion/rapports', $body);
            $this->assertSame([422, $fields], [$this->status(), $this->body()['fields'] ?? null], json_encode($body));
        }
        $this->request('POST', '/api/gestion/rapports', ['kind' => 'period', 'periodId' => 2147483000]);
        $this->assertSame([404, 'PERIOD_NOT_FOUND'], [$this->status(), $this->body()['code']]);
        // une collection qui n'est pas une période (une collection faite à la main) n'a pas de bilan de période
        $manual = (int) $this->create(\App\Entity\Collections::class, ['kind' => \App\Entity\Collections::KIND_MANUAL])->getId();
        $this->request('POST', '/api/gestion/rapports', ['kind' => 'period', 'periodId' => $manual]);
        $this->assertSame([404, 'PERIOD_NOT_FOUND'], [$this->status(), $this->body()['code']]);
        $this->em()->getConnection()->executeStatement('DELETE FROM collections WHERE id = ?', [$manual]);
        $this->endOfWrite();
        $this->assertSame([0, 0, 0], $counts());

        // Demande acceptée : en préparation, confiée au worker pdf ; les chiffres sont déjà calculés et gardés avec le rapport
        $report = $this->post(['kind' => 'period', 'periodId' => $pid], 202);
        $id = (int) $report['id'];
        $name = PeriodCalendar::displayName($this->em()->find(\App\Entity\Collections::class, $pid), 'fr');
        $this->assertSame(['period', 'pending', 'fr', ['periodId' => $pid, 'withAdvice' => false], "Bilan de la période $name", null, null, false, 0, null], [$report['kind'], $report['state'], $report['locale'], $report['params'], $report['title'],
            $report['download'], $report['advice'], $report['reused'], $report['unitsCharged'], $report['credits']]);
        $this->assertCount(1, array_filter(static::getContainer()->get('messenger.transport.pdf')->getSent(), fn ($e) => $e->getMessage() instanceof GenerateGestionReportMessage && $e->getMessage()->reportId === $id));
        $same = $this->post(['kind' => 'period', 'periodId' => $pid], 202);
        $this->assertSame([$id, true], [$same['id'], $same['reused']], 'la même demande pendant la préparation ne crée pas un second rapport');

        // Les chiffres du site changent APRÈS la demande : le worker met en page ceux de la demande, sans recalcul
        $stored = static::getContainer()->get(GestionReportStore::class);
        $this->em();
        $content = $stored->find($id)['content'];
        $this->sale($this->variant, 1, 2500, 2500, $this->today);
        $this->endOfWrite();
        $this->generate($id);
        $done = $this->get("/api/gestion/rapports/$id");
        $this->assertSame(['done', null, null, 0, null], [$done['state'], $done['error'], $done['advice'], $done['unitsCharged'], $done['credits']]);
        $this->assertGreaterThanOrEqual(1, $done['pages']);
        $this->assertMatchesRegularExpression('/^rapport-bilan-de-la-periode-[a-z0-9-]+-\d+\.pdf$/', $done['download']['filename']);
        $this->request('GET', "/api/gestion/rapports/$id/fichier");
        $this->assertSame(200, $this->status());
        $this->assertStringStartsWith('%PDF-', (string) file_get_contents($this->client->getResponse()->getFile()->getPathname()));
        $this->em();
        $this->assertSame($content, $stored->find($id)['content'], 'le contenu calculé à la demande n\'est pas refait par le worker');
        $this->assertSame([0, [1, 0, 0]], [count(FakeLandingAiClient::$requests), $counts()], 'sans avis : aucun appel au modèle, aucune question à la console, aucune unité');

        // Le document, avant sa mise en PDF : les chiffres de la demande (3 ventes à 25,00 $, coût 10,00 $), ceux des analyses nommées
        $renderer = static::getContainer()->get(ReportRenderer::class);
        $currency = static::getContainer()->get(TenantCurrencyProvider::class)->code(true);
        $html = self::plain($renderer->html($renderer->review($content, null, 'fr', $currency)));
        $money = fn (int $cents, string $locale = 'fr'): string => self::plain(ReportValue::write($cents, 'money', $locale, $currency));
        $figures = [];
        foreach ($content['blocks'] as $block) {
            foreach ($block['type'] === 'figures' ? $block['items'] : [] as $item) {
                $figures[$item['source']['screen']][$item['id']] = $item['value'];
            }
        }
        $this->assertSame(['period.result', 'sales.overview', 'budget.status', 'stock.overview'], array_column($content['analyses'], 'key'), 'la période en cours porte aussi le stock d\'aujourd\'hui');
        $this->assertSame(array_column($content['analyses'], 'key'), array_column($content['selected'], 'key'));
        foreach (["Bilan de la période $name", 'Bilan de période', 'Chiffres provisoires au ', 'la période n\'est pas clôturée', 'Résultat d\'une période', 'Budget : prévu et réel', 'Postes du budget', 'Stock : valeur et articles en stock bas', 'D\'où viennent ces chiffres', 'Chiffres calculés le '] as $expected) {
            $this->assertStringContainsString(htmlspecialchars($expected, ENT_QUOTES), $html, $expected);
        }
        $this->assertStringNotContainsString('Avis de la console', $html);
        $this->assertStringNotContainsString('Question posée', $html);
        // chaque analyse fait une partie, sous son titre (le même titre revient aussi dans « d'où viennent ces chiffres »)
        foreach (['Résultat d\'une période', 'Ventes et marge', 'Budget : prévu et réel', 'Stock : valeur et articles en stock bas'] as $part) {
            $this->assertStringContainsString('<h2 class="part">' . htmlspecialchars($part, ENT_QUOTES) . '</h2>', $html, $part);
        }
        foreach ($figures as $screen => $list) {
            foreach ($list as $figureId => $value) {
                $unit = null;
                foreach ($content['blocks'] as $block) {
                    foreach ($block['type'] === 'figures' ? $block['items'] : [] as $item) {
                        $unit = $item['id'] === $figureId && $item['source']['screen'] === $screen ? $item['unit'] : $unit;
                    }
                }
                $this->assertStringContainsString(htmlspecialchars(self::plain(ReportValue::write($value, $unit, 'fr', $currency)), ENT_QUOTES), $html, "$screen.$figureId");
            }
        }
        $this->assertStringContainsString($money(300000), $html, 'le budget prévu');

        // … et ces chiffres sont ceux des écrans, au cent : le bilan refait maintenant (4 ventes) contre les routes des écrans
        $second = $this->post(['kind' => 'period', 'periodId' => $pid], 202);
        $this->assertNotSame($id, $second['id'], 'les chiffres de la période ont changé : un nouveau bilan');
        $this->em();
        $fresh = $stored->find((int) $second['id'])['content'];
        $now = [];
        foreach ($fresh['blocks'] as $block) {
            foreach ($block['type'] === 'figures' ? $block['items'] : [] as $item) {
                $now[$item['source']['screen']][$item['id']] = $item['value'];
            }
        }
        $result = $this->get("/api/periodes/$pid/resultat");
        $stats = $this->get("/api/gestion/statistiques?periodId=$pid");
        $budget = $this->get("/api/periodes/$pid/budget");
        $this->assertSame([$result['sales']['revenue'], $result['sales']['orders'], $result['cogs']['amount'], $result['result']['gross'], $result['result']['net']],
            [$now['periode.resultat']['revenue'], $now['periode.resultat']['orders'], $now['periode.resultat']['cogs'], $now['periode.resultat']['grossMargin'], $now['periode.resultat']['netResult']]);
        $this->assertSame([$stats['indicators']['revenue']['value'], $stats['indicators']['grossMargin']['value'], $stats['indicators']['orders']['value']], [$now['statistiques']['revenue'], $now['statistiques']['grossMargin'], $now['statistiques']['orders']]);
        $this->assertSame([$budget['total']['planned'], $budget['total']['actual'], 300000], [$now['periode.budget']['planned'], $now['periode.budget']['actual'], $now['periode.budget']['planned']]);
        $this->assertSame($figures['periode.resultat']['revenue'] + 2500, $now['periode.resultat']['revenue'], 'une vente de plus depuis le premier bilan');
        $this->generate((int) $second['id']);

        // La même période sans changement de ses chiffres : le fichier déjà prêt, rien de créé ; l'anglais est un autre rapport
        $again = $this->post(['kind' => 'period', 'periodId' => $pid], 200);
        $this->assertSame([$second['id'], true, 'done'], [$again['id'], $again['reused'], $again['state']]);
        $english = $this->post(['kind' => 'period', 'periodId' => $pid, 'locale' => 'en'], 202);
        $this->em();
        $en = self::plain($renderer->html($renderer->review($stored->find((int) $english['id'])['content'], null, 'en', $currency)));
        foreach (['Period review', 'Provisional figures as of ', 'the period is not closed', 'Budget lines', $money($now['periode.resultat']['revenue'], 'en')] as $expected) {
            $this->assertStringContainsString(htmlspecialchars($expected, ENT_QUOTES), $en, $expected);
        }
        $this->assertNotSame($second['id'], $english['id']);

        // Une période clôturée : ses chiffres sont figés, la mention le dit ; pas de stock d'aujourd'hui pour une période passée
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $this->em();
        $past = $calendar->periodFor($calendar->today()->modify('-200 days'));
        $this->assertNotNull($past);
        $this->assertTrue($calendar->isClosed($past));
        $closed = $this->post(['kind' => 'period', 'periodId' => (int) $past->getId()], 202);
        $this->em();
        $closedContent = $stored->find((int) $closed['id'])['content'];
        $this->assertSame(['period.result', 'sales.overview', 'budget.status'], array_column($closedContent['analyses'], 'key'));
        $this->assertStringStartsWith('Période clôturée le ', $closedContent['notice']);
        $this->assertStringNotContainsString('provisoire', $closedContent['notice']);

        // La liste : les bilans du site, filtrables par genre ; lecture seule de bout en bout
        $list = $this->get('/api/gestion/rapports?kind=period');
        $this->assertSame([4, ['period']], [$list['total'], array_values(array_unique(array_column($list['reports'], 'kind')))]);
        $this->assertSame(0, $this->get('/api/gestion/rapports?kind=year')['total']);
        $this->assertSame([0, [4, 0, 0]], [count(FakeLandingAiClient::$requests), $counts()]);

        // Le plafond du jour vaut pour les bilans, et il refuse AVANT la question à la console : ni échange, ni unité réservée
        $conn()->executeStatement("INSERT INTO gestion_report (kind, params, state, title, locale, fingerprint, expires_at) SELECT 'console.answer', '{}', 'failed', 'Plein', 'fr', 'plein-' || g, NOW() + INTERVAL '90 days' FROM generate_series(1, 26) g");
        foreach ([['kind' => 'year', 'year' => (int) substr($this->today, 0, 4) - 2], ['kind' => 'year', 'year' => (int) substr($this->today, 0, 4) - 2, 'withAdvice' => true]] as $body) {
            $this->request('POST', '/api/gestion/rapports', $body);
            $this->assertSame([429, 'REPORT_DAILY_LIMIT', GestionReportService::DAILY_LIMIT], [$this->status(), $this->body()['code'], $this->body()['limit']], json_encode($body));
        }
        $this->assertSame([GestionReportService::DAILY_LIMIT, 0, 0], $counts());
    }

    public function testAYearReviewShowsTheMonthsInOrderAndTheFiscalYearOfTheScreen(): void
    {
        $stored = static::getContainer()->get(GestionReportStore::class);
        $year = (int) substr($this->today, 0, 4);
        $screen = $this->get('/api/gestion/statistiques/annee');

        // Sans `year` : l'exercice en cours, celui de l'écran
        $report = $this->post(['kind' => 'year'], 202);
        $this->assertSame(['year', ['year' => $screen['year'], 'withAdvice' => false], 'Bilan de l\'année ' . $screen['fiscalYear']['label'], null], [$report['kind'], $report['params'], $report['title'], $report['credits']]);
        $this->em();
        $content = $stored->find((int) $report['id'])['content'];
        $this->assertSame([['year.overview'], [['key' => 'year.overview', 'params' => ['year' => $screen['year']]]]], [array_column($content['analyses'], 'key'), $content['selected']]);
        $this->assertStringStartsWith('Chiffres provisoires au ', $content['notice']);
        $this->assertStringContainsString('l\'exercice n\'est pas terminé', $content['notice']);
        $figures = [];
        $table = null;
        foreach ($content['blocks'] as $block) {
            foreach ($block['type'] === 'figures' ? $block['items'] : [] as $item) {
                $figures[$item['id']] = $item['value'];
            }
            $table = $block['type'] === 'table' ? $block : $table;
        }
        $this->assertSame([$screen['totals']['sales'], $screen['totals']['grossMargin'], $screen['totals']['result']], [$figures['sales'], $figures['grossMargin'], $figures['netResult']], 'les chiffres de l\'écran Année, au cent');
        // le mois par mois sort en tableau, dans l'ordre de l'exercice (la console, elle, les range du meilleur au moins bon)
        $this->assertNotNull($table);
        $keys = array_column($table['rows'], 'monthKey');
        $sorted = $keys;
        sort($sorted);
        $this->assertSame(['Mois par mois', $sorted, ['month', 'sales', 'grossMargin', 'result', 'rank']], [$table['title'], $keys, array_column($table['columns'], 'key')]);
        $this->assertContains(substr($this->today, 0, 7), $keys, 'le mois en cours, avec les ventes de ce test');
        $this->assertNotContains(substr($this->today, 0, 7), array_column($table['rows'], 'month'), 'le mois est écrit en toutes lettres');
        $this->generate((int) $report['id']);
        $done = $this->get('/api/gestion/rapports/' . $report['id']);
        $this->assertSame(['done', null], [$done['state'], $done['advice']]);
        $this->assertMatchesRegularExpression('/^rapport-bilan-de-l-annee-[a-z0-9-]+-\d+\.pdf$/', $done['download']['filename']);
        $renderer = static::getContainer()->get(ReportRenderer::class);
        $currency = static::getContainer()->get(TenantCurrencyProvider::class)->code(true);
        $html = self::plain($renderer->html($renderer->review($content, null, 'fr', $currency)));
        foreach (['Bilan de l\'année', 'Mois par mois', self::plain(ReportValue::write($screen['totals']['sales'], 'money', 'fr', $currency))] as $expected) {
            $this->assertStringContainsString(htmlspecialchars($expected, ENT_QUOTES), $html, $expected);
        }

        // La même année nommée : le même bilan (fichier déjà prêt) ; une année terminée n'est pas « provisoire »
        $named = $this->post(['kind' => 'year', 'year' => $screen['year']], 200);
        $this->assertSame([$report['id'], true], [$named['id'], $named['reused']]);
        $past = $this->post(['kind' => 'year', 'year' => $year - 3], 202);
        $this->em();
        $this->assertSame([['year' => $year - 3, 'withAdvice' => false], null], [$past['params'], $stored->find((int) $past['id'])['content']['notice']]);
        $this->assertSame(0, count(FakeLandingAiClient::$requests));
    }

    public function testTheAdviceOfTheConsoleCostsOneUnitAndIsGivenBackWhenItCannotBeWritten(): void
    {
        $conn = fn () => $this->em()->getConnection();
        $pid = $this->period;
        $usage = fn (): array => array_column($conn()->fetchAllAssociative('SELECT status, COUNT(*) AS n FROM ai_usage WHERE id > ? GROUP BY status ORDER BY status', [$this->usageMark]), 'n', 'status');
        $counts = fn (): array => [(int) $conn()->fetchOne('SELECT COUNT(*) FROM gestion_report'), (int) $conn()->fetchOne("SELECT COUNT(*) FROM gestion_conversation WHERE kind = 'console'"), $usage()];
        $stored = static::getContainer()->get(GestionReportStore::class);
        $written = fn (string $text): object => $this->reply(['blocks' => [['type' => 'text', 'text' => $text, 'figureIds' => ['a1.revenue'], 'analysis' => '', 'items' => []]], 'proposals' => []]);

        // Refus avant toute création : palier d'IA, puis crédits épuisés ; une réponse de la console n'a pas d'avis
        $master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $master->prepare("DELETE FROM tenant_entitlements WHERE tenant_code = ? AND feature_key IN ('ai.full', 'ai.help')")->execute([MV_TEST_TENANT_CODE]);
        $this->assertSame('AI_NOT_INCLUDED', $this->post(['kind' => 'period', 'periodId' => $pid, 'withAdvice' => true], 403)['code']);
        $plainWithoutAi = $this->post(['kind' => 'period', 'periodId' => $pid, 'withAdvice' => false], 202);
        $this->assertSame([null, null], [$plainWithoutAi['credits'], $plainWithoutAi['advice']], 'sans avis, le bilan ne demande pas le palier d\'IA');
        AiRightTestHelper::restoreFullAi(static::getContainer());
        $monthly = $conn()->fetchOne('SELECT monthly_credits FROM ai_credit_setting ORDER BY id LIMIT 1');
        try {
            $monthly === false ? $conn()->executeStatement('INSERT INTO ai_credit_setting (monthly_credits) VALUES (0)') : $conn()->executeStatement('UPDATE ai_credit_setting SET monthly_credits = 0');
            static::getContainer()->get(TenantCacheService::class)->invalidateTenant();
            $this->assertSame('AI_CREDITS_EXHAUSTED', $this->post(['kind' => 'period', 'periodId' => $pid, 'withAdvice' => true], 402)['code']);
        } finally {
            $monthly === false ? $conn()->executeStatement('DELETE FROM ai_credit_setting') : $conn()->executeStatement('UPDATE ai_credit_setting SET monthly_credits = ?', [$monthly]);
            static::getContainer()->get(TenantCacheService::class)->invalidateTenant();
        }
        $this->assertSame([1, 0, []], $counts(), 'rien de créé par les refus : ni rapport, ni question, ni unité');
        $this->generate((int) $plainWithoutAi['id']);
        $plainSize = $this->get('/api/gestion/rapports/' . $plainWithoutAi['id'])['size'];

        // Avec l'avis : la question est posée à la console au nom du gérant, 1 unité est réservée, le rapport attend
        $report = $this->post(['kind' => 'period', 'periodId' => $pid, 'withAdvice' => true], 202);
        $id = (int) $report['id'];
        $this->assertSame(['pending', ['periodId' => $pid, 'withAdvice' => true], null, 0, false], [$report['state'], $report['params'], $report['advice'], $report['unitsCharged'], $report['reused']]);
        $this->assertSame(['monthly', 'used', 'remaining', 'resetAt'], array_keys($report['credits']), 'le solde du site, pour une demande avec avis');
        $this->assertSame([2, 1, ['reserved' => 1]], $counts());
        $this->em();
        $advice = $stored->find($id)['advice'];
        $messageId = (int) $advice['messageId'];
        $pendingAnswer = ConsoleService::message($this->em()->find(GestionConversationMessage::class, $messageId));
        $this->assertSame(ConsoleService::STATE_PENDING, $pendingAnswer['state']);
        $same = $this->post(['kind' => 'period', 'periodId' => $pid, 'withAdvice' => true], 202);
        $this->assertSame([$id, true], [$same['id'], $same['reused']], 'la même demande pendant la préparation : ni second rapport, ni seconde question');
        $this->assertSame([2, 1, ['reserved' => 1]], $counts());

        // Le worker fait rédiger l'avis sur les analyses du bilan, imposées par le serveur : UN seul appel au modèle (il ne choisit rien)
        $this->em();
        $revenue = self::plain(ReportValue::write($stored->find($id)['content']['blocks'][1]['items'][0]['value'], 'money', 'fr', 'CAD'));
        FakeLandingAiClient::$queue[] = $written("Votre période tient debout : les ventes atteignent $revenue. Surveillez le budget des achats.");
        $this->generate($id);
        $done = $this->get("/api/gestion/rapports/$id");
        $this->assertSame(['done', ['included' => true], 1, null], [$done['state'], $done['advice'], $done['unitsCharged'], $done['error']]);
        $this->assertSame([1, ['success' => 1]], [count(FakeLandingAiClient::$requests), $usage()], 'un seul appel (la rédaction), l\'unité décomptée');
        $this->assertGreaterThan($plainSize, $done['size'], 'le fichier porte la partie « Avis de la console » en plus du bilan');
        $answer = ConsoleService::message($this->em()->find(GestionConversationMessage::class, $messageId));
        $this->assertSame(['done', ['period.result', 'sales.overview', 'budget.status', 'stock.overview']], [$answer['state'], array_column($answer['analyses'], 'key')], 'les analyses de l\'avis sont celles du bilan');
        $this->assertStringContainsString('Donne ton avis sur le bilan de cette période', $this->get('/api/gestion/console/conversations/' . $advice['conversationId'])['messages'][0]['text'], 'l\'échange se relit dans l\'historique de la console');
        $renderer = static::getContainer()->get(ReportRenderer::class);
        $this->em();
        $html = self::plain($renderer->html($renderer->review($stored->find($id)['content'], ['included' => true, 'blocks' => $answer['blocks'], 'answeredAt' => $answer['answeredAt']], 'fr', 'CAD')));
        foreach (['Avis de la console', 'Rédigé par l\'IA le ', "Votre période tient debout : les ventes atteignent $revenue."] as $expected) {
            $this->assertStringContainsString(htmlspecialchars($expected, ENT_QUOTES), $html, $expected);
        }
        $this->assertLessThan(strpos($html, 'Avis de la console'), strpos($html, 'Postes du budget'), 'l\'avis ferme le bilan');

        // La même demande : le fichier déjà prêt, aucune nouvelle question, aucune nouvelle unité
        $again = $this->post(['kind' => 'period', 'periodId' => $pid, 'withAdvice' => true], 200);
        $this->assertSame([$id, true, ['included' => true], 1], [$again['id'], $again['reused'], $again['advice'], $again['unitsCharged']]);
        $this->assertSame([2, 1, ['success' => 1]], $counts());

        // L'IA échoue : le bilan sort sans avis, l'unité est rendue ; redemander l'avis le redemande vraiment
        $this->sale($this->variant, 1, 2500, 2500, $this->today);
        $this->endOfWrite();
        $failing = $this->post(['kind' => 'period', 'periodId' => $pid, 'withAdvice' => true], 202);
        FakeLandingAiClient::$queue[] = new \RuntimeException('panne simulée');
        $this->generate((int) $failing['id']);
        $failed = $this->get('/api/gestion/rapports/' . $failing['id']);
        $this->assertSame(['done', ['included' => false, 'code' => 'AI_UNAVAILABLE'], 0], [$failed['state'], $failed['advice'], $failed['unitsCharged']]);
        $this->assertSame(['failed' => 1, 'success' => 1], $usage());
        $this->em();
        $missing = self::plain($renderer->html($renderer->review($stored->find((int) $failing['id'])['content'], ['included' => false], 'fr', 'CAD')));
        $this->assertStringContainsString(htmlspecialchars('L\'avis de la console n\'a pas pu être rédigé cette fois. Aucune unité n\'a été décomptée', ENT_QUOTES), $missing);
        $nude = $this->post(['kind' => 'period', 'periodId' => $pid], 202);
        $this->assertSame([false, ['periodId' => $pid, 'withAdvice' => false]], [$nude['reused'], $nude['params']], 'ce fichier dit que l\'avis manque : la demande sans avis fait un bilan propre');
        $this->assertNotSame($failing['id'], $nude['id']);
        $retry = $this->post(['kind' => 'period', 'periodId' => $pid, 'withAdvice' => true], 202);
        $this->assertNotSame($failing['id'], $retry['id']);
        $this->assertSame(false, $retry['reused']);

        // Une réponse de repli (le modèle écrit deux fois un nombre que le serveur n'a pas fourni) n'est pas un avis : rien n'est décompté
        FakeLandingAiClient::$queue = [$written('Vous avez gagné 987 654 $.'), $written('Vous avez gagné 987 654 $.')];
        $this->generate((int) $retry['id']);
        $fallback = $this->get('/api/gestion/rapports/' . $retry['id']);
        $this->assertSame(['done', ['included' => false, 'code' => 'AI_UNAVAILABLE'], 0], [$fallback['state'], $fallback['advice'], $fallback['unitsCharged']]);
        $this->assertSame(['failed' => 2, 'success' => 1], $usage());

        // Délai de la console dépassé avant le worker (file occupée) : le worker clôt lui-même la réponse, sans appeler le modèle ; l'avis manque, l'unité est rendue
        $this->sale($this->variant, 1, 2500, 2500, $this->today);
        $this->endOfWrite();
        $late = $this->post(['kind' => 'year', 'withAdvice' => true], 202);
        $this->em();
        $lateAdvice = $stored->find((int) $late['id'])['advice'];
        $conn()->executeStatement("UPDATE gestion_conversation_message SET created_at = NOW() - INTERVAL '121 seconds' WHERE id = ?", [$lateAdvice['messageId']]);
        $requests = count(FakeLandingAiClient::$requests);
        $this->generate((int) $late['id']);
        $timedOut = $this->get('/api/gestion/rapports/' . $late['id']);
        $this->assertSame(['done', ['included' => false, 'code' => 'CONSOLE_TIMEOUT'], 0, $requests], [$timedOut['state'], $timedOut['advice'], $timedOut['unitsCharged'], count(FakeLandingAiClient::$requests)]);
        $this->assertSame(['failed' => 3, 'success' => 1], $usage());
    }

    private function generate(int $id): void
    {
        $this->em();
        static::getContainer()->get(GestionReportService::class)->generate($id);
        $this->em()->clear();
        $this->endOfWrite();
    }

    private function reply(array $output): object
    {
        return json_decode(json_encode([
            'id' => 'msg_' . bin2hex(random_bytes(4)), 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => json_encode($output)]],
            'usage' => ['input_tokens' => 3000, 'output_tokens' => 500, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
        ]), false);
    }

    private static function plain(string $text): string
    {
        return str_replace(["\u{00A0}", "\u{202F}", '&nbsp;'], ' ', $text);
    }

    private function post(array $body, int $expected): array
    {
        $this->request('POST', '/api/gestion/rapports', $body);
        $this->assertSame($expected, $this->status(), json_encode($body) . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 600));

        return $this->body();
    }

    private function request(string $method, string $route, ?array $body = null): void
    {
        $this->client->request($method, $route, [], [], array_filter(['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $this->token !== '' ? 'Bearer ' . $this->token : null]), $body === null ? null : json_encode($body));
    }

    private function get(string $route): array
    {
        $this->request('GET', $route);
        $this->assertSame(200, $this->status(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 400));

        return $this->body();
    }

    private function status(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    private function body(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
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
        $email = 'bilans-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Rose')->setLastname('Bilan')->setRoles($roles)->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->fixtureUserId = $this->fixtureUserId ?: (int) $user->getId();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
