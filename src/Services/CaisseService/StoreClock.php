<?php

namespace App\Services\CaisseService;

use App\Entity\Entreprise;
use App\Services\OrderService\TaxEngine;
use App\Services\TenantEntityManagerProvider;

/**
 * Fuseau horaire du magasin (F14, 29/10/2026) : réglage du site, sinon déduit du pays et de la province de l'adresse de
 * l'entreprise. Sert aux journées des statistiques par caissier et des exports comptables. Les réservations de la boutique
 * s'y appuient aussi (BookingClock, 04/11/2026), avec America/Toronto en repli quand rien n'est connu (source « default »).
 */
class StoreClock
{
    public const BY_PROVINCE_CA = ['QC' => 'America/Toronto', 'ON' => 'America/Toronto', 'MB' => 'America/Winnipeg', 'SK' => 'America/Regina', 'AB' => 'America/Edmonton', 'BC' => 'America/Vancouver',
        'NS' => 'America/Halifax', 'NB' => 'America/Moncton', 'PE' => 'America/Halifax', 'NL' => 'America/St_Johns', 'YT' => 'America/Whitehorse', 'NT' => 'America/Yellowknife', 'NU' => 'America/Iqaluit'];
    public const BY_COUNTRY = ['CA' => 'America/Toronto', 'US' => 'America/New_York', 'FR' => 'Europe/Paris', 'MQ' => 'America/Martinique', 'GP' => 'America/Guadeloupe', 'GF' => 'America/Cayenne', 'RE' => 'Indian/Reunion',
        'BE' => 'Europe/Brussels', 'CH' => 'Europe/Zurich', 'LU' => 'Europe/Luxembourg', 'MC' => 'Europe/Monaco', 'GB' => 'Europe/London', 'DE' => 'Europe/Berlin', 'ES' => 'Europe/Madrid', 'IT' => 'Europe/Rome', 'PT' => 'Europe/Lisbon',
        'MA' => 'Africa/Casablanca', 'TN' => 'Africa/Tunis', 'DZ' => 'Africa/Algiers', 'SN' => 'Africa/Dakar', 'CI' => 'Africa/Abidjan', 'CM' => 'Africa/Douala', 'HT' => 'America/Port-au-Prince', 'MX' => 'America/Mexico_City', 'BR' => 'America/Sao_Paulo'];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /** @return array{timezone: string, source: string} fuseau et origine (setting | province | country | default) */
    public function settings(): array
    {
        $e = $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([]);

        return self::resolve($e);
    }

    public static function resolve(?Entreprise $e): array
    {
        return self::resolveFrom($e?->getPosTimezone(), $e?->getAddressEntreprise()?->getCountry(), $e?->getAddressEntreprise()?->getState());
    }

    /** La même règle à partir des seules valeurs (fuseau réglé, pays, province), lues ailleurs que par la fiche Doctrine du site courant */
    public static function resolveFrom(?string $timezone, ?string $countryRaw, ?string $state): array
    {
        if ($timezone !== null && $timezone !== '' && in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            return ['timezone' => $timezone, 'source' => 'setting'];
        }
        $address = TaxEngine::normalizeAddress(['country' => $countryRaw, 'province' => $state]);
        $country = $address['country'] ?? null;
        $province = $address['province'] ?? null;
        if ($country === 'CA' && $province !== null && isset(self::BY_PROVINCE_CA[$province])) {
            return ['timezone' => self::BY_PROVINCE_CA[$province], 'source' => 'province'];
        }
        if ($country !== null && isset(self::BY_COUNTRY[$country])) {
            return ['timezone' => self::BY_COUNTRY[$country], 'source' => 'country'];
        }

        return ['timezone' => 'UTC', 'source' => 'default'];
    }

    public function timezone(): \DateTimeZone
    {
        return new \DateTimeZone($this->settings()['timezone']);
    }

    /**
     * Un instant rendu à l'heure du magasin, avec son décalage (ATOM : « 2026-12-20T13:31:45-05:00 ») ; accepte un instant ou un texte daté
     * déjà rangé (ATOM en UTC, par exemple dans un champ JSON). Lot ABO-DATES (20/11/2026). Un texte illisible est rendu tel quel.
     */
    public static function atomIn(\DateTimeZone $timezone, \DateTimeInterface|string|null $at): ?string
    {
        if ($at === null || $at === '') {
            return null;
        }
        if (is_string($at)) {
            try {
                $at = new \DateTimeImmutable($at);
            } catch (\Exception) {
                return $at;
            }
        }

        return \DateTimeImmutable::createFromInterface($at)->setTimezone($timezone)->format(\DATE_ATOM);
    }

    public function atom(\DateTimeInterface|string|null $at): ?string
    {
        return self::atomIn($this->timezone(), $at);
    }

    /** Journée (Y-m-d) d'un instant, dans le fuseau du magasin */
    public function day(\DateTimeInterface $at): string
    {
        return \DateTimeImmutable::createFromInterface($at)->setTimezone($this->timezone())->format('Y-m-d');
    }

    /**
     * Bornes UTC d'une période de jours du magasin [from, to] (Y-m-d, inclusives) ; par défaut la journée courante.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: string, 3: string} début, fin exclusive, from, to
     */
    public function range(mixed $from, mixed $to): array
    {
        $tz = $this->timezone();
        $today = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        $fromDay = is_string($from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : $today;
        $toDay = is_string($to) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : $fromDay;
        if ($toDay < $fromDay) {
            throw new CashRegisterException('VALIDATION_FAILED', 'to : antérieur à from', 422, [['path' => 'to', 'message' => 'période invalide']]);
        }
        $start = new \DateTimeImmutable($fromDay . ' 00:00:00', $tz);
        $end = (new \DateTimeImmutable($toDay . ' 00:00:00', $tz))->modify('+1 day');

        return [$start->setTimezone(new \DateTimeZone('UTC')), $end->setTimezone(new \DateTimeZone('UTC')), $fromDay, $toDay];
    }
}
