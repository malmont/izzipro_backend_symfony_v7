<?php

namespace App\Tests\Functional\Contact;

use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Formulaire de contact public (POST /api/contacts/create) : name, email et message obligatoires, le reste facultatif ;
 * erreurs par champ en français ; seuls les envois acceptés comptent dans la limite (07/10/2026 : tout était refusé,
 * phone et subject étaient exigés et le message attendu sous le nom « Content »).
 */
class ContactFormTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $key = MV_TEST_TENANT_HOST . '|127.0.0.1';
        static::getContainer()->get('limiter.public_form_ip')->create($key)->reset();
        static::getContainer()->get('limiter.public_form_attempts_ip')->create($key)->reset();
        $this->db()->executeStatement('DELETE FROM contact');
    }

    public function testFormWithSectorCompanyAndFunctionIsSaved(): void
    {
        $response = $this->post(['name' => 'Marie Durand', 'email' => 'marie@example.com', 'message' => "Bonjour,\nun devis ?", 'phone' => '+1 (514) 555-0101',
            'industry' => 'BTP', 'companyName' => 'Durand Construction', 'jobFunction' => 'Directrice', 'acceptPolicy' => true]);

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $row = $this->db()->fetchAssociative('SELECT * FROM contact');
        $this->assertSame(['Marie Durand', 'marie@example.com', "Bonjour,\nun devis ?", '+1 (514) 555-0101', 'btp', 'Durand Construction', 'Directrice', false],
            [$row['name'], $row['email'], $row['content'], $row['phone'], $row['industry'], $row['company_name'], $row['job_function'], $row['is_read']]);
        $this->assertStringStartsWith('Demande de contact – ', $row['subject'], 'sujet généré');
        $body = json_decode($response->getContent(), true);
        $this->assertSame('Durand Construction', $body['companyName']);
    }

    public function testMinimalFormNeedsOnlyNameEmailAndMessage(): void
    {
        $response = $this->post(['name' => 'Jo', 'email' => 'jo@example.com', 'message' => 'Rappelez-moi.', 'phone' => '', 'subject' => 'Rendez-vous']);

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $row = $this->db()->fetchAssociative('SELECT phone, subject, industry FROM contact');
        $this->assertSame([null, 'Rendez-vous', null], [$row['phone'], $row['subject'], $row['industry']], 'champ vide = absent ; sujet envoyé conservé');
        $this->assertSame(201, $this->post(['name' => 'Ancien', 'email' => 'a@example.com', 'Content' => 'ancien nom du champ'])->getStatusCode());
    }

    public function testErrorsAreGivenFieldByFieldInFrench(): void
    {
        $response = $this->post(['name' => 'A', 'email' => 'pas-une-adresse', 'message' => '  ', 'phone' => '12', 'industry' => 'mines', 'isRead' => true]);

        $this->assertSame(422, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertSame('Formulaire incomplet', $body['message']);
        $this->assertSame([
            'name' => 'Le nom doit compter au moins 2 caractères.',
            'email' => 'L\'adresse e-mail n\'est pas valide.',
            'message' => 'Le message est obligatoire.',
            'phone' => 'Le téléphone doit compter au moins 5 caractères.',
            'industry' => 'Secteur inconnu : tourisme, btp, agroalimentaire ou autre.',
        ], array_column($body['errors'], 'message', 'field'));
        $this->assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM contact'));
    }

    public function testRefusedAttemptsDoNotCountInTheSubmissionLimit(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->assertSame(422, $this->post(['name' => 'Jo'])->getStatusCode(), 'une faute de saisie ne bloque pas');
        }
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(201, $this->post(['name' => 'Jo', 'email' => 'jo@example.com', 'message' => "Message $i"])->getStatusCode());
        }

        $response = $this->post(['name' => 'Jo', 'email' => 'jo@example.com', 'message' => 'Un de trop']);
        $this->assertSame(429, $response->getStatusCode());
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertSame(10, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM contact'));
    }

    private function post(array $body): Response
    {
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/contacts/create', [], [], [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode($body));

        return $this->client->getResponse();
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager()->getConnection();
    }
}
