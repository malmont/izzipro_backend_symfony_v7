<?php

namespace App\Tests\EventSubscriber;

use App\Entity\BaniereStatique;
use App\Entity\Banniere;
use App\Entity\Contact;
use App\Entity\Entreprise;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Services\TenantConnectionProvider;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class CacheInvalidationSubscriberTest extends TestCase
{
    private MockObject|TagAwareCacheInterface $tagAwareCache;
    private MockObject|CacheInterface $simpleCache;
    private MockObject|TenantConnectionProvider $tcp;
    private CacheInvalidationSubscriber $subscriberWithTags;
    private CacheInvalidationSubscriber $subscriberWithoutTags;

    protected function setUp(): void
    {
        $this->tagAwareCache = $this->createMock(TagAwareCacheInterface::class);
        $this->simpleCache = $this->createMock(CacheInterface::class);
        $this->tcp = $this->createMock(TenantConnectionProvider::class);

        $this->tcp->method('getTenantCode')->willReturn('test_tenant');

        $this->subscriberWithTags = new CacheInvalidationSubscriber($this->tagAwareCache, $this->tcp);
        $this->subscriberWithoutTags = new CacheInvalidationSubscriber($this->simpleCache, $this->tcp);
    }

    public function testGetSubscribedEvents(): void
    {
        $events = $this->subscriberWithTags->getSubscribedEvents();
        $this->assertContains(Events::postPersist, $events);
        $this->assertContains(Events::postUpdate, $events);
        $this->assertContains(Events::postRemove, $events);
    }

    public function testPostPersistWithContactInvalidatesCache(): void
    {
        $contact = new Contact();

        $args = $this->createMock(LifecycleEventArgs::class);
        $args->method('getEntity')->willReturn($contact);

        // Expectation: specific keys deleted and tags invalidated
        // From CACHE_INVALIDATIONS: Contact -> delete 'contacts_all', invalidate 'contacts'
        $this->tagAwareCache->expects($this->once())
            ->method('delete')
            ->with('test_tenant:contacts_all');

        $this->tagAwareCache->expects($this->once())
            ->method('invalidateTags')
            ->with(['test_tenantcontacts']);

        $this->subscriberWithTags->postPersist($args);
    }

    public function testPostUpdateWithBanniereInvalidatesTags(): void
    {
        $banniere = $this->createMock(Banniere::class);
        $banniere->method('getId')->willReturn(123);

        $args = $this->createMock(LifecycleEventArgs::class);
        $args->method('getEntity')->willReturn($banniere);

        // Expectation: 
        // 1. Explicit logic: invalidate 'banniere_123'
        // 2. CACHE_INVALIDATIONS: Banniere -> invalidate 'bannieres_all'



        // Let's refine the expectation. The code calls invalidateTags multiple times.
        // First call: $this->cache->invalidateTags(['banniere_' . $entity->getId()]);
        // Loop call: $this->cache->invalidateTags($tags);

        // We can capture arguments to be more robust
        $invalidatedTags = [];
        $this->tagAwareCache->method('invalidateTags')
            ->willReturnCallback(function (array $tags) use (&$invalidatedTags) {
                $invalidatedTags = array_merge($invalidatedTags, $tags);
                return true;
            });

        $this->subscriberWithTags->postUpdate($args);

        $this->assertContains('banniere_123', $invalidatedTags);
        $this->assertContains('test_tenantbannieres_all', $invalidatedTags);
    }

    public function testPostRemoveWithEntrepriseInvalidatesTags(): void
    {
        $entreprise = $this->createMock(Entreprise::class);
        $entreprise->method('getId')->willReturn(456);

        $args = $this->createMock(LifecycleEventArgs::class);
        $args->method('getEntity')->willReturn($entreprise);

        // Logic for Entreprise:
        // 1. Explicit: invalidate 'entreprise_ID'
        // 2. Explicit: delete 'prefix:entreprise_ID'
        // 3. Explicit: invalidate 'prefix:entreprise'
        // 4. Config: invalidate 'entreprise' -> 'prefix:entreprise'

        $deletedKeys = [];
        $this->tagAwareCache->method('delete')
            ->willReturnCallback(function ($key) use (&$deletedKeys) {
                $deletedKeys[] = $key;
                return true;
            });

        $invalidatedTags = [];
        $this->tagAwareCache->method('invalidateTags')
            ->willReturnCallback(function ($tags) use (&$invalidatedTags) {
                $invalidatedTags = array_merge($invalidatedTags, $tags);
                return true;
            });

        $this->subscriberWithTags->postRemove($args);

        $this->assertContains('test_tenant:entreprise_456', $deletedKeys);
        $this->assertContains('entreprise_456', $invalidatedTags);
        $this->assertContains('test_tenantentreprise', $invalidatedTags);
    }

    public function testNonTagAwareCacheDoesNotCallInvalidateTags(): void
    {
        // Use an entity that definitely triggers invalidateTags if cache supports it
        // E.g. BaniereStatique triggers explicit invalidateTags AND loop invalidateTags
        $baniereStatique = $this->createMock(BaniereStatique::class);
        $baniereStatique->method('getId')->willReturn(999);

        $args = $this->createMock(LifecycleEventArgs::class);
        $args->method('getEntity')->willReturn($baniereStatique);

        // Simple cache should NEVER receive invalidateTags call
        // But since MockObject generates method stubs, and CacheInterface DOES NOT have invalidateTags, 
        // we can't expect it effectively unless we used a magic mock or the interface actually had it (which it doesn't).
        // However, if our code calls it on the object, PHP runtime would throw error if method unknown, OR mock would throw if strict.
        // We really want to ensure the CODE guards against it. 

        // If the code calls $this->cache->invalidateTags(), it would fatal error on a real object that implements only CacheInterface.
        // On a mock of CacheInterface, the method doesn't exist so PHPUnit might complain or PHP would.
        // This test case basically ensures no exception is thrown.

        $this->subscriberWithoutTags->postUpdate($args);

        // If we reached here without "Error: Call to undefined method", success.
        $this->assertTrue(true);
    }
}
