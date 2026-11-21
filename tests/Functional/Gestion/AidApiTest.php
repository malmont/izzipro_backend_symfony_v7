<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Gestion\Aid\AidCalculator as C;
use App\Gestion\Aid\AidReminderService;
use App\Gestion\Analysis\AnalysisCatalog;
use App\Gestion\Services\PeriodCalendar;
use App\Services\CaisseService\StoreClock;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\Notification\NotificationGate;
use App\Services\Notification\NotificationLevelResolver;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot FIS-2, aides de la gestion (21/11/2026, `docs/aides-gestion.md`) : calcul (prorata additif, reçu et à recevoir, versements à leur
 * date, remboursement décidé, aide demandée, avance sans flux), routes et refus, trésorerie et remarque de double comptage, produit du
 * résultat de période (période figée avant le lot), des statistiques et de l'année ; conditions et justificatifs ; avance remboursable
 * et son prêt à 0 % (lié, protégé : AID_LINKED_LOAN) ; rappel par courriel (une fois par élément, niveau du commerçant) ; analyse
 * `aids.overview` sans organisme ni notes ; règle du guide. Dates des aides en 2049 (aucune autre donnée datée là), sauf celles de la
 * trésorerie et des rappels ; tables des aides, prêts et mouvements vidées avant et après.
 */
class AidApiTest extends WebTestCase
{
    private const AID = ['kind' => 'operating', 'amount' => 120000, 'currency' => 'CAD', 'status' => 'granted', 'coveredFrom' => '2049-01-01', 'coveredTo' => '2049-12-31',
        'payments' => [], 'conditions' => [], 'documents' => [], 'repayment' => null, 'advance' => null];

    private KernelBrowser $client;
    private string $token = '';
    private string $email = '';
    private array $periods = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clean();
        $this->login();
    }

    protected function tearDown(): void
    {
        $this->clean();
        foreach ($this->periods as $id) {
            $this->em()->getConnection()->executeStatement('DELETE FROM collections WHERE id = ?', [$id]);
        }
        parent::tearDown();
    }

    public function testTheCalculation(): void
    {
        // 1 200 $ accordés pour 2049 : janvier en reçoit 31/365 (101,92 $), à la journée près, et les mois font exactement le total
        $this->assertSame(['received' => 0, 'pending' => 10192, 'repaid' => 0, 'total' => 10192], C::productBetween(self::AID, '2049-01-01', '2049-01-31'));
        $months = 0;
        foreach (range(1, 12) as $m) {
            $from = sprintf('2049-%02d-01', $m);
            $months += C::productBetween(self::AID, $from, (new \DateTimeImmutable($from))->modify('last day of this month')->format('Y-m-d'))['total'];
        }
        $this->assertSame(120000, $months, 'additif au cent');
        $this->assertSame(120000, C::productBetween(self::AID, '2040-01-01', '2060-12-31')['total'], 'hors de la période couverte : rien de plus');
        // la moitié reçue : la moitié du produit est « reçu », le reste « accordé, pas encore reçu »
        $half = ['payments' => [['date' => '2049-02-10', 'amount' => 60000, 'received' => true], ['date' => '2049-09-10', 'amount' => 60000, 'received' => false]]] + self::AID;
        $this->assertSame(['received' => 5096, 'pending' => 5096, 'repaid' => 0, 'total' => 10192], C::productBetween($half, '2049-01-01', '2049-01-31'));
        // sans période couverte : le produit tombe à la date de chaque versement
        $dated = ['coveredFrom' => null, 'coveredTo' => null] + $half;
        $this->assertSame([0, 60000, 60000], [C::productBetween($dated, '2049-01-01', '2049-01-31')['total'], C::productBetween($dated, '2049-02-01', '2049-02-28')['total'], C::productBetween($dated, '2049-09-01', '2049-09-30')['pending']]);
        // demandée : ni produit, ni versement prévu ; un versement déjà reçu entre quand même en trésorerie
        $requested = ['status' => 'requested'] + $half;
        $this->assertSame(0, C::productBetween($requested, '2049-01-01', '2049-12-31')['total']);
        $this->assertSame([['date' => '2049-02-10', 'direction' => 'in', 'amount' => 60000, 'kind' => 'payment']], C::cashFlows($requested, '2049-01-01', '2049-12-31'));
        $this->assertCount(2, C::cashFlows($half, '2049-01-01', '2049-12-31'), 'accordée : le versement prévu aussi');
        // à rembourser : sortie de trésorerie à sa date ; le produit baisse sur la période de la DÉCISION, pas de l'échéance
        $repay = ['status' => 'to_repay', 'repayment' => ['amount' => 30000, 'dueOn' => '2049-11-15', 'decidedOn' => '2049-06-03']] + $half;
        $this->assertSame(['received' => 4932, 'pending' => 4931, 'repaid' => 30000, 'total' => -20137], C::productBetween($repay, '2049-06-01', '2049-06-30'));
        $this->assertSame(['date' => '2049-11-15', 'direction' => 'out', 'amount' => 30000, 'kind' => 'repayment'], C::cashFlows($repay, '2049-11-01', '2049-11-30')[0]);
        // investissement : trésorerie seulement ; avance : rien ici (son prêt porte l'argent)
        $this->assertSame(0, C::productBetween(['kind' => 'investment'] + $half, '2049-01-01', '2049-12-31')['total']);
        $this->assertSame([], C::cashFlows(['kind' => 'refundable_advance'] + $half, '2049-01-01', '2049-12-31'));
    }

    public function testRoutesCashResultStatisticsConditionsAndDocuments(): void
    {
        $conn = $this->em()->getConnection();
        $this->assertSame(['label' => 'AID_LABEL_INVALID', 'kind' => 'AID_KIND_INVALID', 'amount' => 'AID_AMOUNT_INVALID'], $this->send('POST', '/api/gestion/aides', [], 422)['fields']);
        $body = ['label' => 'Aide au démarrage', 'organism' => 'Organisme Témoin', 'notes' => 'Interlocutrice : Mme Témoin', 'kind' => 'operating', 'amount' => 120000, 'status' => 'granted',
            'coveredFrom' => '2049-01-01', 'coveredTo' => '2049-12-31', 'payments' => [['date' => '2049-02-10', 'amount' => 60000, 'received' => true]],
            'conditions' => [['label' => 'Garder deux emplois', 'dueOn' => '2049-12-31']]];
        $this->assertSame(['coveredFrom' => 'AID_PERIOD_REQUIRED'], $this->send('POST', '/api/gestion/aides', ['coveredFrom' => null, 'coveredTo' => null, 'payments' => []] + $body, 422)['fields']);
        $this->assertSame(['coveredFrom' => 'AID_DATE_INVALID'], $this->send('POST', '/api/gestion/aides', ['coveredTo' => '2048-12-31'] + $body, 422)['fields']);
        $this->assertSame(['payments' => 'AID_PAYMENT_INVALID'], $this->send('POST', '/api/gestion/aides', ['payments' => [['date' => '2049-02-10', 'amount' => 130000, 'received' => true]]] + $body, 422)['fields'], 'jamais plus versé qu\'accordé');
        $this->assertSame(['repayment' => 'AID_REPAYMENT_INVALID'], $this->send('POST', '/api/gestion/aides', ['status' => 'to_repay'] + $body, 422)['fields'], 'à rembourser : montant et date exigés');
        $this->assertSame(['documents' => 'AID_DOCUMENT_NOT_FOUND'], $this->send('POST', '/api/gestion/aides', ['documents' => [999999999]] + $body, 422)['fields']);
        $this->assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM gestion_aid'));

        $aid = $this->send('POST', '/api/gestion/aides', $body, 201);
        $id = (int) $aid['id'];
        $this->assertSame(['CAD', 60000, 60000, 0, true, null, [['label' => 'Garder deux emplois', 'dueOn' => '2049-12-31', 'state' => 'todo']]],
            [$aid['currency'], $aid['receivedTotal'], $aid['toReceive'], $aid['conditionsDue'], $aid['documentsMissing'], $aid['loanId'], $aid['conditions']], 'reçue en partie, sans justificatif');
        $this->assertSame([$id], array_column($this->get('/api/gestion/aides')['aids'], 'id'));
        $this->assertSame(1, (int) $conn->fetchOne("SELECT COUNT(*) FROM content_audit_log WHERE resource = 'gestion-aides' AND resource_id = ? AND action = 'create'", [(string) $id]));
        // une aide dans une autre devise : listée, jamais additionnée
        $this->send('POST', '/api/gestion/aides', ['label' => 'Aide en euros', 'currency' => 'EUR', 'amount' => 500000] + $body, 201);

        // Résultat d'une période : le produit au prorata, en deux parts ; net inchangé
        $period = $this->period('2049-01-01', '2049-01-31');
        $r = $this->get("/api/periodes/$period/resultat");
        $this->assertSame(['received' => 5096, 'pending' => 5096, 'repaid' => 0, 'total' => 10192, 'aids' => 1, 'estimated' => true], $r['charges']['operatingAids']);
        // `total` reste les charges : le même qu'avant l'aide (aucun prêt ici) ; `net` = total − aides, peut être négatif
        $this->assertSame([$r['result']['netAfterFixed'] + 10192, 0, $r['charges']['financial']['total'], -10192], [$r['result']['netAfterCharges'], $r['charges']['total'], $r['charges']['total'], $r['charges']['net']]);
        $conn->executeStatement('UPDATE gestion_aid SET status = ? WHERE id = ?', ['requested', $id]); // la même période sans aide comptée
        $this->endOfWrite();
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant(); // une écriture SQL directe ne vide pas le cache du site
        $without = $this->get("/api/periodes/$period/resultat")['charges'];
        $this->assertSame([$without['total'], 0], [$r['charges']['total'], $without['net']], 'total identique avec ou sans aide');
        $conn->executeStatement('UPDATE gestion_aid SET status = ? WHERE id = ?', ['granted', $id]);
        $this->endOfWrite();
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant(); // une écriture SQL directe ne vide pas le cache du site
        // Une période figée après les prêts mais avant les aides garde son instantané
        $closed = $this->period('2049-02-01', '2049-02-28');
        $conn->executeStatement('UPDATE collections SET closed_at = NOW() WHERE id = ?', [$closed]);
        $this->endOfWrite();
        $this->assertSame(9205, $this->get("/api/periodes/$closed/resultat")['charges']['operatingAids']['total']);
        $conn->executeStatement("UPDATE period_result SET payload = jsonb_set(payload::jsonb, '{charges}', (payload::jsonb -> 'charges') - 'operatingAids')::json WHERE collection_id = ?", [$closed]);
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        $this->assertNull($this->get("/api/periodes/$closed/resultat")['charges']['operatingAids']);
        // Statistiques et année
        $stats = $this->get('/api/gestion/statistiques?from=2049-01-01&to=2049-12-31&sections=charges')['sections']['charges']['operatingAids'];
        $this->assertSame([120000, 60000, 0], [$stats['current']['total'], $stats['current']['received'], $stats['lastYear']['total']]);
        $all = $this->get('/api/gestion/statistiques?from=2049-01-01&to=2049-12-31&sections=charges')['sections']['charges'];
        $this->assertSame([0, -120000, 0], [$all['financial']['total'], $all['net']['current'], $all['net']['lastYear']], 'les charges seules restent dans financial');

        // Conditions : état par rang ; une condition inconnue, un état inconnu
        $this->assertSame('kept', $this->send('PATCH', "/api/gestion/aides/$id/conditions/0", ['state' => 'kept'], 200)['conditions'][0]['state']);
        $this->assertSame('AID_CONDITION_NOT_FOUND', $this->send('PATCH', "/api/gestion/aides/$id/conditions/5", ['state' => 'kept'], 404)['error']['code'] ?? $this->body()['code'] ?? null);
        $this->send('PATCH', "/api/gestion/aides/$id/conditions/0", ['state' => 'maybe'], 422);
        // Justificatifs : un document de la gestion existant, sans doublon ; retiré
        $doc = (int) $conn->fetchOne("INSERT INTO gestion_document (type, status, locale, files, created_by, created_at, expires_at) VALUES ('facture', 'extracted', 'fr', '{\"pages\": []}', 'essai', NOW(), NOW() + INTERVAL '30 days') RETURNING id");
        $this->send('POST', "/api/gestion/aides/$id/justificatifs", ['documentId' => $doc], 201);
        $with = $this->send('POST', "/api/gestion/aides/$id/justificatifs", ['documentId' => $doc], 201);
        $this->assertSame([[$doc], false], [$with['documents'], $with['documentsMissing']]);
        $this->assertSame(['documentId' => 'AID_DOCUMENT_NOT_FOUND'], $this->send('POST', "/api/gestion/aides/$id/justificatifs", ['documentId' => 999999999], 422)['fields']);
        $this->assertSame([], $this->send('DELETE', "/api/gestion/aides/$id/justificatifs/$doc", [], 200)['documents']);
        $conn->executeStatement('DELETE FROM gestion_document WHERE id = ?', [$doc]);

        // Trésorerie : un versement prévu dans 10 jours ; remarque de double comptage avec un mouvement d'entrée du même montant, le même mois
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $d = fn (int $n) => $calendar->today()->modify("$n days")->format('Y-m-d');
        $conn->executeStatement('INSERT INTO gestion_cash_balance (balance_date, amount_cents, created_at, updated_at) VALUES (?, 100000, NOW(), NOW())', [$d(0)]);
        $this->endOfWrite();
        $without = (int) ($this->get('/api/gestion/tresorerie?days=90')['projection']['end']['amount'] ?? 0);
        $near = (int) $this->send('POST', '/api/gestion/aides', ['label' => 'Aide proche', 'kind' => 'investment', 'amount' => 70000, 'coveredFrom' => null, 'coveredTo' => null,
            'payments' => [['date' => $d(10), 'amount' => 70000, 'received' => false]], 'conditions' => []] + $body, 201)['id'];
        $this->endOfWrite();
        $cash = $this->get('/api/gestion/tresorerie?days=90');
        $this->assertSame($without + 70000, $cash['projection']['end']['amount'] ?? null, 'le versement prévu entre');
        $this->assertSame(['category' => 'aid', 'in' => 70000, 'out' => 0], array_intersect_key(array_column($cash['byCategory'] ?? $cash['categories'] ?? [], null, 'category')['aid'] ?? [], ['category' => 1, 'in' => 1, 'out' => 1]));
        $this->assertNotContains('AID_MAY_OVERLAP_MOVEMENTS', array_column($cash['caveats'], 'code'));
        $this->send('POST', '/api/gestion/tresorerie/mouvements', ['label' => 'Subvention', 'category' => 'other', 'direction' => 'in', 'amount' => 70000, 'startDate' => $d(10), 'recurrence' => 'none'], 201);
        $this->assertContains('AID_MAY_OVERLAP_MOVEMENTS', array_column($this->get('/api/gestion/tresorerie?days=90')['caveats'], 'code'));
        // à rembourser (une condition non tenue) : une sortie prévue à sa date
        $repaid = $this->send('PATCH', "/api/gestion/aides/$near", ['status' => 'to_repay', 'repayment' => ['amount' => 20000, 'dueOn' => $d(20)]], 200);
        $this->assertSame(['amount' => 20000, 'dueOn' => $d(20), 'decidedOn' => $d(0)], $repaid['repayment'], 'décidé aujourd\'hui (jour du magasin)');
        $this->endOfWrite();
        $this->assertSame($without + 70000 + 70000 - 20000, $this->get('/api/gestion/tresorerie?days=90')['projection']['end']['amount'] ?? null, 'le versement, le mouvement saisi (gardé : rien n\'est supprimé), moins le remboursement');

        // Suppression, puis 404 ; jamais sans connexion
        $this->send('DELETE', "/api/gestion/aides/$near", [], 204);
        $this->assertSame('AID_NOT_FOUND', $this->send('GET', "/api/gestion/aides/$near", [], 404)['error']['code'] ?? $this->body()['code'] ?? null);
        $this->token = '';
        $this->send('GET', '/api/gestion/aides', [], 401);
    }

    public function testARefundableAdvanceIsALinkedZeroRateLoan(): void
    {
        $conn = $this->em()->getConnection();
        $body = ['label' => 'Prêt d\'honneur', 'organism' => 'Réseau Témoin', 'kind' => 'refundable_advance', 'amount' => 1200000, 'status' => 'requested',
            'payments' => [['date' => '2049-03-01', 'amount' => 1200000, 'received' => false]], 'advance' => ['durationMonths' => 12, 'frequencyMonths' => 1, 'firstDueOn' => '2049-04-01']];
        $this->assertSame(['advance' => 'AID_ADVANCE_INVALID'], $this->send('POST', '/api/gestion/aides', ['advance' => null] + $body, 422)['fields']);
        // demandée : pas encore de prêt ; accordée : son prêt à 0 % naît, lié, visible dans « Prêts »
        $id = (int) $this->send('POST', '/api/gestion/aides', $body, 201)['id'];
        $this->assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM gestion_loan'));
        $granted = $this->send('PATCH', "/api/gestion/aides/$id", ['status' => 'granted'], 200);
        $loanId = $granted['loanId'];
        $this->assertNotNull($loanId);
        $this->assertSame(['id' => $loanId, 'label' => 'Prêt d\'honneur'], $granted['loan'], 'le libellé du prêt lié, pour l\'écran');
        $loan = $this->get("/api/gestion/prets/$loanId");
        $this->assertSame([1200000, 0, '2049-03-01', '2049-04-01', 0, 100000, 'Réseau Témoin'], [$loan['amount'], $loan['ratePpm'], $loan['disbursedOn'], $loan['firstDueOn'], $loan['totals']['creditCost'], $loan['schedule'][0]['total'], $loan['lender']]);
        $this->assertSame($id, (int) $conn->fetchOne('SELECT aid_id FROM gestion_loan WHERE id = ?', [$loanId]));
        // l'argent n'est compté qu'une fois : par le prêt (déblocage, échéances), jamais par l'aide ; aucun produit
        $period = $this->period('2049-03-01', '2049-03-31');
        $this->assertSame(0, $this->get("/api/periodes/$period/resultat")['charges']['operatingAids']['total']);
        // tant que le prêt existe : ses termes ne changent pas, l'aide ne se supprime pas ; le libellé, lui, se change
        $this->assertSame('AID_LINKED_LOAN', $this->send('PATCH', "/api/gestion/aides/$id", ['amount' => 1300000], 409)['error']['code'] ?? $this->body()['code'] ?? null);
        $this->assertSame('AID_LINKED_LOAN', $this->send('DELETE', "/api/gestion/aides/$id", [], 409)['error']['code'] ?? $this->body()['code'] ?? null);
        $this->assertSame('Prêt d\'honneur 2049', $this->send('PATCH', "/api/gestion/aides/$id", ['label' => 'Prêt d\'honneur 2049', 'status' => 'received'], 200)['label']);
        $this->assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM gestion_loan'), 'jamais un second prêt');
        // le prêt supprimé, l'aide se supprime ; jamais recréé
        $this->send('DELETE', "/api/gestion/prets/$loanId", [], 204);
        $this->assertNull($this->send('PATCH', "/api/gestion/aides/$id", ['notes' => 'prêt soldé'], 200)['loanId']);
        $this->send('DELETE', "/api/gestion/aides/$id", [], 204);
        // un refus du prêt (première échéance trop lointaine) ne laisse rien : ni aide, ni prêt
        $this->send('POST', '/api/gestion/aides', ['status' => 'granted', 'advance' => ['durationMonths' => 12, 'firstDueOn' => '2050-04-01']] + $body, 422);
        $this->assertSame([0, 0], [(int) $conn->fetchOne('SELECT COUNT(*) FROM gestion_aid'), (int) $conn->fetchOne('SELECT COUNT(*) FROM gestion_loan')]);
    }

    public function testTheReminderTheAnalysisAndTheGuide(): void
    {
        $conn = $this->em()->getConnection();
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $today = $calendar->today()->format('Y-m-d');
        $yesterday = $calendar->today()->modify('-1 day')->format('Y-m-d');
        $id = (int) $this->send('POST', '/api/gestion/aides', ['label' => 'Aide à l\'embauche', 'organism' => 'Organisme Témoin', 'notes' => 'Note privée', 'kind' => 'operating',
            'amount' => 300000, 'status' => 'received', 'coveredFrom' => '2049-01-01', 'coveredTo' => '2049-12-31', 'payments' => [['date' => $yesterday, 'amount' => 300000, 'received' => true]],
            'conditions' => [['label' => 'Embauche confirmée', 'dueOn' => $yesterday], ['label' => 'Bilan annuel', 'dueOn' => '2049-12-31']]], 201)['id'];

        $sentTo = [];
        $mailer = $this->createMock(EmailSenderService::class);
        $mailer->method('subject')->willReturn('Aides : 2 points à vérifier');
        $mailer->method('sendTemplatedEmail')->willReturnCallback(function (string $to, string $subject, string $template, array $context) use (&$sentTo): bool {
            $sentTo[$to] = $context['rows'];

            return true;
        });
        $c = static::getContainer();
        $reminders = new AidReminderService($c->get(TenantEntityManagerProvider::class), $c->get(\App\Gestion\Aid\GestionAidService::class),
            new NotificationGate($c->get(TenantEntityManagerProvider::class), $c->get(NotificationLevelResolver::class), $c->get(StoreClock::class)), $c->get(StoreClock::class), $mailer,
            $c->get(\App\Services\EmailConfigurationService\EmailLocale::class), $c->get('translator'), $c->get(\App\Services\Site\AdminSpaceUrl::class),
            $c->get(\App\Services\TenantConnectionManager::class), $c->get(\App\Services\TenantPublicHostResolver::class), new \Psr\Log\NullLogger());
        $at = fn (string $time) => new \DateTimeImmutable($today . ' ' . $time, $c->get(StoreClock::class)->timezone());
        $conn->executeStatement('UPDATE "user" SET notification_level = NULL WHERE email = ?', [$this->email]);

        // avant 8 h du magasin : rien, rien de réservé
        $this->assertSame('too_early', $reminders->sendIfDue($at('07:30'))['status']);
        $this->assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM gestion_aid_reminder'));
        // à 9 h : la condition échue et le justificatif manquant, en un courriel ; jamais la condition à venir
        $this->assertSame('sent', $reminders->sendIfDue($at('09:00'))['status']);
        $this->assertArrayHasKey($this->email, $sentTo);
        $this->assertCount(2, $sentTo[$this->email]);
        $this->assertStringContainsString('Embauche confirmée', $sentTo[$this->email][0]);
        $this->assertSame(1, (int) $conn->fetchOne("SELECT COUNT(*) FROM notification_log WHERE channel = 'email' AND kind = 'aid_reminder' AND recipient = ? AND status = 'sent'", [mb_strtolower($this->email)]));
        // chaque élément n'est annoncé qu'une fois
        $sentTo = [];
        $this->assertSame('nothing_due', $reminders->sendIfDue($at('10:00'))['status']);
        $this->assertSame([], $sentTo);
        // coupé par le gérant dans ses préférences : rien ne part pour lui
        $conn->executeStatement('DELETE FROM gestion_aid_reminder');
        $this->send('PATCH', '/api/gestion/notifications/preferences', ['kinds' => ['aid_reminder' => false]], 200);
        $reminders->sendIfDue($at('11:00'));
        $this->assertArrayNotHasKey($this->email, $sentTo);

        // Analyse nommée : ni organisme, ni notes, ni texte des conditions ; les chiffres de l'écran
        $analysis = $c->get(AnalysisCatalog::class)->run('aids.overview', [], 'fr');
        $json = (string) json_encode($analysis);
        foreach (['Organisme Témoin', 'Note privée', 'Embauche confirmée'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
        $figures = array_column($analysis['figures'], 'value', 'id');
        $this->assertSame([1, 300000, 0, 1, 1], [$figures['aidsCount'], $figures['aidsReceived'], $figures['aidsToReceive'], $figures['aidsConditionsDue'], $figures['aidsDocumentsMissing']]);
        $this->assertSame(['aides', 'GET /api/gestion/aides'], [$analysis['source']['screen'], $analysis['source']['route']]);

        // Règle du guide : des conditions échues à tenir, effet = le montant accordé en jeu
        $rules = $c->get(\App\Gestion\Services\AdviceRules::class);
        $method = new \ReflectionMethod($rules, 'aidConditionsDue');
        $advice = $method->invoke($rules, $today);
        $this->assertSame(['AID_CONDITIONS_DUE', 300000, 'high', ['screen' => 'aides', 'params' => ['aidId' => $id]], ['count' => 1]],
            [$advice[0]['kind'], $advice[0]['effect']['amount'], $advice[0]['confidence'], $advice[0]['action'], $advice[0]['params']]);
        $this->send('PATCH', "/api/gestion/aides/$id/conditions/0", ['state' => 'kept'], 200);
        $this->assertSame([], $method->invoke($rules, $today), 'condition tenue : plus de conseil');
    }

    private function period(string $from, string $to): int
    {
        $id = (int) $this->em()->getConnection()->fetchOne(
            "INSERT INTO collections (id, budget_collection, start_date_collection, end_date_collection, del, nom_collection, is_closed, kind, period_key) VALUES (nextval('collections_id_seq'), 0, ?, ?, false, ?, false, 'period', ?) RETURNING id",
            [$from . ' 00:00:00', $to . ' 00:00:00', 'Aides ' . $from, 'aid-' . bin2hex(random_bytes(3))]
        );
        $this->periods[] = $id;
        $this->endOfWrite();

        return $id;
    }

    private function clean(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('DELETE FROM gestion_aid_reminder');
        $conn->executeStatement('DELETE FROM gestion_aid');
        $conn->executeStatement('DELETE FROM gestion_loan');
        $conn->executeStatement('DELETE FROM gestion_cash_movement');
        $conn->executeStatement('DELETE FROM gestion_cash_balance');
        $conn->executeStatement("DELETE FROM notification_log WHERE kind = 'aid_reminder'");
        $conn->executeStatement("DELETE FROM notification_preference WHERE kind = 'aid_reminder'");
    }

    private function body(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function get(string $route): array
    {
        return $this->send('GET', $route, [], 200);
    }

    private function send(string $method, string $route, array $body, int $expected): array
    {
        $headers = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'] + ($this->token !== '' ? ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token] : []);
        $this->client->request($method, $route, [], [], $headers, $method === 'GET' ? null : (string) json_encode($body));
        $this->assertSame($expected, $this->client->getResponse()->getStatusCode(), "$method $route : " . substr((string) $this->client->getResponse()->getContent(), 0, 500));

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

    private function login(): void
    {
        $this->email = 'gestion-aide-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($this->email)->setUsername($this->email)->setFirstname('Gestion')->setLastname('Aide')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
