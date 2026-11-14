<?php

namespace App\Services\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Chemin d'une requête tel que le ROUTEUR le lit (lot CHEMIN, 14/11/2026, `docs/architecture.md`, « Chemin d'une requête »).
 *
 * `Request::getPathInfo()` rend le chemin brut, non décodé ; le routeur et les règles d'accès de Symfony, eux, le décodent
 * (`rawurldecode`). Une garde qui comparait le chemin brut ne reconnaissait donc pas `/api/%6cogin` ou `/%61pi/profile`, que le
 * routeur sert pourtant comme `/api/login` et `/api/profile` : la limite de débit de la connexion et la vérification anti-CSRF se
 * contournaient en encodant une lettre. Toute garde, tout abonné qui décide d'après le chemin passe par ici : il voit ce que voit le
 * routeur. Garde-fou : `tests/Functional/Security/RequestPathGuardTest.php`.
 */
final class RequestPath
{
    public static function of(Request $request): string
    {
        return rawurldecode($request->getPathInfo());
    }
}
