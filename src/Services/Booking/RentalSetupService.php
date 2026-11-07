<?php

namespace App\Services\Booking;

use App\Entity\Product;
use App\Entity\RentalPack;
use App\Services\BoutiqueSettingsService\BoutiqueSettingsService;
use App\Services\TenantEntityManagerProvider;

/**
 * Cheminement « Location et réservation » (07/11/2026) : ce qu'il faut pour louer un produit : le mode de vente location, un produit
 * louable, ses règles de stock et de créneaux (`booking_configuration` : unité de temps, stock du pool, durée minimale, battement,
 * horaires), une grille tarifaire (`rental_pack` : tarifs à l'heure, demi-journée, journée, semaine, mois, rattaché aux catégories du
 * produit ; le devis prend le pack de la catégorie), caution et conditions. Lecture seule, avec l'état de chaque produit louable.
 */
class RentalSetupService
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly BoutiqueSettingsService $settings)
    {
    }

    /** @return array{modeOn: bool, steps: list<array<string, mixed>>, level: string, levelLabel: string, products: list<array<string, mixed>>, packs: list<array<string, mixed>>, counts: array<string, int>} */
    public function overview(): array
    {
        $em = $this->emProvider->getEntityManager();
        $modes = (array) ($this->settings->commerceSetting('salesModes') ?? ['sale', 'rental', 'subscription']);
        $modeOn = in_array('rental', $modes, true);
        $packs = [];
        $packsByCategory = [];
        /** @var RentalPack $pack */
        foreach ($em->getRepository(RentalPack::class)->findBy([], ['id' => 'ASC']) as $pack) {
            $rates = array_filter(['hour' => $pack->getHourRate(), 'halfDay' => $pack->getHalfDayRate(), 'day' => $pack->getDayRate(), 'week' => $pack->getWeekRate(), 'month' => $pack->getMonthRate()], fn ($r) => (float) $r > 0);
            $categories = array_map(fn ($c) => (int) $c->getId(), $pack->getCategories()->toArray());
            $packs[] = ['id' => (int) $pack->getId(), 'name' => (string) $pack->getName(), 'rates' => array_keys($rates), 'categories' => array_map(fn ($c) => (string) $c->getName(), $pack->getCategories()->toArray()), 'priced' => $rates !== []];
            if ($rates !== []) {
                foreach ($categories as $cid) {
                    $packsByCategory[$cid] = true;
                }
            }
        }
        $products = [];
        $counts = ['rentable' => 0, 'configured' => 0, 'priced' => 0, 'deposit' => 0, 'hours' => 0, 'bookings' => $this->count('SELECT COUNT(*) FROM booking')];
        /** @var Product $p */
        foreach ($em->getRepository(Product::class)->findBy(['rentalEnabled' => true], ['id' => 'ASC']) as $p) {
            $config = $p->getBookingConfiguration();
            $configured = $config !== null && (string) $config->getGranularity() !== '' && (int) $config->getStockQuantity() > 0;
            $priced = false;
            foreach ($p->getCategory() as $c) {
                $priced = $priced || isset($packsByCategory[(int) $c->getId()]);
            }
            $deposit = $config !== null && ((int) $config->getDeposit() > 0 || trim((string) $config->getCancellationPolicy()) !== '');
            $hours = $config !== null && (trim((string) $config->getOpeningStart()) !== '' || !empty($config->getHalfDays()));
            $counts['rentable']++;
            $counts['configured'] += $configured ? 1 : 0;
            $counts['priced'] += $priced ? 1 : 0;
            $counts['deposit'] += $deposit ? 1 : 0;
            $counts['hours'] += $hours ? 1 : 0;
            $products[] = ['id' => (int) $p->getId(), 'name' => (string) $p->getName(), 'configured' => $configured, 'priced' => $priced, 'deposit' => $deposit, 'hours' => $hours, 'configId' => $config?->getId(),
                'granularity' => $config?->getGranularity(), 'stock' => $config?->getStockQuantity(), 'minDuration' => $config?->getMinDuration(), 'categories' => array_map(fn ($c) => (string) $c->getName(), $p->getCategory()->toArray())];
        }
        $steps = [];
        $add = function (string $key, string $title, string $description, string $status, ?string $screen) use (&$steps) {
            $steps[] = ['key' => $key, 'title' => $title, 'description' => $description, 'status' => $status, 'screen' => $screen, 'blocking' => in_array($status, ['todo'], true)];
        };
        $add('rental.mode', 'Mode de vente « location » activé', $modeOn ? 'Vos clients peuvent réserver des dates sur les produits louables.' : 'Activez « location » dans les modes de vente (Mon site : pilotage, rubrique Commerce) : sans lui, aucun produit ne se loue.', $modeOn ? 'done' : 'todo', 'mon_site');
        $add('rental.product', 'Au moins un produit louable', $counts['rentable'] > 0 ? sprintf('%d produit(s) avec « Location » cochée sur la fiche.', $counts['rentable']) : 'Cochez « Location » sur la fiche des produits à louer (un produit peut être à la fois vendu et loué).', $counts['rentable'] > 0 ? 'done' : ($modeOn ? 'todo' : 'optional'), 'products');
        $add('rental.booking', 'Règles de stock et de créneaux', $counts['rentable'] > 0 && $counts['configured'] === $counts['rentable'] ? 'Chaque produit louable a son unité de temps, son stock du pool, sa durée minimale et son battement.' : sprintf('%d produit(s) louable(s) sans règles : écran « Règles & Stocks » : unité de temps (journée, heure, demi-heure, quart d\'heure), stock du pool (combien d\'exemplaires identiques), durée minimale, temps de battement entre deux clients, horaires d\'ouverture.', max(0, $counts['rentable'] - $counts['configured'])), $counts['rentable'] > 0 && $counts['configured'] === $counts['rentable'] ? 'done' : ($counts['rentable'] > 0 ? 'todo' : 'optional'), 'booking_configuration');
        $add('rental.rates', 'Grilles tarifaires', $counts['rentable'] > 0 && $counts['priced'] === $counts['rentable'] ? 'Chaque produit louable a un pack de location avec au moins un tarif, rattaché à sa catégorie.' : sprintf('%d produit(s) louable(s) sans tarif : écran « Packs de location » : créez un pack (tarif à l\'heure, demi-journée, journée, semaine, mois ; montants en cents) et rattachez-le à la catégorie du produit ; le devis prend le pack de la catégorie.', max(0, $counts['rentable'] - $counts['priced'])), $counts['rentable'] > 0 && $counts['priced'] === $counts['rentable'] ? 'done' : ($counts['rentable'] > 0 ? 'todo' : 'optional'), 'rental_packs');
        $add('rental.hours', 'Horaires et créneaux', $counts['hours'] > 0 ? sprintf('%d produit(s) avec horaires d\'ouverture ou demi-journées.', $counts['hours']) : 'Pour une location à l\'heure : heures d\'ouverture et de fermeture, demi-journées (matin, après-midi), créneau du soir, arrivée en avance (écran Règles & Stocks, panneau facultatif).', $counts['hours'] > 0 ? 'done' : 'optional', 'booking_configuration');
        $add('rental.deposit', 'Caution et conditions d\'annulation', $counts['deposit'] > 0 ? sprintf('%d produit(s) avec caution ou politique d\'annulation.', $counts['deposit']) : 'Caution (en cents) et politique d\'annulation sur les règles du produit : recommandé pour les biens de valeur.', $counts['deposit'] > 0 ? 'done' : 'optional', 'booking_configuration');
        $add('rental.first_booking', 'Une première réservation d\'essai', $counts['bookings'] > 0 ? sprintf('%d réservation(s) enregistrée(s).', $counts['bookings']) : 'Réservez un créneau depuis le site avec un compte client : vous vérifiez le calendrier, le prix et la confirmation.', $counts['bookings'] > 0 ? 'done' : 'optional', 'bookings');
        $blocking = count(array_filter($steps, fn ($s) => $s['status'] === 'todo'));
        $level = !$modeOn ? 'off' : ($blocking > 0 ? 'todo' : 'ready');

        return ['modeOn' => $modeOn, 'steps' => $steps, 'level' => $level, 'levelLabel' => ['off' => 'Location désactivée', 'todo' => 'À compléter', 'ready' => 'Location prête'][$level], 'products' => $products, 'packs' => $packs, 'counts' => $counts, 'blockingTodo' => $blocking];
    }

    private function count(string $sql): int
    {
        try {
            return (int) $this->emProvider->getEntityManager()->getConnection()->fetchOne($sql);
        } catch (\Throwable) {
            return 0;
        }
    }
}
