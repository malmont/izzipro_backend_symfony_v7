<?php

namespace App\Tests\Functional\Group;

use App\Controller\Admin\BaseTenantCrudController;
use App\Security\Entry\EntryModeGuardSubscriber;
use App\Services\Screens\ScreenRegistry;
use App\Services\SiteAssistantService\SiteAssistantTools;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * GARDE-FOU STRUCTUREL du directeur d'un groupe (lot G-1, demande du chef après deux relectures qui ont chacune trouvé un geste d'argent ou
 * de droits resté ouvert) : la garde du directeur est une liste NOIRE ; ce garde-fou en fait une table CLASSÉE. Toute écriture de l'API et
 * d'EasyAdmin (POST, PUT, PATCH, DELETE), tout écran CRUD d'EasyAdmin et tout outil de l'assistant du site figure dans
 * `tests/Fixtures/ecrans/entree-groupe-ecritures.json`, PERMISE ou FERMÉE avec sa raison. Une route, un écran ou un outil nouveau fait
 * échouer la suite tant qu'il n'est pas classé (régénérer, RELIRE ligne à ligne, copier) : il ne s'ouvre jamais en silence.
 */
class GroupWritesClassificationTest extends KernelTestCase
{
    private const FIXTURE = __DIR__ . '/../../Fixtures/ecrans/entree-groupe-ecritures.json';
    /** Raison de la garde => famille annoncée au chef */
    public const FAMILIES = [
        'payment' => 'argent', 'refund' => 'argent', 'cash' => 'argent',
        'rights' => 'droits', 'accounts' => 'comptes', 'account' => 'comptes',
        'keys' => 'clés', 'security' => 'sécurité', 'modules' => 'module', 'assistant' => 'argent et module (assistant)',
        'platform' => 'plateforme', 'technical' => 'machines', 'memoires' => 'autre application', 'app' => 'autre application',
        'ai' => 'unités IA', 'customers' => 'clients finaux',
    ];
    /** Les outils de l'assistant du site : TOUS fermés au directeur (ses routes le sont) ; la famille dit ce qu'ils toucheraient */
    private const ASSISTANT_TOOLS = [
        'refund_order' => 'argent', 'cancel_order' => 'argent', 'capture_order' => 'argent', 'ship_order' => 'commande', 'resend_order_email' => 'commande',
        'toggle_module' => 'module', 'start_module_trial' => 'module', 'request_feature' => 'module', 'set_site_settings' => 'réglages',
        'set_entreprise_fields' => 'réglages', 'set_custom_domain' => 'sécurité', 'request_agency_assistance' => 'droits', 'propose_setup_batch' => 'réglages',
        'add_default_variant' => 'catalogue', 'generate_missing_barcodes' => 'catalogue', 'estimate_product_dimensions' => 'catalogue',
        'start_loss_count' => 'pertes', 'start_loss_exercise' => 'pertes', 'delegate_to_editor' => 'contenu',
        'get_settings' => 'lecture', 'get_readiness' => 'lecture', 'open_screen' => 'lecture', 'get_domain_status' => 'lecture', 'get_help' => 'lecture',
        'get_configuration_paths' => 'lecture', 'get_controls' => 'lecture', 'get_loss_finding' => 'lecture', 'get_loss_health' => 'lecture',
        'get_missing_barcodes' => 'lecture', 'get_modules' => 'lecture', 'get_order' => 'lecture', 'get_orders_todo' => 'lecture', 'get_storefront_checklist' => 'lecture',
        // lot IA-G : outils du directeur au SIÈGE, avec son compte (jamais dans une boutique où il est entré : l'assistant y est fermé)
        'propose_group_invitation' => 'groupe', 'propose_group_copy' => 'catalogue', 'get_group' => 'lecture', 'get_group_view' => 'lecture',
    ];

    public function testEveryWriteEveryScreenAndEveryAssistantToolIsClassifiedForTheDirector(): void
    {
        self::bootKernel();
        $table = [];
        foreach (static::getContainer()->get('router')->getRouteCollection() as $route) {
            $raw = $route->getPath();
            if (!str_starts_with($raw, '/api/') && !str_starts_with($raw, '/admin')) {
                continue;
            }
            $path = preg_replace('/\.1$/', '', (string) preg_replace('/\{[^}]+\}/', '1', $raw));
            $methods = $route->getMethods() === [] ? ['POST'] : array_intersect($route->getMethods(), ['POST', 'PUT', 'PATCH', 'DELETE']);
            foreach ($methods as $method) {
                $table["$method $path"] = EntryModeGuardSubscriber::groupDecision($method, $path) ?? 'permise';
            }
        }
        foreach ($this->crudScreens() as $class) {
            $target = ScreenRegistry::adminCrud($class);
            $short = substr($class, (int) strrpos($class, '\\') + 1);
            $read = EntryModeGuardSubscriber::groupScreenDecision($class, 'index');
            $write = EntryModeGuardSubscriber::groupScreenDecision($class, 'edit');
            $table["EA $short"] = $target === null ? 'non rangé' : ($read !== null ? $read : ($write !== null ? 'lecture seule : ' . $write : 'permise (actions ordinaires seulement)'));
        }
        foreach (SiteAssistantTools::definitions() as $tool) {
            $name = (string) $tool['name'];
            $table["ASSISTANT $name"] = 'assistant';
            $this->assertArrayHasKey($name, self::ASSISTANT_TOOLS, "outil de l'assistant non classé : $name");
        }
        ksort($table);
        if (is_string(getenv('REGENERER_GROUPE_ECRITURES')) && getenv('REGENERER_GROUPE_ECRITURES') !== '') {
            file_put_contents(getenv('REGENERER_GROUPE_ECRITURES'), json_encode($table, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n");
            $counts = [];
            foreach ($table as $decision) {
                $family = str_starts_with($decision, 'permise') ? 'permise' : (str_starts_with($decision, 'lecture seule') ? 'lecture seule (' . (self::FAMILIES[substr($decision, 16)] ?? '?') . ')' : (self::FAMILIES[$decision] ?? $decision));
                $counts[$family] = ($counts[$family] ?? 0) + 1;
            }
            fwrite(\STDERR, "\n[G-1] classement des écritures : " . json_encode($counts, \JSON_UNESCAPED_UNICODE) . "\n");
            $this->markTestIncomplete('Table écrite dans ' . getenv('REGENERER_GROUPE_ECRITURES') . ' : la RELIRE ligne à ligne, puis la copier dans tests/Fixtures/ecrans/entree-groupe-ecritures.json');
        }
        $this->assertSame(json_decode((string) file_get_contents(self::FIXTURE), true), $table,
            'écriture, écran ou outil de l\'assistant non classé pour le directeur : tests/Fixtures/ecrans/entree-groupe-ecritures.json, à régénérer et RELIRE');
        // chaque raison de fermeture a sa famille ; un écran CRUD n'est jamais « non rangé »
        foreach ($table as $key => $decision) {
            $reason = str_starts_with($decision, 'lecture seule : ') ? substr($decision, strlen('lecture seule : ')) : $decision;
            $this->assertTrue(str_starts_with($decision, 'permise') || isset(self::FAMILIES[$reason]), "$key : raison sans famille ($decision)");
        }
        // aucun outil de l'assistant ne sert le directeur : ses routes lui sont fermées
        foreach (['POST /api/site/assistant', 'POST /api/site/assistant/actions/1/confirm', 'POST /admin/mon-site/assistant', 'POST /admin/mon-site/assistant/actions/1/confirm'] as $key) {
            $this->assertSame('assistant', $table[$key] ?? null, $key);
        }
        // sur un écran CRUD, seules les actions ordinaires ; toute autre (un geste propre à l'écran) est fermée par défaut
        $this->assertSame(['index', 'detail', 'new', 'edit', 'delete', 'autocomplete', 'renderFilters'], EntryModeGuardSubscriber::GROUP_EA_ACTIONS);
    }

    /** @return list<class-string> */
    private function crudScreens(): array
    {
        $root = dirname(__DIR__, 3) . '/src/';
        $classes = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . 'Controller/Admin', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $class = 'App\\' . str_replace('/', '\\', substr($file->getPathname(), strlen($root), -4));
            if (class_exists($class) && is_subclass_of($class, BaseTenantCrudController::class) && !(new \ReflectionClass($class))->isAbstract()) {
                $classes[] = $class;
            }
        }
        sort($classes);

        return $classes;
    }
}
