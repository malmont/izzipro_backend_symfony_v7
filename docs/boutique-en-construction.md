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
- Un site sans fiche suit la valeur par défaut de sa base : « en construction » s'il est né après ce lot. Une base pas encore migrée
  ou injoignable est tenue pour « ouverte » (jamais un site existant fermé par accident), sans que cette réponse soit gardée en cache.
- L'état est gardé en cache 15 secondes côté serveur ; ouvrir, refermer ou changer le lien d'aperçu vide le cache du site.

## 2. Ce que le visiteur obtient

`GET /api/site/storefront` (public) → `{ state, name, locale, openedAt, preview: { active, expiresAt } }`. C'est la première lecture
du front, sur toute page ; `Cache-Control` de 15 secondes (`public` pour un visiteur, `private` dès qu'un compte ou un jeton d'aperçu
est en jeu). Tant que cette route n'existe pas sur un serveur (404), le front se comporte comme pour `open`.

En construction, le serveur ferme lui-même ce que la page « Bientôt ouvert » cache (`StorefrontGateSubscriber`). La règle est
**fermée par défaut** : sur un site en construction, TOUTE route est refusée au visiteur et au compte client, qu'elle soit de l'API
ou une page du serveur, sauf une liste courte de routes gardées ouvertes (`KEPT`). Une route nouvelle est donc fermée sans qu'on y
pense. (La première écriture du lot fermait une liste de chemins ; la relecture indépendante l'a contournée par une autre adresse de
la fiche d'un article, un suffixe `.json` et des pages hors de `/api` : d'où cette règle.)

| Quoi | Pour qui | Réponse |
|---|---|---|
| Toute route hors de `KEPT` : catalogue, pages, réglages, comptes clients, pages de l'ancienne boutique, **Mémoires Vivantes, Living Memory et Boussole comprises** | visiteur, compte client | 403 `STOREFRONT_UNDER_CONSTRUCTION { name }` (une page sobre « Bientôt ouvert » pour une page du serveur) |
| Les mêmes routes | compte d'**équipe** connecté (administrateur, assistance, caisse, biographe, agence…) | servies |
| Les mêmes routes, en lecture (`GET`, `HEAD`) | jeton d'aperçu valable (en-tête `X-Storefront-Preview`) | servies ; jamais une écriture |
| Commander, payer, s'inscrire, écrire par un formulaire public (`ORDERS` : commande, panier chiffré, paiement, contact, infolettre, réservation, formulaire à étapes, candidature, abonnement, inscription, invitation d'un client repris ; formulaires des pages du serveur) | **tous**, équipe et aperçu compris | 403 `STOREFRONT_UNDER_CONSTRUCTION` |
| `KEPT` : connexion, mot de passe, code à usage unique, devises, découverte et état du site, fichiers (logo), espace d'administration, création de site, entrée des applications de l'équipe (activation, profil, PIN de caisse), webhooks, déploiement des réglages, boîtiers | tous | servies |
| `GET /api/entreprise/{id}` | visiteur, compte client | servie, **réduite** à l'identité légale, aux textes légaux, au logo et aux indicateurs des applications (`StorefrontService::LEGAL_FIELDS`) ; même forme, le reste vide |

- La vente en magasin (`POST /api/order/create-multi-payment`, routes `/api/pos`) n'est pas la boutique publique : la caisse vend sur
  un site en construction. La commande d'essai de la boutique n'existe pas : elle est notée pour plus tard.
- Un site Mémoires Vivantes ou Boussole se **ouvre avant** d'être utilisé par ses clients : en construction, leurs clients sont
  refusés comme ceux de la boutique (sinon on paierait sur un site qui n'est pas ouvert). L'équipe, elle, y travaille.
- Le compte d'équipe est reconnu même sur les routes que le pare-feu de l'API ne couvre pas (`StorefrontViewer` : le jeton du site y
  est lu comme par le pare-feu, les rôles relus en base).
- Toute réponse d'un site en construction part en `Cache-Control: private, no-store` (sauf l'état du site et les fichiers) : une
  lecture servie à l'équipe ou par un lien d'aperçu n'est jamais gardée par un cache partagé.
- `StorefrontTest::testOnlyAShortListOfRoutesStaysOpenUnderConstructionAmongAllTheRoutesOfTheApplication` relit TOUTES les routes de
  l'application : les routes gardées ouvertes hors de l'espace d'administration y sont écrites en toutes lettres.

## 3. Voir la vraie boutique avant l'ouverture

- Un **compte d'équipe connecté** du site (administrateur, assistance, caisse…) : `preview.active` vaut vrai, les lectures sont servies.
- Un **lien d'aperçu** : `https://<adresse du site>/apercu#token=<64 caractères>`, valable 7 jours ; un nouveau lien remplace l'ancien ;
  il est retiré à l'ouverture et quand le site est refermé. Le jeton est dans le fragment de l'adresse, jamais dans les paramètres. Le front le garde pour la
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

Les mêmes gestes sont dans l'espace d'administration du serveur, page **« Ouverture du site »** (`/admin/ouverture-du-site`, menu
« Mon site ») : état, liste de contrôle, « Ouvrir la boutique », « Refermer le site », « Créer un lien d'aperçu » (le lien est montré
une fois, sur la page qui suit sa création). Les écrans de « appli de gestion web » viendront ensuite.

La liste de contrôle avertit sans bloquer (articles visibles, conditions de vente remplies), sauf **sans aucun moyen de paiement**
(un compte de paiement connecté ET actif) quand la boutique en ligne fait partie du site : un site vitrine ou Mémoires Vivantes n'a pas besoin d'un moyen de paiement pour
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
- Le site vitrine d'un site en construction est fermé aussi : il n'y a pas d'ouverture de la vitrine seule. Mémoires Vivantes,
  Living Memory et Boussole de même, pour leurs clients.
- L'en-tête `X-Storefront-Preview` n'est pas dans la liste des en-têtes autorisés du serveur web (`docker/nginx`, hors de ce lot) :
  sans effet tant que le front appelle le serveur par son relais ; un appel direct du navigateur demanderait ce réglage.
- La liste de contrôle lit les conditions de vente dans les traductions de la fiche, et accepte un compte de paiement actif de l'un ou
  l'autre mode (essai ou réel) : elle ne regarde pas le mode de paiement du site.
- Refermer un site ouvert ferme aussitôt ses commandes ; les commandes déjà passées se traitent normalement dans l'administration.
- La valeur par défaut de `storefront_state` diffère d'une base à l'autre (`open` sur les sites d'avant le lot, `construction` sur
  les sites nés après) : une migration future ne doit pas la réécrire.

## Tenir cette fiche à jour

Toute route publique nouvelle se classe dans `StorefrontGateSubscriber` (`CONTENT`, `ORDERS` ou `KEPT`) ; l'essai de classement le rappelle.
