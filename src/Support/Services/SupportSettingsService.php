<?php

namespace App\Support\Services;

use App\Services\Booking\BookingClock;
use App\Services\TenantEntityManagerProvider;
use App\Support\Entity\SupportSetting;

/** Réglages de l'assistance du site (lot 1) : délai en heures ouvrées, jours ouvrés, envoi des courriels (log | send) */
class SupportSettingsService
{
    public const RETENTION_YEARS = 3;

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly BookingClock $clock)
    {
    }

    public function current(): SupportSetting
    {
        return $this->emProvider->getEntityManager()->getRepository(SupportSetting::class)->findOneBy([], ['id' => 'ASC']) ?? new SupportSetting();
    }

    public function toArray(?SupportSetting $s = null): array
    {
        $s ??= $this->current();

        return ['responseHours' => $s->getResponseHours(), 'businessDays' => $s->getBusinessDays(), 'timezone' => $this->clock->name(), 'outboundMode' => $s->getOutboundMode(),
            'inboundAddress' => null, 'retentionYears' => self::RETENTION_YEARS, 'savedAt' => $s->getSavedAt()?->format(\DateTimeInterface::ATOM)];
    }

    public function save(array $body, ?string $user): array
    {
        $errors = [];
        $hours = $body['responseHours'] ?? null;
        if ($hours !== null && (!is_int($hours) || $hours < 1 || $hours > BusinessClock::MAX_HOURS)) {
            $errors['responseHours'] = 'OUT_OF_RANGE';
        }
        $days = $body['businessDays'] ?? null;
        if ($days !== null && (!is_array($days) || !array_is_list($days) || $days === [] || array_filter($days, fn ($d) => !is_int($d) || $d < 1 || $d > 7) !== [])) {
            $errors['businessDays'] = 'INVALID_DAYS';
        }
        $mode = $body['outboundMode'] ?? null;
        if ($mode !== null && !in_array($mode, SupportSetting::OUTBOUND, true)) {
            $errors['outboundMode'] = 'INVALID_MODE';
        }
        if ($errors !== []) {
            throw SupportException::validation($errors);
        }
        $em = $this->emProvider->getEntityManager();
        $s = $this->current();
        if ($hours !== null) {
            $s->setResponseHours($hours);
        }
        if ($days !== null) {
            $days = array_values(array_unique($days));
            sort($days);
            $s->setBusinessDays($days);
        }
        if ($mode !== null) {
            $s->setOutboundMode($mode);
        }
        $s->setSavedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setUpdatedBy($user);
        $em->persist($s);
        $em->flush();

        return $this->toArray($s);
    }

    public function due(\DateTimeImmutable $from): \DateTimeImmutable
    {
        $s = $this->current();

        return BusinessClock::due($from, $s->getResponseHours(), $s->getBusinessDays(), $this->clock->timezone());
    }
}
