<?php

namespace App\UseCase\SuperAdminUseCase;

use App\Services\SuperAdminService\SuperAdminAccountService;

/**
 * Création ou mise à jour du compte super administrateur sur toutes les bases (écran EasyAdmin, 03/11/2026) : contrôle de la saisie
 * (courriel, mot de passe d'au moins 12 caractères confirmé), puis application base par base.
 */
class ApplySuperAdminAccountUseCase
{
    public function __construct(private readonly SuperAdminAccountService $accounts)
    {
    }

    /** @return array{errors: list<string>, results: list<array{dbname: string, codes: list<string>, action: string, message: ?string}>} */
    public function execute(string $email, string $password, string $confirmation, string $firstname, string $lastname): array
    {
        $email = mb_strtolower(trim($email));
        $errors = SuperAdminAccountService::validate($email, $password, $confirmation);
        if ($errors !== []) {
            return ['errors' => $errors, 'results' => []];
        }

        return ['errors' => [], 'results' => $this->accounts->apply($email, $password, mb_substr(trim($firstname), 0, 100) ?: 'Plateforme', mb_substr(trim($lastname), 0, 100) ?: 'Arkanoa')];
    }
}
