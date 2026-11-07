<?php

namespace App\Controller\Admin;

use App\Entity\ProductVariant;
use App\Entity\Size;
use App\Entity\Style;
use App\Entity\Cart;
use App\Entity\Order;
use App\Entity\Color;
use App\Entity\Carrier;
use App\Entity\Contact;
use App\Entity\Reservation;
use App\Entity\Product;
use App\Entity\Categories;
use App\Entity\HomeSlider;
use App\Entity\Collections;
use App\Entity\Commande;
use App\Entity\User;
use App\Entity\CollectionPicture;
use App\Entity\SquareConfig;
use App\Entity\NoteDeFrais;
use App\Entity\Fournisseur;
use App\Entity\Transporteur;
use App\Entity\FraisDePort;
use App\Entity\Adress;
use App\Entity\TransactionType;
use App\Entity\OrderItems;
use App\Entity\MovementType;
use App\Entity\PaymentMethod;
use App\Entity\OrderSource;
use App\Entity\StatusPayment;
use App\Entity\Caisse;
use App\Entity\TransactionCaisse;
use App\Entity\TypeNoteDeFrais;
use App\Entity\TypeFournisseur;
use App\Entity\InventoryMovements;
use App\Entity\Payments;
use App\Entity\StatusCommande;
use App\Entity\Tax;
use App\Entity\OrderTax;
use App\Entity\OrderType;
use App\Entity\AdminSettings;
use App\Entity\BoutiqueSetting;
use App\Entity\Subscription;
use App\Entity\SubscriptionPlan;
use App\Entity\Entreprise;
use App\Entity\SocialNetwork;
use App\Entity\PaymentType;
use App\Entity\CashDetails;
use App\Entity\Denomination;
use App\Entity\TypeCash;
use App\Entity\Feature;
use App\Entity\ExploreCard;
use App\Entity\PackagingType;
use App\Entity\ProductShipping;
use App\Entity\EasyPostConfiguration;
use App\Entity\ShippingClass;
use App\Entity\GooglePlacesConfig;
use App\Entity\ShippingLabel;
use App\Entity\ShippingOrder;
use App\Entity\Parcel;
use App\Entity\AddressEntreprise;
use App\Entity\ProductOption;
use App\Entity\ProductOptionValue;
use App\Entity\LandingPageSetting;
use App\Entity\Presentation;
use App\Entity\ProductType;
use App\Entity\PresentationGroup;
use App\Entity\BaniereStatiqueTranslation;
use App\Entity\StripeConfig;
use App\Entity\Currency;
use App\Controller\Admin\StripeConfigCrudController;
use App\Entity\SharedMedia;
use App\Entity\AiUsage;
use App\Entity\AiCreditSetting;
use App\Controller\Admin\SharedMediaCrudController;



use App\Controller\Admin\OrderAllCrudController;
use App\Controller\Admin\OrderCrudController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\ColorScheme;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use App\Entity\EmailConfiguration;
use App\Entity\Team;
use App\Entity\Banniere;
use App\Entity\Emploi;
use App\Entity\Candidature;
use App\Entity\Marque;
use App\Entity\CategorieMarque;
use App\Entity\Multilien;
use App\Entity\Recherche;
use App\Entity\ServiceOffer;
use App\Entity\Video;
use App\Entity\Embed;
use App\Entity\BaniereStatique;
use App\Entity\NewsletterSubscriber;
use App\Entity\BookingConfiguration;
use App\Entity\Booking;
use App\Entity\RentalPack;
use App\Entity\SaleUnit;
use App\Entity\Vehicle;
use App\Controller\Admin\RentalPackCrudController;
use App\Controller\Admin\SaleUnitCrudController;
use App\Controller\Admin\VehicleCrudController;
use App\Controller\Admin\ProductVariantCrudController;




use App\Services\TenantEntityManagerProvider;

use App\ESG\Entity\DiagnosticQuestion;
use App\ESG\Entity\CertificationReferential;
use App\ESG\Entity\SubsidyProgram;
use App\ESG\Entity\CertificationRecommendation;
use App\ESG\Entity\OddMapping;

use App\Controller\Admin\ESG\DiagnosticQuestionCrudController;
use App\Controller\Admin\ESG\CertificationReferentialCrudController;
use App\Controller\Admin\ESG\SubsidyProgramCrudController;
use App\Controller\Admin\ESG\CertificationRecommendationCrudController;
use App\Controller\Admin\ESG\OddMappingCrudController;

use App\MemoiresVivantes\Entity\MemoireQuestion;
use App\MemoiresVivantes\Entity\Book as MemoireBook;
use App\MemoiresVivantes\Entity\Chapter as MemoireChapter;
use App\Controller\Admin\Memoires\MemoireQuestionCrudController;
use App\Controller\Admin\Memoires\BookCrudController as MemoireBookCrudController;
use App\Controller\Admin\Memoires\ChapterCrudController as MemoireChapterCrudController;
use App\MemoiresVivantes\Entity\BookPrintOrder;
use App\Controller\Admin\Memoires\BookPrintOrderCrudController;

use App\Services\TenantConnectionManager;
use App\Services\TenantVisitorTrackingService;
use Symfony\Component\HttpFoundation\RequestStack;

class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private TenantEntityManagerProvider $emProvider,
        private TenantConnectionManager $tenantConnectionManager,
        private TenantVisitorTrackingService $visitorTrackingService,
        private RequestStack $requestStack,
        private \App\Services\CaisseService\StoreClock $storeClock,
        private readonly ?\App\Services\Platform\SiteReadinessService $readiness = null, // cheminement de préparation du site (05/11/2026)
        private readonly ?\EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator $adminUrls = null,
        private readonly ?\App\Services\OrderService\OrderDeskService $orderDesk = null, // commandes à traiter (lot 4, 07/11/2026)
        private readonly ?\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface $csrf = null, // console IA flottante (07/11/2026)
        private readonly ?\App\Services\SiteAssistantService\SiteAssistantService $assistant = null
    ) {}

    #[Route('/admin', name: 'admin')]
    public function index(): Response
    {
        $em = $this->emProvider->getEntityManager();

        // Journées et mois dans le fuseau du magasin (réglage de la caisse, sinon pays et province), bornes converties en UTC pour la base
        $tz = $this->storeClock->timezone();
        $utc = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $tz);
        $today = $now->setTime(0, 0);
        $startThisMonth = $today->modify('first day of this month');
        $startNextMonth = $startThisMonth->modify('+1 month');
        $startLastMonth = $startThisMonth->modify('-1 month');

        // Commandes comptées : toutes sauf « Incomplete » (paiement jamais finalisé) et « Annulation » ; les retours (montant négatif) restent déduits
        $excludedStatuses = [];
        try {
            $excludedStatuses = array_map('intval', $em->getConnection()->fetchFirstColumn("SELECT id FROM status_commande WHERE name IN ('Incomplete', 'Annulation')"));
        } catch (\Throwable) {
        }
        $valid = $excludedStatuses === [] ? '1 = 1' : '(o.status IS NULL OR IDENTITY(o.status) NOT IN (' . implode(',', $excludedStatuses) . '))';
        $between = fn (\DateTimeImmutable $start, \DateTimeImmutable $end) => ['start' => $start->setTimezone($utc), 'end' => $end->setTimezone($utc)];
        $sumBetween = function (\DateTimeImmutable $start, \DateTimeImmutable $end) use ($em, $valid, $between): float {
            return ((float) ($em->createQuery("SELECT COALESCE(SUM(o.totalAmount), 0) FROM App\Entity\Order o WHERE $valid AND o.orderDate >= :start AND o.orderDate < :end")
                ->setParameters($between($start, $end))->getSingleScalarResult() ?? 0)) / 100.0;
        };
        $countBetween = function (\DateTimeImmutable $start, \DateTimeImmutable $end) use ($em, $valid, $between): int {
            return (int) ($em->createQuery("SELECT COUNT(o.id) FROM App\Entity\Order o WHERE $valid AND o.orderDate >= :start AND o.orderDate < :end")
                ->setParameters($between($start, $end))->getSingleScalarResult() ?? 0);
        };

        // 1. Chiffre d'affaires et commandes (total, mois courant, mois précédent)
        $totalSales = 0.0;
        $salesThisMonth = 0.0;
        $salesLastMonth = 0.0;
        $totalOrders = 0;
        $ordersThisMonth = 0;
        $ordersLastMonth = 0;
        try {
            $totalSales = ((float) ($em->createQuery("SELECT COALESCE(SUM(o.totalAmount), 0) FROM App\Entity\Order o WHERE $valid")->getSingleScalarResult() ?? 0)) / 100.0;
            $totalOrders = (int) ($em->createQuery("SELECT COUNT(o.id) FROM App\Entity\Order o WHERE $valid")->getSingleScalarResult() ?? 0);
            $salesThisMonth = $sumBetween($startThisMonth, $startNextMonth);
            $salesLastMonth = $sumBetween($startLastMonth, $startThisMonth);
            $ordersThisMonth = $countBetween($startThisMonth, $startNextMonth);
            $ordersLastMonth = $countBetween($startLastMonth, $startThisMonth);
        } catch (\Exception $e) {
            // tables absentes sur ce site : zéros
        }

        $salesDiffPercent = $salesLastMonth > 0 ? round((($salesThisMonth - $salesLastMonth) / $salesLastMonth) * 100, 1) : 0;
        $ordersDiffPercent = $ordersLastMonth > 0 ? round((($ordersThisMonth - $ordersLastMonth) / $ordersLastMonth) * 100, 1) : 0;

        $avgOrderValue = $totalOrders > 0 ? ($totalSales / $totalOrders) : 0.0;
        $avgThisMonth = $ordersThisMonth > 0 ? ($salesThisMonth / $ordersThisMonth) : 0.0;
        $avgLastMonth = $ordersLastMonth > 0 ? ($salesLastMonth / $ordersLastMonth) : 0.0;
        $avgDiffPercent = $avgLastMonth > 0 ? round((($avgThisMonth - $avgLastMonth) / $avgLastMonth) * 100, 1) : 0;

        // 2. Clients et taxes (taxes des seules commandes comptées)
        $totalCustomers = 0;
        $totalTaxes = 0.0;
        try {
            $totalCustomers = (int) ($em->createQuery('SELECT COUNT(u.id) FROM App\Entity\User u')->getSingleScalarResult() ?? 0);
            $totalTaxes = ((float) ($em->createQuery("SELECT COALESCE(SUM(ot.amount), 0) FROM App\Entity\OrderTax ot JOIN ot.orderTax o WHERE $valid")->getSingleScalarResult() ?? 0)) / 100.0;
        } catch (\Exception $e) {
            // fallback
        }

        // 3. Stock Metrics & Breakdown (Vehicles vs Products/Accessories)
        $stockValue = 0.0;
        $vehicleStockValue = 0.0;
        $productStockValue = 0.0;
        $outOfStockCount = 0;
        $lowStockCount = 0;
        try {
            $rawStockValue = (float) ($em->createQuery('SELECT SUM(p.price * p.quantity) FROM App\Entity\Product p WHERE p.quantity > 0 AND p.price IS NOT NULL')->getSingleScalarResult() ?? 0);
            $stockValue = $rawStockValue / 100.0;

            $rawVehicleStock = (float) ($em->createQuery('SELECT SUM(p.price * p.quantity) FROM App\Entity\Product p WHERE p.quantity > 0 AND p.price IS NOT NULL AND EXISTS (SELECT v.id FROM App\Entity\Vehicle v WHERE v.product = p)')->getSingleScalarResult() ?? 0);
            $vehicleStockValue = $rawVehicleStock / 100.0;

            $productStockValue = max(0.0, $stockValue - $vehicleStockValue);

            $outOfStockCount = (int) ($em->createQuery('SELECT COUNT(p.id) FROM App\Entity\Product p WHERE p.quantity <= 0 OR p.quantity IS NULL')->getSingleScalarResult() ?? 0);
            $lowStockCount = (int) ($em->createQuery('SELECT COUNT(p.id) FROM App\Entity\Product p WHERE p.quantity > 0 AND p.quantity <= 5')->getSingleScalarResult() ?? 0);
        } catch (\Exception $e) {
            // fallback
        }

        // 4. Courbes des ventes : 7 jours, semaines du mois (le mois entier, 1 au 7 … 29 au 31), mois de l'année
        $labels7Days = [];
        $values7Days = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = $today->modify("-$i days");
            $labels7Days[] = $day->format('d M');
            try {
                $values7Days[] = round($sumBetween($day, $day->modify('+1 day')), 2);
            } catch (\Exception $e) {
                $values7Days[] = 0.0;
            }
        }

        $labelsMonth = [];
        $valuesMonth = [];
        for ($wStart = $startThisMonth; $wStart < $startNextMonth; $wStart = $wStart->modify('+7 days')) {
            $wEnd = min($wStart->modify('+7 days'), $startNextMonth);
            $labelsMonth[] = sprintf('%d au %d', (int) $wStart->format('j'), (int) $wEnd->modify('-1 day')->format('j'));
            try {
                $valuesMonth[] = round($sumBetween($wStart, $wEnd), 2);
            } catch (\Exception $e) {
                $valuesMonth[] = 0.0;
            }
        }

        $labelsYear = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];
        $valuesYear = array_fill(0, 12, 0.0);
        try {
            $currentYear = (int) $now->format('Y');
            for ($m = 1; $m <= 12; $m++) {
                $mStart = new \DateTimeImmutable(sprintf('%d-%02d-01 00:00:00', $currentYear, $m), $tz);
                $valuesYear[$m - 1] = round($sumBetween($mStart, $mStart->modify('+1 month')), 2);
            }
        } catch (\Exception $e) {}

        // 5. Modes de paiement : encaissements client terminés, par mode, cumul en dollars (remboursements exclus)
        $paymentLabels = [];
        $paymentSeries = [];
        try {
            $rows = $em->createQuery("SELECT pm.name AS label, SUM(p.amount) AS total FROM App\Entity\Payments p LEFT JOIN p.paymentMethod pm LEFT JOIN p.paymentType pt LEFT JOIN p.statutPayment sp
                WHERE p.amount > 0 AND (pt.id IS NULL OR pt.name = 'PaiementClient') AND (sp.id IS NULL OR sp.name = 'Complété') GROUP BY pm.name ORDER BY total DESC")->getArrayResult();
            foreach ($rows as $row) {
                $paymentLabels[] = $row['label'] ?: 'Non précisé';
                $paymentSeries[] = round(((float) $row['total']) / 100.0, 2);
            }
        } catch (\Exception $e) {
            // fallback : aucun graphique
        }

        // 6. Transporteurs : commandes comptées, par transporteur (sans transporteur : retrait ou vente en caisse)
        $carrierLabels = [];
        $carrierSeries = [];
        try {
            $rows = $em->createQuery("SELECT c.name AS label, COUNT(o.id) AS n FROM App\Entity\Order o LEFT JOIN o.carrier c WHERE $valid GROUP BY c.name ORDER BY n DESC")->getArrayResult();
            foreach ($rows as $row) {
                $carrierLabels[] = $row['label'] ?: 'Sans livraison (retrait ou caisse)';
                $carrierSeries[] = (int) $row['n'];
            }
        } catch (\Exception $e) {
            // fallback : aucun graphique
        }

        // 7. Recent Orders (optimized query with user eager join to prevent N+1)
        $recentOrders = [];
        try {
            $recentOrders = $em->createQuery('SELECT o, u FROM App\Entity\Order o LEFT JOIN o.userId u ORDER BY o.orderDate DESC')
                ->setMaxResults(5)
                ->getResult();
        } catch (\Exception $e) {
            // fallback
        }

        // 8. Détection du Tenant Actif & Statistiques de Fréquentation ciblées
        $request = $this->requestStack->getCurrentRequest();
        $currentTenantCode = $this->tenantConnectionManager->getCurrentTenantCode();

        if (!$currentTenantCode && $request) {
            $host = $request->headers->get('X-Tenant-Host') ?: $request->getHost();
            $tenantConfig = $this->tenantConnectionManager->findTenantConfigByHost($host);
            if ($tenantConfig) {
                $currentTenantCode = $tenantConfig->getCode();
            }
        }

        if (!$currentTenantCode && $request) {
            $cleanHost = explode(':', $request->getHost())[0];
            $parts = explode('.', $cleanHost);
            if (!empty($parts[0]) && !in_array($parts[0], ['api', 'admin', 'backend', 'localhost', 'www'], true)) {
                $currentTenantCode = preg_replace('/-v2$/i', '', $parts[0]);
            }
        }

        $currentTenantCode = $currentTenantCode ?: 'lintendantprive';

        $tenants = [];
        try {
            $tenants = $this->tenantConnectionManager->getAllTenants();
        } catch (\Throwable $e) {}

        $currentTenantData = null;
        foreach ($tenants as $t) {
            if ($t['code'] === $currentTenantCode) {
                $currentTenantData = $t;
                break;
            }
        }

        $tenantName = $currentTenantData['name'] ?? ucfirst($currentTenantCode);
        $tenantDomain = $currentTenantData['custom_domain'] ?? ($request ? $request->getHost() : "{$currentTenantCode}.com");

        // Rapport analytique dédié au tenant courant
        $tenantReport = $this->visitorTrackingService->getTenantDetailedReport(
            $currentTenantCode,
            $tenantName,
            $tenantDomain
        );

        // Métriques de tous les sites : réservées au propriétaire de la plateforme (un administrateur de site ne voit que le sien)
        $trafficMetrics = $this->isGranted('ROLE_SUPER_ADMIN') ? $this->visitorTrackingService->getGlobalTrafficMetrics($tenants) : null;

        $readiness = null;
        try {
            $readiness = $this->readiness?->forCurrentSite();
        } catch (\Throwable) {
        }
        $monSiteUrl = null;
        try {
            $monSiteUrl = $this->adminUrls?->unsetAll()->setRoute('admin_mon_site')->generateUrl();
        } catch (\Throwable) {
        }

        $ordersTodo = null;
        $ordersTodoUrl = null;
        try {
            $ordersTodo = $this->orderDesk?->todo(50)['counts'] ?? null;
            $ordersTodoUrl = $this->adminUrls?->unsetAll()->setRoute('admin_orders_todo')->generateUrl();
        } catch (\Throwable) {
        }

        return $this->render('admin/dashboard.html.twig', [
            'ordersTodo' => $ordersTodo,
            'ordersTodoUrl' => $ordersTodoUrl,
            'readiness' => $readiness,
            'monSiteUrl' => $monSiteUrl,
            'tenantReport' => $tenantReport,
            'storeTimezone' => $tz->getName(),
            'currentTenantCode' => $currentTenantCode,
            'trafficMetrics' => $trafficMetrics,
            'totalSales' => $totalSales,
            'salesThisMonth' => $salesThisMonth,
            'salesDiffPercent' => $salesDiffPercent,
            'totalOrders' => $totalOrders,
            'ordersThisMonth' => $ordersThisMonth,
            'ordersDiffPercent' => $ordersDiffPercent,
            'avgOrderValue' => $avgOrderValue,
            'avgDiffPercent' => $avgDiffPercent,
            'totalCustomers' => $totalCustomers,
            'totalTaxes' => $totalTaxes,
            'stockValue' => $stockValue,
            'vehicleStockValue' => $vehicleStockValue,
            'productStockValue' => $productStockValue,
            'outOfStockCount' => $outOfStockCount,
            'lowStockCount' => $lowStockCount,
            'labels7Days' => json_encode($labels7Days),
            'values7Days' => json_encode($values7Days),
            'labelsMonth' => json_encode($labelsMonth),
            'valuesMonth' => json_encode($valuesMonth),
            'labelsYear' => json_encode($labelsYear),
            'valuesYear' => json_encode($valuesYear),
            'paymentLabels' => json_encode($paymentLabels),
            'paymentSeries' => json_encode($paymentSeries),
            'carrierLabels' => json_encode($carrierLabels),
            'carrierSeries' => json_encode($carrierSeries),
            'recentOrders' => $recentOrders,
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('<img src="/assets/logo-arkanoa-media.png" alt="Arkanoa Media" style="max-height: 65px !important; max-width: 230px !important; width: auto !important; height: auto !important; object-fit: contain;">')
            ->setFaviconPath('assets/arkanoa-favicon.png')
            ->setDefaultColorScheme(ColorScheme::DARK);
    }

    public function configureAssets(): Assets
    {
        return parent::configureAssets()
            ->addHtmlContentToHead('
                <link rel="preconnect" href="https://fonts.googleapis.com">
                <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
                <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Michroma&family=Outfit:wght@300;400;500;600;700&display=swap">
            ')
            ->addCssFile('assets/css/admin-arkanoa.css?v=' . $this->assetVersion('assets/css/admin-arkanoa.css'))
            ->addJsFile('assets/js/admin-arkanoa.js?v=' . $this->assetVersion('assets/js/admin-arkanoa.js'))
            ->addJsFile('assets/js/admin-assistant.js?v=' . $this->assetVersion('assets/js/admin-assistant.js'))
            ->addHtmlContentToBody($this->assistantConsoleHtml());
    }

    /**
     * Console IA flottante (07/11/2026) : présente sur toutes les pages EasyAdmin pour un administrateur du site ; même assistant et mêmes
     * routes que le panneau de « Mon site : pilotage » (jeton CSRF `admin_mon_site`). Rien pour un utilisateur sans ROLE_ADMIN.
     */
    private function assistantConsoleHtml(): string
    {
        try {
            if ($this->csrf === null || !$this->isGranted('ROLE_ADMIN')) {
                return '';
            }
            $code = (string) ($this->tenantConnectionManager->getCurrentTenantCode() ?? '');
            $tier = $this->assistant?->tier($code, $this->isGranted('ROLE_SUPER_ADMIN')) ?? 'full';
            if ($tier === 'none') {
                return '';
            }

            return sprintf('<div id="arkanoa-assistant" data-chat="%s" data-confirm="%s" data-token="%s" data-tier="%s" data-site="%s"></div>',
                htmlspecialchars($this->generateUrl('admin_mon_site_assistant'), ENT_QUOTES), htmlspecialchars($this->generateUrl('admin_mon_site_assistant_confirm', ['id' => '0000000000000000']), ENT_QUOTES),
                htmlspecialchars($this->csrf->getToken('admin_mon_site')->getValue(), ENT_QUOTES), $tier, htmlspecialchars($code, ENT_QUOTES));
        } catch (\Throwable) {
            return '';
        }
    }

    /** Date de modification du fichier en suffixe : nginx fait garder les fichiers statiques 30 jours au navigateur, une version changée force le rechargement */
    private function assetVersion(string $publicPath): string
    {
        return (string) (@filemtime($this->getParameter('kernel.project_dir') . '/public/' . $publicPath) ?: 1);
    }

    /** « Avis clients », avec le nombre d'avis en attente de modération */
    private function reviewsMenuItem(): \EasyCorp\Bundle\EasyAdminBundle\Config\Menu\CrudMenuItem
    {
        $item = MenuItem::linkToCrud('Avis clients', 'fas fa-star-half-alt', \App\Entity\ReviewsProduct::class)->setController(ReviewCrudController::class);
        try {
            $pending = $this->emProvider->getEntityManager()->getRepository(\App\Entity\ReviewsProduct::class)->countPending();
            if ($pending > 0) {
                $item->setBadge($pending, 'warning');
            }
        } catch (\Throwable) {
            // tables pas encore migrées sur ce site : menu sans compteur
        }

        return $item;
    }

    /** Icône de chaque groupe du menu (sections repliables, 31/10/2026) */
    private const MENU_GROUP_ICONS = [
        'Maintenance' => 'fas fa-tools', 'UI' => 'fas fa-palette', 'Landing Page' => 'fas fa-window-maximize', 'Médiathèque & Partage' => 'fas fa-photo-video',
        'Entreprise' => 'fa fa-building', 'Livraison' => 'fas fa-truck', 'IIZIMANAGER' => 'fas fa-briefcase', 'Réservations & Clients' => 'fas fa-users',
        'Mouvements stock' => 'fas fa-dolly', 'Caisse' => 'fas fa-cash-register', 'Order' => 'fas fa-shopping-bag', 'Payment' => 'fas fa-credit-card',
        'Taxe' => 'fas fa-percent', 'Catalogue & Véhicules' => 'fas fa-boxes', 'Location / Réservation' => 'fas fa-calendar-check',
        'Boussole ESG' => 'fas fa-compass', 'Mémoires Vivantes' => 'fas fa-book-open',
    ];

    /**
     * Menu de gauche : le tableau de bord, puis chaque section de menuEntries() devient un groupe repliable (flèche),
     * ouvert seulement s'il contient la page courante ; les groupes ouverts à la main sont mémorisés par admin-arkanoa.js.
     */
    public function configureMenuItems(): iterable
    {
        $group = null;
        $items = [];
        $flush = function () use (&$group, &$items) {
            if ($group === null) {
                return null;
            }
            $label = (string) $group->getAsDto()->getLabel();
            $subMenu = MenuItem::subMenu($label, self::MENU_GROUP_ICONS[$label] ?? 'fas fa-folder')->setSubItems($items);
            $group = null;
            $items = [];

            return $subMenu;
        };
        foreach ($this->menuEntries() as $entry) {
            if ($entry instanceof \EasyCorp\Bundle\EasyAdminBundle\Config\Menu\SectionMenuItem) {
                if (($done = $flush()) !== null) {
                    yield $done;
                }
                $group = $entry;
            } elseif ($group === null) {
                yield $entry;
            } else {
                $items[] = $entry;
            }
        }
        if (($done = $flush()) !== null) {
            yield $done;
        }
    }

    /** Entrées du menu à plat : une section (`MenuItem::section`) ouvre un groupe, les liens qui suivent en font partie */
    private function menuEntries(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');
        
        yield MenuItem::section('Maintenance');
        // Cache Redis partagé par tous les sites : propriétaire de la plateforme seulement (03/11/2026)
        yield MenuItem::linkToRoute('Vider cache Redis', 'fas fa-trash-alt', 'admin_redis_flush')
            ->setPermission('ROLE_SUPER_ADMIN');
        // Workers communs à tous les sites : propriétaire de la plateforme seulement
        yield MenuItem::linkToRoute('Workers (tâches de fond)', 'fas fa-heartbeat', 'admin_workers')
            ->setPermission('ROLE_SUPER_ADMIN');
        // Mesures techniques de tous les sites (ressources consommées, PHP-FPM, sauvegardes) : propriétaire de la plateforme seulement
        yield MenuItem::linkToRoute('Tableau de bord technique', 'fas fa-chart-line', 'admin_technique')
            ->setPermission('ROLE_SUPER_ADMIN');
        // Compte du propriétaire de la plateforme sur toutes les bases (03/11/2026)
        yield MenuItem::linkToRoute('Compte super administrateur', 'fas fa-user-shield', 'admin_super_admin')
            ->setPermission('ROLE_SUPER_ADMIN');
        // Console de pilotage de la plateforme : mode, droits d'usage de tous les sites, politiques, offres, demandes (lot 4, 04/11/2026)
        yield MenuItem::linkToRoute('Plateforme : pilotage', 'fas fa-sliders-h', 'admin_plateforme')
            ->setPermission('ROLE_SUPER_ADMIN');
        
        yield MenuItem::section('UI');
        yield MenuItem::linkToCrud('Home Slider', 'fas fa-images', HomeSlider::class);
        yield MenuItem::linkToCrud('Admin Settings', 'fas fa-cogs', AdminSettings::class);
        yield MenuItem::linkToCrud('Configuration boutique réglable', 'fas fa-store', BoutiqueSetting::class);
        yield MenuItem::linkToCrud('Formules d\'abonnement', 'fas fa-sync-alt', SubscriptionPlan::class);
        yield MenuItem::linkToCrud('Abonnements', 'fas fa-id-card', Subscription::class);
        yield MenuItem::linkToCrud('Features', 'fa fa-star', Feature::class);
        yield MenuItem::linkToCrud('Explore Cards', 'fa fa-th-large', ExploreCard::class);

        yield MenuItem::section('Landing Page');


        yield MenuItem::linkToCrud('Bannières', 'fas fa-image', Banniere::class);
        yield MenuItem::linkToCrud('Offres d\'emploi', 'fas fa-briefcase', Emploi::class);
        yield MenuItem::linkToCrud('Candidatures', 'fas fa-file-alt', Candidature::class);
        yield MenuItem::linkToCrud('Marques', 'fas fa-tags', Marque::class);
        yield MenuItem::linkToCrud('Catégories de Marque', 'fas fa-sitemap', CategorieMarque::class);
        yield MenuItem::linkToCrud('Multiliens', 'fas fa-link', Multilien::class);
        yield MenuItem::linkToCrud('Section Recherche', 'fas fa-search', Recherche::class);
        yield MenuItem::linkToCrud('Offres de Service', 'fas fa-concierge-bell', ServiceOffer::class);
        yield MenuItem::linkToCrud('Familles de prestations', 'fas fa-layer-group', \App\Entity\ServiceFamily::class);
        yield MenuItem::linkToCrud('Vidéos', 'fas fa-video', Video::class);
        yield MenuItem::linkToCrud('Contenus Intégrés', 'fas fa-code', Embed::class);
        yield MenuItem::linkToCrud('Configuration', 'fas fa-cogs', LandingPageSetting::class);
        yield MenuItem::linkToCrud('Bannières Statiques', 'fas fa-image', BaniereStatique::class);
        yield MenuItem::linkToCrud('Présentations', 'fas fa-columns', Presentation::class);
        yield MenuItem::linkToCrud('Groupes de Présentation', 'fas fa-columns', PresentationGroup::class);
        yield MenuItem::linkToCrud('Assistant IA : historique', 'fas fa-robot', AiUsage::class);
        yield MenuItem::linkToCrud('Assistant IA : crédits', 'fas fa-coins', AiCreditSetting::class);

        yield MenuItem::section('Médiathèque & Partage');
        yield MenuItem::linkToCrud('Partage de Fichiers & Médias', 'fas fa-share-alt', SharedMedia::class)
            ->setController(SharedMediaCrudController::class);



        yield MenuItem::section('Entreprise');
        // Console de pilotage du site : localisation, modules et droits, commerce, services (lot 5, 04/11/2026)
        yield MenuItem::linkToRoute('Mon site : pilotage', 'fas fa-sliders-h', 'admin_mon_site');
        yield MenuItem::linkToRoute('Configuration : Stripe, taxes, courriels, avis', 'fas fa-tasks', 'admin_configuration'); // cheminements de configuration (lot 5, 07/11/2026)
        yield MenuItem::linkToCrud('Entreprise', 'fa fa-building', Entreprise::class);
        yield MenuItem::linkToCrud('Profils des applications', 'fas fa-id-card', \App\Entity\AppProfile::class)->setController(AppProfileCrudController::class);
        yield MenuItem::linkToRoute('Apparence de l\'app mobile', 'fas fa-mobile-alt', 'admin_mobile_theme'); // thème de l'application e-commerce mobile (31/10/2026)
        yield MenuItem::linkToRoute('Code court de la boutique', 'fas fa-hashtag', 'admin_short_code'); // code tapé dans l'application mobile (31/10/2026)
        yield MenuItem::linkToCrud('AddressEntreprise', 'fa fa-map-marker', AddressEntreprise::class);
        yield MenuItem::linkToCrud('Réseaux Sociaux', 'fa fa-share-alt', SocialNetwork::class);
        yield MenuItem::linkToCrud('Équipes', 'fa fa-users', Team::class);

        yield MenuItem::section('Livraison');
        yield MenuItem::linkToRoute('Livraison : réglages et cheminement', 'fas fa-truck-loading', 'admin_livraison'); // lot 1 (07/11/2026)
        yield MenuItem::linkToCrud('Transporteurs et retrait', 'fas fa-truck', Carrier::class);
        yield MenuItem::linkToCrud('Poids et dimensions des produits', 'fas fa-weight-hanging', ProductShipping::class);
        yield MenuItem::linkToCrud('Gabarits d\'emballage', 'fas fa-box', PackagingType::class);
        yield MenuItem::linkToCrud('Classes d\'expédition', 'fas fa-tags', ShippingClass::class);
        yield MenuItem::linkToCrud('Clés EasyPost', 'fa fa-cogs', EasyPostConfiguration::class);
        yield MenuItem::linkToCrud('Adresses (Google Places)', 'fa fa-map-marker', GooglePlacesConfig::class)
            ->setController(GooglePlacesConfigCrudController::class);
        yield MenuItem::linkToCrud('Expéditions (étiquettes achetées)', 'fas fa-shipping-fast', ShippingOrder::class);
        yield MenuItem::linkToCrud('Colis', 'fas fa-box', Parcel::class);
        yield MenuItem::linkToCrud('Étiquettes', 'fas fa-tag', ShippingLabel::class);

        yield MenuItem::section('IIZIMANAGER');
        yield MenuItem::linkToRoute('Exports comptables (caisse)', 'fas fa-file-csv', 'admin_pos_exports'); // F14 (29/10/2026)
        yield MenuItem::linkToRoute('Archive annuelle (caisse)', 'fas fa-archive', 'admin_pos_archive'); // A1 (30/10/2026)
        yield MenuItem::linkToRoute('Coupons de fabricant (caisse)', 'fas fa-ticket-alt', 'admin_pos_coupons'); // F17 (01/11/2026)
        yield MenuItem::linkToCrud('Collections', 'fas fa-archive', Collections::class);
        yield MenuItem::linkToCrud('Commandes', 'fas fa-shopping-cart', Commande::class);
        yield MenuItem::linkToCrud('Notes de Frais', 'fas fa-receipt', NoteDeFrais::class);
        yield MenuItem::linkToCrud('Fournisseurs', 'fas fa-truck', Fournisseur::class);
        yield MenuItem::linkToCrud('Type Fournisseurs', 'fas fa-list', TypeFournisseur::class);
        yield MenuItem::linkToCrud('Transporteurs', 'fas fa-truck', Transporteur::class);
        yield MenuItem::linkToCrud('Frais de Port', 'fas fa-shipping-fast', FraisDePort::class);
        yield MenuItem::linkToCrud('ImageCollection', 'fas fa-images ', CollectionPicture::class);
        yield MenuItem::linkToCrud('Type note de frais', 'fas fa-tags', TypeNoteDeFrais::class);
        
        yield MenuItem::section('Réservations & Clients');
        yield MenuItem::linkToCrud('Réservations de Prestations', 'fas fa-calendar-check', Reservation::class)
            ->setController(ReservationCrudController::class);
        yield MenuItem::linkToCrud('Demandes reçues', 'fas fa-inbox', \App\Entity\FormSubmission::class);
        yield MenuItem::linkToCrud('Contact', 'fas fa-envelope', Contact::class);
        yield MenuItem::linkToCrud('Users', 'fas fa-user', User::class);
        yield MenuItem::linkToCrud('Adresse user', 'fas fa-shipping-fast', Adress::class);
        yield MenuItem::linkToCrud('Email Configuration', 'fa fa-envelope', EmailConfiguration::class);
        yield MenuItem::linkToCrud('Newsletter', 'fa fa-envelope', NewsletterSubscriber::class);

        yield MenuItem::section('Mouvements stock');
        yield MenuItem::linkToCrud('Types de Mouvement', 'fas fa-exchange-alt', MovementType::class);
        yield MenuItem::linkToCrud('Inventory Movements', 'fas fa-dolly', InventoryMovements::class);

        yield MenuItem::section('Caisse');
        yield MenuItem::linkToCrud('Postes de caisse', 'fas fa-store', \App\Entity\CashRegister::class);
        yield MenuItem::linkToRoute('Clés des applications', 'fas fa-key', 'admin_app_keys');
        yield MenuItem::linkToCrud('Favoris de la caisse', 'fas fa-th', \App\Entity\PosFavorite::class)->setController(PosFavoriteCrudController::class); // F10
        yield MenuItem::linkToRoute('Paiements de caisse (Stripe)', 'fas fa-credit-card', 'admin_pos_stripe'); // M1
        yield MenuItem::linkToCrud('Caisse', 'fas fa-cash-register', Caisse::class);
        yield MenuItem::linkToCrud('Rapports Z', 'fas fa-file-invoice-dollar', \App\Entity\CashRegisterReport::class)->setController(CashRegisterReportCrudController::class);
        yield MenuItem::linkToCrud('Journal de caisse', 'fas fa-history', \App\Entity\PosAuditLog::class)->setController(PosAuditLogCrudController::class);
        yield MenuItem::linkToCrud('Transaction Caisse', 'fas fa-cash-register', TransactionCaisse::class)->setController(TransactionCaisseCrudController::class);
        yield MenuItem::linkToCrud('Transaction Type caisse', 'fas fa-exchange-alt', TransactionType::class);
        yield MenuItem::linkToCrud('Cash Details', 'fas fa-money-bill-wave', CashDetails::class)->setController(CashDetailsCrudController::class);
        yield MenuItem::linkToCrud('Type Cash', 'fas fa-money-bill-wave', TypeCash::class)->setController(TypeCashCrudController::class);

        // Commandes et paiements (lot 4, 07/11/2026) : ce que le support utilise d'abord ; les tables techniques sous « Réglages avancés »
        yield MenuItem::section('Commandes');
        yield MenuItem::linkToRoute('À traiter', 'fas fa-inbox', 'admin_orders_todo');
        yield MenuItem::linkToCrud('Commandes', 'fas fa-shopping-bag', Order::class);
        yield MenuItem::linkToCrud('Paiements', 'fas fa-credit-card', Payments::class);
        yield MenuItem::linkToCrud('Paiements en ligne (Stripe)', 'fab fa-stripe', StripeConfig::class);

        yield MenuItem::section('Réglages avancés (commandes)');
        yield MenuItem::linkToCrud('Créer une commande (interne)', 'fas fa-list', Order::class)
                ->setController(OrderAllCrudController::class);
        yield MenuItem::linkToCrud('Articles de commande', 'fas fa-box', OrderItems::class)->setController(OrderItemsCrudController::class);
        yield MenuItem::linkToCrud('Paniers', 'fas fa-boxes', Cart::class);
        yield MenuItem::linkToCrud('Statuts de commande', 'fas fa-tags', StatusCommande::class);
        yield MenuItem::linkToCrud('Sources de commande', 'fas fa-shopping-cart', OrderSource::class);
        yield MenuItem::linkToCrud('Types de commande', 'fas fa-tags', OrderType::class);
        yield MenuItem::linkToCrud('Types de paiement', 'fas fa-credit-card', PaymentType::class);
        yield MenuItem::linkToCrud('Statuts de paiement', 'fas fa-credit-card', StatusPayment::class);
        yield MenuItem::linkToCrud('Méthodes de paiement', 'fas fa-credit-card', PaymentMethod::class);
        yield MenuItem::linkToCrud('Devises', 'fas fa-money-bill-wave', Currency::class);
         

        yield MenuItem::section('Taxe');
        yield MenuItem::linkToCrud('Taxes', 'fas fa-percent', Tax::class);
        yield MenuItem::linkToCrud('Order Taxes', 'fas fa-receipt', OrderTax::class);

        yield MenuItem::section('Catalogue & Véhicules');
        yield MenuItem::linkToCrud('Véhicules & Bateaux (Vente)', 'fas fa-ship', VehicleProduct::class)->setController(VehicleProductCrudController::class);
        yield MenuItem::linkToCrud('Produits & Accessoires', 'fas fa-shopping-cart', Product::class);
        yield $this->reviewsMenuItem();
        yield MenuItem::linkToCrud('Réglages des avis', 'fas fa-sliders-h', \App\Entity\ReviewSetting::class)->setController(ReviewSettingCrudController::class);
        yield MenuItem::linkToCrud('Variants de Produits', 'fas fa-boxes', ProductVariant::class)->setController(ProductVariantCrudController::class);
        yield MenuItem::linkToCrud('Colors', 'fas fa-palette', Color::class);
        yield MenuItem::linkToCrud('Styles', 'fas fa-brush', Style::class);
        yield MenuItem::linkToCrud('Sizes', 'fas fa-ruler', Size::class);
        yield MenuItem::linkToCrud('Categories', 'fas fa-list', Categories::class);
        yield MenuItem::linkToRoute('Gestion des Codes-Barres', 'fa fa-barcode', 'admin_barcode_management');
        yield MenuItem::linkToCrud('Types d\'options', 'fas fa-tag', ProductOption::class);
        yield MenuItem::linkToCrud('Valeurs d\'options (Manches, Cols...)', 'fas fa-palette', ProductOptionValue::class)->setController(ProductOptionValueCrudController::class);
        yield MenuItem::linkToCrud('Visuels Configurateur (Vêtements)', 'fas fa-tshirt', \App\Entity\ProductCustomizationImage::class)->setController(\App\Controller\Admin\ProductCustomizationImageCrudController::class);
        yield MenuItem::linkToCrud('Types de Produit', 'fas fa-box', ProductType::class);
        yield MenuItem::linkToCrud('Unités de Vente', 'fas fa-balance-scale', SaleUnit::class)
            ->setController(SaleUnitCrudController::class);

        

        yield MenuItem::section('Location / Réservation');

        yield MenuItem::linkToCrud('Planning & Réservations', 'fas fa-calendar-check', Booking::class)
            ->setController(BookingCrudController::class);

        yield MenuItem::linkToCrud('Règles & Stocks', 'fas fa-sliders-h', BookingConfiguration::class)
            ->setController(BookingConfigurationCrudController::class);

        yield MenuItem::linkToCrud('Grilles Tarifaires (Packs)', 'fas fa-tags', RentalPack::class)
            ->setController(RentalPackCrudController::class);

        yield MenuItem::section('Boussole ESG');
        yield MenuItem::linkToCrud('Questions Diagnostic', 'fas fa-question-circle', DiagnosticQuestion::class)
            ->setController(DiagnosticQuestionCrudController::class);
        yield MenuItem::linkToCrud('Référentiels & Certifications', 'fas fa-award', CertificationReferential::class)
            ->setController(CertificationReferentialCrudController::class);
        yield MenuItem::linkToCrud('Subventions & Aides', 'fas fa-hand-holding-usd', SubsidyProgram::class)
            ->setController(SubsidyProgramCrudController::class);
        yield MenuItem::linkToCrud('Recommandations', 'fas fa-lightbulb', CertificationRecommendation::class)
            ->setController(CertificationRecommendationCrudController::class);
        yield MenuItem::linkToCrud('Objectifs ODD (UN SDG)', 'fas fa-globe', OddMapping::class)
            ->setController(OddMappingCrudController::class);

        yield MenuItem::section('Mémoires Vivantes');
        yield MenuItem::linkToCrud('Questions du Questionnaire', 'fas fa-question-circle', MemoireQuestion::class)
            ->setController(MemoireQuestionCrudController::class);
        yield MenuItem::linkToCrud('Livres créés', 'fas fa-book-open', MemoireBook::class)
            ->setController(MemoireBookCrudController::class);
        yield MenuItem::linkToCrud('Chapitres', 'fas fa-bookmark', MemoireChapter::class)
            ->setController(MemoireChapterCrudController::class);
        yield MenuItem::linkToCrud('Commandes d\'impression', 'fas fa-print', BookPrintOrder::class)
            ->setController(BookPrintOrderCrudController::class);
        yield MenuItem::linkToRoute('Quotas IA Mémoires Vivantes', 'fas fa-tachometer-alt', 'admin_mv_ai_quotas');
    }
}
