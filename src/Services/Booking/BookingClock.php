<?php

namespace App\Services\Booking;

use App\Services\CaisseService\StoreClock;

/**
 * Fuseau horaire de la boutique pour les réservations (04/11/2026, lot L7b de l'application e-commerce mobile) : un seul endroit.
 * Réglage du site (fiche Entreprise « Fuseau horaire du magasin », sinon province ou pays de l'adresse : StoreClock) ; sans rien,
 * America/Toronto, la valeur qui était écrite en dur dans le calendrier des réservations et le courriel de commande.
 *
 * - `parse()` : une date sans fuseau (« 2027-06-07T16:00:00 », « 2027-06-07 16:00 ») est une heure de la boutique ; une date avec
 *   fuseau ou en UTC (« …Z », « …-04:00 ») est convertie dans le fuseau de la boutique, même instant. Tout contrôle (heures
 *   d'ouverture, dates proposées, créneau du soir, durées, jours) et tout enregistrement de Booking passent par là.
 * - `format()` : rendu ATOM dans le fuseau de la boutique, avec son décalage (« 2027-06-07T16:00:00-04:00 »).
 * - `utc()` : même instant en UTC, pour l'enregistrement d'un Booking et les requêtes de disponibilité (Doctrine écrit l'heure
 *   murale de l'objet ; la base et le fuseau PHP par défaut sont en UTC).
 * Le calendrier (`GET /api/booking/calendar`) rend des heures de la boutique sans décalage, inchangé.
 */
final class BookingClock
{
    public const DEFAULT = 'America/Toronto';

    public function __construct(private readonly StoreClock $store)
    {
    }

    /** Identifiant IANA du fuseau de la boutique (exposé en `commerce.timezone`) */
    public function name(): string
    {
        $settings = $this->store->settings();

        return $settings['source'] === 'default' ? self::DEFAULT : $settings['timezone'];
    }

    public function timezone(): \DateTimeZone
    {
        return new \DateTimeZone($this->name());
    }

    /**
     * Instant d'une date reçue, exprimé dans le fuseau de la boutique (sans fuseau : heure de la boutique ; avec : converti).
     *
     * @throws \Exception chaîne illisible
     */
    public function parse(string $value): \DateTimeImmutable
    {
        $tz = $this->timezone();

        return (new \DateTimeImmutable($value, $tz))->setTimezone($tz);
    }

    /** Même instant en UTC (fuseau de la base et de PHP) : à passer à Doctrine et aux requêtes de disponibilité */
    public static function utc(\DateTimeInterface $at): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone('UTC'));
    }

    /** ATOM dans le fuseau de la boutique */
    public function format(\DateTimeInterface $at): string
    {
        return \DateTimeImmutable::createFromInterface($at)->setTimezone($this->timezone())->format(\DateTimeInterface::ATOM);
    }
}
