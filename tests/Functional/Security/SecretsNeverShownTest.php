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
     * Exceptions DÉCLARÉES, chacune avec sa raison. Une fuite nouvelle ne s'ajoute pas ici : elle se corrige.
     * - la console de Mémoires Vivantes rend exprès à l'équipe le LIEN D'ACTIVATION d'un compte en attente (`activationUrl`, qui porte
     *   le jeton de vérification) pour qu'elle le transmette à la main ; le garder ou non est une décision du propriétaire (lot en pause).
     */
    private const KNOWN_API = ['GET /api/memoires/admin/users' => ['verification']];
    /** La clé d'un lien de partage de médias est faite pour être copiée et donnée : elle s'affiche */
    private const KNOWN_FIELDS = ['SharedMediaCrudController.php : accessKey'];

    private KernelBrowser $client;
    private string $tag;
    /** @var array<string, string> nom => valeur reconnaissable */
    private array $secrets = [];
    /** @var list<array{0: string, 1: int, 2: array<string, mixed>|null}> table, identifiant, ligne d'origine (null : ligne créée par l'essai) */
    private array $rows = [];
    private int $userId = 0;

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
        $account = (new User())->setEmail('secrets-compte-' . $this->tag . '@example.invalid')->setUsername('secrets-compte-' . $this->tag . '@example.invalid')->setFirstname('Secrets')->setLastname('Compte')
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
        // les noms qui disent un secret, et ceux qui n'en disent pas
        foreach (['password', 'smtpPassword', 'smtp_password', 'plainPassword', 'clientSecret', 'webhook_secret', 'apiKey', 'api_key', 'easypostApiKeyProd', 'googleApiKeyTest', 'accessToken', 'resetToken', 'token', 'accessKey', 'private-key'] as $name) {
            $this->assertTrue(SecretKeys::names($name), $name);
        }
        foreach (['inputTokens', 'cacheReadTokens', 'memoryKey', 'componentKey', 'periodKey', 'idempotencyKey', 'tokenCount', 'keywords', 'name', 'smtpHost', 'smtpUser'] as $name) {
            $this->assertFalse(SecretKeys::names($name), $name);
        }
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
