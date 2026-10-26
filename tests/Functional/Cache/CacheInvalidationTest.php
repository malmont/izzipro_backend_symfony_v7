<?php

namespace App\Tests\Functional\Cache;

use App\Entity\Presentation;
use App\Entity\PresentationTranslation;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Invalidation du cache après une écriture (26/10/2026) : toute écriture Doctrine d'une entité vide le cache du site,
 * traductions comprises (avant : la liste des présentations restait une heure en cache après une modification).
 * Les écritures techniques (compteurs, date de dernière connexion) ne le vident pas.
 */
class CacheInvalidationTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    private function em(): EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }

    /** Fin de la « requête » d'écriture du test : second passage de l'invalidation (fait par kernel.terminate) */
    private function endOfWrite(): void
    {
        static::getContainer()->get(CacheInvalidationSubscriber::class)->invalidateWrittenTenants();
    }

    private function presentations(): string
    {
        $this->client->request('GET', '/api/presentations?locale=fr', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        return (string) $this->client->getResponse()->getContent();
    }

    private function cachedListExists(): bool
    {
        return static::getContainer()->get('cache.app')->hasItem(MV_TEST_TENANT_CODE . '.presentations_all_fr');
    }

    public function testATranslationChangeIsServedAtOnce(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $em = $this->em();
        $presentation = (new Presentation())->setTitre('Titre de base ' . $suffix);
        $translation = (new PresentationTranslation())->setLanguage('fr')->setTitre('Avant ' . $suffix)->setPresentation($presentation);
        $presentation->addTranslation($translation);
        $em->persist($presentation);
        $em->persist($translation);
        $em->flush();
        $this->endOfWrite();
        $id = $presentation->getId();

        $this->assertStringContainsString('Avant ' . $suffix, $this->presentations(), 'création servie aussitôt');
        $this->assertTrue($this->cachedListExists(), 'la liste est en cache');

        $em = $this->em();
        $em->find(PresentationTranslation::class, $translation->getId())->setTitre('Apres ' . $suffix);
        $em->flush();
        $this->assertFalse($this->cachedListExists(), 'la modification d\'une traduction vide le cache du site');
        $body = $this->presentations();
        $this->assertStringContainsString('Apres ' . $suffix, $body);
        $this->assertStringNotContainsString('Avant ' . $suffix, $body);
        $this->endOfWrite();

        $em = $this->em();
        $em->remove($em->find(Presentation::class, $id));
        $em->flush();
        $this->assertStringNotContainsString('Apres ' . $suffix, $this->presentations(), 'suppression servie aussitôt');
    }

    public function testTechnicalWritesKeepTheCache(): void
    {
        $this->presentations();
        $this->assertTrue($this->cachedListExists());

        $em = $this->em();
        $user = $em->getRepository(User::class)->findOneBy([]);
        $this->assertNotNull($user, 'base de test : au moins un utilisateur');
        $user->setLastLoginAt(new \DateTimeImmutable());
        $em->flush();
        $this->assertTrue($this->cachedListExists(), 'date de dernière connexion : cache gardé');

        $user->setLastLoginAt(new \DateTimeImmutable('+1 second'))->setRoles($user->getRoles());
        $em->flush();
        $this->assertTrue($this->cachedListExists(), 'rôles identiques : aucun changement réel, cache gardé');
    }

    /** Présentation créée hors de la lecture testée ; renvoie l'identifiant de sa traduction */
    private function createPresentation(string $title): int
    {
        $em = $this->em();
        $presentation = (new Presentation())->setTitre($title);
        $translation = (new PresentationTranslation())->setLanguage('fr')->setTitre($title)->setPresentation($presentation);
        $presentation->addTranslation($translation);
        $em->persist($presentation);
        $em->persist($translation);
        $em->flush();
        $this->endOfWrite();

        return $translation->getId();
    }

    /** Écrit avec le code de site donné (null : commande qui change de base sans code), sur la base du site de test */
    private function retitle(int $translationId, ?string $tenantCode, string $title): void
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, $tenantCode);
        $em = $provider->getEntityManager();
        $em->find(PresentationTranslation::class, $translationId)->setTitre($title);
        $em->flush();
    }

    public function testAWriteWithoutSiteCodeClearsTheSiteOfThatDatabase(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $id = $this->createPresentation('Sans code avant ' . $suffix);
        $this->presentations();
        $this->assertTrue($this->cachedListExists());

        $this->retitle($id, null, 'Sans code apres ' . $suffix);
        $this->assertFalse($this->cachedListExists(), 'commande sans code de site : étiquette de la base');
        $this->assertStringContainsString('Sans code apres ' . $suffix, $this->presentations());
    }

    public function testACodeSharingTheDatabaseClearsTheOtherCode(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $id = $this->createPresentation('Alias avant ' . $suffix);
        $this->presentations();
        $this->assertTrue($this->cachedListExists());

        $this->retitle($id, MV_TEST_TENANT_CODE . '-alias', 'Alias apres ' . $suffix);
        $this->assertFalse($this->cachedListExists(), 'autre code sur la même base (esgboost / esgboost1)');
        $this->assertStringContainsString('Alias apres ' . $suffix, $this->presentations());
    }
}
