<?php

namespace App\Tests\Functional\Cache;

use App\Entity\Presentation;
use App\Entity\PresentationTranslation;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Invalidation au COMMIT réel (lot 1, 28/10/2026) : dans une transaction explicite, le cache n'est vidé qu'à la
 * validation (avant : juste après le flush, donc avant la validation, et une lecture concurrente pouvait remettre
 * l'ancienne valeur en cache) ; une transaction annulée ne vide rien, le cache est resté juste.
 */
class CacheInvalidationAfterCommitTest extends WebTestCase
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

    /** @return int identifiant de la traduction */
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

    public function testTheCacheIsClearedAtTheCommitNotAtTheFlush(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $id = $this->createPresentation('Commit avant ' . $suffix);
        $this->assertStringContainsString('Commit avant ' . $suffix, $this->presentations());
        $this->assertTrue($this->cachedListExists());

        $em = $this->em();
        $connection = $em->getConnection();
        $connection->beginTransaction();
        $em->find(PresentationTranslation::class, $id)->setTitre('Commit apres ' . $suffix);
        $em->flush();
        $this->assertTrue($this->cachedListExists(), 'transaction ouverte : rien n\'est vidé avant la validation');
        $connection->commit();
        $this->assertFalse($this->cachedListExists(), 'validation : le cache est vidé');

        $body = $this->presentations();
        $this->assertStringContainsString('Commit apres ' . $suffix, $body);
        $this->assertStringNotContainsString('Commit avant ' . $suffix, $body);
    }

    public function testARolledBackWriteKeepsTheCache(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $id = $this->createPresentation('Rollback avant ' . $suffix);
        $this->assertStringContainsString('Rollback avant ' . $suffix, $this->presentations());
        $this->assertTrue($this->cachedListExists());

        $em = $this->em();
        $connection = $em->getConnection();
        $connection->beginTransaction();
        $em->find(PresentationTranslation::class, $id)->setTitre('Rollback apres ' . $suffix);
        $em->flush();
        $connection->rollBack();
        $em->clear();
        $this->assertTrue($this->cachedListExists(), 'annulation : le cache, resté juste, est gardé');
        $this->endOfWrite();
        $this->assertTrue($this->cachedListExists(), 'fin de requête : rien n\'attendait plus');

        $body = $this->presentations();
        $this->assertStringContainsString('Rollback avant ' . $suffix, $body);
        $this->assertStringNotContainsString('Rollback apres ' . $suffix, $body);
    }

    public function testAFlushWithoutExplicitTransactionClearsAtOnce(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $id = $this->createPresentation('Direct avant ' . $suffix);
        $this->presentations();
        $this->assertTrue($this->cachedListExists());

        $em = $this->em();
        $em->find(PresentationTranslation::class, $id)->setTitre('Direct apres ' . $suffix);
        $em->flush();
        $this->assertFalse($this->cachedListExists(), 'le flush porte sa propre transaction : vidé à son COMMIT');
        $this->assertStringContainsString('Direct apres ' . $suffix, $this->presentations());
    }
}
