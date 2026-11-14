<?php

namespace App\Tests\Functional\LossPrevention;

/**
 * Prévention des pertes, lot L1-b : ce que le module branche dans la gestion. La vignette d'accueil « N nouveaux constats, X en jeu » (deux
 * nombres, aucun nom, seulement si le droit du module est accordé, écartée puis revenue quand la liste change) ; les guides des cinq écrans,
 * en français et en anglais.
 */
class LossPreventionGestionLinksTest extends LossPreventionTestCase
{
    public function testHomeTileCountsNewFindingsWithoutAnyNameAndFollowsTheModuleRight(): void
    {
        $this->drain();
        $this->loginPos(['ROLE_USER_POS']);
        $tile = function (string $locale = 'fr'): ?array {
            $tiles = $this->json($this->call('GET', '/api/gestion/accueil/vignettes?locale=' . $locale, null, $this->adminToken));
            $found = array_values(array_filter($tiles, fn (array $t) => $t['id'] === 'loss-findings'));

            return $found[0] ?? null;
        };
        $before = $tile()['sources'][0]['value'] ?? 0;
        $this->openSession();
        $report = $this->closeWith(-1300, 'Vignette');
        $this->assertSame(1, $this->json($this->admin('POST', self::BASE . '/analyse'))['opened']);
        $id = (int) $this->db->fetchOne('SELECT id FROM loss_finding WHERE dedup_key = ?', ['CASH_DISCREPANCY:z_report:' . $report['id']]);

        $t = $tile();
        $this->assertNotNull($t, 'un constat nouveau : la vignette est là');
        $this->assertSame(['alert', 78, ['screen' => 'pertes.constats', 'params' => ['isNew' => true]], true, 'CAD'], [$t['kind'], $t['priority'], $t['action'], $t['dismissible'], $t['currency']]);
        $this->assertSame([$before + 1, 'count', 'money'], [$t['sources'][0]['value'], $t['sources'][0]['unit'], $t['sources'][1]['unit']]);
        $this->assertSame((int) $this->db->fetchOne("SELECT COALESCE(SUM(amount_at_stake), 0) FROM loss_finding WHERE seen_at IS NULL AND backfill = false AND status = 'open'"), $t['sources'][1]['value']);
        $this->assertMatchesRegularExpression($before === 0 ? '/^1 nouveau constat à vérifier, 13,00/u' : '/^\d+ nouveaux constats à vérifier, /u', $t['text']);
        $this->assertStringNotContainsString($this->mark, (string) json_encode($t), 'la vignette ne nomme personne');
        $this->assertMatchesRegularExpression('/new findings? to check/', $tile('en')['text']);
        $this->assertSame('Loss prevention', $tile('en')['title']);

        // Écartée : elle disparaît ; un constat de plus change la liste : elle revient
        $this->assertSame(204, $this->call('POST', '/api/gestion/accueil/vignettes/loss-findings/ecarter', [], $this->adminToken)->getStatusCode());
        $this->assertNull($tile());
        $this->openSession();
        $this->closeWith(-1400, 'Vignette, second');
        $this->json($this->admin('POST', self::BASE . '/analyse'));
        $this->assertSame($before + 2, $tile()['sources'][0]['value'], 'la liste a changé : la vignette revient');

        // Ouvrir la fiche d'un constat le retire du compte
        $this->json($this->admin('GET', self::BASE . '/constats/' . $id));
        $this->assertSame($before + 1, $tile()['sources'][0]['value']);

        // Sans le droit du module : pas de vignette
        $this->flags(true, false);
        $this->assertNull($tile(), 'module non accordé : la vignette n\'existe pas');
    }

    public function testDailyDigestEmailGoesOnceADayToAdministratorsWhoKeptItOnAndNamesNobody(): void
    {
        $this->drain();
        $cashier = $this->loginPos(['ROLE_USER_POS']);
        $this->openSession();
        $this->closeWith(-1300, 'Courriel du jour');
        $this->db->executeStatement('UPDATE loss_setting SET last_digest_day = NULL');
        // Le courriel part dans la langue du site : français ici (le site de test, en Ontario, serait sinon en anglais)
        $this->db->executeStatement("UPDATE entreprise SET locale = 'fr'");
        $this->provider->getEntityManager()->clear();
        // Préférences de la gestion : le type existe, par courriel, disponible parce que le droit du module est accordé
        $this->flags(true, true);
        $prefs = array_column($this->json($this->call('GET', '/api/gestion/notifications/preferences?locale=fr', null, $this->adminToken))['kinds'], null, 'kind');
        $this->assertSame([true, true, 'email', 'Prévention des pertes : résumé du jour par courriel'], [$prefs['loss_digest']['enabled'], $prefs['loss_digest']['available'], $prefs['loss_digest']['channel'], $prefs['loss_digest']['label']]);
        $this->assertSame('push', $prefs['low_stock']['channel']);
        $adminEmail = (string) $this->db->fetchOne('SELECT email FROM "user" WHERE id = ?', [$this->adminId]);
        $pushLog = (int) $this->db->fetchOne('SELECT COUNT(*) FROM notification_log');

        $run = function (): string {
            $tester = new \Symfony\Component\Console\Tester\CommandTester((new \Symfony\Bundle\FrameworkBundle\Console\Application(static::$kernel))->find('app:pertes:analyser'));
            $tester->execute(['--tenant' => MV_TEST_TENANT_CODE]);
            $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

            return $tester->getDisplay();
        };
        $display = $run();
        $this->assertStringContainsString('courriel de résumé envoyé', $display);
        $mails = $this->getMailerMessages();
        $this->assertNotEmpty($mails);
        $to = array_merge(...array_map(fn ($m) => array_map(fn ($a) => $a->getAddress(), $m->getTo()), $mails));
        $this->assertContains($adminEmail, $to, 'l\'administrateur du site le reçoit');
        $this->assertNotContains($cashier->getEmail(), $to, 'un caissier ne le reçoit pas');
        $mine = array_values(array_filter($mails, fn ($m) => $m->getTo()[0]->getAddress() === $adminEmail))[0];
        $this->assertMatchesRegularExpression('/^Prévention des pertes : \d+ nouveaux? constats? à vérifier$/u', (string) $mine->getSubject());
        $body = (string) $mine->getHtmlBody();
        $this->assertMatchesRegularExpression('/nouveaux? constats? attend/u', $body);
        $this->assertStringContainsString('Montant en jeu', $body);
        $this->assertStringContainsString('Ouvrez la section « Prévention des pertes »', html_entity_decode($body));
        $this->assertStringNotContainsString($this->mark, $body, 'le courriel ne nomme personne');
        $this->assertStringNotContainsString('Courriel du jour', $body, 'ni aucun motif saisi à la caisse');
        $this->assertDoesNotMatchRegularExpression('/href="https?:/i', preg_replace('/<img[^>]*>/i', '', $body), 'aucun lien dans ce lot');
        $this->assertSame($this->today(), (string) $this->db->fetchOne('SELECT last_digest_day FROM loss_setting'));
        $this->assertSame($pushLog, (int) $this->db->fetchOne('SELECT COUNT(*) FROM notification_log'), 'rien dans le journal des notifications du téléphone');
        $sent = json_decode((string) $this->db->fetchOne("SELECT data FROM loss_journal WHERE type = 'DIGEST_SENT' ORDER BY id DESC LIMIT 1"), true);
        $this->assertSame(['day', 'count', 'amount', 'recipients'], array_keys($sent), 'au journal du module : des nombres, aucune adresse');

        // Les textes anglais existent (un site anglophone reçoit le courriel en anglais : la langue du site est lue par EmailLocale)
        $translator = static::getContainer()->get('translator');
        $this->assertSame('Loss prevention: 2 new findings to check', $translator->trans('loss_digest.subject', ['%count%' => 2], 'emails', 'en'));
        $this->assertSame('Loss prevention: 1 new finding to check', $translator->trans('loss_digest.subject', ['%count%' => 1], 'emails', 'en'));
        $this->assertSame('Prévention des pertes : 1 nouveau constat à vérifier', $translator->trans('loss_digest.subject', ['%count%' => 1], 'emails', 'fr'));

        // Le même jour : pas de second courriel, même avec un nouveau constat
        $this->openSession();
        $this->closeWith(-1500, 'Second constat du jour');
        $this->assertStringNotContainsString('courriel de résumé envoyé', $run());
        $this->assertEmailCount(0);

        // Un gérant qui coupe le type ne le reçoit plus (le lendemain : le jour est remis à zéro pour l'essai)
        $this->json($this->call('PATCH', '/api/gestion/notifications/preferences', ['kinds' => ['loss_digest' => false]], $this->adminToken));
        $this->db->executeStatement('UPDATE loss_setting SET last_digest_day = NULL');
        $run();
        $this->assertNotContains($adminEmail, array_merge([], ...array_map(fn ($m) => array_map(fn ($a) => $a->getAddress(), $m->getTo()), $this->getMailerMessages())));
        $this->json($this->call('PATCH', '/api/gestion/notifications/preferences', ['kinds' => ['loss_digest' => true]], $this->adminToken));

        // Sans le droit du module : la commande ne passe pas sur le site, aucun courriel ; le type n'est plus disponible dans les préférences
        $this->flags(true, false);
        $this->db->executeStatement('UPDATE loss_setting SET last_digest_day = NULL');
        $run();
        $this->assertEmailCount(0);
        $this->assertFalse(array_column($this->json($this->call('GET', '/api/gestion/notifications/preferences', null, $this->adminToken))['kinds'], 'available', 'kind')['loss_digest']);
    }

    /**
     * Relecture du lot (14/11/2026), quatre défauts du courriel : il partait alors que l'analyse était arrêtée par la notice ; il affichait la
     * signature configurée du site (nom, lien, HTML) ; il passait un domaine de traduction à la place de l'hôte du site ; il marquait le jour
     * après l'envoi, sans réservation (double envoi possible). La vignette d'accueil suit la même règle que le courriel pour la notice.
     */
    public function testDailyDigestStopsWithTheAnalysisReservesItsDayAndNeverCarriesTheSiteSignature(): void
    {
        $this->drain();
        $this->loginPos(['ROLE_USER_POS']);
        $this->db->executeStatement("UPDATE entreprise SET locale = 'fr'");
        $this->provider->getEntityManager()->clear();
        $this->flags(true, true);
        $adminEmail = (string) $this->db->fetchOne('SELECT email FROM "user" WHERE id = ?', [$this->adminId]);
        $day = fn (): ?string => ($v = $this->db->fetchOne('SELECT last_digest_day FROM loss_setting')) === null || $v === false ? null : (string) $v;
        $tile = fn (): ?array => array_values(array_filter($this->json($this->call('GET', '/api/gestion/accueil/vignettes?locale=fr', null, $this->adminToken)), fn (array $t) => $t['id'] === 'loss-findings'))[0] ?? null;

        // Un envoi commun simulé : il note ce qu'il reçoit, et aboutit ou non selon $deliver
        $calls = [];
        $deliver = true;
        $mailer = $this->createMock(\App\Services\EmailConfigurationService\EmailSenderService::class);
        $mailer->method('subject')->willReturn('Sujet');
        $mailer->method('sendTemplatedEmail')->willReturnCallback(function (string $to, string $subject, string $template, array $context, string $locale, string $domain) use (&$calls, &$deliver): bool {
            $calls[] = ['to' => $to, 'context' => $context, 'domain' => $domain];

            return $deliver;
        });
        $digest = function () use ($mailer): \App\LossPrevention\Services\LossDigestService { // les services du noyau EN COURS, replacés sur le site de test
            $c = static::getContainer();
            $c->get(\App\Services\TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

            return new \App\LossPrevention\Services\LossDigestService($c->get(\App\Services\TenantEntityManagerProvider::class), $c->get(\App\LossPrevention\Services\LossSettingsService::class),
                $c->get(\App\LossPrevention\Services\LossHomeTile::class), $c->get(\App\LossPrevention\Services\LossSite::class), $c->get(\App\Gestion\Services\GestionNotificationService::class), $mailer,
                $c->get(\App\Services\EmailConfigurationService\EmailLocale::class), $c->get('translator'), new \Psr\Log\NullLogger(),
                $c->get(\App\Services\TenantConnectionManager::class), $c->get(\App\Services\TenantPublicHostResolver::class));
        };
        $mine = function () use (&$calls, $adminEmail): array {
            return array_values(array_filter($calls, fn (array $c) => $c['to'] === $adminEmail));
        }; // d'autres administrateurs d'essai s'accumulent dans la base de test

        // Québec : la notice est exigée. Confirmée, une clôture avec un manque ouvre un constat nouveau : vignette présente
        $this->place('CA', 'QC');
        $this->json($this->admin('PUT', self::BASE . '/reglages', ['staffNoticeConfirmed' => true]));
        $this->openSession();
        $this->closeWith(-1300, 'Notice retirée');
        $this->assertSame(1, $this->json($this->admin('POST', self::BASE . '/analyse'))['opened']);
        $this->assertNotNull($tile(), 'notice confirmée, un constat nouveau : la vignette est là');

        // B1. Notice RETIRÉE : l'analyse est arrêtée ; ni courriel, ni vignette, et le jour n'est pas marqué
        $this->json($this->admin('PUT', self::BASE . '/reglages', ['staffNoticeConfirmed' => false]));
        $this->db->executeStatement('UPDATE loss_setting SET last_digest_day = NULL');
        $this->assertSame(['status' => 'not_allowed', 'sent' => 0], $digest()->sendIfDue());
        $this->assertSame([[], null], [$calls, $day()], 'notice retirée : aucun envoi, jour non marqué');
        $this->assertNull($tile(), 'notice retirée : pas de « N nouveaux constats » sur l\'accueil');
        $run = function (): string { // la commande du minuteur, prise dans le noyau EN COURS (il redémarre à chaque requête)
            $tester = new \Symfony\Component\Console\Tester\CommandTester((new \Symfony\Bundle\FrameworkBundle\Console\Application(static::$kernel))->find('app:pertes:analyser'));
            $tester->execute(['--tenant' => MV_TEST_TENANT_CODE]);
            $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

            return $tester->getDisplay();
        };
        $this->assertStringNotContainsString('courriel de résumé envoyé', $run(), 'la commande du minuteur n\'envoie rien non plus');
        $this->assertNull($day());
        $this->json($this->admin('PUT', self::BASE . '/reglages', ['staffNoticeConfirmed' => true]));
        $this->assertNotNull($tile(), 'notice reconfirmée : la vignette revient');

        // B4. Aucun envoi n'aboutit : le jour réservé est RENDU (la valeur d'avant revient), on réessaiera
        $deliver = false;
        $this->assertSame(['status' => 'not_delivered', 'sent' => 0], $digest()->sendIfDue());
        $this->assertCount(1, $mine());
        $this->assertNull($day(), 'envoi impossible : jour non marqué');
        $this->db->executeStatement("UPDATE loss_setting SET last_digest_day = '2020-01-01'");
        $this->assertSame('not_delivered', $digest()->sendIfDue()['status']);
        $this->assertSame('2020-01-01', $day(), 'la valeur d\'avant la réservation revient');

        // Envoi qui aboutit : jour marqué ; un second appel n'envoie rien (deux lancements de suite : un seul courriel)
        $deliver = true;
        $calls = [];
        $first = $digest()->sendIfDue();
        $this->assertSame(['sent', $this->today()], [$first['status'], $day()]);
        $this->assertCount(1, $mine());
        $this->assertSame(['status' => 'already_sent', 'sent' => 0], $digest()->sendIfDue());
        $this->assertCount(1, $mine(), 'deux appels de suite : un seul envoi');
        // La réservation elle-même : une écriture conditionnelle, que le second n'obtient pas
        $settings = $this->provider->getEntityManager()->getRepository(\App\LossPrevention\Entity\LossSetting::class);
        $this->assertNull($settings->reserveDigestDay($this->today()), 'jour déjà pris');
        $this->db->executeStatement('UPDATE loss_setting SET last_digest_day = NULL');
        $this->assertSame([['previous' => null], null], [$settings->reserveDigestDay($this->today()), $settings->reserveDigestDay($this->today())], 'de deux réservations, une seule passe');
        $settings->releaseDigestDay($this->today(), null);
        $this->assertNull($day());

        // B3. Le domaine passé à l'envoi commun est l'hôte public du site (adresse du logo), jamais un domaine de traduction
        // B2. La dernière ligne du courriel voyage sous `siteLine` : `signature` est écrasée par l'envoi commun
        $sent = $mine()[0];
        $host = static::getContainer()->get(\App\Services\TenantPublicHostResolver::class)->describe(MV_TEST_TENANT_CODE)['host'] ?? MV_TEST_TENANT_CODE;
        $this->assertSame($host, $sent['domain']);
        $this->assertNotSame('emails', $sent['domain']);
        $this->assertArrayNotHasKey('signature', $sent['context']);
        $this->assertArrayHasKey('siteLine', $sent['context']);

        // B2, par le VRAI envoi : la signature configurée du site (HTML, un nom, un lien) n'entre pas dans le courriel
        $signature = '<p>Jean-Michel Signataire<br><a href="https://signature.example.invalid/contact">Nous écrire</a></p>';
        $before = $this->db->fetchAllAssociative('SELECT id, signature FROM email_configuration_translation');
        $added = null;
        try {
            if ($before === []) {
                $this->db->insert('email_configuration_translation', ['email_configuration_id' => (int) $this->db->fetchOne('SELECT MIN(id) FROM email_configuration'), 'language' => 'fr', 'from_name' => 'Essai', 'signature' => $signature]);
                $added = (int) $this->db->lastInsertId();
            } else {
                $this->db->executeStatement('UPDATE email_configuration_translation SET signature = ?', [$signature]);
            }
            $this->db->executeStatement('UPDATE loss_setting SET last_digest_day = NULL');
            $this->provider->getEntityManager()->clear();
            $this->assertStringContainsString('courriel de résumé envoyé', $run());
            $mails = array_values(array_filter($this->getMailerMessages(), fn ($m) => $m->getTo()[0]->getAddress() === $adminEmail));
            $this->assertNotEmpty($mails);
            $body = (string) $mails[0]->getHtmlBody();
            $this->assertStringNotContainsString('Signataire', $body, 'le nom porté par la signature du site n\'est pas dans le courriel');
            $this->assertStringNotContainsString('http', $body, 'ni son lien, ni aucune adresse');
            $this->assertStringNotContainsString('&lt;', $body, 'ni balise affichée en clair');
            $siteName = (string) $this->db->fetchOne('SELECT name FROM entreprise ORDER BY id LIMIT 1');
            if ($siteName !== '') {
                $this->assertStringContainsString($siteName, html_entity_decode($body, ENT_QUOTES), 'la dernière ligne est le nom du site');
            }
        } finally {
            if ($added !== null) {
                $this->db->executeStatement('DELETE FROM email_configuration_translation WHERE id = ?', [$added]);
            }
            foreach ($before as $row) {
                $this->db->executeStatement('UPDATE email_configuration_translation SET signature = ? WHERE id = ?', [$row['signature'], $row['id']]);
            }
        }
    }

    public function testTheFiveScreensHaveTheirGuideInBothLanguages(): void
    {
        foreach (['pertes.home', 'pertes.constats', 'pertes.constat', 'pertes.regles', 'pertes.reglages'] as $code) {
            $fr = $this->json($this->call('GET', "/api/gestion/ecrans/$code/guide?locale=fr", null, $this->adminToken));
            $en = $this->json($this->call('GET', "/api/gestion/ecrans/$code/guide?locale=en", null, $this->adminToken));
            $this->assertSame([$code, $code], [$fr['screen'], $en['screen']]);
            $this->assertGreaterThan(40, mb_strlen($fr['purpose']), $code);
            $this->assertNotSame($fr['purpose'], $en['purpose'], "$code : le guide anglais n'est pas le texte français");
            $this->assertGreaterThanOrEqual(3, count($fr['points']), $code);
            $this->assertSame(count($fr['points']), count($en['points']));
            $this->assertNotSame($fr['title'], $en['title']);
            foreach ([$fr, $en] as $guide) {
                $this->assertStringNotContainsString('pertes_', (string) json_encode($guide), "$code : aucun texte en clé brute");
                $this->assertNotSame('', (string) $guide['next']);
            }
        }
        $this->assertSame('Prévention des pertes', $this->json($this->call('GET', '/api/gestion/ecrans/pertes.home/guide?locale=fr', null, $this->adminToken))['title']);
    }
}
