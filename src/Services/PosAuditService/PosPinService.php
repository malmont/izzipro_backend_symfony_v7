<?php

namespace App\Services\PosAuditService;

use App\Entity\PosUserPin;
use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * PIN de caisse (chantier C3, 20/10/2026) : 4 à 6 chiffres, empreinte = hacheur des mots de passe de User appliqué à
 * HMAC-SHA256(PIN, secret du serveur) (une fuite de la base seule ne suffit pas à retrouver les PIN). Anti-force brute
 * par compte : 5 essais faux → blocage 15 minutes (423 PIN_LOCKED + retryAfter), quelle que soit la route.
 */
class PosPinService
{
    public const MAX_ATTEMPTS = 5;
    public const LOCK_MINUTES = 15;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly PasswordHasherFactoryInterface $hashers,
        private readonly PosAuditService $audit,
        #[Autowire('%kernel.secret%')] private readonly string $secret
    ) {
    }

    public static function validFormat(mixed $pin): bool
    {
        return is_string($pin) && preg_match('/^\d{4,6}$/', $pin) === 1;
    }

    public function hasPin(User $user): bool
    {
        return $this->row($user) !== null;
    }

    public function set(User $user, mixed $pin): void
    {
        if (!self::validFormat($pin)) {
            throw new CashRegisterException('VALIDATION_FAILED', 'newPin : 4 à 6 chiffres attendus (chaîne)', 422, [['path' => 'newPin', 'message' => '4 à 6 chiffres attendus']]);
        }
        $em = $this->emProvider->getEntityManager();
        $user = $em->contains($user) ? $user : ($em->find(User::class, $user->getId()) ?? $user); // utilisateur suivi par l'EntityManager du site
        $hash = $this->hasher()->hash($this->peppered($pin));
        $row = $this->row($user);
        $row ? $row->changePin($hash) : $em->persist($row = new PosUserPin($user, $hash));
        $em->flush();
    }

    /** Efface le PIN (et donc le blocage) ; rend vrai s'il y en avait un */
    public function reset(User $user): bool
    {
        $row = $this->row($user);
        if (!$row) {
            return false;
        }
        $em = $this->emProvider->getEntityManager();
        $em->remove($row);
        $em->flush();

        return true;
    }

    /**
     * Vérifie le PIN ; refus : PIN_NOT_SET (409), PIN_LOCKED (423, retryAfter), PIN_INVALID ($invalidStatus).
     * Chaque essai faux est journalisé (PIN_FAILED), le blocage aussi (PIN_LOCKED).
     *
     * @param array<string, mixed> $context champs ajoutés aux refus (ex. managerId)
     */
    public function verify(User $user, mixed $pin, int $invalidStatus = 400, array $context = [], string $via = 'unlock'): void
    {
        $row = $this->row($user) ?? throw new CashRegisterException('PIN_NOT_SET', 'Aucun PIN de caisse défini pour cet utilisateur', 409, [], $context);
        $now = new \DateTimeImmutable();
        if ($row->getLockedUntil() && $row->getLockedUntil() > $now) {
            throw self::locked($row->getLockedUntil()->getTimestamp() - $now->getTimestamp(), $context);
        }
        $em = $this->emProvider->getEntityManager();
        if (!self::validFormat($pin) || !$this->hasher()->verify($row->getPinHash(), $this->peppered($pin))) {
            $attempts = $row->getFailedAttempts() + 1;
            $this->audit->record(PosAuditService::PIN_FAILED, ['userId' => $user->getId(), 'email' => $user->getEmail(), 'attempts' => $attempts, 'via' => $via], subjectType: 'user', subjectId: $user->getId());
            if ($attempts >= self::MAX_ATTEMPTS) {
                $row->setFailedAttempts(0)->setLockedUntil($now->modify('+' . self::LOCK_MINUTES . ' minutes'));
                $em->flush();
                $this->audit->record(PosAuditService::PIN_LOCKED, ['userId' => $user->getId(), 'email' => $user->getEmail(), 'minutes' => self::LOCK_MINUTES, 'via' => $via], subjectType: 'user', subjectId: $user->getId());
                throw self::locked(self::LOCK_MINUTES * 60, $context);
            }
            $row->setFailedAttempts($attempts);
            $em->flush();
            throw new CashRegisterException('PIN_INVALID', 'PIN incorrect', $invalidStatus, [], $context + ['attemptsLeft' => self::MAX_ATTEMPTS - $attempts]);
        }
        if ($row->getFailedAttempts() > 0 || $row->getLockedUntil() !== null) {
            $row->setFailedAttempts(0)->setLockedUntil(null);
            $em->flush();
        }
    }

    private static function locked(int $seconds, array $context): CashRegisterException
    {
        $seconds = max(1, $seconds);

        return new CashRegisterException('PIN_LOCKED', sprintf('PIN bloqué après %d essais : réessayez dans %d minute(s)', self::MAX_ATTEMPTS, (int) ceil($seconds / 60)), 423, [], $context + ['retryAfter' => $seconds]);
    }

    private function row(User $user): ?PosUserPin
    {
        return $this->emProvider->getEntityManager()->getRepository(PosUserPin::class)->findOneBy(['user' => $user]);
    }

    private function peppered(string $pin): string
    {
        return hash_hmac('sha256', $pin, $this->secret);
    }

    private function hasher(): \Symfony\Component\PasswordHasher\PasswordHasherInterface
    {
        return $this->hashers->getPasswordHasher(User::class);
    }
}
