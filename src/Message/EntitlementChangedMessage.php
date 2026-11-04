<?php

namespace App\Message;

/**
 * Changement d'un droit d'usage à notifier au site (console de pilotage, lot 3, 04/11/2026), file `email` : courriel au contact
 * du site dans sa langue (EntitlementChangedHandler). Événements : granted, trial_started, expiring_7, expiring_1, expired, grace,
 * suspended, resumed. Aucune donnée personnelle : site, fonctionnalité, dates.
 */
final class EntitlementChangedMessage
{
    /** @param array<string, mixed> $payload endsAt, graceUntil, days… */
    public function __construct(
        public readonly string $tenantCode,
        public readonly string $featureKey,
        public readonly string $event,
        public readonly int $entitlementId,
        public readonly array $payload = [],
    ) {
    }
}
