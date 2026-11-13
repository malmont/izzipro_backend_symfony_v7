<?php

namespace App\Twig;

use App\Services\Admin\AdminStoreTimezone;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Dates des pages sur mesure de l'administration qui parlent au commerçant (lot EA-LISTES, 13/11/2026, `docs/architecture.md`,
 * « Dates et heures dans EasyAdmin ») : `{{ date|store_time('d/m/Y H:i') }}` formate un instant (en base en UTC) à l'heure du
 * magasin ; `{{ store_time_mention() }}` rend « (heure du magasin) », ou « (UTC) » pour un site sans fuseau ni pays. Les pages
 * techniques (workers, clés, tableau technique) restent en UTC et l'écrivent.
 */
class StoreTimeExtension extends AbstractExtension
{
    public function __construct(private readonly AdminStoreTimezone $timezone)
    {
    }

    public function getFilters(): array
    {
        return [new TwigFilter('store_time', [$this, 'format'])];
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('store_time_mention', [$this->timezone, 'mention'])];
    }

    /**
     * Instant formaté au fuseau du magasin ; chaîne vide sans date. Une chaîne sans décalage (« 2026-11-12 02:15 », telle que les
     * services la rendent à partir de la base) est lue comme de l'UTC ; une chaîne illisible est rendue telle quelle.
     */
    public function format(\DateTimeInterface|string|null $date, string $format): string
    {
        if ($date === null || $date === '') {
            return '';
        }
        if (is_string($date)) {
            try {
                $date = new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
            } catch (\Exception) {
                return $date;
            }
        }

        return \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone($this->timezone->name()))->format($format);
    }
}
