<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Gestion\Entity\GestionConversation;
use App\Gestion\Entity\GestionConversationMessage;
use App\Gestion\Message\GenerateGestionReportMessage;
use App\Gestion\Services\Console\ConsoleService;
use App\Gestion\Services\Report\GestionReportService;
use App\Gestion\Services\Report\ReportRenderer;
use App\Gestion\Services\Report\ReportValue;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeLandingAiClient;
use App\Tests\Functional\Support\AiRightTestHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Rapports PDF de la gestion, lot DOC-1 (15/11/2026, `docs/rapports-gestion.md`) : une réponse de la console IA mise en PDF telle
 * qu'elle a été lue. Chaque chiffre des blocs se retrouve dans le document, rédigé à la devise et à la langue demandées ; aucun
 * modèle n'est appelé ; le fichier est un PDF ; la même réponse rend le fichier déjà prêt ; droits, limites, effacement.
 */
class GestionReportsApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $token = '';
    private string $tag;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeLandingAiClient::reset();
        AiRightTestHelper::restoreFullAi(static::getContainer());
        $this->tag = bin2hex(random_bytes(3));
        $this->wipe();
        $this->token = $this->tokenFor(['ROLE_ADMIN']);
    }

    protected function tearDown(): void
    {
        $this->wipe();
        parent::tearDown();
    }

    public function testAConsoleAnswerBecomesAPdfWithEveryFigureOfItsBlocksAndNoModelCall(): void
    {
        $conn = $this->em()->getConnection();
        [$answerId] = $this->answer(ConsoleService::STATE_DONE, $this->blocks());
        $usage = (int) $conn->fetchOne('SELECT COUNT(*) FROM ai_usage');

        // Droits : ni anonyme, ni compte client
        $admin = $this->token;
        $this->token = '';
        $this->request('POST', '/api/gestion/rapports', ['kind' => 'console.answer', 'messageId' => $answerId]);
        $this->assertSame(401, $this->status());
        $this->token = $this->tokenFor(['ROLE_USER_INTERNET']);
        $this->request('GET', '/api/gestion/rapports');
        $this->assertSame(403, $this->status());
        $this->token = $admin;

        // Demandes refusées : rien n'est créé
        foreach ([[[], ['kind' => 'REQUIRED']], [['kind' => 'period', 'periodId' => 1], ['kind' => 'INVALID_VALUE']], [['kind' => 'console.answer'], ['messageId' => 'REQUIRED']],
            [['kind' => 'console.answer', 'messageId' => 'x'], ['messageId' => 'INVALID_ID']], [['kind' => 'console.answer', 'messageId' => $answerId, 'locale' => 'de'], ['locale' => 'INVALID_VALUE']],
            [['kind' => 'console.answer', 'messageId' => $answerId, 'withAdvice' => true], ['withAdvice' => 'NOT_ALLOWED']]] as [$body, $fields]) {
            $this->request('POST', '/api/gestion/rapports', $body);
            $this->assertSame([422, $fields], [$this->status(), $this->body()['fields'] ?? null], json_encode($body));
        }
        $this->request('POST', '/api/gestion/rapports', ['kind' => 'console.answer', 'messageId' => 999999]);
        $this->assertSame([404, 'CONSOLE_MESSAGE_NOT_FOUND'], [$this->status(), $this->body()['code']]);
        [$pendingId] = $this->answer(ConsoleService::STATE_PENDING, []);
        $this->request('POST', '/api/gestion/rapports', ['kind' => 'console.answer', 'messageId' => $pendingId]);
        $this->assertSame([409, 'CONSOLE_ANSWER_PENDING'], [$this->status(), $this->body()['code']]);
        [$failedId, $questionId] = $this->answer(ConsoleService::STATE_FAILED, []);
        $this->request('POST', '/api/gestion/rapports', ['kind' => 'console.answer', 'messageId' => $failedId]);
        $this->assertSame([409, 'CONSOLE_ANSWER_FAILED'], [$this->status(), $this->body()['code']]);
        $this->request('POST', '/api/gestion/rapports', ['kind' => 'console.answer', 'messageId' => $questionId]);
        $this->assertSame([404, 'CONSOLE_MESSAGE_NOT_FOUND'], [$this->status(), $this->body()['code']], 'une question n\'est pas une réponse');
        $this->assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM gestion_report'));

        // Demande acceptée : en préparation, confiée au worker pdf
        $this->request('POST', '/api/gestion/rapports', ['kind' => 'console.answer', 'messageId' => $answerId]);
        $this->assertSame(202, $this->status(), (string) $this->client->getResponse()->getContent());
        $report = $this->body();
        $id = (int) $report['id'];
        $this->assertSame(['console.answer', 'pending', 'fr', ['messageId' => $answerId], null, null, null, null, null, false, 0, null], [$report['kind'], $report['state'], $report['locale'], $report['params'],
            $report['download'], $report['pages'], $report['size'], $report['error'], $report['advice'], $report['reused'], $report['unitsCharged'], $report['credits']]);
        $this->assertSame("Que disent mes ventes {$this->tag} ?", $report['title']);
        $this->assertEqualsWithDelta(time() + 90 * 86400, strtotime((string) $report['expiresAt']), 120, 'conservation de 90 jours');
        $sent = array_filter(static::getContainer()->get('messenger.transport.pdf')->getSent(), fn ($e) => $e->getMessage() instanceof GenerateGestionReportMessage && $e->getMessage()->reportId === $id);
        $this->assertCount(1, $sent);
        $this->request('GET', "/api/gestion/rapports/$id/fichier");
        $this->assertSame([409, 'REPORT_NOT_READY'], [$this->status(), $this->body()['code']]);
        $this->request('DELETE', "/api/gestion/rapports/$id");
        $this->assertSame([409, 'REPORT_NOT_READY'], [$this->status(), $this->body()['code']]);
        // la même demande pendant la préparation ne crée pas un second rapport
        $this->request('POST', '/api/gestion/rapports', ['kind' => 'console.answer', 'messageId' => $answerId]);
        $this->assertSame([202, $id, true], [$this->status(), $this->body()['id'], $this->body()['reused']]);

        // Les chiffres du site changent après la réponse : le rapport reste celui des blocs gardés
        $this->em();
        static::getContainer()->get(GestionReportService::class)->generate($id);
        $done = $this->get("/api/gestion/rapports/$id");
        $this->assertSame(['done', null, "/api/gestion/rapports/$id/fichier", 'application/pdf'], [$done['state'], $done['error'], $done['download']['url'], $done['download']['contentType']]);
        $this->assertGreaterThanOrEqual(1, $done['pages']);
        $this->assertMatchesRegularExpression('/^rapport-que-disent-mes-ventes-[a-z0-9]+-\d+\.pdf$/', $done['download']['filename']);
        $this->request('GET', "/api/gestion/rapports/$id/fichier");
        $this->assertSame(200, $this->status());
        $this->assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $file = (string) file_get_contents($this->client->getResponse()->getFile()->getPathname());
        $this->assertStringStartsWith('%PDF-', $file);
        $this->assertSame($done['size'], strlen($file));

        // Le document, avant sa mise en PDF : chaque chiffre des blocs y est, rédigé en français à la devise du site
        $renderer = static::getContainer()->get(ReportRenderer::class);
        $message = $this->em()->getRepository(GestionConversationMessage::class)->find($answerId);
        $html = self::plain($renderer->html($renderer->document(ConsoleService::message($message), "Que disent mes ventes {$this->tag} ?", 'fr', 'CAD')));
        foreach (['1 234,56 $', '42,35 %', '12 jours', '1,8', '3 456', 'Casquette lin', '34,99 $', 'À monter', 'Coefficient sous les semblables', 'Taux de marque', 'La marge rapportée au prix de vente.',
            'Ventes du mois', 'novembre 2026', 'D\'où viennent ces chiffres', 'Question posée', 'et 4 autres lignes, à lire dans l\'application.', 'Écran des périodes', 'Chaque ligne est une période.'] as $expected) {
            $this->assertStringContainsString(htmlspecialchars($expected, ENT_QUOTES), $html, $expected);
        }
        $this->assertStringContainsString('<strong>À garder</strong> (2)', $html, 'les groupes d\'un bilan, avec leur compte');
        // les comparaisons gardées avec la carte, sans recalcul ; un texte qui ressemble à un nombre reste un texte ; un montant négatif
        foreach (['Période précédente : 1 000,00 $ (+23,46 %)', 'An dernier : 1 500,00 $ (-17,7 %)', '>2024<', '>007<', '-12,50 $'] as $expected) {
            $this->assertStringContainsString($expected, $html, $expected);
        }
        $this->assertStringNotContainsString('2 024', $html);
        // l'en-tête du site : son nom, et son numéro d'entreprise quand il est saisi
        $this->assertStringContainsString(htmlspecialchars((string) $conn->fetchOne('SELECT name FROM entreprise ORDER BY id LIMIT 1'), ENT_QUOTES), $html);
        $this->assertStringNotContainsString('Quelle est la bonne réponse', $html, 'les questions de vérification d\'une leçon ne vont pas au papier');
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('&lt;b&gt;gras&lt;/b&gt;', $html, 'un texte de bloc est échappé, jamais interprété');
        // En anglais : mêmes chiffres, écrits à l'anglaise
        $english = self::plain($renderer->html($renderer->document(ConsoleService::message($message), 'What about my sales?', 'en', 'CAD')));
        foreach (['$1,234.56', '42.35%', '12 days', '1.8', '3,456', 'Raise', 'Where these figures come from'] as $expected) {
            $this->assertStringContainsString(htmlspecialchars($expected, ENT_QUOTES), $english, $expected);
        }

        // Aucun appel au modèle, aucune unité décomptée
        $this->assertSame([], FakeLandingAiClient::$requests);
        $this->assertSame($usage, (int) $conn->fetchOne('SELECT COUNT(*) FROM ai_usage'));

        // La même réponse demandée de nouveau : le fichier déjà prêt, sans rien refabriquer ; une autre langue est un autre rapport
        $this->request('POST', '/api/gestion/rapports', ['kind' => 'console.answer', 'messageId' => $answerId]);
        $this->assertSame([200, $id, true, 'done'], [$this->status(), $this->body()['id'], $this->body()['reused'], $this->body()['state']]);
        $this->request('POST', '/api/gestion/rapports', ['kind' => 'console.answer', 'messageId' => $answerId, 'locale' => 'en']);
        $this->assertSame([202, 'en', false], [$this->status(), $this->body()['locale'], $this->body()['reused']]);
        $englishId = (int) $this->body()['id'];
        $this->assertNotSame($id, $englishId);

        // Liste, filtre, effacement
        $list = $this->get('/api/gestion/rapports');
        $this->assertSame([[$englishId, $id], 1, 1, 2], [array_column($list['reports'], 'id'), $list['page'], $list['pages'], $list['total']]);
        $this->assertSame(2, $this->get('/api/gestion/rapports?kind=console.answer')['total']);
        $this->request('GET', '/api/gestion/rapports?kind=period');
        $this->assertSame([422, ['kind' => 'INVALID_VALUE']], [$this->status(), $this->body()['fields']]);
        $path = static::getContainer()->getParameter('kernel.project_dir') . '/var/storage/private_media/gestion-rapports/' . MV_TEST_TENANT_CODE . '/' . $conn->fetchOne('SELECT file_name FROM gestion_report WHERE id = ?', [$id]);
        $this->assertFileExists($path);
        $this->request('DELETE', "/api/gestion/rapports/$id");
        $this->assertSame(204, $this->status());
        $this->assertFileDoesNotExist($path);
        $this->request('GET', "/api/gestion/rapports/$id");
        $this->assertSame([404, 'REPORT_NOT_FOUND'], [$this->status(), $this->body()['code']]);
        $this->request('GET', "/api/gestion/rapports/$id/fichier");
        $this->assertSame(404, $this->status());
        $this->assertNotContains($id, array_column($this->get('/api/gestion/rapports')['reports'], 'id'));
        $this->assertSame(2, (int) $conn->fetchOne('SELECT COUNT(*) FROM gestion_report'), 'un rapport effacé garde sa ligne : il compte encore dans le plafond du jour');
        // effacé, il peut être refait : un nouveau rapport, pas le fichier retiré
        $this->request('POST', '/api/gestion/rapports', ['kind' => 'console.answer', 'messageId' => $answerId]);
        $this->assertSame([202, false], [$this->status(), $this->body()['reused']]);
        $this->assertNotSame($id, $this->body()['id']);

        // Le worker : il se place sur le site et fabrique le rapport
        $again = (int) $this->body()['id'];
        static::getContainer()->get(\App\Gestion\MessageHandler\GenerateGestionReportHandler::class)(new GenerateGestionReportMessage($again, MV_TEST_TENANT_CODE));
        $this->assertSame('done', $this->get("/api/gestion/rapports/$again")['state']);

        // L'en-tête du site change (son nom) : la même réponse n'est plus « le même rapport »
        $name = $conn->fetchOne('SELECT name FROM entreprise ORDER BY id LIMIT 1');
        $conn->executeStatement('UPDATE entreprise SET name = ?', ["Nouveau nom {$this->tag}"]);
        $this->endOfWrite();
        $this->em()->clear();
        try {
            $this->request('POST', '/api/gestion/rapports', ['kind' => 'console.answer', 'messageId' => $answerId]);
            $this->assertSame([202, false], [$this->status(), $this->body()['reused']]);
        } finally {
            $conn->executeStatement('UPDATE entreprise SET name = ?', [$name]);
            $this->endOfWrite();
        }

        // Une réponse d'un fil qui n'est pas la console n'est pas une réponse de la console
        $em = $this->em();
        $now = new \DateTimeImmutable();
        $thread = (new GestionConversation())->setKind(GestionConversation::KIND_INVOICE)->setLocale('fr')->setCreatedBy('test')->setCreatedAt($now)->setUpdatedAt($now)->setAnswers([]);
        $em->persist($thread);
        $other = GestionConversationMessage::of($thread, GestionConversationMessage::ROLE_ASSISTANT, 'text', ['state' => 'done', 'blocks' => [['type' => 'text', 'text' => 'Fil de facture']]], 'test');
        $em->persist($other);
        $em->flush();
        $this->request('POST', '/api/gestion/rapports', ['kind' => 'console.answer', 'messageId' => (int) $other->getId()]);
        $this->assertSame([404, 'CONSOLE_MESSAGE_NOT_FOUND'], [$this->status(), $this->body()['code']]);
        $conn->executeStatement('DELETE FROM gestion_conversation WHERE id = ?', [$thread->getId()]);

        // Sans le palier d'IA : le rapport d'une réponse de la console est refusé
        $master = static::getContainer()->get(\App\Services\TenantConnectionManager::class)->getPdoMaster();
        $master->prepare("DELETE FROM tenant_entitlements WHERE tenant_code = ? AND feature_key IN ('ai.full', 'ai.help')")->execute([MV_TEST_TENANT_CODE]);
        try {
            $this->request('POST', '/api/gestion/rapports', ['kind' => 'console.answer', 'messageId' => $answerId, 'locale' => 'en']);
            $this->assertSame([403, 'AI_NOT_INCLUDED'], [$this->status(), $this->body()['code']]);
        } finally {
            AiRightTestHelper::restoreFullAi(static::getContainer());
        }
    }

    public function testLimitsFailuresAndRetention(): void
    {
        $conn = $this->em()->getConnection();
        $service = static::getContainer()->get(GestionReportService::class);
        // Un rapport de plus de 40 pages est refusé, avec un code clair
        $rows = [];
        for ($i = 1; $i <= 1600; ++$i) {
            $rows[] = ['name' => "Article $i", 'price' => 1000 + $i];
        }
        [$long] = $this->answer(ConsoleService::STATE_DONE, [['type' => 'table', 'title' => 'Tous les articles', 'columns' => [['key' => 'name', 'label' => 'Article', 'unit' => 'text'], ['key' => 'price', 'label' => 'Prix', 'unit' => 'money']], 'rows' => $rows, 'more' => 0, 'source' => []]]);
        $this->request('POST', '/api/gestion/rapports', ['kind' => 'console.answer', 'messageId' => $long]);
        $longId = (int) $this->body()['id'];
        $this->em();
        $service->generate($longId);
        $failed = $this->get("/api/gestion/rapports/$longId");
        $this->assertSame(['failed', 'REPORT_TOO_LONG', null], [$failed['state'], $failed['error']['code'], $failed['download']]);
        $this->assertGreaterThan(GestionReportService::MAX_PAGES, $failed['pages']);
        $this->assertStringContainsString('40', $failed['error']['message']);
        $this->assertSame('This report is over 40 pages: it was not made.', $this->get("/api/gestion/rapports/$longId?locale=en")['error']['message']);
        $this->request('GET', "/api/gestion/rapports/$longId/fichier");
        $this->assertSame([409, 'REPORT_FAILED_NO_FILE'], [$this->status(), $this->body()['code']]);

        // Un rapport resté en préparation au-delà du délai est tenu pour interrompu
        [$small] = $this->answer(ConsoleService::STATE_DONE, [['type' => 'text', 'text' => 'Court.', 'figureIds' => []]]);
        $this->request('POST', '/api/gestion/rapports', ['kind' => 'console.answer', 'messageId' => $small]);
        $stuck = (int) $this->body()['id'];
        $conn->executeStatement("UPDATE gestion_report SET created_at = NOW() - INTERVAL '20 minutes' WHERE id = ?", [$stuck]);
        $this->assertSame(['failed', 'REPORT_TIMEOUT'], [$this->get("/api/gestion/rapports/$stuck")['state'], $this->get("/api/gestion/rapports/$stuck")['error']['code']]);
        $this->em();
        $service->generate($stuck); // le worker arrive trop tard : il ne refabrique pas un rapport déjà clos
        $this->assertSame('failed', $this->get("/api/gestion/rapports/$stuck")['state']);

        // 30 rapports par jour et par site ; effacer un rapport ne rend pas une place
        $conn->executeStatement('DELETE FROM gestion_report');
        $conn->executeStatement("INSERT INTO gestion_report (kind, params, state, title, locale, fingerprint, expires_at) SELECT 'console.answer', '{}', 'failed', 'Plein', 'fr', 'plein-' || g, NOW() + INTERVAL '90 days' FROM generate_series(1, 29) g");
        [$other] = $this->answer(ConsoleService::STATE_DONE, [['type' => 'text', 'text' => 'Autre.', 'figureIds' => []]]);
        $this->request('POST', '/api/gestion/rapports', ['kind' => 'console.answer', 'messageId' => $other]);
        $this->assertSame(202, $this->status(), 'le trentième passe');
        $thirtieth = (int) $this->body()['id'];
        $this->em();
        $service->generate($thirtieth);
        $this->request('DELETE', "/api/gestion/rapports/$thirtieth");
        $this->assertSame(204, $this->status());
        $this->request('POST', '/api/gestion/rapports', ['kind' => 'console.answer', 'messageId' => $other]);
        $this->assertSame([429, 'REPORT_DAILY_LIMIT', 30], [$this->status(), $this->body()['code'], $this->body()['limit']]);
        $this->assertNotEmpty($this->body()['resetAt']);

        // Conservation : un rapport passé son délai est retiré, avec son fichier
        $conn->executeStatement('DELETE FROM gestion_report');
        $this->request('POST', '/api/gestion/rapports', ['kind' => 'console.answer', 'messageId' => $other]);
        $kept = (int) $this->body()['id'];
        $this->em();
        $service->generate($kept);
        $path = static::getContainer()->getParameter('kernel.project_dir') . '/var/storage/private_media/gestion-rapports/' . MV_TEST_TENANT_CODE . '/' . $conn->fetchOne('SELECT file_name FROM gestion_report WHERE id = ?', [$kept]);
        $this->assertFileExists($path);
        $this->assertSame(0, $service->purgeExpired());
        $conn->executeStatement("UPDATE gestion_report SET expires_at = NOW() - INTERVAL '1 minute' WHERE id = ?", [$kept]);
        $this->request('GET', "/api/gestion/rapports/$kept/fichier");
        $this->assertSame([404, 'REPORT_NOT_FOUND'], [$this->status(), $this->body()['code']], 'un rapport échu ne se télécharge plus, même avant la purge');
        $this->assertFileExists($path);
        $this->assertNotContains($kept, array_column($this->get('/api/gestion/rapports')['reports'], 'id'), 'la liste retire d\'abord les rapports échus');
        $this->assertFileDoesNotExist($path);
        $this->assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM gestion_report WHERE id = ?', [$kept]));

        // L'écriture d'une valeur selon son unité
        $this->assertSame(['—', '0,00 $', '1 jour', '2 days', 'Correct', '14 novembre 2026', 'November 14, 2026', '2024', '2,5', '12 345', '€12.50'], array_map(self::plain(...), [ReportValue::write(null, 'money', 'fr', 'CAD'), ReportValue::write(0, 'money', 'fr', 'CAD'), ReportValue::write(1, 'days', 'fr', 'CAD'),
            ReportValue::write(2, 'days', 'en', 'CAD'), ReportValue::write('Correct', 'text', 'fr', 'CAD'), ReportValue::write('2026-11-14', 'date', 'fr', 'CAD'), ReportValue::write('2026-11-14', 'date', 'en', 'CAD'),
            ReportValue::write('2024', 'text', 'fr', 'CAD'), ReportValue::write(2.5, 'count', 'fr', 'CAD'), ReportValue::write(12345, 'count', 'fr', 'CAD'), ReportValue::write(1250, 'money', 'en', 'EUR')]));
    }

    /** Les espaces insécables que la mise en forme des nombres pose (1 234,56 $) deviennent des espaces ordinaires, pour comparer */
    private static function plain(string $text): string
    {
        return str_replace(["\u{00A0}", "\u{202F}", '&nbsp;'], ' ', $text);
    }

    /** @return list<array<string, mixed>> une réponse qui porte tous les types de blocs */
    private function blocks(): array
    {
        return [
            ['type' => 'text', 'text' => "Vos ventes tiennent.\n\nUn texte avec <b>gras</b> et <script>alert(1)</script>.", 'figureIds' => ['a.revenue']],
            ['type' => 'figures', 'items' => [
                ['id' => 'a.revenue', 'label' => 'Ventes du mois', 'value' => 123456, 'unit' => 'money', 'previous' => 100000, 'lastYear' => 150000, 'delta' => 23456, 'deltaPercent' => 23.46, 'deltaLastYear' => -26544, 'deltaPercentLastYear' => -17.7, 'source' => []],
                ['id' => 'a.name', 'label' => 'Article le plus vendu', 'value' => '2024', 'unit' => 'text', 'source' => []],
                ['id' => 'a.loss', 'label' => 'Écart de caisse', 'value' => -1250, 'unit' => 'money', 'source' => []],
                ['id' => 'a.rate', 'label' => 'Taux de marque', 'value' => 42.35, 'unit' => 'percent', 'source' => []],
                ['id' => 'a.days', 'label' => 'Jours de stock', 'value' => 12, 'unit' => 'days', 'source' => []],
                ['id' => 'a.coef', 'label' => 'Coefficient', 'value' => 1.8, 'unit' => 'ratio', 'source' => []],
            ]],
            ['type' => 'table', 'title' => 'Articles neufs', 'columns' => [['key' => 'name', 'label' => 'Article', 'unit' => 'text'], ['key' => 'price', 'label' => 'Prix', 'unit' => 'money'], ['key' => 'sold', 'label' => 'Vendus', 'unit' => 'count']],
                'rows' => [['name' => 'Casquette lin', 'price' => 3499, 'sold' => 3456, 'productId' => 88], ['name' => '007', 'price' => 500, 'sold' => 1, 'productId' => 89]], 'more' => ['count' => 4, 'total' => 6], 'source' => []],
            ['type' => 'verdicts', 'items' => [['subject' => ['type' => 'product', 'id' => 88, 'name' => 'Casquette lin'], 'verdict' => 'raise', 'why' => 'Coefficient sous les semblables', 'figureIds' => []]],
                'groups' => [['verdict' => 'keep', 'source' => 'site', 'count' => 2, 'label' => 'À garder', 'productIds' => [1, 2]]]],
            ['type' => 'lesson', 'key' => 'taux-de-marque', 'term' => 'Taux de marque', 'definition' => 'La marge rapportée au prix de vente.', 'illustration' => 'Sur 100 $ vendus, 42 $ de marge.',
                'related' => [['key' => 'marge', 'term' => 'Marge']], 'quiz' => [['id' => 'q1', 'question' => 'Quelle est la bonne réponse ?', 'choices' => []]], 'source' => []],
            ['type' => 'guide', 'screen' => 'periodes', 'title' => 'Écran des périodes', 'theme' => ['key' => 'resultats', 'label' => 'Résultats'], 'purpose' => 'Voir le résultat de chaque période.',
                'points' => [['label' => 'Le tableau', 'text' => 'Chaque ligne est une période.', 'term' => null]], 'next' => 'Ouvrez une période.', 'source' => []],
            ['type' => 'inconnu', 'text' => 'Un bloc d\'un type que ce lot ne connaît pas est laissé de côté'],
        ];
    }

    /**
     * Un échange de la console avec sa question et sa réponse, écrits comme le fait la console.
     *
     * @param list<array<string, mixed>> $blocks
     * @return array{0: int, 1: int} identifiants de la réponse et de la question
     */
    private function answer(string $state, array $blocks): array
    {
        $em = $this->em();
        $now = new \DateTimeImmutable();
        $text = "Que disent mes ventes {$this->tag} ?";
        $conversation = (new GestionConversation())->setKind(ConsoleService::KIND)->setLocale('fr')->setCreatedBy('test')->setCreatedAt($now)->setUpdatedAt($now)->setAnswers(['title' => $text]);
        $em->persist($conversation);
        $question = GestionConversationMessage::of($conversation, GestionConversationMessage::ROLE_USER, ConsoleService::QUESTION, ['text' => $text, 'context' => null], 'test');
        $em->persist($question);
        $em->flush();
        $answer = GestionConversationMessage::of($conversation, GestionConversationMessage::ROLE_ASSISTANT, ConsoleService::ANSWER, ['state' => $state, 'replyTo' => (int) $question->getId(), 'error' => null, 'blocks' => $blocks,
            'analyses' => $blocks === [] ? [] : [['key' => 'sales.overview', 'ref' => 'a', 'title' => 'Ventes et marge', 'params' => [], 'source' => ['screen' => 'statistiques', 'route' => 'GET /api/gestion/statistiques', 'params' => []],
                'period' => ['from' => '2026-11-01', 'to' => '2026-11-30', 'label' => 'novembre 2026'], 'computedAt' => '2026-11-14T20:42:00+00:00']],
            'proposals' => [], 'answeredAt' => $state === ConsoleService::STATE_DONE ? '2026-11-14T20:42:05+00:00' : null]);
        $em->persist($answer);
        $em->flush();
        $this->endOfWrite();

        return [(int) $answer->getId(), (int) $question->getId()];
    }

    private function wipe(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('DELETE FROM gestion_report');
        $conn->executeStatement("DELETE FROM gestion_conversation WHERE created_by = 'test'");
        $conn->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'rapports-%@example.invalid'");
        foreach (glob(static::getContainer()->getParameter('kernel.project_dir') . '/var/storage/private_media/gestion-rapports/' . MV_TEST_TENANT_CODE . '/*.pdf') ?: [] as $file) {
            @unlink($file);
        }
        $this->endOfWrite();
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

    private function em(): EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }

    private function endOfWrite(): void
    {
        static::getContainer()->get(CacheInvalidationSubscriber::class)->invalidateWrittenTenants();
    }

    private function tokenFor(array $roles): string
    {
        $email = 'rapports-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Rose')->setLastname('Rapport')->setRoles($roles)->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->endOfWrite();

        return static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }
}
