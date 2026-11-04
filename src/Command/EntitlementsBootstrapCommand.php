<?php

namespace App\Command;

use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\Platform\EntitlementProjector;
use App\Services\Platform\EntitlementService;
use App\Services\AppKeyService\AppKeyService;
use App\Services\TenantConnectionManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Amorçage des droits d'usage depuis l'existant (console de pilotage, lot 3, 04/11/2026), idempotent : pour chaque site, chaque
 * indicateur vrai de la fiche Entreprise sans droit vivant reçoit un droit `manual` sans fin ; une application dont une clé
 * d'activation est valide mais dont l'indicateur est faux (relevé du 04/11/2026 : Living Memory sur memoiresvivantes) reçoit aussi
 * son droit, et l'indicateur est projeté à vrai. Aucun courriel. --dry-run : montre sans écrire.
 */
#[AsCommand(name: 'app:entitlements:bootstrap', description: 'Crée les droits d\'usage manquants depuis les indicateurs et les clés d\'activation de chaque site (idempotent)')]
final class EntitlementsBootstrapCommand extends Command
{
    public function __construct(private readonly TenantConnectionManager $tenants, private readonly EntitlementService $entitlements, private readonly EntitlementProjector $projector, private readonly AppKeyService $appKeys)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Montre ce qui serait créé sans rien écrire');
    }

    protected function execute(InputInterface $input, $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dry = (bool) $input->getOption('dry-run');
        $created = 0;
        foreach ($this->tenants->getAllTenants() as $tenant) {
            $code = (string) $tenant['code'];
            $flags = $this->projector->currentFlags($code);
            if ($flags === null) {
                $io->text(sprintf('%-20s aucune fiche Entreprise (modèle ?) : ignoré', $code));
                continue;
            }
            $withKeys = $this->appsWithValidKeys($code);
            $effective = $this->entitlements->enabledMap($code);
            $toGrant = [];
            foreach (FeatureCatalog::GOVERNED as $key => $column) {
                $app = FeatureCatalog::GOVERNED_APP[$key] ?? null;
                $wanted = $flags[$key] || ($app !== null && in_array($app, $withKeys, true));
                if ($wanted && !$effective[$key]) {
                    $toGrant[] = $key;
                }
            }
            if ($toGrant === []) {
                $io->text(sprintf('%-20s à jour', $code));
                continue;
            }
            $io->text(sprintf('%-20s %s%s', $code, $dry ? 'à accorder : ' : 'accordé : ', implode(', ', $toGrant)));
            if ($dry) {
                continue;
            }
            foreach ($toGrant as $key) {
                $this->entitlements->grant($code, $key, 'manual', null, 'app:entitlements:bootstrap', $flags[$key] ? 'amorçage depuis la fiche Entreprise' : 'amorçage depuis une clé d\'activation valide', null, null, false);
                $created++;
            }
        }
        $io->success(sprintf('%d droit(s) créé(s)%s.', $created, $dry ? ' (simulation)' : ''));

        return Command::SUCCESS;
    }

    /** @return list<string> applications ayant une clé valide */
    private function appsWithValidKeys(string $code): array
    {
        $apps = [];
        try {
            foreach ($this->appKeys->list($code) as $k) {
                $expired = !empty($k['expires_at']) && strtotime((string) $k['expires_at']) < time();
                if (empty($k['revoked_at']) && !$expired) {
                    $apps[] = (string) $k['app'];
                }
            }
        } catch (\Throwable) {
        }

        return array_values(array_unique($apps));
    }
}
