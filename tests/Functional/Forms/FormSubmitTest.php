<?php

namespace App\Tests\Functional\Forms;

use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;

/**
 * Formulaires à étapes, lot 3 (28/10/2026) : envoi public POST /api/form-submissions. Formulaire lu dans les réglages
 * enregistrés, réponses revalidées, demande enregistrée avec sa référence, cibles réservation et contact dans la même
 * transaction, notifications après l'enregistrement, anti-robot.
 */
class FormSubmitTest extends WebTestCase
{
    private KernelBrowser $client;
    private ?string $landing = null;
    private int $offer;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $key = MV_TEST_TENANT_HOST . '|127.0.0.1';
        static::getContainer()->get('limiter.public_form_ip')->create($key)->reset();
        static::getContainer()->get('limiter.public_form_attempts_ip')->create($key)->reset();
        $db = $this->db();
        $db->executeStatement('DELETE FROM form_submission');
        $db->executeStatement("DELETE FROM reservation WHERE client_email LIKE '%@formulaire.test'");
        $db->executeStatement("DELETE FROM contact WHERE email LIKE '%@formulaire.test'");
        $db->executeStatement("UPDATE entreprise SET email = 'direction@mvtest.test', form_submissions_email = NULL");
        $this->landing = $db->fetchOne('SELECT configuration FROM landing_page_setting ORDER BY id LIMIT 1') ?: null;
        $this->offer = (int) $db->fetchOne("INSERT INTO service_offer (id, titre) VALUES (nextval('service_offer_id_seq'), 'Accompagnement aux courses') RETURNING id");
        $this->saveLanding([
            $this->section('s-gen', 'generic'),
            $this->section('s-rdv', 'reservation'),
            $this->section('s-contact', 'contact'),
        ]);
    }

    protected function tearDown(): void
    {
        $db = $this->db();
        if ($this->landing !== null) {
            $db->executeStatement('UPDATE landing_page_setting SET configuration = ? WHERE id = (SELECT MIN(id) FROM landing_page_setting)', [$this->landing]);
        }
        $db->executeStatement('DELETE FROM service_offer WHERE id = ?', [$this->offer]);
        $db->executeStatement('DELETE FROM boutique_setting');
        parent::tearDown();
    }

    public function testAValidSubmissionIsStoredWithItsReferenceAndNotified(): void
    {
        $response = $this->submit('s-gen', $this->answers());

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $reference = json_decode($response->getContent(), true)['reference'];
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{2}-\d{8}-[2-9A-HJKMNP-Z]{4}$/', $reference);
        $emails = $this->emails();
        $row = $this->db()->fetchAssociative('SELECT * FROM form_submission WHERE reference = ?', [$reference]);
        $this->assertSame(['landingpage', 's-gen', 'f', 'Rendez-vous', 'generic', 'new', 'fr'], [$row['app'], $row['section_uid'], $row['form_id'], $row['form_name'], $row['target'], $row['status'], $row['locale']]);
        $answers = array_column(json_decode($row['answers'], true), null, 'name');
        $this->assertSame(['nom', 'courriel', 'telephone', 'jour', 'creneau', 'service', 'invites', 'notes', 'accord'], array_keys($answers), 'toutes les questions à remplir, dans l\'ordre ; pas celles de l\'étape success');
        $this->assertSame(['Votre nom', 'Marie Tremblay'], [$answers['nom']['label'], $answers['nom']['display']], 'libellé au moment de l\'envoi');
        $this->assertSame([$this->offer, 'Accompagnement aux courses'], [$answers['service']['value'], $answers['service']['display']]);
        $this->assertSame(['Oui', 'Matin'], [$answers['accord']['display'], $answers['creneau']['display']]);
        $this->assertSame(64, strlen((string) $row['form_hash']));
        $this->assertNotNull($row['notified_at']);
        $this->assertSame(['direction@mvtest.test', 'marie@formulaire.test'], array_map(fn (Email $m) => $m->getTo()[0]->getAddress(), $emails), 'entreprise, puis accusé au visiteur');
        $this->assertSame('marie@formulaire.test', $emails[0]->getReplyTo()[0]->getAddress());
        $this->assertStringContainsString($reference, (string) $emails[0]->getSubject());
        $this->assertStringContainsString('Marie Tremblay', (string) $emails[0]->getHtmlBody());
        $this->assertStringContainsString($reference, (string) $emails[1]->getHtmlBody());

        $this->db()->executeStatement("UPDATE entreprise SET form_submissions_email = 'demandes@mvtest.test'");
        $this->submit('s-gen', ['courriel' => null] + $this->answers(), 'fr');
        $this->assertSame(['demandes@mvtest.test'], array_map(fn (Email $m) => $m->getTo()[0]->getAddress(), $this->emails()), 'adresse dédiée ; pas d\'accusé sans courriel');
    }

    public function testEachAnswerIsRevalidatedWithTheBrowserRules(): void
    {
        $response = $this->submit('s-gen', [
            'nom' => '   ', 'courriel' => 'marie@', 'telephone' => '12-34', 'jour' => $this->day(0), 'creneau' => 'Minuit',
            'service' => 999999, 'invites' => 9, 'notes' => str_repeat('a', 5001), 'accord' => false,
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertSame('Formulaire incomplet', $body['message']);
        $this->assertSame([
            'answers.nom' => 'Cette question est obligatoire.',
            'answers.courriel' => 'Adresse courriel invalide.',
            'answers.telephone' => 'Numéro de téléphone invalide.',
            'answers.jour' => 'Date au plus tôt dans 1 jour(s).',
            'answers.creneau' => 'Choix invalide.',
            'answers.service' => 'Prestation indisponible : choisissez-en une autre.',
            'answers.invites' => 'Au plus 6.',
            'answers.notes' => '5000 caractères au plus.',
            'answers.accord' => 'Cette question est obligatoire.',
        ], array_column($body['errors'], 'message', 'path'));
        $this->assertSame(422, $this->submit('s-gen', ['invites' => '2'] + $this->answers())->getStatusCode(), 'un nombre en texte est refusé');
        $this->assertSame(422, $this->submit('s-gen', ['jour' => '2026-02-30'] + $this->answers())->getStatusCode(), 'date impossible');
        $this->db()->executeStatement('UPDATE service_offer SET bookable = false WHERE id = ?', [$this->offer]);
        $this->assertSame(422, $this->submit('s-gen', $this->answers())->getStatusCode(), 'prestation non réservable');
        $this->assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM form_submission'));
        $this->assertSame([], $this->emails());
    }

    public function testChangedOrMissingFormsAndRobotsAreHandled(): void
    {
        $changed = $this->submit('s-gen', ['ancienne_question' => 'x'] + $this->answers());
        $this->assertSame(409, $changed->getStatusCode());
        $this->assertSame('form_changed', json_decode($changed->getContent(), true)['code']);

        $missing = $this->submit('s-inconnue', $this->answers());
        $this->assertSame(404, $missing->getStatusCode());
        $this->assertSame('form_not_found', json_decode($missing->getContent(), true)['code']);
        $this->assertSame(404, $this->submit('s-gen', $this->answers(), 'fr', ['formId' => 'e1'])->getStatusCode(), 'formId d\'une étape, pas du formulaire');
        $this->assertSame(404, $this->submit('s-gen', $this->answers(), 'fr', ['app' => 'boutique'])->getStatusCode(), 'pas dans la boutique');
        $this->assertSame(400, $this->submit('s-gen', $this->answers(), 'fr', ['app' => 'blog'])->getStatusCode());

        foreach ([['site' => 'http://spam.example'], ['fillSeconds' => 3], ['fillSeconds' => null]] as $robot) {
            $response = $this->submit('s-gen', $this->answers(), 'fr', $robot);
            $this->assertSame(201, $response->getStatusCode(), json_encode($robot));
            $this->assertMatchesRegularExpression('/^[A-Z0-9]{2}-\d{8}-[2-9A-HJKMNP-Z]{4}$/', json_decode($response->getContent(), true)['reference'], 'fausse référence de même forme');
        }
        $this->assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM form_submission'), 'rien enregistré');
        $this->assertSame([], $this->emails(), 'rien envoyé');

        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/form-submissions', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json'], str_repeat(' ', 70000));
        $this->assertSame(413, $this->client->getResponse()->getStatusCode());
    }

    public function testReservationTargetCreatesTheAppointmentInTheSameTransaction(): void
    {
        // option écrite avec un tiret long : enregistrée avec un trait d'union, comme les créneaux des formulaires actuels
        $response = $this->submit('s-rdv', ['creneau' => '09:00 – 12:00'] + $this->answers());

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $reference = json_decode($response->getContent(), true)['reference'];
        $submission = $this->db()->fetchAssociative('SELECT reservation_id, target FROM form_submission WHERE reference = ?', [$reference]);
        $reservation = $this->db()->fetchAssociative('SELECT * FROM reservation WHERE id = ?', [$submission['reservation_id']]);
        $this->assertSame(['reservation', 'Accompagnement aux courses', 'accompagnement-aux-courses', $this->day(10), '09:00 - 12:00', 'Marie Tremblay', 'marie@formulaire.test', '514 555-0123', 2],
            [$submission['target'], $reservation['service_name'], $reservation['service_id'], $reservation['reservation_date'], $reservation['reservation_slot'], $reservation['client_name'], $reservation['client_email'], $reservation['client_phone'], $reservation['number_of_guests']]);
        $this->assertStringContainsString('Canne, prévoir du temps.', $reservation['notes']);
        $this->assertStringContainsString('J\'accepte d\'être rappelé : Oui', $reservation['notes'], 'réponses sans rôle dans les remarques');
        $emails = $this->emails();
        $this->assertSame(['marie@formulaire.test', 'direction@mvtest.test'], array_map(fn (Email $m) => $m->getTo()[0]->getAddress(), $emails), 'courriels de la réservation, pas de notification générique');
        $this->assertStringContainsString($reference, (string) $emails[0]->getHtmlBody(), 'référence dans l\'accusé');
        $this->assertCount(1, $emails[0]->getAttachments(), 'invitation de calendrier');

        $taken = $this->submit('s-rdv', ['courriel' => 'autre@formulaire.test'] + $this->answers());
        $this->assertSame(409, $taken->getStatusCode(), 'même créneau (tiret simple) : déjà pris');
        $this->assertSame('slot_taken', json_decode($taken->getContent(), true)['code']);
        $this->assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM form_submission'), 'rien enregistré pour l\'envoi refusé');
        $this->assertSame(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM reservation WHERE client_email LIKE '%@formulaire.test'"));
    }

    public function testContactTargetCreatesAContact(): void
    {
        $response = $this->submit('s-contact', $this->answers());

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $contactId = $this->db()->fetchOne('SELECT contact_id FROM form_submission WHERE reference = ?', [json_decode($response->getContent(), true)['reference']]);
        $contact = $this->db()->fetchAssociative('SELECT * FROM contact WHERE id = ?', [$contactId]);
        $this->assertSame(['Marie Tremblay', 'marie@formulaire.test', '514 555-0123', 'Rendez-vous'], [$contact['name'], $contact['email'], $contact['phone'], $contact['subject']]);
        $this->assertStringContainsString('Canne, prévoir du temps.', $contact['content']);
        $this->assertCount(2, $this->emails(), 'notification générique et accusé, pas les courriels du contact en plus');
    }

    public function testBoutiqueFormsAndTranslatedLabels(): void
    {
        $this->db()->executeStatement("INSERT INTO boutique_setting (id, configuration) VALUES (nextval('boutique_setting_id_seq'), ?)", [json_encode(['tabs' => [['id' => 'product', 'system' => 'product', 'sections' => [$this->section('s-boutique', 'generic')]]]])]);

        $response = $this->submit('s-boutique', $this->answers(), 'en', ['app' => 'boutique']);

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $row = $this->db()->fetchAssociative('SELECT app, locale, answers FROM form_submission');
        $answers = array_column(json_decode($row['answers'], true), null, 'name');
        $this->assertSame(['boutique', 'en', 'Your name', 'Morning'], [$row['app'], $row['locale'], $answers['nom']['label'], $answers['creneau']['display']]);
    }

    private function answers(): array
    {
        return [
            'nom' => 'Marie Tremblay', 'courriel' => 'marie@formulaire.test', 'telephone' => '514 555-0123', 'jour' => $this->day(10),
            'creneau' => '09:00 - 12:00', 'service' => $this->offer, 'invites' => 2, 'notes' => 'Canne, prévoir du temps.', 'accord' => true,
        ];
    }

    /** Date dans $days jours, dans le fuseau du site (America/Toronto par défaut) */
    private function day(int $days): string
    {
        return (new \DateTimeImmutable('today', new \DateTimeZone('America/Toronto')))->modify("+$days day")->format('Y-m-d');
    }

    private function submit(string $uid, array $answers, string $locale = 'fr', array $override = []): Response
    {
        $body = $override + ['app' => 'landingpage', 'sectionUid' => $uid, 'formId' => 'f', 'locale' => $locale, 'answers' => (object) $answers, 'site' => '', 'fillSeconds' => 94];
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/form-submissions', [], [], [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode($body));

        return $this->client->getResponse();
    }

    /** Section d'onglet avec un formulaire à étapes (2 étapes + success) */
    private function section(string $uid, string $target): array
    {
        $field = fn (string $id, string $parent, array $props) => ['id' => $id, 'type' => 'field', 'parentId' => $parent] + $props;

        return ['id' => 1, 'uid' => $uid, 'componentKey' => 'Contact', 'componentTypeKey' => 'typeReglable', 'reglableConfig' => ['schemaVersion' => 2, 'blocks' => [
            ['id' => 'f', 'type' => 'container', 'layout' => 'steps', 'formName' => 'Rendez-vous', 'formTarget' => $target],
            ['id' => 'e1', 'type' => 'container', 'parentId' => 'f', 'tabLabel' => 'Vous'],
            ['id' => 'e2', 'type' => 'container', 'parentId' => 'f', 'tabLabel' => 'Moment'],
            ['id' => 'ok', 'type' => 'container', 'parentId' => 'f', 'stepRole' => 'success'],
            $field('q1', 'e1', ['name' => 'nom', 'label' => 'Votre nom', 'required' => true, 'fieldRole' => 'name', 'translations' => ['en' => ['label' => 'Your name']]]),
            $field('q2', 'e1', ['name' => 'courriel', 'fieldType' => 'email', 'label' => 'Courriel', 'fieldRole' => 'email', 'required' => $target !== 'generic']),
            $field('q3', 'e1', ['name' => 'telephone', 'fieldType' => 'tel', 'label' => 'Téléphone', 'fieldRole' => 'phone']),
            $field('q4', 'e2', ['name' => 'jour', 'fieldType' => 'date', 'label' => 'Jour', 'fieldRole' => 'date', 'minDays' => 1, 'maxDays' => 365]),
            $field('q5', 'e2', ['name' => 'creneau', 'fieldType' => 'choice', 'label' => 'Créneau', 'fieldRole' => 'slot',
                'options' => [['value' => '09:00 - 12:00', 'label' => 'Matin'], ['value' => '14:00 - 18:00', 'label' => 'Après-midi'], ['value' => '09:00 – 12:00', 'label' => 'Matin (tiret long)']], 'translations' => ['en' => ['options' => ['Morning', '']]]]),
            $field('q6', 'e2', ['name' => 'service', 'fieldType' => 'select', 'label' => 'Prestation', 'fieldRole' => 'service', 'optionsSource' => 'services']),
            $field('q7', 'e2', ['name' => 'invites', 'fieldType' => 'number', 'label' => 'Personnes', 'fieldRole' => 'guests', 'minValue' => 1, 'maxValue' => 6]),
            $field('q8', 'e2', ['name' => 'notes', 'fieldType' => 'textarea', 'label' => 'Remarques', 'fieldRole' => 'notes']),
            $field('q9', 'e2', ['name' => 'accord', 'fieldType' => 'checkbox', 'label' => 'J\'accepte d\'être rappelé', 'required' => true]),
            $field('q10', 'ok', ['name' => 'merci', 'label' => 'Question de l\'étape success (ignorée)']),
            ['id' => 'b1', 'type' => 'button', 'parentId' => 'e2', 'action' => 'submit', 'text' => 'Envoyer'],
        ]]];
    }

    private function saveLanding(array $sections): void
    {
        $configuration = json_encode(['tabs' => [['id' => 'accueil', 'title' => ['fr' => 'Accueil'], 'sections' => $sections]]]);
        $db = $this->db();
        $db->fetchOne('SELECT id FROM landing_page_setting LIMIT 1')
            ? $db->executeStatement('UPDATE landing_page_setting SET configuration = ? WHERE id = (SELECT MIN(id) FROM landing_page_setting)', [$configuration])
            : $db->executeStatement("INSERT INTO landing_page_setting (id, configuration) VALUES (nextval('landing_page_setting_id_seq'), ?)", [$configuration]);
    }

    /** E-mails envoyés pendant la dernière requête (pas les mises en file) */
    private function emails(): array
    {
        $sent = array_filter(self::getMailerEvents(), fn ($event) => !$event->isQueued() && $event->getMessage() instanceof Email);

        return array_values(array_map(fn ($event) => $event->getMessage(), $sent));
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager()->getConnection();
    }
}
