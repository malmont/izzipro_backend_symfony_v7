<?php

namespace App\Tests\Functional\Security;

use App\Controller\Admin\EasyPostConfigurationCrudController;
use App\Controller\Admin\EmailConfigurationCrudController;
use App\Controller\Admin\GooglePlacesConfigCrudController;
use App\Controller\Admin\SquareConfigCrudController;
use App\Controller\Admin\UserCrudController;
use App\Entity\EasyPostConfiguration;
use App\Entity\EmailConfiguration;
use App\Entity\GooglePlacesConfig;
use App\Entity\SquareConfig;
use App\Entity\User;
use App\Security\SecretKeys;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Support\OtpTestHelper;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot SECRETS-1 (16/11/2026) : UN SECRET NE S'AFFICHE JAMAIS. Le mot de passe du serveur d'envoi se lisait en clair sur la fiche
 * détail de la configuration du courriel dans EasyAdmin.
 *
 * Des valeurs RECONNAISSABLES sont posées dans tous les secrets d'un site : mot de passe du serveur d'envoi, clés EasyPost et Google
 * Places (test et production), jeton Square, jetons de réinitialisation et de vérification d'un compte. Puis :
 * - chaque écran d'EasyAdmin qui les porte est ouvert (liste, fiche, formulaire), et toutes les pages d'administration ;
 * - toutes les lectures de l'API de la gestion, du site et de la plateforme sont appelées ;
 * par l'administrateur du site et par le super administrateur : aucune réponse ne porte une de ces valeurs.
 * Le journal des modifications masque à la lecture tout secret qu'une ligne aurait gardé.
 */
class SecretsNeverShownTest extends WebTestCase
{
    /** Lectures de l'API balayées : gestion, site, plateforme et leurs voisines d'administration (la caisse a son propre journal inaltérable : hors balayage) */
    private const API = '#^/api/(gestion|site|platform|entreprise|boutique-settings|landingpage-settings|landingpage-audit|admin-settings|app-keys|support|shipping|transporteurs|carrier|fournisseurs|collections|periodes|commandes|dashboard|statistiques|payments|memoires/admin|me)(/|$)#';

    /**
     * Exceptions DÉCLARÉES de l'API, chacune avec sa raison : aucune. Une fuite nouvelle ne s'ajoute pas ici, elle se corrige. (La
     * console de Mémoires Vivantes rendait le jeton de vérification de tout compte en attente : fermé par le même train, voir
     * `ActivationLinkTest`.)
     */
    private const KNOWN_API = [];
    /** La clé d'un lien de partage de médias est faite pour être copiée et donnée : elle s'affiche */
    private const KNOWN_FIELDS = ['SharedMediaCrudController.php : accessKey'];

    private KernelBrowser $client;
    private string $tag;
    /** @var array<string, string> nom => valeur reconnaissable */
    private array $secrets = [];
    /** @var list<array{0: string, 1: int, 2: array<string, mixed>|null}> table, identifiant, ligne d'origine (null : ligne créée par l'essai) */
    private array $rows = [];
    private int $userId = 0;
    private string $mail = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->tag = bin2hex(random_bytes(4));
        foreach (['smtp', 'easypostTest', 'easypostProd', 'googleTest', 'googleProd', 'square', 'reset', 'verification'] as $name) {
            $this->secrets[$name] = 'SECRET-' . strtoupper($name) . '-' . $this->tag;
        }
        $em = $this->em();
        $email = $em->getRepository(EmailConfiguration::class)->findOneBy([]) ?? (new EmailConfiguration())->setFromEmail('envoi-' . $this->tag . '@example.invalid')->setFromName('Secrets');
        $this->keep($em, $email, 'email_configuration');
        $email->setSmtpPassword($this->secrets['smtp']);
        $easypost = $em->getRepository(EasyPostConfiguration::class)->findOneBy([]) ?? new EasyPostConfiguration();
        $this->keep($em, $easypost, 'easy_post_configuration');
        $easypost->setEasypostApiKeySandbox($this->secrets['easypostTest']);
        $this->optional('easypostProd', static fn (string $v) => $easypost->setEasypostApiKeyProd($v));
        $google = $em->getRepository(GooglePlacesConfig::class)->findOneBy([]) ?? new GooglePlacesConfig();
        $this->keep($em, $google, 'google_places_config');
        $google->setGoogleApiKeyTest($this->secrets['googleTest']);
        $this->optional('googleProd', static fn (string $v) => $google->setGoogleApiKeyProd($v));
        $square = $em->getRepository(SquareConfig::class)->findOneBy([]) ?? (new SquareConfig())->setApplicationId('app-' . $this->tag)->setIsActive(false)->setAccessToken('x');
        $this->keep($em, $square, 'square_config');
        $square->setAccessToken($this->secrets['square']);
        // (l'adresse du compte ne porte aucun fragment des secrets : une recherche par un fragment de secret ne doit le trouver par rien d'autre)
        $this->mail = 'compte' . random_int(100000, 999999);
        $account = (new User())->setEmail('secrets-' . $this->mail . '@example.invalid')->setUsername('secrets-' . $this->mail . '@example.invalid')->setFirstname('Secrets')->setLastname('Compte')
            ->setRoles(['ROLE_USER_INTERNET'])->setPassword('inutilisable')->setIsVerified(false)->setResetToken($this->secrets['reset'])->setVerificationToken($this->secrets['verification']);
        $em->persist($account);
        $em->flush();
        $this->userId = (int) $account->getId();
        foreach ([[$email, 'email_configuration'], [$easypost, 'easy_post_configuration'], [$google, 'google_places_config'], [$square, 'square_config']] as [$entity, $table]) {
            foreach ($this->rows as $i => [$t, $id]) {
                if ($t === $table && $id === 0) {
                    $this->rows[$i][1] = (int) $entity->getId();
                }
            }
        }
        // l'essai n'a de sens que si les secrets sont bien en base, et relus tels quels par le code
        $this->assertSame($this->secrets['smtp'], $em->getConnection()->fetchOne('SELECT smtp_password FROM email_configuration WHERE id = ?', [$email->getId()]));
        $this->assertSame($this->secrets['square'], $square->getAccessToken());
    }

    protected function tearDown(): void
    {
        $db = $this->em()->getConnection();
        foreach ($this->rows as [$table, $id, $original]) {
            if ($original === null) {
                $db->executeStatement("DELETE FROM $table WHERE id = ?", [$id]);
            } else {
                $db->update($table, array_diff_key($original, ['id' => 1]), ['id' => $id]);
            }
        }
        $db->executeStatement("DELETE FROM content_audit_log WHERE resource = 'secrets-essai'");
        $db->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'secrets-%@example.invalid'");
        parent::tearDown();
    }

    public function testNoAdministrationScreenShowsASecretItOnlySaysWhetherItIsSet(): void
    {
        $ids = array_column(array_map(static fn (array $row): array => ['table' => $row[0], 'id' => $row[1]], $this->rows), 'id', 'table');
        $screens = [
            [EmailConfigurationCrudController::class, $ids['email_configuration']], [EasyPostConfigurationCrudController::class, $ids['easy_post_configuration']],
            [GooglePlacesConfigCrudController::class, $ids['google_places_config']], [SquareConfigCrudController::class, $ids['square_config']], [UserCrudController::class, $this->userId],
        ];
        foreach ([['ROLE_ADMIN'], ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']] as $roles) {
            $who = implode('+', $roles);
            $this->loginAs($roles);
            $opened = 0;
            foreach ($screens as [$controller, $id]) {
                foreach ([['index', null], ['detail', $id], ['edit', $id]] as [$action, $entityId]) {
                    $html = $this->page('/admin?' . http_build_query(array_filter(['crudAction' => $action, 'crudControllerFqcn' => $controller, 'entityId' => $entityId])));
                    $opened += $this->client->getResponse()->getStatusCode() === 200 ? 1 : 0;
                    $this->assertSame([], $this->shown($html), "$who : $controller ($action)");
                }
            }
            $this->assertGreaterThan(8, $opened, "$who : les écrans se sont bien ouverts");
            // la fiche de la configuration du courriel DIT que le mot de passe est défini, sans le montrer
            $detail = $this->page('/admin?' . http_build_query(['crudAction' => 'detail', 'crudControllerFqcn' => EmailConfigurationCrudController::class, 'entityId' => $ids['email_configuration']]));
            $this->assertSame(200, $this->client->getResponse()->getStatusCode());
            $this->assertStringContainsString('Mot de passe SMTP', $detail);
            $this->assertMatchesRegularExpression('/aria-label="valeur masquée">\s*' . SecretKeys::DEFINED . '\s*</u', $detail);
            // toutes les pages d'administration à adresse propre (configuration, mon site, livraison, pilotage…)
            foreach (static::getContainer()->get('router')->getRouteCollection() as $route) {
                if (str_starts_with($route->getPath(), '/admin/') && !str_contains($route->getPath(), '{') && ($route->getMethods() === [] || in_array('GET', $route->getMethods(), true))
                    && preg_match('#^/admin/(redis|workers|stripe)#', $route->getPath()) !== 1) {
                    $this->assertSame([], $this->shown($this->page($route->getPath())), "$who : " . $route->getPath());
                }
            }
            $this->client->getCookieJar()->clear();
        }
        // un secret vide se dit « non défini »
        $this->em()->getConnection()->executeStatement('UPDATE email_configuration SET smtp_password = NULL WHERE id = ?', [$ids['email_configuration']]);
        $this->loginAs(['ROLE_ADMIN']);
        $this->assertStringContainsString(SecretKeys::UNDEFINED, $this->page('/admin?' . http_build_query(['crudAction' => 'detail', 'crudControllerFqcn' => EmailConfigurationCrudController::class, 'entityId' => $ids['email_configuration']])));
    }

    public function testNoAdministrationReadOfTheApiGivesASecret(): void
    {
        $leaking = [];
        $answered = 0;
        foreach (['administrateur' => ['ROLE_ADMIN'], 'super administrateur' => ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']] as $who => $roles) {
            $token = $this->token($roles);
            foreach (static::getContainer()->get('router')->getRouteCollection() as $route) {
                if (preg_match(self::API, $route->getPath()) !== 1 || ($route->getMethods() !== [] && !in_array('GET', $route->getMethods(), true))) {
                    continue;
                }
                $path = (string) preg_replace('/\{\w+\}/', '1', $route->getPath());
                try {
                    $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
                } catch (\Throwable) {
                    continue; // une route qui plante sur les données laissées par un autre essai ne rend rien : ce n'est pas l'objet de ce garde-fou
                }
                $answered += $this->client->getResponse()->getStatusCode() === 200 ? 1 : 0;
                foreach (array_diff($this->shown((string) $this->client->getResponse()->getContent()), self::KNOWN_API["GET $path"] ?? []) as $name) {
                    $leaking[] = "$who GET $path : $name";
                }
            }
        }
        $this->assertSame([], $leaking, 'Lecture(s) de l\'API qui rendent un secret.');
        $this->assertGreaterThan(120, $answered, 'le balayage a bien lu des réponses');
    }

    public function testTheChangeJournalMasksASecretWhenItIsReadAndKeepsTheLineUntouched(): void
    {
        $state = ['smtpHost' => 'smtp.exemple.test', 'smtpPassword' => $this->secrets['smtp'], 'inputTokens' => 12, 'memoryKey' => 'page:accueil', 'vide' => ['apiKey' => ''],
            'services' => [['name' => 'easypost', 'easypost_api_key_prod' => $this->secrets['easypostProd'], 'access-token' => $this->secrets['square']]]];
        $this->loginAs(['ROLE_ADMIN']); // l'auteur de la ligne
        $entry = static::getContainer()->get(ContentAuditRecorder::class)->record('secrets-essai', 1, 'update', ['smtpPassword' => 'ancien-' . $this->secrets['smtp']], $state, ['smtpPassword']);
        $id = (int) $entry->getId();
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . "/api/landingpage-audit/$id", [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token(['ROLE_ADMIN'])]);
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $body);
        $this->assertSame([], $this->shown($body));
        $read = json_decode($body, true);
        $read = $read['entry'] ?? $read;
        $this->assertSame(SecretKeys::MASK, $read['before']['smtpPassword']);
        // seuls les secrets sont masqués : le reste de l'état est rendu tel quel, et un secret vide reste vide
        $this->assertSame(['smtpHost' => 'smtp.exemple.test', 'smtpPassword' => SecretKeys::MASK, 'inputTokens' => 12, 'memoryKey' => 'page:accueil', 'vide' => ['apiKey' => ''],
            'services' => [['name' => 'easypost', 'easypost_api_key_prod' => SecretKeys::MASK, 'access-token' => SecretKeys::MASK]]], $read['after']);
        // la ligne gardée n'est pas touchée : c'est la lecture qui masque (le retour arrière relit la ligne entière)
        $this->assertStringContainsString($this->secrets['smtp'], (string) $this->em()->getConnection()->fetchOne('SELECT after_state FROM content_audit_log WHERE id = ?', [$id]));
        // le masquage vaut aussi pour un état en tableaux : un secret vide ou absent reste tel quel, à toute profondeur
        $this->assertSame(['apiKey' => '', 'smtpPassword' => SecretKeys::MASK, 'n' => ['token' => null, 'accessToken' => SecretKeys::MASK, 'port' => 587], 'liste' => [['secret' => SecretKeys::MASK], ['secret' => false]]],
            SecretKeys::mask(['apiKey' => '', 'smtpPassword' => 'x', 'n' => ['token' => null, 'accessToken' => 'y', 'port' => 587], 'liste' => [['secret' => 'z'], ['secret' => false]]]));
        $this->assertSame('texte', SecretKeys::mask('texte'));
        $this->assertNull(SecretKeys::mask(null));
        // les noms qui disent un secret, et ceux qui n'en disent pas
        foreach (['password', 'smtpPassword', 'smtp_password', 'plainPassword', 'clientSecret', 'webhook_secret', 'apiKey', 'api_key', 'easypostApiKeyProd', 'googleApiKeyTest', 'accessToken', 'resetToken', 'token', 'accessKey', 'private-key'] as $name) {
            $this->assertTrue(SecretKeys::names($name), $name);
        }
        foreach (['inputTokens', 'cacheReadTokens', 'memoryKey', 'componentKey', 'periodKey', 'idempotencyKey', 'tokenCount', 'keywords', 'name', 'smtpHost', 'smtpUser'] as $name) {
            $this->assertFalse(SecretKeys::names($name), $name);
        }
    }

    /**
     * LA RECHERCHE ET LE TRI d'une liste ne lisent jamais un secret. Sans champs de recherche déclarés, EasyAdmin cherchait dans toutes
     * les colonnes d'une table : un fragment tapé dans la recherche disait si le jeton de réinitialisation d'un compte le contenait,
     * et, répété, le reconstituait. Enchaîné avec « mot de passe oublié » sur le compte de la plateforme, un administrateur de site
     * devenait super administrateur.
     */
    public function testTheSearchAndTheSortOfAListNeverReadASecret(): void
    {
        $ids = array_column(array_map(static fn (array $row): array => ['table' => $row[0], 'id' => $row[1]], $this->rows), 'id', 'table');
        $db = $this->em()->getConnection();
        $db->executeStatement('UPDATE "user" SET password = ? WHERE id = ?', ['EMPREINTE-' . $this->tag, $this->userId]);
        $found = function (string $controller, int $id, string $query): bool {
            $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(['crudAction' => 'index', 'crudControllerFqcn' => $controller, 'query' => $query]), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
            $this->assertSame(200, $this->client->getResponse()->getStatusCode(), "$controller ?query=$query");

            return $crawler->filter('tr[data-id="' . $id . '"]')->count() > 0;
        };
        foreach ([['ROLE_ADMIN'], ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']] as $roles) {
            $this->loginAs($roles);
            // la recherche marche : le compte se trouve par son adresse, la configuration du courriel par son expéditeur
            $this->assertTrue($found(UserCrudController::class, $this->userId, 'secrets-' . $this->mail), 'un compte se cherche par son adresse');
            // … mais par AUCUN fragment d'un secret : début, milieu ou fin du jeton, de l'empreinte du mot de passe, du mot de passe du serveur d'envoi, d'une clé
            foreach ([[UserCrudController::class, $this->userId, $this->secrets['reset']], [UserCrudController::class, $this->userId, $this->secrets['verification']],
                [UserCrudController::class, $this->userId, 'EMPREINTE-' . $this->tag], [EmailConfigurationCrudController::class, $ids['email_configuration'], $this->secrets['smtp']],
                [EasyPostConfigurationCrudController::class, $ids['easy_post_configuration'], $this->secrets['easypostTest']],
                [GooglePlacesConfigCrudController::class, $ids['google_places_config'], $this->secrets['googleTest']]] as [$controller, $id, $secret]) {
                foreach ([$secret, substr($secret, 0, 9), substr($secret, 3, 8), substr($secret, -6), strtolower($secret)] as $fragment) {
                    $this->assertFalse($found($controller, $id, $fragment), implode('+', $roles) . " : $controller se trouve par « $fragment »");
                }
            }
            // le tri par un secret est ignoré : l'ordre de la liste ne dépend pas de lui
            $order = function (string $direction, string $property): array {
                $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(['crudAction' => 'index', 'crudControllerFqcn' => UserCrudController::class, 'query' => 'secrets-', 'sort' => [$property => $direction]]), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
                $this->assertSame(200, $this->client->getResponse()->getStatusCode());

                return $crawler->filter('tr[data-id]')->each(static fn ($row) => $row->attr('data-id'));
            };
            foreach (['resetToken', 'verificationToken', 'password'] as $property) {
                $this->assertSame($order('ASC', $property), $order('DESC', $property), "tri par $property ignoré");
            }
            $this->assertNotSame($order('ASC', 'id'), $order('DESC', 'id'), 'le tri par une colonne ordinaire, lui, change l\'ordre');
            // un secret glissé dans le SENS ou dans le NOM du tri ne passe pas non plus : la liste répond, comme sans tri
            foreach ([['id' => 'ASC, entity.resetToken'], ['resetToken,entity.id' => 'ASC'], ['id' => 'ASC; DROP'], ['entity.password' => 'DESC']] as $forged) {
                $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query(['crudAction' => 'index', 'crudControllerFqcn' => UserCrudController::class, 'query' => 'secrets-', 'sort' => $forged]), [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
                $this->assertSame(200, $this->client->getResponse()->getStatusCode(), json_encode($forged));
            }
            $this->client->getCookieJar()->clear();
        }
        // le tri sûr : un nom de champ simple, un sens ASC ou DESC ; rien d'autre ne passe
        $none = static fn (string $property): bool => false;
        $this->assertSame(['id' => 'ASC', 'user.email' => 'DESC', 'name' => 'ASC'], \App\Controller\Admin\BaseTenantCrudController::safeSort(
            ['id' => 'ASC', 'user.email' => 'desc', 'name' => 'Asc', 'x' => 'ASC, entity.resetToken', 'resetToken,entity.id' => 'ASC', 'a.b.c' => 'ASC', '1x' => 'ASC', 'y' => ['ASC'], 3 => 'ASC'], $none));
        $this->assertSame([], \App\Controller\Admin\BaseTenantCrudController::safeSort(['resetToken' => 'ASC'], [SecretKeys::class, 'unsearchable']));
        // les noms de colonne qu'une liste ne cherche ni ne trie
        foreach (['resetToken', 'verificationToken', 'password', 'smtpPassword', 'easypostApiKeySandbox', 'guestToken', 'accessToken', 'pinHash', 'tokenHash', 'keyHash', 'user.resetToken'] as $property) {
            $this->assertTrue(SecretKeys::unsearchable($property), $property);
        }
        foreach (['email', 'username', 'firstname', 'smtpHost', 'smtpUser', 'id', 'user.email', 'periodKey', 'secretQuestion.label', 'passwordPolicy.name'] as $property) {
            $this->assertFalse(SecretKeys::unsearchable($property), $property . ' (seul le dernier segment d\'une association compte)');
        }
        // un écran qui fabrique lui-même sa requête de liste ne passe pas par ce filtre : aucun ne porte sur une table à secret
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/src', \FilesystemIterator::SKIP_DOTS));
        $metadata = $this->em()->getMetadataFactory();
        foreach ($files as $file) {
            $source = $file->getExtension() === 'php' ? (string) file_get_contents($file->getPathname()) : '';
            if (!str_contains($source, 'function createIndexQueryBuilder') || str_contains($source, 'parent::createIndexQueryBuilder') || !preg_match('/return\s+\\?([\w\\\\]+)::class;/', $source, $m)) {
                continue;
            }
            $entity = str_contains($m[1], '\\') ? ltrim($m[1], '\\') : 'App\\Entity\\' . $m[1];
            if (class_exists($entity) && $metadata->hasMetadataFor($entity)) {
                $this->assertSame([], array_values(array_filter($metadata->getMetadataFor($entity)->getFieldNames(), [SecretKeys::class, 'unsearchable'])), $file->getFilename() . ' : liste faite à la main sur une table à secret');
            }
        }
    }

    /** Le mot de passe et les jetons d'un compte ne sortent d'aucune sérialisation, quel que soit le groupe */
    public function testAnAccountIsNeverSerializedWithItsPasswordOrItsTokens(): void
    {
        $user = $this->em()->find(User::class, $this->userId);
        $user->setPassword('EMPREINTE-SERIALISEE-' . $this->tag)->setResetTokenExpiresAt(new \DateTime('+1 hour'));
        $serializer = static::getContainer()->get('serializer');
        foreach ([[], ['groups' => ['product:list']], ['groups' => ['vehicle_product:read']]] as $context) {
            $normalized = $serializer->normalize($user, 'json', $context + [\Symfony\Component\Serializer\Normalizer\AbstractNormalizer::CIRCULAR_REFERENCE_HANDLER => static fn ($o) => null]);
            foreach (['password', 'plainPassword', 'resetToken', 'resetTokenExpiresAt', 'verificationToken', 'verificationTokenExpiresAt'] as $key) {
                $this->assertArrayNotHasKey($key, (array) $normalized, $key);
            }
            $this->assertSame([], $this->shown((string) json_encode($normalized)));
            $this->assertStringNotContainsString('EMPREINTE-SERIALISEE-' . $this->tag, (string) json_encode($normalized));
        }
        $this->em()->clear();
    }

    /** Le compte de la plateforme ne se réinitialise jamais par un site : aucun lien créé, aucun lien accepté */
    public function testThePlatformAccountIsNeverResetFromASite(): void
    {
        $em = $this->em();
        $make = function (string $name, array $roles) use ($em): User {
            $email = "secrets-$name-{$this->tag}@example.invalid";
            $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Secrets')->setLastname($name)->setRoles($roles)->setPassword('mot-de-passe-de-la-plateforme')->setIsVerified(true);
            $em->persist($user);

            return $user;
        };
        $platform = $make('plateforme', ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $ordinary = $make('ordinaire', ['ROLE_ADMIN']);
        $em->flush();
        $db = $em->getConnection();
        $post = function (string $path, array $body): array {
            $this->client->getCookieJar()->clear();
            $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
                'REMOTE_ADDR' => '10.82.' . random_int(1, 254) . '.' . random_int(1, 254)], json_encode($body));

            return [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true)['code'] ?? null];
        };
        // la demande : réponse neutre, la même pour les deux comptes ; un lien n'est créé que pour le compte ordinaire
        $this->assertSame(200, $post('/api/password-reset/request', ['email' => $ordinary->getEmail()])[0]);
        $this->assertSame(200, $post('/api/password-reset/request', ['email' => $platform->getEmail()])[0]);
        $this->assertNotNull($db->fetchOne('SELECT reset_token FROM "user" WHERE id = ?', [$ordinary->getId()]));
        $this->assertNull($db->fetchOne('SELECT reset_token FROM "user" WHERE id = ?', [$platform->getId()]) ?: null, 'aucun lien pour le compte de la plateforme');
        // un lien qui existerait quand même (posé avant ce lot) n'est accepté ni à la vérification ni à la confirmation
        $db->executeStatement("UPDATE \"user\" SET reset_token = ?, reset_token_expires_at = NOW() + INTERVAL '1 hour' WHERE id = ?", ['lien-force-' . $this->tag, $platform->getId()]);
        $this->assertSame([400, 'RESET_LINK_INVALID'], $post('/api/password-reset/verify', ['token' => 'lien-force-' . $this->tag]));
        $this->assertNotSame(200, $post('/api/password-reset/confirm', ['token' => 'lien-force-' . $this->tag, 'password' => 'Un-autre-mot-de-passe-9'])[0]);
        $this->assertSame('mot-de-passe-de-la-plateforme', $db->fetchOne('SELECT password FROM "user" WHERE id = ?', [$platform->getId()]), 'le mot de passe du compte de la plateforme n\'a pas changé');
        // le compte ordinaire, lui, se réinitialise comme avant
        $token = (string) $db->fetchOne('SELECT reset_token FROM "user" WHERE id = ?', [$ordinary->getId()]);
        $this->assertSame(200, $post('/api/password-reset/confirm', ['token' => $token, 'password' => 'Un-autre-mot-de-passe-9'])[0]);
    }

    /**
     * Le code à usage unique du compte de la plateforme part par le serveur de la PLATEFORME, jamais par le serveur d'envoi du site
     * (que l'administrateur du site règle lui-même : il aurait reçu le code).
     */
    public function testTheLoginCodeOfThePlatformAccountNeverGoesThroughTheMailServerOfTheSite(): void
    {
        $em = $this->em();
        $make = function (string $name, array $roles) use ($em): User {
            $email = "secrets-code-$name-{$this->tag}@example.invalid";
            $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Secrets')->setLastname($name)->setRoles($roles)->setPassword('x')->setIsVerified(true);
            $em->persist($user);

            return $user;
        };
        $platform = $make('plateforme', ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $ordinary = $make('ordinaire', ['ROLE_ADMIN']);
        $em->flush();
        $request = \Symfony\Component\HttpFoundation\Request::create('https://' . MV_TEST_TENANT_HOST . '/account/otp');
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);

        $sender = $this->createMock(\App\Services\EmailConfigurationService\EmailSenderService::class);
        $sender->method('subject')->willReturn('Votre code');
        $sender->expects($this->once())->method('sendPlatformEmail')->with($platform->getEmail(), 'Votre code', 'security/2fa_email.html.twig', $this->callback(static fn (array $context): bool => isset($context['code'])))->willReturn(true);
        $sender->expects($this->never())->method('sendCustomerEmail');
        $sender->expects($this->never())->method('sendTemplatedEmail');
        (new \App\Services\OtpService($provider, $sender))->generateAndSendOtp($platform, $request);
        $this->assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM otp_code WHERE user_otp_id = ?', [$platform->getId()]), 'le code est bien créé');

        // un compte ordinaire garde son chemin : le serveur d'envoi du site, avec le repli de la plateforme si le site ne peut pas envoyer
        $sender = $this->createMock(\App\Services\EmailConfigurationService\EmailSenderService::class);
        $sender->expects($this->once())->method('sendCustomerEmail')->with($ordinary->getEmail())->willReturn(true);
        $sender->expects($this->never())->method('sendPlatformEmail');
        (new \App\Services\OtpService($provider, $sender))->generateAndSendOtp($ordinary, $request);
        $em->getConnection()->executeStatement('DELETE FROM otp_code WHERE user_otp_id IN (?, ?)', [$platform->getId(), $ordinary->getId()]);
    }

    public function testEveryEasyAdminFieldWhoseNameSaysASecretIsMaskedOrLivesInTheFormOnly(): void
    {
        $unmasked = [];
        $seen = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || !str_contains((string) ($source = file_get_contents($file->getPathname())), 'configureFields')) {
                continue;
            }
            // chaque déclaration de champ, jusqu'à la suivante
            foreach (preg_split('/(?=\b\w+Field::new\()/', $source) ?: [] as $declaration) {
                if (preg_match('/^\w+Field::new\(\s*\'([^\']+)\'/', $declaration, $m) && SecretKeys::names($m[1])) {
                    ++$seen;
                    if (!str_contains($declaration, 'masked_secret.html.twig') && preg_match('/->(onlyOnForms|onlyWhenCreating|onlyWhenUpdating)\(\)/', $declaration) !== 1) {
                        $unmasked[] = $file->getFilename() . ' : ' . $m[1];
                    }
                }
            }
        }
        $this->assertSame([], array_values(array_diff($unmasked, self::KNOWN_FIELDS)), 'Champ(s) d\'EasyAdmin dont le nom dit un secret : gabarit admin/field/masked_secret.html.twig, ou formulaire seulement.');
        $this->assertSame([], array_values(array_diff(self::KNOWN_FIELDS, $unmasked)), 'une exception déclarée qui n\'a plus lieu d\'être se retire');
        $this->assertGreaterThanOrEqual(7, $seen, 'les champs secrets connus ont bien été relus (courriel, EasyPost, Google Places, Square, mot de passe d\'un compte)');
    }

    /** @return list<string> les noms des secrets dont la valeur figure dans une réponse */
    private function shown(string $body): array
    {
        return array_keys(array_filter($this->secrets, static fn (string $value): bool => str_contains($body, $value)));
    }

    private function page(string $path): string
    {
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);

        return (string) $this->client->getResponse()->getContent();
    }

    /** Garde la ligne d'origine d'une entité (remise à la fin), ou note qu'elle est créée par l'essai */
    private function keep(\Doctrine\ORM\EntityManagerInterface $em, object $entity, string $table): void
    {
        if ($entity->getId() === null) {
            $em->persist($entity);
            $this->rows[] = [$table, 0, null];

            return;
        }
        $this->rows[] = [$table, (int) $entity->getId(), $em->getConnection()->fetchAssociative("SELECT * FROM $table WHERE id = ?", [$entity->getId()]) ?: []];
    }

    /** Un secret chiffré au repos : sans sa clé de chiffrement dans l'environnement des essais, il n'est pas posé (ni guetté) */
    private function optional(string $name, callable $set): void
    {
        try {
            $set($this->secrets[$name]);
        } catch (\Throwable) {
            unset($this->secrets[$name]);
        }
    }

    private function loginAs(array $roles): void
    {
        $em = $this->em();
        $email = 'secrets-ecran-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Secrets')->setLastname('Écran')->setRoles($roles)->setIsVerified(true)->setPassword('x');
        $em->persist($user);
        $em->flush();
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user, 'main');
        OtpTestHelper::validateIfRequired($this->client, $user);
    }

    private function token(array $roles): string
    {
        $em = $this->em();
        $email = 'secrets-jeton-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Secrets')->setLastname('Jeton')->setRoles($roles)->setIsVerified(true)->setPassword('x');
        $em->persist($user);
        $em->flush();

        return static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }

    private function em(): \Doctrine\ORM\EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }
}
