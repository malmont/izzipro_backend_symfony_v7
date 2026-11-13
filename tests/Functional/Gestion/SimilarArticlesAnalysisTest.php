<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\Categories;
use App\Entity\User;
use App\Gestion\Analysis\CoefficientSuggestion;
use App\Gestion\Analysis\SimilarArticlesAnalysis;
use App\Gestion\Services\GestionPriceSettingsService;
use App\Gestion\Services\PeriodCalendar;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Coefficient proposé pour un article acheté (lot IA-ACT-5, 13/11/2026, `docs/prix-gestion.md` § 6), sans IA : l'analyse « un article
 * face à ses semblables » sur une saison terminée, chaque ligne de la règle sur des données réelles, puis le repli sur le coefficient
 * constaté chez le fournisseur et sur le réglage du site, avec les libellés chiffrés.
 */
class SimilarArticlesAnalysisTest extends WebTestCase
{
    use CacheTestEntityFactory;
    use PricingFixtures;

    private string $tag = '';
    private \DateTimeImmutable $today;
    /** @var array{start: \DateTimeImmutable, end: \DateTimeImmutable, key: string, name: string} */
    private array $season;

    protected function setUp(): void
    {
        static::createClient();
        $this->tag = bin2hex(random_bytes(3));
        $em = $this->em();
        $user = (new User())->setEmail("semblables-{$this->tag}@example.invalid")->setUsername("semblables-{$this->tag}")->setFirstname('Sem')->setLastname('Blables')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->fixtureUserId = (int) $user->getId();
        $calendar = static::getContainer()->get(PeriodCalendar::class);
        $this->today = $calendar->today();
        $this->season = PeriodCalendar::definition($this->today->modify('-1 year'), $calendar->frequency());
    }

    protected function tearDown(): void
    {
        $this->removePricingFixtures();
        $this->em()->getConnection()->executeStatement('UPDATE entreprise SET gestion_default_coefficient = NULL, gestion_min_coefficient = NULL, gestion_price_rounding = NULL');
        parent::tearDown();
    }

    public function testSimilarItemsOfAFinishedSeasonGiveTheCoefficientThenTheSupplierThenTheSite(): void
    {
        $tag = $this->tag;
        $bought = $this->season['start']->modify('+2 days')->format('Y-m-d');
        $sold = $this->season['start']->modify('+16 days')->format('Y-m-d'); // 14 jours après l'achat : 2 semaines
        $s1 = $this->supplier("Maison Semblables $tag");
        $category = (int) $this->create(Categories::class, ['name' => "Tuques $tag"])->getId();

        // A : coût 10,00, affichés à 2,5, 9 vendus sur 10 sans rabais → bien vendus, sans rabais
        $a = [];
        foreach (range(1, 6) as $i) {
            $a[] = $this->article($s1, "A$i $tag", 1000, 2500, $bought, 10, $i <= 5 ? [$category] : []);
            $this->sale($a[$i - 1][1], 9, 2500, 2500, $sold);
        }
        // B : coût 50,00, affichés à 2,5, 8 vendus sur 10 à 100,00 au lieu de 125,00 → bien vendus, avec 20 % de rabais
        foreach (range(1, 5) as $i) {
            $this->sale($this->article($s1, "B$i $tag", 5000, 12500, $bought)[1], 8, 10000, 12500, $sold);
        }
        // C : coût 200,00, affichés à 2,5, 2 vendus sur 10 sans rabais → mal vendus, sans rabais
        foreach (range(1, 5) as $i) {
            $this->sale($this->article($s1, "C$i $tag", 20000, 50000, $bought)[1], 2, 50000, 50000, $sold);
        }
        // D : coût 500,00, affichés à 1,7, 1 vendu sur 10 sans rabais → sous le coefficient minimum
        foreach (range(1, 5) as $i) {
            $this->sale($this->article($s1, "D$i $tag", 50000, 85000, $bought)[1], 1, 85000, 85000, $sold);
        }
        // E : coût 2 000,00, achetés et vendus CETTE saison → jamais des semblables (saison en cours exclue)
        $now = $this->today->modify('-1 day')->format('Y-m-d');
        foreach (range(1, 5) as $i) {
            $this->sale($this->article($s1, "E$i $tag", 200000, 500000, $now)[1], 9, 500000, 500000, $now);
        }
        $analysis = static::getContainer()->get(SimilarArticlesAnalysis::class);
        $suggestions = static::getContainer()->get(CoefficientSuggestion::class);
        $this->em();

        // L'analyse : même fournisseur, coût à 30 % près, saison terminée (la même période un an plus tôt)
        $r = $analysis->similar($s1, 1100, [], $this->today);
        $this->assertSame([$this->season['key'], $this->season['start']->format('Y-m-d'), $this->season['end']->format('Y-m-d')], [$r['season']['key'], $r['season']['from'], $r['season']['to']]);
        $this->assertSame(['supplierId' => $s1, 'cost' => 1100, 'costFrom' => 770, 'costTo' => 1430, 'categoryIds' => [], 'sameCategory' => false], $r['criteria']);
        $this->assertSame([6, 60, 54, 0.9, 2.0, 2.5, 2.5, 0.0, null], [$r['count'], $r['purchased'], $r['sold'], $r['soldShare'], $r['weeksToSell'], $r['displayedCoefficient'], $r['obtainedCoefficient'], $r['discountRate'], $r['more']]);
        $this->assertSame(['productId' => $a[0][0], 'name' => "A1 $tag", 'cost' => 1000, 'price' => 2500, 'purchased' => 10, 'sold' => 9, 'displayedCoefficient' => 2.5, 'obtainedCoefficient' => 2.5], $r['rows'][0]);
        // coût à 30 % près, bornes comprises : l'article à 10,00 est un semblable d'un article de 7,69 à 14,29
        $count = fn (int $cost): int => $analysis->similar($s1, $cost, [], $this->today)['count'];
        $this->assertSame([0, 6, 6, 0], [$count(768), $count(769), $count(1429), $count(1430)]);
        // même catégorie quand l'article en a une ; l'article lui-même n'est pas son propre semblable
        $withCategory = $analysis->similar($s1, 1100, [$category], $this->today);
        $this->assertSame([5, true, [$category]], [$withCategory['count'], $withCategory['criteria']['sameCategory'], $withCategory['criteria']['categoryIds']]);
        $this->assertSame(5, $analysis->similar($s1, 1100, [], $this->today, $a[0][0])['count']);
        // un autre fournisseur n'a pas ces semblables
        $this->assertSame(0, $analysis->similar($this->supplier("Autre maison $tag"), 1100, [], $this->today)['count']);

        // La règle, ligne par ligne, avec les chiffres dans le libellé
        $short = fn (array $s): array => [$s['proposed'], $s['source'], $s['options'][0]['figures']['adjustment'] ?? null, $s['options'][0]['label']];
        $this->assertSame([2.7, 'similar', 'up', '2,7 : 6 semblables de ce fournisseur affichés à 2,5, vendus à 90 % en 2 semaines pour les unités vendues, sans rabais (+0,2)'], $short($suggestions->suggest($s1, 1100, [], $this->today, 'fr')));
        $this->assertSame('2.7: 6 similar items from this supplier listed at 2.5, 90% sold in 2 weeks for the units sold, no discount (+0.2)', $suggestions->suggest($s1, 1100, [], $this->today, 'en')['options'][0]['label']);
        $this->assertSame('2,7 : 5 semblables affichés à 2,5, vendus à 90 % en 2 semaines pour les unités vendues, sans rabais (+0,2)', $suggestions->suggest($s1, 1100, [$category], $this->today, 'fr')['options'][0]['label'], 'article au catalogue : les trois critères, sans la mention du fournisseur');
        $this->assertSame([2.0, 'similar', 'obtained', '2 : 5 semblables de ce fournisseur affichés à 2,5, vendus à 80 % en 2 semaines pour les unités vendues, avec 20 % de rabais (coefficient obtenu)'], $short($suggestions->suggest($s1, 5000, [], $this->today, 'fr')));
        $this->assertSame([2.3, 'similar', 'down', '2,3 : 5 semblables de ce fournisseur affichés à 2,5, vendus à 20 % en 2 semaines pour les unités vendues, sans rabais (−0,2)'], $short($suggestions->suggest($s1, 20000, [], $this->today, 'fr')));
        $floored = $suggestions->suggest($s1, 50000, [], $this->today, 'fr');
        $this->assertSame([1.6, true, '1,6 : 5 semblables de ce fournisseur affichés à 1,7, vendus à 10 % en 2 semaines pour les unités vendues, sans rabais (−0,2) (relevé au coefficient minimum du site, 1,6)'],
            [$floored['proposed'], $floored['options'][0]['figures']['floored'], $floored['options'][0]['label']]);
        $this->assertSame(['similar', 'supplier', 'site'], array_column($floored['options'], 'source'), 'le gérant voit toutes les options qui existent, la première est proposée');

        // Bilan d'un article déjà en vente : son coefficient actuel face à la proposition, et le verdict CALCULÉ PAR LE SERVEUR
        $review = $suggestions->review($a[5][0], $this->today, 'fr'); // A6, sans catégorie : ses 5 autres semblables de la bande A
        $this->assertSame([['id' => $a[5][0], 'name' => "A6 $tag"], $s1, 1000, 2500, 2.5, ['value' => 2.7, 'source' => 'similar'], 'raise', 5],
            [$review['product'], $review['supplierId'], $review['cost'], $review['price'], $review['coefficient'], array_diff_key($review['proposal'], ['label' => 1]), $review['verdict'], $review['similar']['count']]);
        $this->assertStringStartsWith('2,7 : 5 semblables de ce fournisseur affichés à 2,5', $review['proposal']['label']);
        $this->em()->getConnection()->executeStatement('UPDATE product SET price = 2650 WHERE id = ?', [$a[5][0]]);
        $suggestions->reset();
        $this->assertSame([2.65, 'keep'], [$suggestions->review($a[5][0], $this->today, 'fr')['coefficient'], $suggestions->review($a[5][0], $this->today, 'fr')['verdict']], 'sous un demi-cran d\'écart : garder');
        $this->em()->getConnection()->executeStatement('UPDATE product SET price = 3200 WHERE id = ?', [$a[5][0]]);
        $suggestions->reset();
        $this->assertSame('lower', $suggestions->review($a[5][0], $this->today, 'fr')['verdict']);
        $this->em()->getConnection()->executeStatement('UPDATE product SET price = 2500 WHERE id = ?', [$a[5][0]]);
        $suggestions->reset();
        $this->assertNull($suggestions->review(2147483000, $this->today, 'fr'));

        // Un semblable JAMAIS vendu compte dans la part vendue (0 vendu), pas dans le coefficient obtenu ni le délai ; son prix actuel entre dans le coefficient affiché
        $this->article($s1, "B6 $tag", 5000, 15000, $bought);
        $suggestions->reset();
        $b = $analysis->similar($s1, 5000, [], $this->today);
        $this->assertSame([6, 60, 40, 0.6667, 2.0, 2.58, 2.0, 0.2], [$b['count'], $b['purchased'], $b['sold'], $b['soldShare'], $b['weeksToSell'], $b['displayedCoefficient'], $b['obtainedCoefficient'], $b['discountRate']]);
        $this->assertSame([2.58, 'similar', 'keep'], array_slice($short($suggestions->suggest($s1, 5000, [], $this->today, 'fr')), 0, 3), 'entre 40 % et 70 % : le coefficient affiché, inchangé, même avec des rabais');
        // Un retour est déduit des unités vendues
        $this->sale($a[0][1], 1, 2500, 2500, $sold, 2);
        $this->assertSame([53, 0.8833], [$analysis->similar($s1, 1100, [], $this->today)['sold'], $analysis->similar($s1, 1100, [], $this->today)['soldShare']]);

        // Saison en cours exclue : aucun semblable → le coefficient CONSTATÉ chez le fournisseur (27 articles : 64 ÷ 27 = 2,37)
        $suggestions->reset();
        $this->assertSame(0, $analysis->similar($s1, 200000, [], $this->today)['count']);
        $current = $suggestions->suggest($s1, 200000, [], $this->today, 'fr');
        $this->assertSame([2.37, 'supplier', '2,37 : constaté chez ce fournisseur, sur 27 articles', ['supplier', 'site']], [$current['proposed'], $current['source'], $current['options'][0]['label'], array_column($current['options'], 'source')]);
        $this->assertSame(['coefficient' => 2.37, 'articles' => 27], $analysis->supplierCoefficient($s1));

        // Moins de 5 semblables : pas de règle ; 5 articles chez le fournisseur → son coefficient constaté
        $s2 = $this->supplier("Petite maison $tag");
        foreach (range(1, 4) as $i) {
            $this->sale($this->article($s2, "P$i $tag", 1000, 2500, $bought)[1], 9, 2500, 2500, $sold);
        }
        $this->article($s2, "P5 $tag", 9000, 22500, $bought);
        $few = $suggestions->suggest($s2, 1000, [], $this->today, 'fr');
        $this->assertSame([4, 2.5, 'supplier', '2,5 : constaté chez ce fournisseur, sur 5 articles'], [$few['similar']['count'], $few['proposed'], $few['source'], $few['options'][0]['label']]);
        // Fournisseur sans assez d'historique (moins de 5 articles), fournisseur inconnu, coût inconnu : le réglage du site
        $s3 = $this->supplier("Nouvelle maison $tag");
        $this->article($s3, "N1 $tag", 1000, 4000, $bought);
        $this->article($s3, "N2 $tag", 1000, 4000, $bought);
        foreach ([[$s3, 1000], [null, 1000], [null, null]] as [$supplier, $cost]) {
            $site = $suggestions->suggest($supplier, $cost, [], $this->today, 'fr');
            $this->assertSame([2.0, 'site', '2 : le coefficient par défaut du site'], [$site['proposed'], $site['source'], $site['options'][0]['label']], json_encode([$supplier, $cost]));
        }
        $this->assertNull($suggestions->suggest(null, 1000, [], $this->today, 'fr')['similar']);
        $unknownCost = $suggestions->suggest($s1, null, [], $this->today, 'fr');
        $this->assertSame([null, 'supplier', 2.37], [$unknownCost['similar'], $unknownCost['source'], $unknownCost['proposed']], 'coût inconnu : pas de semblables, mais le fournisseur garde son coefficient constaté');

        // Les réglages du site : coefficient par défaut, et coefficient minimum sous lequel le serveur ne propose jamais
        $settings = static::getContainer()->get(GestionPriceSettingsService::class);
        $settings->put(['defaultCoefficient' => 3, 'minCoefficient' => 2.6]);
        $suggestions->reset();
        $this->assertSame([3.0, '3 : le coefficient par défaut du site'], [$suggestions->suggest($s3, 1000, [], $this->today, 'fr')['proposed'], $suggestions->suggest($s3, 1000, [], $this->today, 'fr')['options'][0]['label']]);
        $raised = $suggestions->suggest($s2, 1000, [], $this->today, 'fr');
        $this->assertSame([2.6, '2,6 : constaté chez ce fournisseur, sur 5 articles (relevé au coefficient minimum du site, 2,6)', ['articles' => 5, 'observedCoefficient' => 2.5, 'floored' => true]], [$raised['proposed'], $raised['options'][0]['label'], $raised['options'][0]['figures']]);
        $this->assertSame([2.6, true], [$suggestions->suggest($s1, 20000, [], $this->today, 'fr')['proposed'], $suggestions->suggest($s1, 20000, [], $this->today, 'fr')['options'][0]['figures']['floored']], 'la règle des semblables aussi suit le minimum du site');
    }

    // ---- outils --------------------------------------------------------------------------------------------------------------------

    protected function em(): EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }
}
