<?php

namespace App\Tests\Functional\Gestion;

use App\Gestion\Services\Conversation\Action\ActionCatalog;
use App\Gestion\Services\GestionBudgetService;
use App\Gestion\Services\GestionCashService;
use App\Gestion\Services\GestionFiscalYearService;
use App\Gestion\Services\GestionPriceService;
use App\Gestion\Services\GestionPriceSettingsService;
use App\Gestion\Services\GestionProductCostService;
use App\Gestion\Services\GestionTripService;
use App\Gestion\Services\MissingCostQuery;
use App\Gestion\Services\PeriodCalendar;
use App\Gestion\Services\PeriodGuard;
use App\Gestion\Services\PeriodResultCalculator;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\FournisseurService\FournisseurService;
use App\Services\FraisDePortService\FraisDePortService;
use App\Services\NoteDeFraisService\NoteDeFraisService;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\CollectionDashboardUseCase\CloseCollectionUseCase;
use App\UseCase\EntrepriseUsecase\UpdateEntrepriseUseCase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Garde-fou du périmètre du fil de la gestion (décision du propriétaire, 13/11/2026, en tête de `docs/conversation-gestion.md`) : le
 * catalogue d'actions sert EXCLUSIVEMENT l'application de gestion. Chaque action n'écrit que par le service de gestion déclaré ici pour
 * elle. Une action nouvelle, une dépendance nouvelle ou une écriture directe hors de ces listes fait échouer ce test : on n'allonge une
 * liste qu'après en avoir décidé avec le propriétaire (rien sur la description ou les photos d'un article, les comptes, les rôles,
 * les réservations, les livres, les biographes, la Boussole, le contenu du site ; le prix de vente et la mise en ligne d'un article,
 * eux, font partie de la gestion).
 */
class ActionCatalogScopeTest extends KernelTestCase
{
    /** Le service par lequel chaque action écrit : un service de la gestion (achats, trésorerie, périodes, exercice, coût d'achat) */
    private const WRITERS = [
        'expense.create' => [NoteDeFraisService::class],
        'supplier.create' => [FournisseurService::class],
        'shipping.create' => [FraisDePortService::class],
        'budget.put' => [GestionBudgetService::class],
        'cash.balance.create' => [GestionCashService::class],
        'cash.movement.create' => [GestionCashService::class],
        'trip.create' => [GestionTripService::class],
        'fiscal_year.set' => [GestionFiscalYearService::class],
        'periods.calendar.set' => [UpdateEntrepriseUseCase::class], // la fiche Entreprise, pour le seul découpage des périodes (vérifié plus bas)
        'product.cost.set' => [GestionProductCostService::class], // le coût d'achat seul, jamais le prix de vente
        'period.close' => [CloseCollectionUseCase::class],
        'price.set' => [GestionPriceService::class], // le prix de vente, par le service des prix du lot P4 (aperçu, lot, annulation) : lot IA-ACT-5
    ];

    /** Ce qu'une action peut utiliser sans écrire de donnée d'un autre module : lectures de la gestion, textes, devise, requête, journal */
    private const HELPERS = [TenantEntityManagerProvider::class, TranslatorInterface::class, TenantCurrencyProvider::class, PeriodCalendar::class, PeriodGuard::class,
        PeriodResultCalculator::class, MissingCostQuery::class, RequestStack::class, ContentAuditRecorder::class, GestionPriceSettingsService::class];

    /**
     * Écritures faites dans le code même d'une action, hors de son service : seulement l'annulation exacte d'une clôture, qu'aucun service
     * existant ne sait faire. Fichier => tables écrites en SQL.
     */
    private const DIRECT_WRITES = ['PeriodCloseAction.php' => ['product', 'commande_statistiques', 'collection_statistiques']];

    /**
     * Ce qu'aucune action du fil ne doit appeler ni nommer : description et photos d'un article, contenu du site, autres modules, comptes
     * et rôles. Le prix de vente et la mise en ligne d'un article SONT de la gestion (précision du propriétaire, 13/11/2026) : ils ne
     * sont pas ici, et une action qui les pose se déclare dans `WRITERS` comme les autres.
     */
    private const FORBIDDEN = ['LandingContent', 'MemoiresVivantes', 'App\\ESG', 'setRoles(', 'TeamRoleService', 'Booking', 'BiographerService', 'UserPasswordHasher',
        'setDescription(', 'setImage(', 'ProductImage', 'ProductPhoto', 'MediaLibrary'];

    public function testEveryActionOnlyWritesThroughItsManagementService(): void
    {
        self::bootKernel();
        $catalog = static::getContainer()->get(ActionCatalog::class);
        $this->assertSame(array_keys(self::WRITERS), $catalog->keys(), 'une action nouvelle se déclare ici, avec le service de gestion par lequel elle écrit');
        $dir = dirname((new \ReflectionClass(ActionCatalog::class))->getFileName());
        $files = [];
        foreach ($catalog->keys() as $key) {
            $reflection = new \ReflectionClass($catalog->get($key));
            $files[basename($reflection->getFileName())] = $key;
            $injected = array_map(fn (\ReflectionParameter $p) => (string) $p->getType(), $reflection->getConstructor()->getParameters());
            foreach ($injected as $class) {
                $this->assertContains(ltrim($class, '?'), array_merge(self::HELPERS, self::WRITERS[$key]), "$key : dépendance « $class » hors du périmètre de la gestion");
            }
            foreach (self::WRITERS[$key] as $writer) {
                $this->assertContains($writer, $injected, "$key : n'écrit plus par $writer ?");
            }
        }
        // Toutes les classes d'action du dossier sont au catalogue : pas d'action cachée
        $classes = array_map('basename', glob($dir . '/*Action.php'));
        $this->assertSame([], array_values(array_diff($classes, array_keys($files), ['AbstractGestionAction.php', 'GestionAction.php'])), 'une classe d\'action hors du catalogue');

        foreach (array_merge(array_keys($files), ['AbstractGestionAction.php']) as $file) {
            $source = (string) file_get_contents("$dir/$file");
            foreach (self::FORBIDDEN as $needle) {
                $this->assertStringNotContainsString($needle, $source, "$file : « $needle » sort du périmètre de la gestion");
            }
            // Écritures directes : aucune, sauf celles déclarées ; l'action passe par son service
            preg_match_all('/(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+"?([a-z_]+)"?\s/i', $source, $m);
            $tables = array_values(array_unique(array_map('strtolower', $m[1])));
            $this->assertSame([], array_values(array_diff($tables, self::DIRECT_WRITES[$file] ?? [])), "$file : écriture SQL directe hors de la liste admise");
            if (!isset(self::DIRECT_WRITES[$file])) {
                foreach (['->persist(', '->remove(', '->flush(', 'executeStatement(', 'beginTransaction('] as $write) {
                    $this->assertStringNotContainsString($write, $source, "$file : écriture directe (« $write ») : une action écrit par son service de gestion");
                }
            }
        }

        // La fiche Entreprise n'est écrite que pour le découpage des périodes
        $calendar = (string) file_get_contents("$dir/PeriodsCalendarSetAction.php");
        $this->assertSame(1, substr_count($calendar, '$this->update->execute('));
        $this->assertStringContainsString('$this->update->execute($entrepriseId, [\'purchasePeriodFrequency\' => $frequency], $host, \'fr\')', $calendar);
        // Le coût d'achat ne touche jamais au prix de vente : le service remet le prix tel qu'il était
        $cost = (string) file_get_contents((new \ReflectionClass(GestionProductCostService::class))->getFileName());
        $this->assertStringNotContainsString('setPrice(', $cost);
        $this->assertStringContainsString("setFieldValue(\$product, 'price', \$price)", $cost);
        $this->assertSame(1, substr_count($cost, '->setPurchasePrice('));
        // Le prix de vente d'un ensemble d'articles : seulement par le service des prix, jamais la visibilité ni la fiche
        $price = (string) file_get_contents("$dir/PriceSetAction.php");
        foreach (['setIsWeb(', 'setIsPos(', 'setPrice(', 'writeFields('] as $needle) {
            $this->assertStringNotContainsString($needle, $price, "PriceSetAction.php : « $needle » : l'action passe par l'aperçu, l'application et l'annulation du service des prix");
        }
        $this->assertSame([1, 1], [substr_count($price, '$this->prices->apply('), substr_count($price, '$this->prices->undo(')]);
    }
}
