<?php

namespace App\Tests\Functional\Boussole;

use App\ESG\DTO\Output\ReportStatusOutputDTO;
use App\ESG\Entity\DiagnosticReport;
use App\ESG\Entity\DiagnosticSession;
use App\ESG\Entity\EsgCompany;
use App\ESG\Entity\EsgDocument;
use App\ESG\Entity\EsgUser;
use App\ESG\Enum\DomainEnum;
use App\ESG\Enum\ReportStatusEnum;
use App\ESG\Enum\SectorEnum;
use App\ESG\Enum\SessionStatusEnum;
use App\ESG\Enum\SizeEnum;
use App\ESG\Enum\TerritoryEnum;
use App\ESG\UseCase\Auth\LoginUseCase;
use App\ESG\UseCase\Auth\RegisterUseCase;
use App\ESG\UseCase\Company\GetCompanyProfileUseCase;
use App\ESG\UseCase\Company\UpdateCompanyProfileUseCase;
use App\ESG\UseCase\Diagnostic\CreateSessionUseCase;
use App\ESG\UseCase\Diagnostic\GetHistoriqueUseCase;
use App\ESG\UseCase\Diagnostic\ListSessionsUseCase;
use App\ESG\UseCase\Diagnostic\SaveAnswersUseCase;
use App\ESG\UseCase\Document\DeleteDocumentUseCase;
use App\ESG\UseCase\Document\DownloadDocumentUseCase;
use App\ESG\UseCase\Document\ListDocumentsUseCase;
use App\ESG\UseCase\Document\UploadDocumentUseCase;
use App\ESG\UseCase\Report\GetReportStatusUseCase;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Services\Http\ClientSafeError;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Lot ERREURS-3, Boussole ESG (14/11/2026, `docs/erreurs-client.md`). Les 14 routes de la Boussole qui attrapaient largement rendaient
 * le texte de toute exception, une erreur SQL comprise. Pour chacune, le cas d'usage est remplacé par un double qui lève une panne au
 * texte témoin : la réponse garde son statut et sa clé `error`, porte le texte neutre et `code: INTERNAL_ERROR`, jamais le témoin ; le
 * journal, lui, le reçoit. Les refus voulus des mêmes routes sont rejoués : ils ne changent pas d'un caractère (texte, statut, aucune
 * clé ajoutée). Le message d'échec d'un rapport reste en base et ne sort plus dans la réponse.
 *
 * Le front de la Boussole n'a rien à changer : c'est ce que ce test garantit.
 */
class BoussoleClientErrorsTest extends WebTestCase
{
    private const WITNESS = 'TEMOIN-SQLSTATE[08006] connexion refusée pour app_user@10.0.0.7';
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private const NO_COMPANY = 'Aucune entreprise associée à cet utilisateur.';
    private KernelBrowser $client;
    private string $tag;
    /** @var list<string> messages reçus par le journal */
    private array $logged = [];
    /** @var list<int> */
    private array $companyIds = [];
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->tag = 'err' . bin2hex(random_bytes(3));
        $this->fresh();
    }

    protected function tearDown(): void
    {
        $conn = $this->em()->getConnection();
        foreach ($this->companyIds as $id) {
            $conn->executeStatement('DELETE FROM esg_user WHERE company_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM esg_company WHERE id = ?', [$id]); // diagnostics, rapports et documents suivent (ON DELETE CASCADE)
        }
        $conn->executeStatement('DELETE FROM esg_user WHERE email LIKE ?', ['%' . $this->tag . '%']);
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testAnUnexpectedFailureNeverShowsItsTextOnTheFourteenRoutes(): void
    {
        $company = $this->company('Pannes');
        $owner = $this->esgUser($company, 'pannes');
        $session = $this->session($company, SessionStatusEnum::IN_PROGRESS);
        $uuid = $session->getUuid()->toRfc4122();
        $document = $this->document($company);
        $profile = ['name' => 'Entreprise', 'sector' => SectorEnum::cases()[0]->value, 'sizeCategory' => SizeEnum::cases()[0]->value, 'territory' => TerritoryEnum::QUEBEC->value, 'existingCertifications' => [], 'contactEmail' => 'contact@example.invalid'];
        $registration = ['email' => "inscription-{$this->tag}@example.invalid", 'password' => self::PASSWORD, 'firstName' => 'Alice', 'lastName' => 'Alpha', 'companyName' => 'Entreprise ' . $this->tag,
            'sector' => $profile['sector'], 'sizeCategory' => $profile['sizeCategory'], 'territory' => $profile['territory'], 'contactEmail' => 'contact@example.invalid'];

        // [cas d'usage remplacé, méthode, chemin, corps, statut gardé, route nommée au journal, compte ou null]
        $routes = [
            [RegisterUseCase::class, 'POST', '/api/boussole/auth/register', $registration, 409, 'POST /api/boussole/auth/register', null],
            [LoginUseCase::class, 'POST', '/api/boussole/auth/login', ['email' => $owner->getEmail(), 'password' => self::PASSWORD], 401, 'POST /api/boussole/auth/login', null],
            [GetReportStatusUseCase::class, 'GET', "/api/boussole/sessions/$uuid/report", null, 404, 'GET /api/boussole/sessions/{uuid}/report', $owner],
            [ListDocumentsUseCase::class, 'GET', '/api/boussole/documents', null, 500, 'GET /api/boussole/documents', $owner],
            [DeleteDocumentUseCase::class, 'DELETE', '/api/boussole/documents/' . $document->getId(), null, 500, 'DELETE /api/boussole/documents/{id}', $owner],
            [DownloadDocumentUseCase::class, 'GET', '/api/boussole/documents/download/' . $document->getId(), null, 500, 'GET /api/boussole/documents/download/{id}', $owner],
            [DownloadDocumentUseCase::class, 'GET', '/api/boussole/documents/download?path=' . rawurlencode((string) $document->getFilePath()), null, 500, 'GET /api/boussole/documents/download', $owner],
            [GetCompanyProfileUseCase::class, 'GET', '/api/boussole/company', null, 404, 'GET /api/boussole/company', $owner],
            [UpdateCompanyProfileUseCase::class, 'PUT', '/api/boussole/company', $profile, 400, 'PUT /api/boussole/company', $owner],
            [CreateSessionUseCase::class, 'POST', '/api/boussole/sessions', [], 409, 'POST /api/boussole/sessions', $owner],
            [ListSessionsUseCase::class, 'GET', '/api/boussole/sessions', null, 404, 'GET /api/boussole/sessions', $owner],
            [GetHistoriqueUseCase::class, 'GET', '/api/boussole/sessions/historique', null, 404, 'GET /api/boussole/sessions/historique', $owner],
            [SaveAnswersUseCase::class, 'PUT', "/api/boussole/sessions/$uuid/answers", ['answers' => [['questionId' => 1, 'answerValue' => 'oui']]], 403, 'PUT /api/boussole/sessions/{uuid}/answers', $owner],
        ];
        foreach ($routes as [$useCase, $method, $path, $body, $status, $route, $as]) {
            $this->fresh();
            $this->replace($useCase);
            $this->assertNeutral($this->call($method, $path, $body, $as), $status, $route);
        }

        // Envoi d'un document : la panne (500), et la même classe que nos refus levée par une bibliothèque (400) : neutres toutes les deux
        $this->fresh();
        $this->replace(UploadDocumentUseCase::class);
        $this->assertNeutral($this->upload($owner, $this->file('%PDF-1.4 essai', 'politique.pdf')), 500, 'POST /api/boussole/documents');
        $this->fresh();
        $this->replace(UploadDocumentUseCase::class, new \InvalidArgumentException(self::WITNESS));
        $this->assertNeutral($this->upload($owner, $this->file('%PDF-1.4 essai', 'politique.pdf')), 400, 'POST /api/boussole/documents');

        // Rien n'a été écrit par ces appels en panne
        $this->assertSame([0, 1], [(int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM esg_user WHERE email = ?', [$registration['email']]),
            (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM esg_document WHERE company_id = ?', [$company->getId()])]);
    }

    public function testTheIntendedRefusalsOfTheSameRoutesDoNotChangeByOneCharacter(): void
    {
        $company = $this->company('Refus');
        $owner = $this->esgUser($company, 'refus', true);
        $orphan = $this->esgUser(null, 'orpheline', true); // compte sans entreprise
        $other = $this->company('Autre');
        $foreign = $this->session($other, SessionStatusEnum::IN_PROGRESS)->getUuid()->toRfc4122();
        $open = $this->session($company, SessionStatusEnum::IN_PROGRESS)->getUuid()->toRfc4122();
        $closed = $this->session($company, SessionStatusEnum::COMPLETED)->getUuid()->toRfc4122();
        $unknown = Uuid::v4()->toRfc4122();
        $profile = ['name' => 'Entreprise', 'sector' => SectorEnum::cases()[0]->value, 'sizeCategory' => SizeEnum::cases()[0]->value, 'territory' => TerritoryEnum::QUEBEC->value, 'existingCertifications' => [], 'contactEmail' => 'contact@example.invalid'];
        // une réponse de refus : le statut, et un corps qui ne porte QUE la clé `error` (aucun `code` ajouté)
        $refusal = fn (int $status, string $text): array => [$status, ['error' => $text]];
        $answers = fn (string $uuid, int $question = 999999999): array => $this->call('PUT', "/api/boussole/sessions/$uuid/answers", ['answers' => [['questionId' => $question, 'answerValue' => 'oui']]], $owner);

        // Connexion : compte inconnu et mauvais mot de passe rendent la même réponse ; un compte désactivé ne le dit qu'à qui a le bon mot de passe
        $login = fn (string $email, string $password): array => $this->call('POST', '/api/boussole/auth/login', ['email' => $email, 'password' => $password], null);
        $this->assertSame($refusal(401, 'Identifiants incorrects.'), $login("personne-{$this->tag}@example.invalid", self::PASSWORD));
        $this->assertSame($refusal(401, 'Identifiants incorrects.'), $login($owner->getEmail(), 'mauvais-mot-de-passe'));
        $disabled = $this->esgUser($company, 'desactive', true, false);
        $this->assertSame($refusal(401, 'Identifiants incorrects.'), $login($disabled->getEmail(), 'mauvais-mot-de-passe'), 'sans le mot de passe, rien ne dit que le compte existe ni qu\'il est désactivé');
        $this->assertSame($refusal(401, 'Votre compte utilisateur est désactivé.'), $login($disabled->getEmail(), self::PASSWORD));
        $inactive = $this->company('Inactive', false);
        $employee = $this->esgUser($inactive, 'inactive', true);
        $this->assertSame($refusal(401, 'Identifiants incorrects.'), $login($employee->getEmail(), 'mauvais-mot-de-passe'));
        $this->assertSame($refusal(401, 'Votre entreprise est désactivée.'), $login($employee->getEmail(), self::PASSWORD));
        // Connexion réussie : le jeton n'est rendu que par le cookie protégé, jamais dans le corps ; le front reconnaît la réussite à userId
        [$status, $session] = $login($owner->getEmail(), self::PASSWORD);
        $this->assertSame([200, ['userId' => (int) $owner->getId(), 'email' => $owner->getEmail(), 'firstName' => 'Alice', 'lastName' => 'Alpha']], [$status, $session]);
        $set = [];
        foreach ($this->client->getResponse()->headers->getCookies() as $c) {
            $set[$c->getName()] = $c;
        }
        $this->assertSame(['auth_token_' . MV_TEST_TENANT_CODE, 'XSRF-TOKEN_' . MV_TEST_TENANT_CODE], array_keys($set));
        $cookie = $set['auth_token_' . MV_TEST_TENANT_CODE];
        $this->assertSame([true, true], [$cookie->isHttpOnly(), $cookie->isSecure()], 'cookie du jeton protégé');
        $this->assertStringNotContainsString((string) $cookie->getValue(), (string) $this->client->getResponse()->getContent());
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie($cookie->getName(), (string) $cookie->getValue(), null, '/', MV_TEST_TENANT_HOST, true));
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/boussole/company', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json']);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), 'le cookie seul ouvre la session');

        // Entreprise
        $this->assertSame($refusal(404, self::NO_COMPANY), $this->call('GET', '/api/boussole/company', null, $orphan));
        $this->assertSame($refusal(400, self::NO_COMPANY), $this->call('PUT', '/api/boussole/company', $profile, $orphan));

        // Diagnostics
        $this->assertSame($refusal(409, self::NO_COMPANY), $this->call('POST', '/api/boussole/sessions', [], $orphan));
        $this->assertSame($refusal(409, 'Une session de diagnostic est déjà en cours pour cette entreprise.'), $this->call('POST', '/api/boussole/sessions', [], $owner));
        $this->assertSame($refusal(404, self::NO_COMPANY), $this->call('GET', '/api/boussole/sessions', null, $orphan));
        $this->assertSame($refusal(404, self::NO_COMPANY), $this->call('GET', '/api/boussole/sessions/historique', null, $orphan));
        $this->assertSame($refusal(403, 'Session de diagnostic introuvable.'), $answers($unknown));
        $this->assertSame($refusal(403, 'Vous n\'avez pas l\'autorisation de modifier cette session.'), $answers($foreign));
        $this->assertSame($refusal(403, 'Cette session est complétée ou archivée et ne peut pas être modifiée.'), $answers($closed));
        $this->assertSame($refusal(403, 'Question active introuvable pour l\'ID : 999999999'), $answers($open));

        // Rapport
        $this->assertSame($refusal(404, 'Session de diagnostic introuvable.'), $this->call('GET', "/api/boussole/sessions/$unknown/report", null, $owner));
        $this->assertSame($refusal(404, 'Vous n\'avez pas accès à ce rapport.'), $this->call('GET', "/api/boussole/sessions/$foreign/report", null, $owner));
        $this->assertSame($refusal(404, 'Aucun rapport associé à cette session.'), $this->call('GET', "/api/boussole/sessions/$open/report", null, $owner));

        // Documents : nos deux refus de l'envoi gardent leur texte (400), « aucune entreprise » aussi (500 à l'envoi et à la liste)
        $this->assertSame($refusal(400, 'Type de fichier non autorisé.'), $this->upload($owner, $this->file('du texte simple', 'notes.txt')));
        $this->assertSame($refusal(400, 'Fichier trop volumineux (max 10 Mo).'), $this->upload($owner, $this->file('%PDF-1.4 essai', 'gros.pdf', 11 * 1024 * 1024)));
        $this->assertSame($refusal(500, self::NO_COMPANY), $this->upload($orphan, $this->file('%PDF-1.4 essai', 'politique.pdf')));
        $this->assertSame($refusal(500, self::NO_COMPANY), $this->call('GET', '/api/boussole/documents', null, $orphan));

        $this->assertSame([], $this->logged, 'un refus voulu ne va pas au journal des pannes');
    }

    public function testTheFailureTextOfAReportStaysInTheDatabaseAndNeverGoesOut(): void
    {
        $company = $this->company('Rapport');
        $owner = $this->esgUser($company, 'rapport');
        $raw = self::WITNESS . ' dans /var/www/src/ESG/MessageHandler/GenerateEsgReportHandler.php';
        $report = function (ReportStatusEnum $status, ?string $error) use ($company): string {
            $session = $this->session($company, SessionStatusEnum::COMPLETED);
            $em = $this->em();
            $em->persist((new DiagnosticReport())->setStatus($status)->setErrorMessage($error)->setSession($em->find(DiagnosticSession::class, $session->getId())));
            $em->flush();
            $this->endOfWrite();

            return $session->getUuid()->toRfc4122();
        };
        $failed = $report(ReportStatusEnum::FAILED, $raw);
        [$status, $body] = $this->call('GET', "/api/boussole/sessions/$failed/report", null, $owner);
        $this->assertSame([200, ['status' => 'failed', 'downloadUrl' => null, 'generatedAt' => null, 'error' => 'La génération du rapport a échoué. Veuillez réessayer dans quelques instants.']], [$status, $body]);
        $this->assertSame(ReportStatusOutputDTO::FAILURE_MESSAGE, $body['error']);
        $this->assertStringNotContainsString('TEMOIN', json_encode($body));
        $this->assertSame($raw, $this->em()->getConnection()->fetchOne('SELECT r.error_message FROM esg_diag_report r JOIN esg_diag_session s ON s.id = r.session_id WHERE s.uuid = ?', [$failed]), 'la base garde le texte brut, pour le diagnostic');

        // un rapport sans erreur : `error` reste null, comme avant
        $ready = $report(ReportStatusEnum::READY, null);
        [$status, $body] = $this->call('GET', "/api/boussole/sessions/$ready/report", null, $owner);
        $this->assertSame([200, 'ready', null, "/api/boussole/sessions/$ready/report/download"], [$status, $body['status'], $body['error'], $body['downloadUrl']]);
    }

    // ---------------------------------------------------------------- aides

    /** Noyau neuf, journal des pannes capté (un double posé dans le conteneur ne vit que jusqu'à la requête suivante) */
    private function fresh(): void
    {
        static::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->logged = [];
        $logged = &$this->logged;
        $logger = new class($logged) extends AbstractLogger {
            public function __construct(private array &$records)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = (string) $message;
            }
        };
        static::getContainer()->set(ClientSafeError::class, new ClientSafeError($logger, (string) static::getContainer()->getParameter('kernel.project_dir')));
    }

    /** Remplace un cas d'usage par un double dont `execute` lève une panne (née ici, donc hors de `src/`) */
    private function replace(string $class, ?\Throwable $failure = null): void
    {
        $double = $this->createMock($class);
        $double->method('execute')->willThrowException($failure ?? new \RuntimeException(self::WITNESS));
        static::getContainer()->set($class, $double);
    }

    /** @param array{0: int, 1: mixed} $response */
    private function assertNeutral(array $response, int $status, string $route): void
    {
        [$actual, $body] = $response;
        $this->assertSame([$status, ['error' => ClientSafeError::NEUTRAL, 'code' => 'INTERNAL_ERROR']], [$actual, $body], "$route : statut gardé, clé error au texte neutre, code ajouté, rien d'autre");
        $this->assertStringNotContainsString('TEMOIN', json_encode($body), "$route : le message de la panne ne sort pas");
        $this->assertStringContainsString(self::WITNESS, implode("\n", $this->logged), "$route : le détail est au journal");
        $this->assertStringContainsString("[erreur imprévue] $route :", implode("\n", $this->logged), "$route : la route est nommée au journal");
    }

    /** @return array{0: int, 1: mixed} */
    private function call(string $method, string $path, ?array $body, ?EsgUser $as): array
    {
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        if ($as !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token($as);
        }
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], $server, $body !== null ? json_encode($body) : null);

        return [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true)];
    }

    /** @return array{0: int, 1: mixed} envoi d'un document (formulaire avec fichier) */
    private function upload(EsgUser $as, UploadedFile $file): array
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/boussole/documents', ['code' => 'ENV-001', 'domain' => DomainEnum::ENVIRONMENT->value], ['file' => $file], [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token($as),
        ]);

        return [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true)];
    }

    /** Jeton du compte ; le site est d'abord choisi dans le noyau courant (un noyau neuf ne connaît encore aucun site : le jeton serait refusé) */
    private function token(EsgUser $as): string
    {
        $this->em();

        return static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($as);
    }

    /** Fichier d'essai ; `$size` : taille voulue, sans écrire les octets (fichier creux) */
    private function file(string $content, string $name, ?int $size = null): UploadedFile
    {
        $path = sys_get_temp_dir() . '/' . $this->tag . '-' . bin2hex(random_bytes(3)) . '-' . $name;
        file_put_contents($path, $content);
        if ($size !== null) {
            $handle = fopen($path, 'r+');
            ftruncate($handle, $size);
            fclose($handle);
        }
        $this->files[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    private function company(string $name, bool $active = true): EsgCompany
    {
        $company = (new EsgCompany())->setName($name . ' ' . $this->tag)->setSector(SectorEnum::cases()[0])->setSizeCategory(SizeEnum::cases()[0])->setTerritory(TerritoryEnum::QUEBEC)
            ->setCity('Ville-' . $name . '-' . $this->tag)->setContactEmail(strtolower($name) . '-contact@example.invalid')->setIsActive($active);
        $em = $this->em();
        $em->persist($company);
        $em->flush();
        $this->companyIds[] = (int) $company->getId();
        $this->endOfWrite();

        return $company;
    }

    /** `$withPassword` : mot de passe connu (pour une vraie connexion) */
    private function esgUser(?EsgCompany $company, string $prefix, bool $withPassword = false, bool $active = true): EsgUser
    {
        $em = $this->em(); // em() bascule le site et vide l'EntityManager : l'entreprise est relue
        $user = (new EsgUser())->setEmail($prefix . '-' . $this->tag . '@example.invalid')->setFirstName('Alice')->setLastName('Alpha')->setRoles(['ROLE_COMPANY'])
            ->setCompany($company === null ? null : $em->find(EsgCompany::class, $company->getId()))->setIsActive($active)->setIsVerified(true)->setPassword('inutilisable');
        if ($withPassword) {
            $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        }
        $em->persist($user);
        $em->flush();
        $this->endOfWrite();

        return $user;
    }

    private function session(EsgCompany $company, SessionStatusEnum $status): DiagnosticSession
    {
        $em = $this->em();
        $session = (new DiagnosticSession())->setCompany($em->find(EsgCompany::class, $company->getId()))->setStatus($status)->setReferentialVersion('test');
        if ($status === SessionStatusEnum::COMPLETED) {
            $session->setScoreEnvironment(70.0)->setScoreGovernance(60.0)->setScoreSocial(65.0)->setScoreClimate(55.0)->setScoreGlobal(62.5)->setMaturityLevel('Confirmé')->setCompletedAt(new \DateTimeImmutable());
        }
        $em->persist($session);
        $em->flush();
        $this->endOfWrite();

        return $session;
    }

    private function document(EsgCompany $company): EsgDocument
    {
        $em = $this->em();
        $stored = $company->getId() . '/doc_' . $this->tag . '.pdf';
        $document = (new EsgDocument())->setCode('ENV-001')->setDomain(DomainEnum::ENVIRONMENT)->setOriginalName('politique.pdf')->setStoredName('doc_' . $this->tag . '.pdf')
            ->setFilePath($stored)->setFileSize(20)->setMimeType('application/pdf')->setCompany($em->find(EsgCompany::class, $company->getId()));
        $em->persist($document);
        $em->flush();
        $this->endOfWrite();

        return $document;
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
}
