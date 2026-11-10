<?php

namespace App\Command;

use App\Cache\CacheInvalidationQueue;
use App\Cache\CacheTags;
use App\Services\CaisseService\StoreClock;
use App\Services\TenantCacheService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lot P5b (11/11/2026, `docs/promotions-gestion.md` § 7) : remet en UTC les dates de promotion saisies avant la correction (EasyAdmin, PATCH avec
 * décalage, semis), lues comme l'heure du magasin voulue par le gérant (00:00 à Toronto en hiver → 05:00 UTC ; en été → 04:00). Seulement les
 * articles nommés (`--ids`), simulation par défaut, `--apply` pour écrire ; anciennes valeurs gardées dans `gestion_promotion_tz_fix` ; un article
 * déjà corrigé (valeurs = celles du journal) est sauté : rejouer ne change rien. Écriture SQL directe (sans DemoContentMarker : un article
 * d'exemple le reste) avec invalidation du cache de l'article.
 */
#[AsCommand(name: 'app:gestion:promotions:fuseau', description: 'Remet en UTC les dates de promotion de quelques articles (simulation par défaut, --apply pour écrire)')]
class PromotionTimezoneFixCommand extends Command
{
    public function __construct(
        private readonly TenantConnectionManager $tenants,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly StoreClock $clock,
        private readonly ?CacheInvalidationQueue $cacheQueue = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Code du site')
            ->addOption('ids', null, InputOption::VALUE_REQUIRED, 'Identifiants des articles, séparés par des virgules')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Écrire (sinon simulation)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $code = (string) $input->getOption('tenant');
        $tenant = $this->tenants->findTenantByCode($code);
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $input->getOption('ids')))));
        if ($tenant === null || empty($tenant['dbname']) || $ids === []) {
            $io->error('--tenant (site existant) et --ids sont obligatoires');

            return Command::FAILURE;
        }
        $this->emProvider->switchTenant((string) $tenant['dbname'], $code);
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $tz = $this->clock->timezone();
        $apply = (bool) $input->getOption('apply');
        $toUtc = fn (?string $naive) => $naive === null ? null : (new \DateTimeImmutable(substr($naive, 0, 19), $tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $done = 0;
        foreach ($ids as $id) {
            $row = $conn->fetchAssociative('SELECT id, name, special_price, special_price_from, special_price_to FROM product WHERE id = ?', [$id]);
            if ($row === false) {
                $io->warning("Article $id introuvable");
                continue;
            }
            $from = $row['special_price_from'] === null ? null : substr((string) $row['special_price_from'], 0, 19);
            $to = $row['special_price_to'] === null ? null : substr((string) $row['special_price_to'], 0, 19);
            $logged = $conn->fetchAssociative('SELECT new_from, new_to FROM gestion_promotion_tz_fix WHERE product_id = ? ORDER BY id DESC LIMIT 1', [$id]);
            if ($logged !== false && (($logged['new_from'] === null ? null : substr((string) $logged['new_from'], 0, 19)) === $from) && (($logged['new_to'] === null ? null : substr((string) $logged['new_to'], 0, 19)) === $to)) {
                $io->text(sprintf('%d %s : déjà corrigé, rien à faire', $id, $row['name']));
                continue;
            }
            $newFrom = $toUtc($from);
            $newTo = $toUtc($to);
            $io->text(sprintf('%d %s : %s → %s ; %s → %s (%s)%s', $id, $row['name'], $from ?? '—', $newFrom ?? '—', $to ?? '—', $newTo ?? '—', $tz->getName(), $apply ? '' : ' [simulation]'));
            if (!$apply) {
                continue;
            }
            $conn->transactional(function () use ($conn, $id, $from, $to, $newFrom, $newTo, $tz): void {
                $conn->executeStatement('INSERT INTO gestion_promotion_tz_fix (product_id, old_from, old_to, new_from, new_to, timezone, applied_at) VALUES (?, ?, ?, ?, ?, ?, NOW())', [$id, $from, $to, $newFrom, $newTo, $tz->getName()]);
                $conn->executeStatement('UPDATE product SET special_price_from = ?, special_price_to = ? WHERE id = ?', [$newFrom, $newTo, $id]);
            });
            $dbname = (string) $tenant['dbname'];
            if ($this->cacheQueue !== null) {
                $this->cacheQueue->add($dbname, [TenantCacheService::componentTag($dbname, '', CacheTags::PRODUCTS . '.list'), TenantCacheService::componentTag($dbname, '', CacheTags::PRODUCTS . '.item.' . $id)]);
                $this->cacheQueue->commit($dbname);
            }
            ++$done;
        }
        $io->success($apply ? "$done article(s) corrigé(s)." : 'Simulation : rien n\'a été écrit (--apply pour écrire).');

        return Command::SUCCESS;
    }
}
