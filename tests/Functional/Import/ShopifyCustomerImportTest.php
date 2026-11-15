<?php

namespace App\Tests\Functional\Import;

use App\Entity\Order;
use App\Entity\OrderSource;
use App\Entity\OrderType;
use App\Entity\StatusCommande;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Import\CustomerImportService;
use App\Import\ImportGuests;
use App\Import\Message\RunCustomerImportMessage;
use App\Import\Message\SendImportInvitationsMessage;
use App\Security\AppFamilies;
use App\Services\AppMembershipService\AppMembershipDirectory;
use App\Services\AppMembershipService\AppTermsService;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Import des clients d'une boutique existante, lot IMP-2 (15/11/2026, `docs/import-boutique.md` § 9). Le fichier s'analyse sans rien
 * écrire ; chaque client devient un compte « invité par import » qui ne peut se connecter par AUCUN chemin et ne reçoit aucun courriel ;
 * le commerçant envoie les invitations, une par invité, une seule fois ; le client accepte (mot de passe, conditions : appartenance
 * `boutique` de source `import`) ou refuse (tout est effacé) ; le consentement commercial n'est repris que s'il est dit par le fichier,
 * attesté par le commerçant et non décoché par le client ; un invité qui n'accepte pas est effacé à 90 jours, ou par l'annulation.
 */
class ShopifyCustomerImportTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private const PASSWORD = 'Un-mot-de-passe-solide-42';

    private KernelBrowser $client;
    private string $token = '';
    private string $tag;
    private string $ip;
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->tag = bin2hex(random_bytes(3));
        $this->ip = '10.84.' . random_int(1, 254) . '.' . random_int(1, 254); // les limites de débit (Redis) survivent d'un lancement à l'autre
        $this->wipe();
        $this->token = $this->tokenFor(['ROLE_ADMIN']);
    }

    protected function tearDown(): void
    {
        $this->wipe();
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testCustomersBecomeGuestsWhoCannotSignInThenAreInvitedOnceAndAcceptOrDecline(): void
    {
        $conn = fn () => $this->em()->getConnection();
        $t = $this->tag;
        $existing = $this->account("fanny-$t@example.invalid", 'Fanny', ['ROLE_USER_INTERNET']);
        $users = fn (): int => (int) $conn()->fetchOne('SELECT COUNT(*) FROM "user"');

        // Droits : ni anonyme, ni compte client
        $admin = $this->token;
        $this->token = '';
        $this->upload($this->csv(), 'customers');
        $this->assertSame(401, $this->status());
        $this->token = $this->tokenFor(['ROLE_USER_INTERNET']);
        $this->request('GET', '/api/site/imports');
        $this->assertSame(403, $this->status());
        $this->token = $admin;
        $before = $users();

        // Dépôts refusés : nature inconnue ; un fichier de produits n'est pas un fichier de clients, et l'inverse
        $this->upload($this->csv(), 'fournisseurs');
        $this->assertSame([422, 'IMPORT_KIND_UNKNOWN'], [$this->status(), $this->body()['error']]);
        $this->upload((string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/import/shopify-products.csv'), 'customers');
        $this->assertSame([422, 'IMPORT_NOT_A_CUSTOMER_FILE'], [$this->status(), $this->body()['error']]);
        $this->upload($this->csv(), 'catalog');
        $this->assertSame([422, 'IMPORT_NOT_A_PRODUCT_FILE'], [$this->status(), $this->body()['error']]);
        $this->assertSame(0, (int) $conn()->fetchOne('SELECT COUNT(*) FROM import_job'));

        // Analyse SANS RIEN ÉCRIRE : 8 lignes, 3 à reprendre ; le rapport ne porte que des numéros de ligne et des raisons
        $this->upload($this->csv(), 'customers');
        $this->assertSame(201, $this->status(), (string) $this->client->getResponse()->getContent());
        $job = $this->body();
        $id = (int) $job['id'];
        $this->assertSame(['customers', 'analysed', 8, true], [$job['kind'], $job['status'], $job['rows'], $job['cancellable']]);
        $this->assertEquals(['total' => 8, 'importable' => 3, 'alreadyCustomer' => 2, 'alreadyImported' => 0, 'duplicates' => 1, 'refused' => 2, 'withAddress' => 2, 'marketingClaimed' => 2], $job['report']['customers']); // objet JSON : l'ordre des clés n'a pas de sens
        $this->assertSame([[4, 'ADDRESS_INCOMPLETE'], [5, 'NO_EMAIL'], [6, 'EMAIL_INVALID'], [7, 'DUPLICATE_IN_FILE'], [8, 'ALREADY_CUSTOMER'], [9, 'ALREADY_CUSTOMER']],
            array_map(fn (array $i) => [$i['line'], $i['code']], array_slice($job['report']['issues'], 0, 6)));
        $this->assertEquals([90, 30, ['imported' => 0, 'invited' => 0, 'accepted' => 0, 'kept' => 0, 'invitable' => 0]], [$job['report']['eraseDays'], $job['report']['linkDays'], $job['guests']]);
        $this->assertStringNotContainsString('@example', (string) $this->client->getResponse()->getContent(), 'aucune adresse dans le rapport');
        $this->assertStringNotContainsString('Martin', (string) $this->client->getResponse()->getContent(), 'aucun nom dans le rapport');
        $this->assertSame([$before, 0], [$users(), (int) $conn()->fetchOne('SELECT COUNT(*) FROM import_customer_invitation')], 'l\'analyse n\'écrit aucun compte');
        $this->request('GET', "/api/site/imports/$id/issues.csv");
        $this->assertSame(200, $this->status());
        $this->assertStringContainsString("5;NO_EMAIL\n", (string) $this->client->getResponse()->getContent());

        // Confirmation : l'attestation du consentement commercial est un booléen ; elle est gardée sur l'import et écrite au journal
        $this->request('POST', "/api/site/imports/$id/confirm", ['attestMarketingConsent' => 'oui']);
        $this->assertSame([422, ['attestMarketingConsent' => 'MUST_BE_BOOLEAN']], [$this->status(), $this->body()['fields']]);
        $this->request('POST', "/api/site/imports/$id/invitations");
        $this->assertSame([409, 'IMPORT_NOT_READY'], [$this->status(), $this->body()['error']], 'aucune invitation avant l\'import');
        $this->request('POST', "/api/site/imports/$id/confirm", ['attestMarketingConsent' => true]);
        $this->assertSame(202, $this->status(), (string) $this->client->getResponse()->getContent());
        $this->assertSame(['running', true], [$this->body()['status'], $this->body()['options']['attestMarketingConsent']]);
        $this->assertNotNull($this->body()['options']['attestedAt']);
        $this->assertCount(1, array_filter(static::getContainer()->get('messenger.transport.media')->getSent(), fn ($e) => $e->getMessage() instanceof RunCustomerImportMessage && $e->getMessage()->jobId === $id));
        $this->assertSame(1, (int) $conn()->fetchOne("SELECT COUNT(*) FROM content_audit_log WHERE resource = 'import_job' AND action = 'attest' AND resource_id = ?", [(string) $id]));
        $stored = (string) $conn()->fetchOne('SELECT stored_name FROM import_job WHERE id = ?', [$id]);
        $path = static::getContainer()->getParameter('kernel.project_dir') . '/var/storage/private_media/imports/' . MV_TEST_TENANT_CODE . '/' . $stored;
        $this->assertFileExists($path);

        // Exécution : 3 comptes invités ; le fichier est supprimé du serveur
        $this->runImport($id);
        $done = $this->get("/api/site/imports/$id");
        $this->assertEquals(['done', ['created' => 3, 'withAddress' => 2, 'marketingClaimed' => 2, 'skipped' => 2, 'failed' => 0, 'failures' => []]], [$done['status'], $done['result']]);
        $this->assertEquals(['imported' => 3, 'invited' => 0, 'accepted' => 0, 'kept' => 0, 'invitable' => 3], $done['guests']);
        $this->assertFileDoesNotExist($path, 'un fichier de clients ne reste pas sur le serveur');
        $this->assertSame([$before + 3, 0], [$users(), count($this->getMailerMessages())], 'trois comptes, aucun courriel');
        $alice = $conn()->fetchAssociative('SELECT * FROM "user" WHERE email = ?', ["alice-$t@example.com"]);
        $this->assertNotFalse($alice, 'l\'adresse est gardée en minuscules');
        $this->assertSame(['Alice', 'Martin', '[]', false, false, 'import_invite', null, null], [$alice['firstname'], $alice['lastname'], $alice['roles'], (bool) $alice['password_set'], (bool) $alice['is_verified'],
            $alice['signup_channel'], $alice['reset_token'], $alice['verification_token']]);
        $this->assertStringStartsWith('!', $alice['password'], 'aucun mot de passe ne correspond à ce hachage');
        $this->assertSame(['Montréal', 'H2X 1Y4', 'CA', '12 rue des Érables'], array_values($conn()->fetchAssociative('SELECT city, codepostal, country, address FROM adress WHERE id = ?', [$alice['primary_address_id']])));
        $this->assertSame(0, (int) $conn()->fetchOne('SELECT COUNT(*) FROM user_app_membership WHERE user_id = ?', [$alice['id']]), 'aucune appartenance avant l\'acceptation');
        $this->assertSame(0, (int) $conn()->fetchOne('SELECT COUNT(*) FROM newsletter_subscriber WHERE LOWER(email) LIKE ?', ["%-$t@example.%"]), 'aucune inscription à l\'infolettre avant l\'acceptation');
        $this->assertSame('Fanny', $conn()->fetchOne('SELECT firstname FROM "user" WHERE id = ?', [$existing]), 'un compte existant n\'est pas touché');
        $chloe = (int) $conn()->fetchOne('SELECT id FROM "user" WHERE email = ?', ["chloe-$t@example.invalid"]);
        $this->assertSame([true, false, true], array_map(fn ($v) => in_array($v, [true, 't', 1, '1'], true), $conn()->fetchFirstColumn('SELECT marketing_claimed FROM import_customer_invitation ORDER BY id')), 'dit par le fichier ET attesté');

        // L'administration : la liste des clients repris, la liste des imports, la marque « invité » de l'écran des utilisateurs
        $list = $this->get("/api/site/imports/$id/customers");
        $this->assertSame([3, 1, ['imported']], [$list['total'], $list['pages'], array_values(array_unique(array_column($list['customers'], 'state')))]);
        $row = array_column($list['customers'], null, 'email')["alice-$t@example.com"];
        $this->assertSame([(int) $alice['id'], 'Alice', 'Martin', true, null, null, null], [$row['userId'], $row['firstname'], $row['lastname'], $row['marketingClaimed'], $row['invitedAt'], $row['linkExpiresAt'], $row['acceptedAt']]);
        $this->assertEqualsWithDelta(time() + 90 * 86400, strtotime((string) $row['eraseAt']), 300, 'effacement 90 jours après l\'import');
        $this->assertSame(0, $this->get("/api/site/imports/$id/customers?state=invited")['total']);
        $this->request('GET', "/api/site/imports/$id/customers?state=tous");
        $this->assertSame([422, ['state' => 'INVALID_VALUE']], [$this->status(), $this->body()['fields']]);
        $first = $this->get('/api/site/imports')['imports'][0];
        $this->assertSame([$id, 'customers', 3, 3], [$first['id'], $first['kind'], $first['summary']['customers']['importable'], $first['guests']['imported']]);
        $directory = static::getContainer()->get(AppMembershipDirectory::class);
        $directory->reset();
        $this->assertSame([[AppMembershipDirectory::INVITED], true, false], [$directory->applications($this->em()->find(User::class, (int) $alice['id'])),
            in_array((int) $alice['id'], $directory->userIds(AppMembershipDirectory::INVITED), true), in_array((int) $alice['id'], $directory->userIds(AppMembershipDirectory::NONE), true)]);

        // Un invité ne se connecte par aucun chemin et ne reçoit aucun courriel : connexion, mot de passe oublié, renvoi du lien de vérification
        $this->publicRequest('/api/login', ['username' => "alice-$t@example.com", 'password' => self::PASSWORD, 'platform' => 'web']);
        $this->assertSame(401, $this->status());
        $this->publicRequest('/api/password-reset/request', ['email' => "alice-$t@example.com"]);
        $this->assertSame([200, 0], [$this->status(), count($this->getMailerMessages())]);
        $this->publicRequest('/api/resend-verification', ['email' => "alice-$t@example.com"]);
        $this->assertSame([200, 0], [$this->status(), count($this->getMailerMessages())]);
        $this->assertSame([null, null], array_values($conn()->fetchAssociative('SELECT reset_token, verification_token FROM "user" WHERE id = ?', [$alice['id']])),
            'ni jeton, ni courriel : ces chemins ouvriraient un invité sans acceptation');
        // … alors que le même chemin sert bien un compte ordinaire (le garde ne vise que les invités)
        $this->publicRequest('/api/password-reset/request', ['email' => "fanny-$t@example.invalid"]);
        $this->assertSame(1, count($this->getMailerMessages()));
        $this->assertNotNull($conn()->fetchOne('SELECT reset_token FROM "user" WHERE id = ?', [$existing]));

        // « Envoyer les invitations » : une par invité, en tâche de fond ; un seul courriel chacun, deux liens, aucune promotion
        $this->request('POST', "/api/site/imports/$id/invitations");
        $this->assertSame([202, ['importId' => $id, 'queued' => 3]], [$this->status(), $this->body()]);
        $this->assertCount(1, array_filter(static::getContainer()->get('messenger.transport.media')->getSent(), fn ($e) => $e->getMessage() instanceof SendImportInvitationsMessage && $e->getMessage()->jobId === $id));
        $this->assertSame(['sent' => 3, 'failed' => 0], $this->sendInvitations($id));
        $sent = $this->invitationMails();
        $this->assertCount(3, $sent);
        $tokens = [];
        foreach ($sent as $mail) {
            $html = (string) $mail->getHtmlBody();
            $this->assertSame(1, preg_match('#href="(https://[^"/]+/invitation\?token=([a-f0-9]{64})&amp;locale=fr)"#', $html, $m), $html);
            $tokens[$mail->getTo()[0]->getAddress()] = $m[2];
            $this->assertStringContainsString('href="' . $m[1] . '&amp;decline=1"', $html, 'le lien « ne pas rouvrir mon compte »');
            $this->assertSame(2, substr_count($html, 'href="http'), 'deux liens, pas un de plus');
            foreach (['Rouvrir mon compte', 'Ne pas rouvrir mon compte', 'Vous ne recevrez pas d&#039;autre message à ce sujet.', 'seront effacées le '] as $expected) {
                $this->assertStringContainsString($expected, $html, $expected);
            }
            $this->assertStringEndsWith(' : votre compte client', (string) $mail->getSubject());
        }
        $this->assertSame(["alice-$t@example.com", "bruno-$t@example.invalid", "chloe-$t@example.invalid"], array_keys($tokens));
        $this->assertStringContainsString('Bonjour Alice,', (string) $sent[0]->getHtmlBody());
        $invitation = $conn()->fetchAssociative('SELECT * FROM import_customer_invitation WHERE user_id = ?', [$alice['id']]);
        $this->assertSame(['invited', hash('sha256', $tokens["alice-$t@example.com"])], [$invitation['state'], $invitation['token_hash']], 'seule l\'empreinte du jeton est gardée');
        $this->assertEqualsWithDelta(time() + 30 * 86400, strtotime($invitation['expires_at'] . ' UTC'), 300, 'lien valable 30 jours');
        // aucune relance : une seconde demande n'a plus personne à inviter, un second passage du worker n'envoie rien
        $this->request('POST', "/api/site/imports/$id/invitations");
        $this->assertSame([409, 'IMPORT_NOTHING_TO_INVITE'], [$this->status(), $this->body()['error']]);
        $this->assertSame(['sent' => 0, 'failed' => 0], $this->sendInvitations($id));
        $this->assertCount(0, $this->invitationMails());
        $this->assertEquals(['imported' => 0, 'invited' => 3, 'accepted' => 0, 'kept' => 0, 'invitable' => 0], $this->get("/api/site/imports/$id")['guests']);

        // Le lien, côté client. Un jeton inconnu, mal formé ou absent : le même refus
        $version = static::getContainer()->get(AppTermsService::class)->version(AppFamilies::BOUTIQUE);
        foreach ([[], ['token' => 'x'], ['token' => str_repeat('a', 64)], ['token' => ['x']]] as $body) {
            $this->publicRequest('/api/import-invitation/verify', $body);
            $this->assertSame([404, 'IMPORT_INVITATION_INVALID'], [$this->status(), $this->body()['code']], json_encode($body));
        }
        $aliceToken = $tokens["alice-$t@example.com"];
        $this->publicRequest('/api/import-invitation/verify', ['token' => $aliceToken]);
        $shown = $this->body();
        $this->assertSame([200, 'Alice', "alice-$t@example.com", true, $version], [$this->status(), $shown['firstname'], $shown['email'], $shown['marketing'], $shown['terms']['version']]);
        $this->assertArrayNotHasKey('token', $shown);
        // Acceptation refusée : mot de passe trop court, conditions non acceptées, version des conditions qui n'est pas celle du moment
        $accept = ['token' => $aliceToken, 'password' => self::PASSWORD, 'termsVersion' => $version, 'accepted' => true];
        foreach ([[['password' => 'court'], 422, 'VALIDATION_FAILED'], [['password' => null], 422, 'VALIDATION_FAILED'], [['marketing' => 'oui'], 422, 'VALIDATION_FAILED'], [['accepted' => false], 422, 'TERMS_NOT_ACCEPTED'],
            [['termsVersion' => 'ancienne'], 409, 'TERMS_VERSION_CHANGED']] as [$change, $status, $code]) {
            $this->publicRequest('/api/import-invitation/accept', $change + $accept);
            $this->assertSame([$status, $code], [$this->status(), $this->body()['code']], json_encode($change));
        }
        $this->assertSame($version, $this->body()['terms']['version'], 'le refus de version rend les conditions du moment');
        $this->assertSame(['invited', false], [$conn()->fetchOne('SELECT state FROM import_customer_invitation WHERE user_id = ?', [$alice['id']]), (bool) $conn()->fetchOne('SELECT password_set FROM "user" WHERE id = ?', [$alice['id']])],
            'un refus ne change rien : le lien reste valable');

        // Acceptation : mot de passe, compte de client, appartenance `boutique` de source `import` avec la version et la date ; infolettre reprise
        $this->publicRequest('/api/import-invitation/accept', $accept);
        $this->assertSame([200, ['accepted' => true, 'email' => "alice-$t@example.com", 'marketing' => true]], [$this->status(), $this->body()]);
        $this->em()->clear();
        $account = $this->em()->find(User::class, (int) $alice['id']);
        $this->assertSame([true, true, true, ['ROLE_USER_INTERNET', 'ROLE_USER']], [$account->isPasswordSet(), $account->isVerified(), static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($account, self::PASSWORD), $account->getRoles()]);
        $membership = $conn()->fetchAssociative('SELECT app, source, terms_version, terms_accepted_at FROM user_app_membership WHERE user_id = ?', [$alice['id']]);
        $this->assertSame(['boutique', 'import', $version], [$membership['app'], $membership['source'], $membership['terms_version']]);
        $this->assertEqualsWithDelta(time(), strtotime($membership['terms_accepted_at'] . ' UTC'), 300);
        $this->assertSame(1, (int) $conn()->fetchOne('SELECT COUNT(*) FROM newsletter_subscriber WHERE email = ?', ["alice-$t@example.com"]));
        $this->assertSame(['accepted', null], array_values($conn()->fetchAssociative('SELECT state, token_hash FROM import_customer_invitation WHERE user_id = ?', [$alice['id']])));
        $this->assertNotNull($conn()->fetchOne('SELECT accepted_at FROM import_customer_invitation WHERE user_id = ?', [$alice['id']]));
        $this->assertSame(1, (int) $conn()->fetchOne("SELECT COUNT(*) FROM content_audit_log WHERE resource = 'user_import' AND action = 'accept' AND resource_id = ?", [(string) $alice['id']]));
        $directory->reset();
        $this->assertSame([AppFamilies::BOUTIQUE], $directory->applications($account), 'un client, plus un invité');
        // le lien ne sert qu'une fois
        foreach (['verify' => ['token' => $aliceToken], 'accept' => $accept] as $route => $body) {
            $this->publicRequest("/api/import-invitation/$route", $body);
            $this->assertSame([404, 'IMPORT_INVITATION_INVALID'], [$this->status(), $this->body()['code']], $route);
        }
        // … et le compte passe maintenant par les chemins ordinaires
        $this->publicRequest('/api/login', ['username' => "alice-$t@example.com", 'password' => self::PASSWORD, 'platform' => 'web']);
        $this->assertSame(200, $this->status(), (string) $this->client->getResponse()->getContent());

        // Le fichier ne disait pas que Bruno acceptait les courriels commerciaux : rien n'est repris, même s'il coche
        $this->publicRequest('/api/import-invitation/accept', ['token' => $tokens["bruno-$t@example.invalid"], 'marketing' => true] + $accept);
        $this->assertSame([200, false], [$this->status(), $this->body()['marketing']]);
        $this->assertSame(0, (int) $conn()->fetchOne('SELECT COUNT(*) FROM newsletter_subscriber WHERE email = ?', ["bruno-$t@example.invalid"]));

        // Chloé refuse : tout ce qui a été repris est effacé tout de suite ; son lien ne vaut plus rien
        $this->publicRequest('/api/import-invitation/decline', ['token' => $tokens["chloe-$t@example.invalid"]]);
        $this->assertSame([200, ['declined' => true]], [$this->status(), $this->body()]);
        $this->assertSame([0, 0, 0], [(int) $conn()->fetchOne('SELECT COUNT(*) FROM "user" WHERE id = ?', [$chloe]), (int) $conn()->fetchOne('SELECT COUNT(*) FROM import_customer_invitation WHERE user_id = ?', [$chloe]),
            (int) $conn()->fetchOne("SELECT COUNT(*) FROM import_item WHERE kind = 'customer' AND entity_id = ?", [$chloe])]);
        $this->publicRequest('/api/import-invitation/verify', ['token' => $tokens["chloe-$t@example.invalid"]]);
        $this->assertSame(404, $this->status());
        $final = $this->get("/api/site/imports/$id");
        $this->assertEquals([['imported' => 0, 'invited' => 0, 'accepted' => 2, 'kept' => 0, 'invitable' => 0], false], [$final['guests'], $final['cancellable']], 'plus aucun invité en attente : rien à annuler');
        $this->request('POST', "/api/site/imports/$id/cancel");
        $this->assertSame([409, 'IMPORT_NOT_CANCELLABLE'], [$this->status(), $this->body()['error']]);
    }

    public function testGuestsWhoDoNotAcceptAreErasedAndTheOnesAnOrderHoldsAreKept(): void
    {
        $conn = fn () => $this->em()->getConnection();
        $t = $this->tag;
        $guests = static::getContainer()->get(ImportGuests::class);
        $idOf = fn (string $email): int => (int) $conn()->fetchOne('SELECT id FROM "user" WHERE email = ?', [$email]);
        $state = fn (int $userId): string|false => $conn()->fetchOne('SELECT state FROM import_customer_invitation WHERE user_id = ?', [$userId]);
        $this->account("fanny-$t@example.invalid", 'Fanny', ['ROLE_USER_INTERNET']); // déjà cliente du site, comme dans le fichier

        // Un import abandonné avant d'être confirmé : son fichier est supprimé, rien n'est écrit
        $this->upload($this->csv(), 'customers');
        $abandoned = (int) $this->body()['id'];
        $path = static::getContainer()->getParameter('kernel.project_dir') . '/var/storage/private_media/imports/' . MV_TEST_TENANT_CODE . '/' . $conn()->fetchOne('SELECT stored_name FROM import_job WHERE id = ?', [$abandoned]);
        $this->assertFileExists($path);
        $this->request('POST', "/api/site/imports/$abandoned/cancel");
        $this->assertSame([200, 'cancelled'], [$this->status(), $this->body()['status']]);
        $this->assertFileDoesNotExist($path);

        // Import SANS attestation : le consentement commercial n'est repris pour personne, même pour ceux que le fichier marque
        $this->upload($this->csv(), 'customers');
        $id = (int) $this->body()['id'];
        $this->request('POST', "/api/site/imports/$id/confirm", []);
        $this->assertSame([202, false, null], [$this->status(), $this->body()['options']['attestMarketingConsent'], $this->body()['options']['attestedAt']]);
        $this->runImport($id);
        $this->assertSame([3, 0], [$this->get("/api/site/imports/$id")['result']['created'], $this->get("/api/site/imports/$id")['result']['marketingClaimed']]);
        $this->assertSame(0, (int) $conn()->fetchOne('SELECT COUNT(*) FROM import_customer_invitation WHERE marketing_claimed'));
        [$alice, $bruno, $chloe] = [$idOf("alice-$t@example.com"), $idOf("bruno-$t@example.invalid"), $idOf("chloe-$t@example.invalid")];

        // Rejouer le même fichier ne crée aucun doublon : les trois sont « déjà repris »
        $this->upload($this->csv(), 'customers');
        $replay = $this->body();
        $this->assertSame([0, 3, 2], [$replay['report']['customers']['importable'], $replay['report']['customers']['alreadyImported'], $replay['report']['customers']['alreadyCustomer']]);
        $this->request('POST', '/api/site/imports/' . $replay['id'] . '/confirm', []);
        $this->assertSame([409, 'IMPORT_NOTHING_TO_IMPORT'], [$this->status(), $this->body()['error']]);
        $this->request('POST', '/api/site/imports/' . $replay['id'] . '/cancel');
        $this->assertSame(200, $this->status());

        // Un lien ne vit jamais au-delà de l'effacement : invité à 10 jours de son effacement, le lien expire avec lui ; à moins d'un jour, il n'est plus invité
        $conn()->executeStatement("UPDATE import_customer_invitation SET erase_at = NOW() + INTERVAL '10 days' WHERE user_id = ?", [$chloe]);
        $conn()->executeStatement("UPDATE import_customer_invitation SET erase_at = NOW() + INTERVAL '12 hours' WHERE user_id = ?", [$bruno]);
        $this->request('POST', "/api/site/imports/$id/invitations");
        $this->assertSame([202, 2], [$this->status(), $this->body()['queued']]);
        $this->assertSame(['sent' => 2, 'failed' => 0], $this->sendInvitations($id));
        $this->assertSame(['invited', 'imported', 'invited'], [$state($alice), $state($bruno), $state($chloe)]);
        $this->assertEqualsWithDelta(time() + 10 * 86400, strtotime($conn()->fetchOne('SELECT expires_at FROM import_customer_invitation WHERE user_id = ?', [$chloe]) . ' UTC'), 300);
        $tokens = [];
        foreach ($this->invitationMails() as $mail) {
            preg_match('#/invitation\?token=([a-f0-9]{64})#', (string) $mail->getHtmlBody(), $m);
            $tokens[$mail->getTo()[0]->getAddress()] = $m[1];
        }
        $this->publicRequest('/api/import-invitation/verify', ['token' => $tokens["chloe-$t@example.invalid"]]);
        $this->assertSame([200, false], [$this->status(), $this->body()['marketing']], 'sans attestation, rien n\'est dit des courriels commerciaux');

        // Un lien expiré ne s'ouvre plus et n'accepte plus
        $conn()->executeStatement("UPDATE import_customer_invitation SET expires_at = NOW() - INTERVAL '1 minute' WHERE user_id = ?", [$chloe]);
        foreach (['verify', 'accept', 'decline'] as $route) {
            $this->publicRequest("/api/import-invitation/$route", ['token' => $tokens["chloe-$t@example.invalid"], 'password' => self::PASSWORD, 'termsVersion' => static::getContainer()->get(AppTermsService::class)->version(AppFamilies::BOUTIQUE), 'accepted' => true]);
            $this->assertSame([404, 'IMPORT_INVITATION_INVALID'], [$this->status(), $this->body()['code']], $route);
        }
        $this->assertSame('invited', $state($chloe));

        // Bruno passe une commande sans compte : son compte tient une commande. À l'échéance, il est GARDÉ et devient un compte ordinaire
        $this->order($bruno);
        $conn()->executeStatement("UPDATE import_customer_invitation SET erase_at = NOW() - INTERVAL '1 minute' WHERE user_id IN (?, ?)", [$bruno, $chloe]);
        // … le passage horaire efface Chloé (délai passé), garde Bruno, ne touche pas Alice (délai non passé)
        $application = new Application(static::getContainer()->get('kernel'));
        $tester = new CommandTester($application->find('app:gestion:notifications:envoyer'));
        $tester->execute(['--tenant' => MV_TEST_TENANT_CODE]);
        $this->assertStringContainsString('1 client(s) repris non acceptés effacé(s), 1 gardé(s)', $tester->getDisplay());
        $this->assertSame([0, 'kept', 'invited'], [(int) $conn()->fetchOne('SELECT COUNT(*) FROM "user" WHERE id = ?', [$chloe]), $state($bruno), $state($alice)]);
        $kept = $conn()->fetchAssociative('SELECT roles, is_verified, password_set FROM "user" WHERE id = ?', [$bruno]);
        $this->assertSame(['["ROLE_USER_INTERNET"]', true, false], [$kept['roles'], (bool) $kept['is_verified'], (bool) $kept['password_set']], 'un compte d\'achat sans compte ordinaire');
        $this->assertFalse($guests->isPending($this->em()->find(User::class, $bruno)));
        // … il n'est plus un invité : « mot de passe oublié » lui répond comme à tout compte né d'un achat
        $this->publicRequest('/api/password-reset/request', ['email' => "bruno-$t@example.invalid"]);
        $this->assertNotNull($conn()->fetchOne('SELECT reset_token FROM "user" WHERE id = ?', [$bruno]));

        // La lecture de la liste efface aussi un invité dont le délai est passé
        $conn()->executeStatement("UPDATE import_customer_invitation SET erase_at = NOW() - INTERVAL '1 minute' WHERE user_id = ?", [$alice]);
        $this->get('/api/site/imports');
        $this->assertSame(0, (int) $conn()->fetchOne('SELECT COUNT(*) FROM "user" WHERE id = ?', [$alice]));
        $this->assertSame(0, (int) $conn()->fetchOne('SELECT COUNT(*) FROM adress WHERE user_adress_id IS NULL AND city = ?', ['Montréal']), 'ses adresses partent avec lui');

        // Une personne qui s'inscrit d'elle-même avec l'adresse d'un invité : la fiche reprise est effacée, l'inscription repart d'un compte neuf
        $this->upload($this->csv(), 'customers');
        $second = (int) $this->body()['id'];
        $this->assertSame(2, $this->body()['report']['customers']['importable'], 'Alice et Chloé ; Bruno est maintenant un compte du site');
        $this->request('POST', "/api/site/imports/$second/confirm", ['attestMarketingConsent' => true]);
        $this->runImport($second);
        $guest = $idOf("alice-$t@example.com");
        $this->assertTrue($guests->isPending($this->em()->find(User::class, $guest)));
        $this->publicRequest('/api/register', ['email' => "Alice-$t@Example.com", 'password' => self::PASSWORD, 'firstName' => 'Alice', 'lastName' => 'Elle-Même', 'platform' => 'mobile']);
        $this->assertSame(201, $this->status(), (string) $this->client->getResponse()->getContent());
        $this->assertSame([0, 0], [(int) $conn()->fetchOne('SELECT COUNT(*) FROM "user" WHERE id = ?', [$guest]), (int) $conn()->fetchOne('SELECT COUNT(*) FROM import_customer_invitation WHERE user_id = ?', [$guest])]);
        $registered = $conn()->fetchAssociative('SELECT lastname, signup_channel FROM "user" WHERE LOWER(email) = ?', ["alice-$t@example.com"]);
        $this->assertSame('Elle-Même', $registered['lastname'], 'rien de la fiche reprise ne reste');
        $this->assertNotSame('import_invite', $registered['signup_channel']);

        // Annulation d'un import terminé : les invités qui n'ont pas accepté sont effacés, leurs liens ne valent plus rien
        $this->request('POST', "/api/site/imports/$second/invitations");
        $this->sendInvitations($second);
        preg_match('#/invitation\?token=([a-f0-9]{64})#', (string) $this->invitationMails()[0]->getHtmlBody(), $m);
        $chloeAgain = $idOf("chloe-$t@example.invalid");
        $this->request('POST', "/api/site/imports/$second/cancel");
        $cancelled = $this->body();
        $this->assertEquals([200, 'cancelled', ['erased' => 1, 'kept' => 0]], [$this->status(), $cancelled['status'], $cancelled['result']['cancel']]);
        $this->assertSame(0, (int) $conn()->fetchOne('SELECT COUNT(*) FROM "user" WHERE id = ?', [$chloeAgain]));
        $this->publicRequest('/api/import-invitation/verify', ['token' => $m[1]]);
        $this->assertSame(404, $this->status());
        $this->assertSame(1, (int) $conn()->fetchOne("SELECT COUNT(*) FROM content_audit_log WHERE resource = 'import_job' AND action = 'cancel' AND resource_id = ?", [(string) $second]));
    }

    /** Le fichier d'export des clients, au format de Shopify : 8 lignes, avec les cas difficiles */
    private function csv(): string
    {
        $t = $this->tag;

        return implode("\n", [
            'Customer ID,First Name,Last Name,Email,Accepts Email Marketing,Default Address Company,Default Address Address1,Default Address Address2,Default Address City,Default Address Province Code,Default Address Country Code,Default Address Zip,Default Address Phone,Phone,Accepts SMS Marketing,Total Spent,Total Orders,Note,Tax Exempt,Tags',
            "'7001,Alice,Martin,Alice-$t@Example.com,yes,,12 rue des Érables,App. 3,Montréal,QC,CA,H2X 1Y4,514-555-0101,+15145550101,no,245.50,4,\"Cliente fidèle : remise à vie, ne pas relancer\",no,VIP",
            "'7002,Bruno,Lavoie,bruno-$t@example.invalid,no,Atelier BL,5 chemin du Lac,,Québec,QC,CA,G1R 2B5,,,no,80.00,1,,no,",
            "'7003,Chloé,Roy,chloe-$t@example.invalid,yes,,,,Laval,,,,,,no,0.00,0,,no,",
            "'7004,Denis,Sans Courriel,,no,,1 rue X,,Ville,QC,CA,H1H 1H1,,,no,0,0,,no,",
            "'7005,Élise,Mauvaise,pas-une-adresse,no,,,,,,,,,,no,0,0,,no,",
            "'7006,Alice,Doublon,alice-$t@example.com,no,,,,,,,,,,no,0,0,,no,",
            "'7007,Fanny,Déjà Cliente,FANNY-$t@example.invalid,yes,,,,,,,,,,no,0,0,,no,",
            "'7008,Irène,Administratrice,{$this->adminEmail},no,,,,,,,,,,no,0,0,,no,",
        ]) . "\n";
    }

    private string $adminEmail = '';

    /** Une commande rattachée à ce compte (comme une commande passée sans compte avec son adresse) */
    private function order(int $userId): void
    {
        $this->create(Order::class, ['totalAmount' => 25.0], function (Order $o, EntityManagerInterface $em) use ($userId): void {
            $o->setOrderDate(new \DateTime())->setStatus($em->find(StatusCommande::class, 3))->setOrderType($em->find(OrderType::class, 1))->setOrderSource($em->find(OrderSource::class, 2))->setUserId($em->find(User::class, $userId));
        });
        $this->endOfWrite();
    }

    private function runImport(int $id): void
    {
        $this->em();
        static::getContainer()->get(CustomerImportService::class)->run($id, 'test');
        $this->em()->clear();
        $this->endOfWrite();
    }

    /** @return array{sent: int, failed: int} */
    private function sendInvitations(int $id): array
    {
        $this->em();
        $out = static::getContainer()->get(CustomerImportService::class)->sendInvitations($id);
        $this->em()->clear();
        $this->endOfWrite();

        return $out;
    }

    /** @return list<\Symfony\Component\Mime\Email> les invitations parties depuis la dernière requête (les courriels se lisent dans le noyau courant) */
    private function invitationMails(): array
    {
        return array_values(array_filter($this->getMailerMessages(), fn ($m) => str_ends_with((string) $m->getSubject(), ' : votre compte client')));
    }

    /** Efface ce que les imports de clients du test ont laissé : commandes d'essai, comptes repris, comptes du test, imports */
    private function wipe(): void
    {
        $conn = $this->em()->getConnection();
        $ids = array_map('intval', $conn->fetchFirstColumn("SELECT id FROM \"user\" WHERE email LIKE '%@example.invalid' AND (email LIKE 'bruno-%' OR email LIKE 'chloe-%' OR email LIKE 'fanny-%' OR email LIKE 'import-clients-%')
            UNION SELECT id FROM \"user\" WHERE LOWER(email) LIKE 'alice-%@example.com' UNION SELECT user_id FROM import_customer_invitation"));
        if ($ids !== []) {
            $in = implode(',', $ids);
            $conn->executeStatement("DELETE FROM \"order\" WHERE user_id_id IN ($in)");
            $conn->executeStatement("UPDATE \"user\" SET primary_address_id = NULL WHERE id IN ($in)");
            $conn->executeStatement("DELETE FROM adress WHERE user_adress_id IN ($in)");
            $conn->executeStatement("DELETE FROM \"user\" WHERE id IN ($in)");
        }
        $conn->executeStatement("DELETE FROM newsletter_subscriber WHERE email LIKE 'alice-%@example.com' OR email LIKE '%@example.invalid'");
        $conn->executeStatement("DELETE FROM import_item WHERE kind = 'customer'");
        $conn->executeStatement('DELETE FROM import_job');
        foreach (glob(static::getContainer()->getParameter('kernel.project_dir') . '/var/storage/private_media/imports/' . MV_TEST_TENANT_CODE . '/*.csv') ?: [] as $csv) {
            @unlink($csv); // les fichiers déposés par le test
        }
        static::getContainer()->get(CacheInvalidationSubscriber::class)->invalidateWrittenTenants();
    }

    private function upload(string $content, string $kind): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($path, $content);
        $this->files[] = $path;
        $this->client->request('POST', '/api/site/imports', ['source' => 'shopify', 'kind' => $kind], ['file' => new UploadedFile($path, 'export-clients.csv', 'text/csv', null, true)], $this->headers(false));
    }

    private function headers(bool $json = true): array
    {
        return array_filter(['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => $json ? 'application/json' : null, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => $this->token !== '' ? 'Bearer ' . $this->token : null]);
    }

    private function request(string $method, string $route, ?array $body = null): void
    {
        $this->client->request($method, $route, [], [], $this->headers(), $body === null ? null : json_encode($body));
    }

    /** Une route publique, sans jeton ni cookie, d'une adresse IP propre au test */
    private function publicRequest(string $route, array $body): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . $route, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => $this->ip], json_encode($body));
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

    /** Un compte du site (un vrai client, ou un administrateur) ; rend son identifiant */
    private function account(string $email, string $firstname, array $roles): int
    {
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname($firstname)->setLastname('Du Site')->setRoles($roles)->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->endOfWrite();

        return (int) $user->getId();
    }

    private function tokenFor(array $roles): string
    {
        $email = 'import-clients-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $id = $this->account($email, 'Irène', $roles);
        if (in_array('ROLE_ADMIN', $roles, true)) {
            $this->adminEmail = $email;
        }

        return static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($this->em()->find(User::class, $id));
    }
}
