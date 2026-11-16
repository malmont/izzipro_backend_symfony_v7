# Boutique en construction (lot CONSTRUCTION, 16/11/2026)

Tout site neuf naît « en construction », sur le modèle des boutiques en ligne du marché : le public n'y voit que « Bientôt ouvert », au
nom de la boutique, jusqu'à ce que son administrateur l'ouvre. Les sites qui existaient avant ce lot restent « ouverts » : rien ne
change pour eux. Besoin validé par le propriétaire le 16/11/2026 ; contrat des réponses validé par le chef de projet le même jour.

Code : `src/Services/Storefront/StorefrontService.php` (état, liste de contrôle, ouverture, lien d'aperçu),
`src/Security/StorefrontGateSubscriber.php` (ce que le serveur ferme), `src/Controller/StorefrontController/`. Tests :
`tests/Functional/Storefront/StorefrontTest.php`, et `tests/Functional/Platform/AgencySitesTest.php` pour un site neuf.

## 1. L'état d'un site

Quatre colonnes sur la fiche du site (`entreprise`), lues et écrites en SQL direct : `storefront_state` (`construction` | `open`),
`storefront_opened_at`, `storefront_preview_hash`, `storefront_preview_expires_at`. Migration `Version20270115100000`, script
`scripts/migrate_all_v2_construction.sh` (hors dépôt).

- **Site existant** : la colonne naît à `open`. Aucun site existant n'est fermé par la migration (le script s'arrête sinon).
- **Site neuf** : à sa création, `TenantConnectionManager::createTenant` règle la valeur par défaut de la colonne, dans la base de CE
  site, sur `construction`. La fiche du site n'existe pas encore à ce moment : quel que soit le chemin qui la créera ensuite
  (formulaire de la plateforme, agence, administrateur), elle naît « en construction ». Elle porte le numéro 1, que les fronts lisent.
- Un site sans fiche, ou une base pas encore migrée, est tenu pour « ouvert » : jamais un site existant fermé par accident.
- L'état est gardé en cache 15 secondes côté serveur ; ouvrir, refermer ou changer le lien d'aperçu vide le cache du site.

## 2. Ce que le visiteur obtient

`GET /api/site/storefront` (public) → `{ state, name, locale, openedAt, preview: { active, expiresAt } }`. C'est la première lecture
du front, sur toute page ; `Cache-Control` de 15 secondes (`public` pour un visiteur, `private` dès qu'un compte ou un jeton d'aperçu
est en jeu). Tant que cette route n'existe pas sur un serveur (404), le front se comporte comme pour `open`.

En construction, le serveur ferme lui-même ce que la page « Bientôt ouvert » cache (`StorefrontGateSubscriber`) :

| Quoi | Pour qui | Réponse |
|---|---|---|
| Lectures du site public et de la boutique (`CONTENT` : catalogue, pages, réglages, bannières, formules, transporteurs…) | visiteur, compte client | 403 `STOREFRONT_UNDER_CONSTRUCTION { name }` |
| Les mêmes lectures | compte d'équipe connecté ; ou jeton d'aperçu valable (en-tête `X-Storefront-Preview`) | servies |
| Commander, payer, s'inscrire, écrire par un formulaire public (`ORDERS` : commande, panier chiffré, paiement, contact, infolettre, réservation, formulaire à étapes, candidature, abonnement, inscription) | **tous**, administrateur et aperçu compris | 403 `STOREFRONT_UNDER_CONSTRUCTION` |
| `POST /api/order/create` et `/api/payment` | équipe de la caisse (`ROLE_USER_POS`, `ROLE_POS_MANAGER`) | servies : la vente en magasin n'est pas la boutique publique |
| Connexion, mot de passe oublié, code à usage unique, devises, découverte du site | tous | servies |
| `GET /api/entreprise/{id}` | visiteur | servie, **réduite** à l'identité légale, aux textes légaux et au logo (`StorefrontService::LEGAL_FIELDS`) ; même forme, le reste vide |
| Applications de l'équipe, caisse, gestion, Mémoires Vivantes, Boussole, webhooks | — | inchangées |

La commande d'essai pendant la construction n'existe pas : elle est notée pour plus tard. Les routes publiques de `security.yaml` sont
toutes classées, fermées ou gardées ouvertes (`KEPT`) : l'essai `testEveryPublicReadOfTheSecurityRulesIsFiledAsClosedOrKeptUnderConstruction`
échoue si une route publique nouvelle n'a pas été rangée.

## 3. Voir la vraie boutique avant l'ouverture

- Un **compte d'équipe connecté** du site (administrateur, assistance, caisse…) : `preview.active` vaut vrai, les lectures sont servies.
- Un **lien d'aperçu** : `https://<adresse du site>/apercu#token=<64 caractères>`, valable 7 jours ; un nouveau lien remplace l'ancien ;
  il est retiré à l'ouverture. Le jeton est dans le fragment de l'adresse, jamais dans les paramètres. Le front le garde pour la
  session du navigateur et l'envoie dans l'en-tête `X-Storefront-Preview`. Contrôle : `POST /api/site/storefront/preview { token }` →
  `{ valid: true, expiresAt }` ou 400 `PREVIEW_LINK_INVALID`. Le lien ouvre les LECTURES seulement.
- La base ne garde que l'empreinte du jeton. Ni le jeton ni l'en-tête ne sont écrits dans un journal : le lien n'est rendu qu'une
  fois, à sa création.

## 4. Ouvrir la boutique

Administrateur du site (`ROLE_ADMIN`) :

| Route | Rôle |
|---|---|
| `GET /api/site/storefront/checklist` | `{ state, openedAt, canOpen, items: [{ key: products \| payment \| terms, ok, blocking, message }] }` |
| `POST /api/site/storefront/open` | `{ state: open, openedAt }`, ou 409 `STOREFRONT_NOT_READY { items }` |
| `POST /api/site/storefront/close` | retour en construction |
| `POST /api/site/storefront/preview-link` | `{ url, expiresAt }` |

La liste de contrôle avertit sans bloquer (articles visibles, conditions de vente remplies), sauf **sans aucun moyen de paiement**
quand la boutique en ligne fait partie du site : un site vitrine ou Mémoires Vivantes n'a pas besoin d'un moyen de paiement pour
ouvrir. Chaque geste est au journal du site (`content_audit_log`, ressource `storefront` : `open`, `close`, `preview_link`).

Agence, pour un site qu'elle a **créé** (`docs/agences-et-import.md` § 8) : `GET /api/agency/sites/{code}/storefront` (les deux rôles),
`POST …/storefront/open` et `POST …/storefront/preview-link` (responsable). C'est le lien d'aperçu qui lui montre la boutique : elle
n'a pas de compte dans le site de son client. Une agence suspendue lit encore, mais n'ouvre plus.

## 5. Fronts

- **boutique next** (livré le 16/11/2026) : page « Bientôt ouvert », noindex, page `/apercu` qui lit le jeton, en-tête
  `X-Storefront-Preview`, bandeau d'aperçu, commande et formulaires masqués en aperçu ; pages légales depuis la fiche réduite.
- **appli de gestion web** : écrans de l'administrateur (liste de contrôle, ouvrir, refermer, lien d'aperçu) et boutons de l'espace
  d'agence.
- **Application mobile de la boutique** : reprendra la lecture de l'état et le refus des commandes à sa reprise ; d'ici là le serveur
  refuse de toute façon.

## 6. Limites connues

- Un client invité par import (`docs/import-boutique.md` § 9) ne peut pas accepter son invitation tant que le site est en
  construction : la page de la boutique est fermée. Inviter ses clients après l'ouverture.
- Le site vitrine d'un site en construction est fermé aussi : il n'y a pas d'ouverture de la vitrine seule.
- Refermer un site ouvert ferme aussitôt ses commandes ; les commandes déjà passées se traitent normalement dans l'administration.
- La valeur par défaut de `storefront_state` diffère d'une base à l'autre (`open` sur les sites d'avant le lot, `construction` sur
  les sites nés après) : une migration future ne doit pas la réécrire.

## Tenir cette fiche à jour

Toute route publique nouvelle se classe dans `StorefrontGateSubscriber` (`CONTENT`, `ORDERS` ou `KEPT`) ; l'essai de classement le rappelle.
