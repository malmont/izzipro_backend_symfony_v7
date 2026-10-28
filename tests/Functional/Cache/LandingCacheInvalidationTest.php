<?php

namespace App\Tests\Functional\Cache;

use App\Entity\BaniereStatique;
use App\Entity\Banniere;
use App\Entity\CategorieMarque;
use App\Entity\Emploi;
use App\Entity\ExploreCard;
use App\Entity\Feature;
use App\Entity\HomeSlider;
use App\Entity\LandingPageSetting;
use App\Entity\Marque;
use App\Entity\Multilien;
use App\Entity\Presentation;
use App\Entity\PresentationGroup;
use App\Entity\PresentationTranslation;
use App\Entity\Recherche;
use App\Entity\ServiceFamily;
use App\Entity\ServiceOffer;
use App\Entity\Team;
use App\Entity\Vehicle;
use App\Entity\Video;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Invalidation ciblée de la Landing Page (lot 2, 28/10/2026) : l'écriture d'une entité vide les lectures de son
 * composant et laisse les autres en cache (témoin : la liste des présentations, ou celle des bannières quand c'est une
 * présentation qui est écrite). Chaque route en cache de la landing page est couverte, en création puis en suppression.
 */
class LandingCacheInvalidationTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private const PRESENTATIONS = ['/api/presentations?locale=fr', 'presentations_all_fr'];
    private const BANNIERES = ['/api/bannieres?locale=fr', 'bannieres_all_fr'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /** @return iterable<string, array{class-string, string, string}> composant => entité écrite, route de liste, clé */
    public static function components(): iterable
    {
        yield 'presentations' => [Presentation::class, ...self::PRESENTATIONS];
        yield 'presentation groups' => [PresentationGroup::class, '/api/presentation-groups?locale=fr', 'presentation_groups_all_fr'];
        yield 'presentation read through its groups' => [Presentation::class, '/api/presentation-groups?locale=fr', 'presentation_groups_all_fr'];
        yield 'bannieres' => [Banniere::class, ...self::BANNIERES];
        yield 'bannieres statiques' => [BaniereStatique::class, '/api/baniere-statiques?locale=fr', 'bannieres_statiques_all_fr'];
        yield 'homeslider' => [HomeSlider::class, '/api/homeslider?locale=fr', 'homeslider_all_fr'];
        yield 'features' => [Feature::class, '/api/features?locale=fr', 'features_all_fr'];
        yield 'explore cards' => [ExploreCard::class, '/api/explore-cards?locale=fr', 'explore_cards_all_fr'];
        yield 'videos' => [Video::class, '/api/videos?locale=fr', 'videos_all_fr'];
        yield 'team' => [Team::class, '/api/team?locale=fr', 'teams_list_fr'];
        yield 'recherches' => [Recherche::class, '/api/recherches', 'recherches_all_en'];
        yield 'multiliens' => [Multilien::class, '/api/multiliens', 'multiliens_all'];
        yield 'marques' => [Marque::class, '/api/marques', 'marques_all'];
        yield 'categories de marque' => [CategorieMarque::class, '/api/categories-marque?locale=fr', 'categories_marque_all_fr'];
        yield 'marque read through the categories' => [Marque::class, '/api/categories-marque?locale=fr', 'categories_marque_all_fr'];
        yield 'emplois' => [Emploi::class, '/api/emplois', 'emplois_all_en'];
        yield 'service offers' => [ServiceOffer::class, '/api/service-offers', 'service_offers_all_en'];
        yield 'service family read through the offers' => [ServiceFamily::class, '/api/service-offers', 'service_offers_all_en'];
        yield 'reservation services' => [ServiceOffer::class, '/api/reservations/services', 'reservations_services_list'];
        yield 'vehicles' => [Vehicle::class, '/api/vehicles/carousel?locale=fr', 'vehicles_carousel_fr'];
    }

    /**
     * @dataProvider components
     * @param class-string $entityClass
     */
    public function testAWriteClearsItsComponentAndKeepsTheOthers(string $entityClass, string $route, string $key): void
    {
        [$witnessRoute, $witnessKey] = $entityClass === Presentation::class ? self::BANNIERES : self::PRESENTATIONS;
        $this->get($route);
        $this->assertTrue($this->cached($key), "$route est servi par le cache");
        $this->get($witnessRoute);
        $this->assertTrue($this->cached($witnessKey));

        $entity = $this->create($entityClass);
        $this->assertFalse($this->cached($key), "création d'un $entityClass : $route est vidé");
        $this->assertTrue($this->cached($witnessKey), "création d'un $entityClass : le témoin $witnessRoute reste en cache");

        $this->get($route);
        $this->assertTrue($this->cached($key));
        $em = $this->em();
        $em->remove($em->find($entityClass, $entity->getId()));
        $em->flush();
        $this->assertFalse($this->cached($key), "suppression d'un $entityClass : $route est vidé");
        $this->assertTrue($this->cached($witnessKey), "suppression d'un $entityClass : le témoin reste en cache");
        $this->endOfWrite();
    }

    public function testAnItemWriteKeepsTheOtherItemsAndClearsTheList(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $a = $this->presentation('Fiche A ' . $suffix);
        $b = $this->presentation('Fiche B ' . $suffix);
        [$route, $listKey] = self::PRESENTATIONS;
        $this->get($route);
        $this->get("/api/presentations/$a?locale=fr");
        $this->get("/api/presentations/$b?locale=fr");
        $this->assertTrue($this->cached($listKey) && $this->cached("presentation_{$a}_fr") && $this->cached("presentation_{$b}_fr"));

        $em = $this->em();
        $translation = $em->getRepository(PresentationTranslation::class)->findOneBy(['presentation' => $a, 'language' => 'fr']);
        $translation->setTitre('Fiche A modifiee ' . $suffix);
        $em->flush();
        $this->assertFalse($this->cached($listKey), 'traduction modifiée : la liste est vidée');
        $this->assertFalse($this->cached("presentation_{$a}_fr"), 'traduction modifiée : la fiche de son parent est vidée');
        $this->assertTrue($this->cached("presentation_{$b}_fr"), 'traduction modifiée : l\'autre fiche reste en cache');
        $this->assertStringContainsString('Fiche A modifiee ' . $suffix, $this->get("/api/presentations/$a?locale=fr"));
        $this->endOfWrite();
    }

    public function testTheLandingSettingsAreCachedUntilWritten(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $this->get('/api/landingpage-settings');
        $this->assertTrue($this->cached('landingpage_settings'));

        $em = $this->em();
        $setting = $em->getRepository(LandingPageSetting::class)->findOneBy([]) ?? new LandingPageSetting();
        $setting->setConfiguration(['cacheTest' => $suffix] + $setting->getConfiguration());
        $em->persist($setting);
        $em->flush();
        $this->assertFalse($this->cached('landingpage_settings'), 'réglages écrits : vidés');
        $this->assertStringContainsString($suffix, $this->get('/api/landingpage-settings'));
        $this->assertTrue($this->cached('landingpage_settings'));
        $this->endOfWrite();
    }

    public function testAnUnknownLocaleFallsBackToFrenchWithoutANewCacheEntry(): void
    {
        $this->get('/api/presentations?locale=xx');
        $this->assertTrue($this->cached('presentations_all_fr'));
        $this->assertFalse($this->cached('presentations_all_xx'));
    }

    private function get(string $route): string
    {
        $this->client->request('GET', $route, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 300));

        return (string) $this->client->getResponse()->getContent();
    }

    private function cached(string $key): bool
    {
        return static::getContainer()->get('cache.app')->hasItem(MV_TEST_TENANT_CODE . '.' . $key);
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

    private function presentation(string $title): int
    {
        $em = $this->em();
        $presentation = (new Presentation())->setTitre($title);
        $translation = (new PresentationTranslation())->setLanguage('fr')->setTitre($title)->setPresentation($presentation);
        $presentation->addTranslation($translation);
        $em->persist($presentation);
        $em->persist($translation);
        $em->flush();
        $this->endOfWrite();

        return $presentation->getId();
    }
}
