<?php

namespace App\Services\Storefront;

use App\Security\StorefrontViewer;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Un article HORS LIGNE (`isWeb` faux ou vide) ne se lit pas au public (lot FICHES-HORS-LIGNE, 21/11/2026) : le visiteur, le
 * compte client et le lien d'aperçu (qui montre ce que verra le public) reçoivent le même 404 qu'un article absent, au même
 * corps, pour ne pas révéler son existence. L'ÉQUIPE du site le lit toujours : la règle de la boutique en construction
 * (`StorefrontViewer::isTeam`, rôles relus en base depuis le jeton, entrée d'agence ou de directeur comprise), car l'éditeur
 * de pages, la gestion, MAUI et la caisse (articles de caisse seule) en ont besoin.
 *
 * Chaque route garde SA réponse d'article absent : elle demande `hidden()` et lève alors la même exception que pour un
 * identifiant inconnu. Le contrôle se fait AVANT tout cache (les fiches sont en cache sous une clé partagée par tous).
 * Hors requête HTTP (commande, worker) : rien n'est caché.
 */
final class PublicProductVisibility
{
    private const TEAM_ATTRIBUTE = '_public_product_visibility_team';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly StorefrontViewer $viewer,
        private readonly RequestStack $requests,
    ) {
    }

    /** Vrai si l'article existe, n'est pas en ligne, et que la requête ne vient pas d'un compte d'équipe du site */
    public function hidden(int $productId): bool
    {
        $row = $this->emProvider->getEntityManager()->getConnection()->fetchAssociative('SELECT is_web FROM product WHERE id = ?', [$productId]);
        if ($row === false || $row['is_web'] === true) {
            return false; // absent : la route rend son propre 404 ; en ligne : servi à tous
        }

        return !$this->viewerIsTeam();
    }

    /** La requête vient-elle d'un compte d'équipe du site ? (lu une fois par requête) */
    public function viewerIsTeam(): bool
    {
        $request = $this->requests->getMainRequest();
        if ($request === null) {
            return true; // hors requête HTTP : un traitement interne, jamais le public
        }
        if (!$request->attributes->has(self::TEAM_ATTRIBUTE)) {
            $request->attributes->set(self::TEAM_ATTRIBUTE, $this->viewer->isTeam($request));
        }

        return (bool) $request->attributes->get(self::TEAM_ATTRIBUTE);
    }
}
