<?php

namespace App\MemoiresVivantes\UseCase\Account;

use App\Entity\User;
use App\MemoiresVivantes\Exception\ApiErrorException;
use App\MemoiresVivantes\Services\AccountDeletionService;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** DELETE /api/memoires/mobile/account : mot de passe redemandé, puis suppression (anonymisation) du compte */
class DeleteMobileAccountUseCase
{
    public function __construct(private readonly UserPasswordHasherInterface $hasher, private readonly AccountDeletionService $deletion)
    {
    }

    /**
     * @throws ApiErrorException PASSWORD_INVALID (401), ADMIN_ACCOUNT (403 : un administrateur se supprime depuis la console, pas depuis l'application)
     */
    public function execute(User $user, string $password): array
    {
        if (in_array('ROLE_ADMIN', $user->getRoles(), true) || in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            throw new ApiErrorException('ADMIN_ACCOUNT', 'Un compte administrateur ne peut pas être supprimé depuis l\'application.', 403);
        }
        if ($password === '' || !$this->hasher->isPasswordValid($user, $password)) {
            throw new ApiErrorException('PASSWORD_INVALID', 'Mot de passe incorrect. La suppression du compte exige votre mot de passe actuel.', 401);
        }

        return ['deleted' => true] + $this->deletion->delete($user);
    }
}
