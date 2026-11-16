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
        $this->assertSame((int) $this->db->fetchOne("SELECT COALESCE(SUM(amount_at_stake), 0) FROM loss_finding WHERE exercise = false AND seen_at IS NULL AND backfill = false AND status = 'open'"), $t['sources'][1]['value'],
            'la vignette ne compte jamais un constat d\'exercice');
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

        // Aucun des constats nouveaux ne porte de montant (tiroir ouvert sans vente) : la vignette ne dit pas « 0,00 $ en jeu » ; dès qu'un
        // constat en porte un, le montant est la somme de ceux qui en ont un
        $this->db->executeStatement('UPDATE loss_finding SET seen_at = NOW(), seen_by = ? WHERE seen_at IS NULL', [$this->adminId]);
        $this->assertNull($tile(), 'plus aucun constat nouveau : pas de vignette');
        $this->openSession();
        foreach (range(1, 4) as $i) {
            $this->pos('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => 'Sans montant ' . $i]);
        }
        $this->assertSame(1, $this->json($this->admin('POST', self::BASE . '/analyse'))['opened']);
        $noAmount = $tile();
        $this->assertSame(['1 nouveau constat à vérifier.', 1, 0], [$noAmount['text'], $noAmount['sources'][0]['value'], $noAmount['sources'][1]['value']]);
        $this->assertSame('1 new finding to check.', $tile('en')['text']);
        $this->closeWith(-1500, 'Avec un montant');
        $this->assertSame(1, $this->json($this->admin('POST', self::BASE . '/analyse'))['opened']);
        $this->assertMatchesRegularExpression('/^2 nouveaux constats à vérifier, 15,00/u', $tile()['text'], 'le montant est la somme des constats qui en portent un');

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
        $pushLog = (int) $this->db->fetchOne("SELECT COUNT(*) FROM notification_log WHERE channel = 'push'");

        // Le compte super administrateur de la plateforme porte aussi le rôle d'administrateur sur chaque site : il ne reçoit pas le résumé
        $platform = $this->user(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], 'Plateforme');

        $run = function (): string {
            $tester = new \Symfony\Component\Console\Tester\CommandTester((new \Symfony\Bundle\FrameworkBundle\Console\Application(static::$kernel))->find('app:pertes:analyser'));
            $tester->execute(['--tenant' => MV_TEST_TENANT_CODE, '--courriel-maintenant' => true]);
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
        $this->assertNotContains($platform->getEmail(), $to, 'le compte super administrateur de la plateforme ne le reçoit pas');
        $mine = array_values(array_filter($mails, fn ($m) => $m->getTo()[0]->getAddress() === $adminEmail))[0];
        $this->assertMatchesRegularExpression('/^Prévention des pertes : \d+ nouveaux? constats? à vérifier$/u', (string) $mine->getSubject());
        $body = (string) $mine->getHtmlBody();
        $this->assertMatchesRegularExpression('/nouveaux? constats? attend/u', $body);
        $this->assertStringContainsString('Montant en jeu', $body);
        $this->assertStringContainsString('Ouvrez la section « Prévention des pertes »', html_entity_decode($body));
        $this->assertStringNotContainsString($this->mark, $body, 'le courriel ne nomme personne');
        $this->assertStringNotContainsString('Courriel du jour', $body, 'ni aucun motif saisi à la caisse');
        // Une seule adresse dans le courriel : le lien vers les constats nouveaux de l'espace d'administration, sur le domaine du site
        $link = $this->adminLink('pertes.constats', ['isNew' => true]);
        $this->assertMatchesRegularExpression('#^https://[^/]+/admin/ouvrir\?screen=pertes\.constats&isNew=1$#', (string) $link);
        $this->assertSame([$link], self::addressesOf($body), 'une seule adresse, celle du lien');
        $this->assertStringContainsString('Ouvrir les nouveaux constats', $body);
        $this->assertSame($this->today(), (string) $this->db->fetchOne('SELECT last_digest_day FROM loss_setting'));
        // (lot NOTIF-1 : le journal porte aussi les courriels, sur le canal « email » ; la notification du jour du téléphone ne compte que « push »)
        $this->assertSame($pushLog, (int) $this->db->fetchOne("SELECT COUNT(*) FROM notification_log WHERE channel = 'push'"), 'rien dans le journal des notifications du téléphone');
        $sent = json_decode((string) $this->db->fetchOne("SELECT data FROM loss_journal WHERE type = 'DIGEST_SENT' ORDER BY id DESC LIMIT 1"), true);
        $this->assertSame(['day', 'count', 'amount', 'recipients', 'renewed', 'renewedUpTo'], array_keys($sent), 'au journal du module : des nombres, aucune adresse');

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
        $mark = fn (): ?int => ($v = $this->db->fetchOne('SELECT last_digest_finding_id FROM loss_setting')) === null || $v === false ? null : (int) $v;
        $tz = static::getContainer()->get(\App\Services\CaisseService\StoreClock::class)->timezone();
        $at = fn (string $time): \DateTimeImmutable => new \DateTimeImmutable($this->today() . ' ' . $time, $tz); // une heure du MAGASIN, aujourd'hui
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
        $build = function (\App\Services\EmailConfigurationService\EmailSenderService $mailer): \App\LossPrevention\Services\LossDigestService { // les services du noyau EN COURS, replacés sur le site de test
            $c = static::getContainer();
            $c->get(\App\Services\TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

            return new \App\LossPrevention\Services\LossDigestService($c->get(\App\Services\TenantEntityManagerProvider::class), $c->get(\App\LossPrevention\Services\LossSettingsService::class),
                $c->get(\App\LossPrevention\Services\LossSite::class), $c->get(\App\Gestion\Services\GestionNotificationService::class), $mailer,
                $c->get(\App\Services\EmailConfigurationService\EmailLocale::class), $c->get('translator'), new \Psr\Log\NullLogger(),
                $c->get(\App\Services\TenantConnectionManager::class), $c->get(\App\Services\TenantPublicHostResolver::class), $c->get(\App\Services\Site\AdminSpaceUrl::class),
                $c->get(\App\Services\Notification\NotificationGate::class)); // lot NOTIF-1 : la décision d'envoi (sites de test au niveau « Tout »)
        };
        $digest = fn (): \App\LossPrevention\Services\LossDigestService => $build($mailer);
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
        $this->db->executeStatement('UPDATE loss_setting SET last_digest_day = NULL, last_digest_finding_id = NULL');
        $this->assertSame(['status' => 'not_allowed', 'sent' => 0], $digest()->sendIfDue($at('09:00')));
        $this->assertSame([[], null], [$calls, $day()], 'notice retirée : aucun envoi, jour non marqué');
        $this->assertNull($tile(), 'notice retirée : pas de « N nouveaux constats » sur l\'accueil');
        $run = function (): string { // la commande du minuteur, prise dans le noyau EN COURS (il redémarre à chaque requête)
            $tester = new \Symfony\Component\Console\Tester\CommandTester((new \Symfony\Bundle\FrameworkBundle\Console\Application(static::$kernel))->find('app:pertes:analyser'));
            $tester->execute(['--tenant' => MV_TEST_TENANT_CODE, '--courriel-maintenant' => true]);
            $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

            return $tester->getDisplay();
        };
        $this->assertStringNotContainsString('courriel de résumé envoyé', $run(), 'la commande du minuteur n\'envoie rien non plus');
        $this->assertNull($day());
        $this->json($this->admin('PUT', self::BASE . '/reglages', ['staffNoticeConfirmed' => true]));
        $this->assertNotNull($tile(), 'notice reconfirmée : la vignette revient');

        // Heure d'envoi (décision du propriétaire) : à partir de 8 h, heure du MAGASIN ; avant, rien ne part et le jour n'est pas réservé
        $this->assertSame(['status' => 'too_early', 'sent' => 0], $digest()->sendIfDue($at('07:59')));
        $this->assertSame([[], null, null], [$calls, $day(), $mark()], '7 h 59 : aucun envoi, ni jour ni borne');

        // B4. Aucun envoi n'aboutit : le jour ET la borne réservés sont RENDUS (les valeurs d'avant reviennent), on réessaiera
        $deliver = false;
        $this->assertSame(['status' => 'not_delivered', 'sent' => 0], $digest()->sendIfDue($at('08:00')));
        $this->assertCount(1, $mine(), '8 h 00 : l\'envoi est tenté');
        $this->assertSame([null, null], [$day(), $mark()], 'envoi impossible : jour non marqué, borne non avancée');
        $this->db->executeStatement("UPDATE loss_setting SET last_digest_day = '2020-01-01', last_digest_finding_id = 0");
        $this->assertSame('not_delivered', $digest()->sendIfDue($at('08:00'))['status']);
        $this->assertSame(['2020-01-01', 0], [$day(), $mark()], 'les deux valeurs d\'avant la réservation reviennent');

        // C2 (relecture). Une erreur PENDANT l'envoi, avant qu'un seul courriel soit parti : la réservation est rendue, l'erreur remonte
        $broken = $this->createMock(\App\Services\EmailConfigurationService\EmailSenderService::class);
        $broken->method('subject')->willReturn('Sujet');
        $broken->method('sendTemplatedEmail')->willThrowException(new \Error('panne pendant l\'envoi'));
        try {
            $build($broken)->sendIfDue($at('08:00'));
            $this->fail('l\'erreur devait remonter');
        } catch (\Error $e) {
            $this->assertSame('panne pendant l\'envoi', $e->getMessage());
        }
        $this->assertSame(['2020-01-01', 0], [$day(), $mark()], 'erreur pendant l\'envoi : jour et borne d\'avant');
        // Un envoi qui lève pour chaque destinataire : aucun courriel parti, tout est rendu
        $refusing = $this->createMock(\App\Services\EmailConfigurationService\EmailSenderService::class);
        $refusing->method('subject')->willReturn('Sujet');
        $refusing->method('sendTemplatedEmail')->willThrowException(new \RuntimeException('refus du serveur d\'envoi'));
        $this->assertSame(['status' => 'not_delivered', 'sent' => 0], $build($refusing)->sendIfDue($at('08:00')));
        $this->assertSame(['2020-01-01', 0], [$day(), $mark()]);
        // Une erreur pendant la PRÉPARATION (ici l'objet du courriel) : elle arrive AVANT la réservation, rien n'est posé ni envoyé
        $unprepared = $this->createMock(\App\Services\EmailConfigurationService\EmailSenderService::class);
        $unprepared->method('subject')->willThrowException(new \RuntimeException('objet illisible'));
        $unprepared->expects($this->never())->method('sendTemplatedEmail');
        $this->db->executeStatement("UPDATE loss_setting SET last_digest_day = NULL, last_digest_finding_id = NULL"); // une réservation laisserait ici une trace visible
        try {
            $build($unprepared)->sendIfDue($at('08:00'));
            $this->fail('l\'erreur de préparation devait remonter');
        } catch (\RuntimeException $e) {
            $this->assertSame('objet illisible', $e->getMessage());
        }
        $this->assertSame([null, null], [$day(), $mark()], 'erreur avant la réservation : ni jour ni borne');
        $this->db->executeStatement("UPDATE loss_setting SET last_digest_day = '2020-01-01', last_digest_finding_id = 0");

        // Envoi qui aboutit : jour marqué, borne posée sur le dernier constat ; un second appel n'envoie rien (un seul courriel)
        $deliver = true;
        $calls = [];
        $lastFinding = (int) $this->db->fetchOne('SELECT MAX(id) FROM loss_finding');
        $first = $digest()->sendIfDue($at('08:00'));
        $this->assertSame(['sent', $this->today(), $lastFinding], [$first['status'], $day(), $mark()]);
        $this->assertCount(1, $mine());
        $this->assertSame(['status' => 'already_sent', 'sent' => 0], $digest()->sendIfDue($at('08:15')));
        $this->assertCount(1, $mine(), 'deux appels de suite : un seul envoi');

        // Rappel (décision du propriétaire) : seuls les constats NÉS depuis le courriel précédent sont annoncés. Le lendemain, le constat
        // d'hier n'est toujours pas ouvert : aucun courriel, et la vignette de l'accueil continue de le rappeler
        $this->db->executeStatement("UPDATE loss_setting SET last_digest_day = '2020-01-01'");
        $this->assertSame(['status' => 'nothing_new', 'sent' => 0], $digest()->sendIfDue($at('08:00')));
        $this->assertSame(['2020-01-01', $lastFinding], [$day(), $mark()], 'rien de né : ni envoi, ni réservation');
        $this->assertCount(1, $mine());
        $this->assertNotNull($tile(), 'la vignette rappelle toujours le constat non ouvert');
        // Un constat naît : le courriel suivant n'annonce que lui
        $this->openSession();
        $this->closeWith(-1500, 'Né depuis le dernier courriel');
        $this->assertSame(1, $this->json($this->admin('POST', self::BASE . '/analyse'))['opened']);
        $calls = [];
        $this->assertSame('sent', $digest()->sendIfDue($at('08:00'))['status']);
        $this->assertSame(['1', (int) $this->db->fetchOne('SELECT MAX(id) FROM loss_finding')], [array_values($mine()[0]['context']['rows'])[0], $mark()], 'un seul constat annoncé : celui qui est né depuis');

        // C1 (relecture). La borne est le dernier constat ANNONCÉ, pas le dernier constat de la table : de deux constats nés depuis, le plus
        // récent est déjà ouvert par le gérant ; il n'est pas annoncé, et la borne ne le dépasse pas
        $finding = fn (array $report): int => (int) $this->db->fetchOne('SELECT id FROM loss_finding WHERE dedup_key = ?', ['CASH_DISCREPANCY:z_report:' . $report['id']]);
        $this->openSession();
        $announced = $finding($this->closeWith(-1600, 'Annoncé') + ['_' => $this->json($this->admin('POST', self::BASE . '/analyse'))]);
        $this->openSession();
        $opened = $finding($this->closeWith(-1700, 'Déjà ouvert') + ['_' => $this->json($this->admin('POST', self::BASE . '/analyse'))]);
        $this->json($this->admin('GET', self::BASE . '/constats/' . $opened));
        $this->assertGreaterThan($announced, $opened);
        $this->db->executeStatement("UPDATE loss_setting SET last_digest_day = '2020-01-02'");
        $calls = [];
        $this->assertSame('sent', $digest()->sendIfDue($at('08:00'))['status']);
        $this->assertSame(['1', $announced], [array_values($mine()[0]['context']['rows'])[0], $mark()], 'un constat annoncé ; la borne s\'arrête à lui');

        // Décision du propriétaire : un constat de REPRISE redevenu « nouveau » parce qu'une preuve récente le complète est annoncé dans le
        // courriel du jour suivant, à part (« complété par une nouvelle preuve »), une seule fois par retour à « nouveau »
        $session = $this->openSession();
        foreach (range(1, 4) as $i) {
            $this->pos('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => 'Reprise ' . $i]);
        }
        $this->assertSame(1, $this->json($this->admin('POST', self::BASE . '/analyse'))['opened']);
        $old = (int) $this->db->fetchOne('SELECT id FROM loss_finding WHERE dedup_key = ?', ['DRAWER_NO_SALE:session:' . $session]);
        $this->db->executeStatement('UPDATE loss_finding SET backfill = true WHERE id = ?', [$old]); // comme s'il venait de la reprise des 30 jours
        $this->db->executeStatement("UPDATE loss_setting SET last_digest_day = '2020-01-03'");
        $this->assertSame('nothing_new', $digest()->sendIfDue($at('08:00'))['status'], 'un constat de reprise n\'est pas annoncé');
        // Une ouverture de plus, postérieure à la première analyse : le constat cesse d'être une reprise, il redevient « nouveau »
        $this->pos('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => 'Preuve récente']);
        $this->assertSame([0, 1], array_values(array_intersect_key($this->json($this->admin('POST', self::BASE . '/analyse')), ['opened' => 1, 'completed' => 1])));
        $this->assertSame([false, 1], [(bool) $this->db->fetchOne('SELECT backfill FROM loss_finding WHERE id = ?', [$old]),
            (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_journal WHERE type = 'FINDING_RENEWED' AND finding_id = ?", [$old])]);
        $markBefore = $mark();
        // Un envoi qui échoue d'abord : rien n'est noté, le constat reste à annoncer ; au réessai il l'est, une fois
        $deliver = false;
        $this->assertSame('not_delivered', $digest()->sendIfDue($at('08:00'))['status']);
        $this->assertSame(['2020-01-03', $markBefore], [$day(), $mark()]);
        $deliver = true;
        $calls = [];
        $this->assertSame('sent', $digest()->sendIfDue($at('08:00'))['status']);
        $context = $mine()[0]['context'];
        $this->assertSame(['Nouveaux constats' => '1', 'Dont complétés par une nouvelle preuve' => '1'], array_slice($context['rows'], 0, 2, true));
        $this->assertStringContainsString('Parmi eux, 1 constat plus ancien, complété par une nouvelle preuve.', $context['message']);
        $this->assertSame($markBefore >= $old ? $markBefore : $old, $mark(), 'la borne des constats nés couvre le constat annoncé');
        $line = json_decode((string) $this->db->fetchOne("SELECT data FROM loss_journal WHERE type = 'DIGEST_SENT' ORDER BY id DESC LIMIT 1"), true);
        $this->assertSame([1, 1], [$line['count'], $line['renewed']]);
        $this->assertGreaterThan(0, $line['renewedUpTo']);
        // Une seule annonce par retour à « nouveau » : le lendemain, toujours pas ouvert, il n'est plus annoncé
        $this->db->executeStatement("UPDATE loss_setting SET last_digest_day = '2020-01-04'");
        $this->assertSame('nothing_new', $digest()->sendIfDue($at('08:00'))['status']);
        // Une ouverture de plus le complète encore : il n'était plus une reprise, ce n'est pas un nouveau retour à « nouveau »
        $this->pos('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => 'Encore une']);
        $this->json($this->admin('POST', self::BASE . '/analyse'));
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM loss_journal WHERE type = 'FINDING_RENEWED' AND finding_id = ?", [$old]));
        $this->assertSame('nothing_new', $digest()->sendIfDue($at('08:00'))['status']);
        $this->closeWith(0);
        // Un courriel qui n'annonce QUE des constats redevenus « nouveaux », tous plus anciens que la borne : rien n'est né, la réservation
        // reçoit « pas de borne » et GARDE celle qu'elle a. Vidée, le courriel suivant ré-annoncerait tous les constats nés depuis toujours
        $session = $this->openSession();
        foreach (range(1, 4) as $i) {
            $this->pos('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => 'Reprise bis ' . $i]);
        }
        $this->assertSame(1, $this->json($this->admin('POST', self::BASE . '/analyse'))['opened']);
        $older = (int) $this->db->fetchOne('SELECT id FROM loss_finding WHERE dedup_key = ?', ['DRAWER_NO_SALE:session:' . $session]);
        $this->db->executeStatement('UPDATE loss_finding SET backfill = true WHERE id = ?', [$older]);
        // la borne est à sa hauteur ou au-dessus, comme si les constats nés jusque-là avaient déjà été annoncés
        $this->db->executeStatement("UPDATE loss_setting SET last_digest_day = '2020-01-06', last_digest_finding_id = (SELECT MAX(id) FROM loss_finding)");
        $top = $mark();
        $this->assertGreaterThanOrEqual($older, $top);
        $this->pos('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => 'Preuve récente bis']);
        $this->assertSame([0, 1], array_values(array_intersect_key($this->json($this->admin('POST', self::BASE . '/analyse')), ['opened' => 1, 'completed' => 1])));
        $calls = [];
        $this->assertSame('sent', $digest()->sendIfDue($at('08:00'))['status']);
        $this->assertSame(['Nouveaux constats' => '1', 'Dont complétés par une nouvelle preuve' => '1'], array_slice($mine()[0]['context']['rows'], 0, 2, true));
        $this->assertSame($top, $mark(), 'rien de né : la borne des constats nés ne bouge pas, et surtout n\'est pas vidée');
        $this->db->executeStatement("UPDATE loss_setting SET last_digest_day = '2020-01-07'");
        $this->assertSame('nothing_new', $digest()->sendIfDue($at('08:00'))['status'], 'le lendemain : aucun constat né n\'est ré-annoncé');
        $this->closeWith(0);
        // Ce que la commande dit du courriel : envoyé, retenu par les niveaux des courriels de suivi (avec ou sans le nombre), sinon rien
        $this->assertSame(['demo : courriel de résumé envoyé à 2 destinataire(s)', 'demo : courriel de résumé retenu pour 3 destinataire(s) (niveau de notification) : rien n\'est parti',
            'demo : courriel de résumé retenu (niveau de notification des destinataires) : rien n\'est parti', null, null, null], [
            \App\LossPrevention\Command\LossAnalyseCommand::digestLine('demo', ['status' => 'sent', 'sent' => 2]),
            \App\LossPrevention\Command\LossAnalyseCommand::digestLine('demo', ['status' => 'held', 'sent' => 0, 'held' => 3]),
            \App\LossPrevention\Command\LossAnalyseCommand::digestLine('demo', ['status' => 'held', 'sent' => 0]),
            \App\LossPrevention\Command\LossAnalyseCommand::digestLine('demo', ['status' => 'nothing_new', 'sent' => 0]),
            \App\LossPrevention\Command\LossAnalyseCommand::digestLine('demo', ['status' => 'not_delivered', 'sent' => 0]),
            \App\LossPrevention\Command\LossAnalyseCommand::digestLine('demo', null)]);
        // Le courriel suit la même règle que la vignette : un constat né sans montant n'annonce pas « 0,00 $ », ni dans la phrase ni dans le tableau
        $this->db->executeStatement("UPDATE loss_setting SET last_digest_day = '2020-01-05', last_digest_finding_id = (SELECT MAX(id) FROM loss_finding)");
        $this->openSession();
        foreach (range(1, 4) as $i) {
            $this->pos('POST', '/api/caisse/drawer-open', ['cashRegisterId' => $this->register, 'reason' => 'Sans montant ' . $i]);
        }
        $this->assertSame(1, $this->json($this->admin('POST', self::BASE . '/analyse'))['opened']);
        $calls = [];
        $this->assertSame('sent', $digest()->sendIfDue($at('08:00'))['status']);
        $plain = $mine()[0]['context'];
        $this->assertSame(['1 nouveau constat attend votre vérification.', ['Nouveaux constats', 'Journée du magasin']], [$plain['message'], array_keys($plain['rows'])]);
        $this->closeWith(0);
        $this->db->executeStatement("UPDATE loss_setting SET last_digest_day = ?", [$this->today()]); // l'état attendu par la suite du test

        // La réservation elle-même : une écriture conditionnelle, que le second lancement n'obtient pas ; lui seul n'avance pas la borne
        $settings = $this->provider->getEntityManager()->getRepository(\App\LossPrevention\Entity\LossSetting::class);
        $this->assertNull($settings->reserveDigestDay($this->today(), 999), 'jour déjà pris');
        $this->db->executeStatement('UPDATE loss_setting SET last_digest_day = NULL, last_digest_finding_id = 7');
        $this->assertSame([['previous' => null, 'previousFindingId' => 7], null], [$settings->reserveDigestDay($this->today(), 10), $settings->reserveDigestDay($this->today(), 20)], 'de deux réservations, une seule passe');
        $this->assertSame(10, $mark(), 'la borne est celle du lancement qui a réservé');
        $settings->releaseDigestDay($this->today(), null, 7);
        $this->assertSame([null, 7], [$day(), $mark()]);

        // B3. Le domaine passé à l'envoi commun est l'hôte public du site (adresse du logo), jamais un domaine de traduction
        // B2. La dernière ligne du courriel voyage sous `siteLine` : `signature` est écrasée par l'envoi commun
        $sent = $mine()[0];
        $host = static::getContainer()->get(\App\Services\TenantPublicHostResolver::class)->describe(MV_TEST_TENANT_CODE)['host'] ?? MV_TEST_TENANT_CODE;
        $this->assertSame($host, $sent['domain']);
        $this->assertNotSame('emails', $sent['domain']);
        $this->assertArrayNotHasKey('signature', $sent['context']);
        $this->assertArrayHasKey('siteLine', $sent['context']);
        // Le lien (lot LIEN) : celui du service commun ; un réglage du site le déplace ; sans adresse connue, pas de lien dans le courriel
        $this->assertSame([$this->adminLink('pertes.constats', ['isNew' => true]), 'Ouvrir les nouveaux constats'], [$sent['context']['linkUrl'], $sent['context']['linkLabel']]);
        $twig = static::getContainer()->get('twig');
        $this->assertStringNotContainsString('href', $twig->render(\App\LossPrevention\Services\LossDigestService::TEMPLATE, ['linkUrl' => null] + $sent['context']), 'sans adresse connue : aucun lien, le texte dit où regarder');
        $this->assertStringContainsString('href="' . htmlspecialchars((string) $sent['context']['linkUrl']) . '"', $twig->render(\App\LossPrevention\Services\LossDigestService::TEMPLATE, $sent['context']));

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
            $this->db->executeStatement('UPDATE loss_setting SET last_digest_day = NULL, last_digest_finding_id = NULL');
            $this->provider->getEntityManager()->clear();
            $this->assertStringContainsString('courriel de résumé envoyé', $run());
            $mails = array_values(array_filter($this->getMailerMessages(), fn ($m) => $m->getTo()[0]->getAddress() === $adminEmail));
            $this->assertNotEmpty($mails);
            $body = (string) $mails[0]->getHtmlBody();
            $this->assertStringNotContainsString('Signataire', $body, 'le nom porté par la signature du site n\'est pas dans le courriel');
            $this->assertSame([$this->adminLink('pertes.constats', ['isNew' => true])], self::addressesOf($body), 'ni son lien, ni aucune autre adresse que le lien vers les constats');
            $this->assertStringNotContainsString('signature.example.invalid', $body);
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

    /** Le cheminement du site ne montre les étapes du module qu'avec le droit du module (une mutation de la condition passait sans échec) */
    public function testReadinessShowsTheModuleStepsOnlyWithTheModuleRight(): void
    {
        $readiness = fn (): array => $this->json($this->call('GET', '/api/site/readiness?locale=fr', null, $this->adminToken));
        $lossSteps = fn (array $r): array => array_values(array_filter(array_column($r['steps'], 'key'), fn (string $k) => str_starts_with($k, 'loss.')));
        static::getContainer()->get(\App\Services\Platform\PlatformModeService::class)->setMode('live', 'test'); // en mode « demo », tout est ouvert

        $this->flags(true, false);
        $without = $readiness();
        $this->assertSame([], $lossSteps($without), 'sans le droit du module : aucune étape de la prévention des pertes');
        // Son groupe ne porte alors que l'offre « Obtenir » de la plateforme, jamais une étape du module
        $this->assertSame(['obtain.module.loss_prevention'], array_column(array_values(array_filter($without['steps'], fn (array $s) => ($s['group'] ?? null) === 'module.loss_prevention')), 'key'));

        // Le droit accordé comme le fait la plateforme (un droit d'usage, que le cheminement lit en mode « live »), puis retiré
        $entitlements = static::getContainer()->get(\App\Services\Platform\EntitlementService::class);
        $granted = (int) $entitlements->grant(MV_TEST_TENANT_CODE, 'module.loss_prevention', 'manual', null, 'test', 'PERTES-CHEMINEMENT', null, null, false)['id'];
        try {
            $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
            $with = $readiness();
            $this->assertSame(['loss.management', 'loss.place', 'loss.staff_notice', 'loss.rules', 'loss.first_analysis'], $lossSteps($with), 'avec le droit : les cinq étapes');
            $this->assertSame(['loss.management', 'loss.place', 'loss.staff_notice', 'loss.rules', 'loss.first_analysis'],
                array_column(array_values(array_filter($with['steps'], fn (array $s) => ($s['group'] ?? null) === 'module.loss_prevention')), 'key'), 'le groupe du module : ses cinq étapes, plus d\'offre « Obtenir »');
            $this->assertSame(5, array_column($with['groups'], 'total', 'key')['module.loss_prevention']);
        } finally {
            static::getContainer()->get(\App\Services\Platform\EntitlementService::class)->cancel($granted, 'test', 'PERTES-CHEMINEMENT');
            $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
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
