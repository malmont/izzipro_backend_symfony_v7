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
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        private readonly ?PosSettingsService $settings = null
    ) {
    }

    /** Format par défaut (4 à 6 chiffres) ; les règles du site sont dans validForSite() (R1) */
    public static function validFormat(mixed $pin): bool
    {
        return is_string($pin) && preg_match('/^\d{4,6}$/', $pin) === 1;
    }

    /** @return array{minLength: int, maxLength: int, maxAttempts: int, lockMinutes: int} */
    private function rules(): array
    {
        return $this->settings?->pinRules() ?? PosSettingsService::PIN;
    }

    private function validForSite(mixed $pin): bool
    {
        $r = $this->rules();

        return is_string($pin) && preg_match('/^\d{' . $r['minLength'] . ',' . $r['maxLength'] . '}$/', $pin) === 1;
    }

    public function hasPin(User $user): bool
    {
        return $this->row($user) !== null;
    }

    public function set(User $user, mixed $pin): void
    {
        if (!$this->validForSite($pin)) {
            $r = $this->rules();
            throw new CashRegisterException('VALIDATION_FAILED', sprintf('newPin : %d à %d chiffres attendus (chaîne)', $r['minLength'], $r['maxLength']), 422, [['path' => 'newPin', 'message' => sprintf('%d à %d chiffres attendus', $r['minLength'], $r['maxLength'])]]);
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
            throw self::locked($row->getLockedUntil()->getTimestamp() - $now->getTimestamp(), $context, $this->rules()['maxAttempts']);
        }
        $em = $this->emProvider->getEntityManager();
        $rules = $this->rules();
        if (!is_string($pin) || !preg_match('/^\d{4,12}$/', $pin) || !$this->hasher()->verify($row->getPinHash(), $this->peppered($pin))) {
            $attempts = $row->getFailedAttempts() + 1;
            $this->audit->record(PosAuditService::PIN_FAILED, ['userId' => $user->getId(), 'email' => $user->getEmail(), 'attempts' => $attempts, 'via' => $via], subjectType: 'user', subjectId: $user->getId());
            if ($attempts >= $rules['maxAttempts']) {
                $row->setFailedAttempts(0)->setLockedUntil($now->modify('+' . $rules['lockMinutes'] . ' minutes'));
                $em->flush();
                $this->audit->record(PosAuditService::PIN_LOCKED, ['userId' => $user->getId(), 'email' => $user->getEmail(), 'minutes' => $rules['lockMinutes'], 'via' => $via], subjectType: 'user', subjectId: $user->getId());
                throw self::locked($rules['lockMinutes'] * 60, $context, $rules['maxAttempts']);
            }
            $row->setFailedAttempts($attempts);
            $em->flush();
            throw new CashRegisterException('PIN_INVALID', 'PIN incorrect', $invalidStatus, [], $context + ['attemptsLeft' => $rules['maxAttempts'] - $attempts]);
        }
        if ($row->getFailedAttempts() > 0 || $row->getLockedUntil() !== null) {
            $row->setFailedAttempts(0)->setLockedUntil(null);
            $em->flush();
        }
    }

    private static function locked(int $seconds, array $context, int $maxAttempts = self::MAX_ATTEMPTS): CashRegisterException
    {
        $seconds = max(1, $seconds);

        return new CashRegisterException('PIN_LOCKED', sprintf('PIN bloqué après %d essais : réessayez dans %d minute(s)', $maxAttempts, (int) ceil($seconds / 60)), 423, [], $context + ['retryAfter' => $seconds]);
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
