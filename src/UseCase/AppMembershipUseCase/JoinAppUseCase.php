<?php

namespace App\UseCase\AppMembershipUseCase;

use App\Services\AppMembershipService\AppMembershipException;
use App\Services\AppMembershipService\AppMembershipService;
use App\Services\AuthenticationService;
use App\Services\TokenService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/apps/join (lot APPS-MEMBRES M3, `docs/apps-membres.md` § 6) : le client accepte les conditions d'une famille qu'une
 * connexion vient de lui refuser. L'appartenance est créée (ou l'acceptation enregistrée), puis la session est rendue exactement
 * comme une connexion réussie sur la même plateforme : jetons, cookies du web, famille dans le jeton, dernière connexion marquée.
 */
class JoinAppUseCase
{
    public function __construct(private readonly AppMembershipService $memberships, private readonly TokenService $tokens, private readonly AuthenticationService $authentication)
    {
    }

    /**
     * @param array<string, mixed> $body `{ joinToken, termsVersion, accepted }`
     *
     * @throws AppMembershipException
     */
    public function execute(array $body, Request $request): Response
    {
        $joined = $this->memberships->join($body['joinToken'] ?? null, $body['termsVersion'] ?? null, $body['accepted'] ?? null, $request->getSchemeAndHttpHost(), $request->query->get('locale'));
        $this->authentication->recordLogin($joined['user']);

        return $this->tokens->createResponseWithTokens($this->tokens->generateTokens($joined['user'], $joined['platform'], $joined['app']), $joined['platform'], $request->getHost());
    }
}
