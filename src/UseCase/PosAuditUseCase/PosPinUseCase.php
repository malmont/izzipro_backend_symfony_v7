<?php

namespace App\UseCase\PosAuditUseCase;

use App\Entity\PosUserPin;
use App\Entity\User;
use App\Security\TwoFactorPolicy;
use App\Services\AuthenticationService;
use App\Services\CaisseService\CashRegisterException;
use App\Services\PosAuditService\PosAuditService;
use App\Services\PosAuditService\PosPinService;
use App\Services\PosAuditService\PosTerminalService;
use App\Services\TenantEntityManagerProvider;
use App\Services\TokenService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** PIN de caisse : définition, déverrouillage, liste des caissiers d'un terminal, connexion par PIN (chantier C3) */
class PosPinUseCase
{
    private const POS_ROLES = ['ROLE_USER_POS', 'ROLE_POS_MANAGER'];
    private const ADMIN_ROLES = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly PosPinService $pins,
        private readonly PosTerminalService $terminals,
        private readonly PosAuditService $audit,
        private readonly UserPasswordHasherInterface $passwords,
        private readonly AuthenticationService $authentication,
        private readonly TokenService $tokens,
        private readonly TwoFactorPolicy $twoFactor
    ) {
    }

    /** POST /api/pos/pin {password, newPin} : l'utilisateur connecté définit ou change son PIN */
    public function setPin(User $user, mixed $password, mixed $newPin): void
    {
        if (!is_string($password) || $password === '' || !$this->passwords->isPasswordValid($user, $password)) {
            throw new CashRegisterException('PASSWORD_INVALID', 'Mot de passe incorrect', 400);
        }
        $this->pins->set($user, $newPin);
        $this->audit->record(PosAuditService::PIN_SET, ['userId' => $user->getId()], subjectType: 'user', subjectId: $user->getId(), user: $user);
    }

    /** POST /api/pos/unlock {pin} : PIN de l'utilisateur du jeton ; pas de nouveau jeton */
    public function unlock(User $user, mixed $pin): array
    {
        $this->pins->verify($user, $pin, 400, [], 'unlock');
        $entry = $this->audit->record(PosAuditService::UNLOCK, [], subjectType: 'user', subjectId: $user->getId(), user: $user);

        return ['unlockedAt' => $entry['occurredAt']];
    }

    /** GET /api/pos/users : caissiers et responsables vérifiés, avec un PIN, pouvant se connecter par PIN */
    public function users(): array
    {
        $this->requireTerminal();
        $users = [];
        foreach ($this->emProvider->getEntityManager()->getRepository(PosUserPin::class)->findAll() as $pin) {
            $user = $pin->getUser();
            if ($this->pinLoginRefusal($user) === null) {
                $first = (string) $user->getFirstname();
                $last = (string) $user->getLastname();
                $users[] = [
                    'id' => $user->getId(),
                    'name' => trim("$first $last"),
                    'initials' => mb_strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1)),
                    'roles' => array_values(array_intersect(self::POS_ROLES, $user->getRoles())),
                ];
            }
        }
        usort($users, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $users;
    }

    /** POST /api/pos/pin-login {userId, pin} : mêmes jetons que /api/login en platform « pos » */
    public function pinLogin(mixed $userId, mixed $pin, Request $request): Response
    {
        $terminal = $this->requireTerminal();
        $user = is_numeric($userId) ? $this->emProvider->getEntityManager()->find(User::class, (int) $userId) : null;
        if (!$user instanceof User) {
            $this->audit->record(PosAuditService::LOGIN_FAILED, ['method' => 'pin', 'userId' => is_numeric($userId) ? (int) $userId : null, 'reason' => 'unknown_user'], anonymous: true);
            throw new CashRegisterException('PIN_INVALID', 'Utilisateur ou PIN incorrect', 401);
        }
        if ($refusal = $this->pinLoginRefusal($user)) {
            $this->audit->record(PosAuditService::LOGIN_FAILED, ['method' => 'pin', 'userId' => $user->getId(), 'email' => $user->getEmail(), 'reason' => $refusal], anonymous: true);
            throw new CashRegisterException('PIN_LOGIN_NOT_ALLOWED', 'Connexion par PIN non autorisée pour ce compte (administrateur, code e-mail ou compte non caisse)', 403);
        }
        try {
            $this->pins->verify($user, $pin, 401, [], 'pin-login');
        } catch (CashRegisterException $e) {
            $this->audit->record(PosAuditService::LOGIN_FAILED, ['method' => 'pin', 'userId' => $user->getId(), 'email' => $user->getEmail(), 'reason' => $e->errorCode], anonymous: true);
            throw $e;
        }
        $this->authentication->recordLogin($user);
        $this->audit->record(PosAuditService::LOGIN, ['method' => 'pin', 'email' => $user->getEmail(), 'terminal' => $terminal['label']], subjectType: 'user', subjectId: $user->getId(), user: $user);
        $tokens = $this->tokens->generateTokens($user, 'pos');

        return $this->tokens->createResponseWithTokens($tokens, 'pos', $request->getHost());
    }

    /** Raison du refus de la connexion par PIN, ou null : administrateur, code e-mail exigé, compte non caisse, non vérifié, supprimé */
    private function pinLoginRefusal(User $user): ?string
    {
        return match (true) {
            $user->isDeleted() => 'deleted',
            !$user->isVerified() => 'not_verified',
            array_intersect(self::ADMIN_ROLES, $user->getRoles()) !== [] => 'admin',
            array_intersect(self::POS_ROLES, $user->getRoles()) === [] => 'not_pos',
            $this->twoFactor->required($user) => 'otp_required',
            default => null,
        };
    }

    private function requireTerminal(): array
    {
        return $this->terminals->current() ?? throw new CashRegisterException('TERMINAL_REQUIRED', 'Terminal de caisse non identifié (en-tête X-Pos-Terminal)', 403);
    }
}
