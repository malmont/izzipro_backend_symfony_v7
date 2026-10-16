<?php

namespace App\MemoiresVivantes\Services;

use App\Entity\User;
use App\MemoiresVivantes\Entity\AiQuotaSetting;
use App\MemoiresVivantes\Entity\AiUsage;
use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Exception\AiQuotaExceededException;
use App\MemoiresVivantes\Repository\AiQuotaSettingRepository;
use App\MemoiresVivantes\Repository\AiUsageRepository;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Quotas des appels IA de Mémoires Vivantes (16/10/2026) : transcriptions et améliorations par utilisateur et par
 * jour (minuit à minuit dans le fuseau horaire réglé pour le site), durée maximale d'un enregistrement. Réglages
 * par site (AiQuotaSetting), consommation en base (AiUsage : seuls les appels aboutis comptent).
 * Qui est limité : le propriétaire connecté d'un livre mobile (channel = mobile), ou d'un livre web si le site
 * l'a demandé ; jamais un administrateur, jamais un invité venu par un lien (sa règle propre reste : une
 * amélioration par question).
 */
class AiQuotaService
{
    /** Réglages déjà lus, par EntityManager (une bascule de site change l'EntityManager) */
    private array $settingsCache = [];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /** Réglages du site : la ligne existante, sinon les valeurs par défaut (objet non enregistré) */
    public function settings(): AiQuotaSetting
    {
        $key = spl_object_id($this->emProvider->getEntityManager());

        return $this->settingsCache[$key] ??= ($this->settingsRepository()->current() ?? new AiQuotaSetting());
    }

    /** Enregistre des réglages (crée la ligne du site au premier enregistrement) */
    public function save(AiQuotaSetting $settings): AiQuotaSetting
    {
        $em = $this->emProvider->getEntityManager();
        if ($settings->getId() === null) {
            $em->persist($settings);
        }
        $em->flush();
        $this->settingsCache = [];

        return $settings;
    }

    /** L'utilisateur est-il soumis aux quotas pour ce livre ? (voir l'en-tête de la classe) */
    public function isLimited(?Book $book, ?UserInterface $user): bool
    {
        if (!$user instanceof User || $book === null) {
            return false;
        }
        if (in_array('ROLE_ADMIN', $user->getRoles(), true) || in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return false;
        }

        return $book->getChannel() === Book::CHANNEL_MOBILE || $this->settings()->isApplyToWebBooks();
    }

    /** @throws AiQuotaExceededException quota du jour atteint */
    public function assertWithinQuota(User $user, string $kind): void
    {
        $limit = $this->settings()->limitFor($kind);
        if ($limit === null) {
            return;
        }
        [$from, $to, $resetsAt] = $this->dayWindow();
        $used = $this->usageRepository()->countFor($user, $kind, $from, $to);
        if ($used >= $limit) {
            throw new AiQuotaExceededException($kind, $limit, $used, $resetsAt);
        }
    }

    /**
     * Reprise de rédaction d'un chapitre déjà rédigé : bornée par chapitre et par jour (regenerationsPerDay, 0 =
     * illimité). La première rédaction n'est pas une reprise.
     *
     * @throws AiQuotaExceededException quota de reprises du jour atteint
     */
    public function assertRegenerationAllowed(User $user, Chapter $chapter): void
    {
        $limit = $this->settings()->limitFor(AiUsage::KIND_REGENERATION);
        $alreadyWritten = trim((string) ($chapter->getContentFinal() ?: $chapter->getContentGenerated())) !== '';
        if ($limit === null || !$alreadyWritten) {
            return;
        }
        [$from, $to, $resetsAt] = $this->dayWindow();
        $used = $this->usageRepository()->countGenerationsForChapter($chapter, $from, $to);
        if ($used >= $limit) {
            throw new AiQuotaExceededException(AiUsage::KIND_REGENERATION, $limit, $used, $resetsAt);
        }
    }

    /** À appeler seulement quand l'appel IA a rendu un résultat */
    public function record(User $user, string $kind, ?Book $book = null, ?Chapter $chapter = null): void
    {
        $em = $this->emProvider->getEntityManager();
        $em->persist(new AiUsage($user, $kind, $book?->getId(), $chapter?->getId()));
        $em->flush();
    }

    /**
     * État des quotas d'un utilisateur (GET /api/memoires/mobile/quotas) : limit null = illimité
     *
     * @return array{transcription: array, improvement: array, maxAudioSeconds: int, timezone: string}
     */
    public function usageFor(User $user): array
    {
        $settings = $this->settings();
        [$from, $to, $resetsAt] = $this->dayWindow();
        $result = [];
        foreach (AiUsage::QUOTA_KINDS as $kind) {
            $limit = $settings->limitFor($kind);
            $used = $this->usageRepository()->countFor($user, $kind, $from, $to);
            $result[$kind] = ['limit' => $limit, 'used' => $used, 'remaining' => $limit === null ? null : max(0, $limit - $used), 'resetsAt' => $resetsAt->format(\DateTimeInterface::ATOM)];
        }

        return $result + ['maxAudioSeconds' => $settings->getMaxAudioSeconds(), 'timezone' => $settings->getTimezone()];
    }

    /**
     * Consommation par utilisateur pour un jour du site (YYYY-MM-DD dans le fuseau du site ; aujourd'hui par défaut)
     *
     * @return array{date: string, timezone: string, from: string, to: string, users: list<array>}
     */
    public function usageByUser(?string $date = null): array
    {
        $tz = $this->timezone();
        if ($date !== null && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]))) {
            throw new \InvalidArgumentException('date : format AAAA-MM-JJ attendu (jour existant)');
        }
        $day = $date !== null ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz) : new \DateTimeImmutable('now', $tz);
        [$from, $to] = $this->dayWindow($day);

        return [
            'date' => $day->setTimezone($tz)->format('Y-m-d'), 'timezone' => $tz->getName(),
            'from' => $from->setTimezone($tz)->format(\DateTimeInterface::ATOM), 'to' => $to->setTimezone($tz)->format(\DateTimeInterface::ATOM),
            'users' => $this->usageRepository()->usageByUser($from, $to),
        ];
    }

    public function maxAudioSeconds(): int
    {
        return $this->settings()->getMaxAudioSeconds();
    }

    /**
     * Fenêtre du jour (minuit à minuit dans le fuseau du site), en UTC pour la base, plus l'instant de remise à zéro
     * dans le fuseau du site
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: \DateTimeImmutable}
     */
    public function dayWindow(?\DateTimeImmutable $moment = null): array
    {
        $tz = $this->timezone();
        $start = ($moment ?? new \DateTimeImmutable('now'))->setTimezone($tz)->setTime(0, 0, 0);
        $end = $start->modify('+1 day');
        $utc = new \DateTimeZone('UTC');

        return [$start->setTimezone($utc), $end->setTimezone($utc), $end];
    }

    public function timezone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone($this->settings()->getTimezone());
        } catch (\Throwable) {
            return new \DateTimeZone(AiQuotaSetting::DEFAULT_TIMEZONE);
        }
    }

    private function settingsRepository(): AiQuotaSettingRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(AiQuotaSetting::class);
    }

    private function usageRepository(): AiUsageRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(AiUsage::class);
    }
}
