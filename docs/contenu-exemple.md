# Contenu d'exemple des sites (12/10/2026)

Un site neuf montre son style tout de suite : il contient des présentations, produits, catégories… d'exemple, que le
client remplace ensuite en cliquant dessus. Contexte général : `docs/architecture.md`.

## Principe

- **Site de référence = `gmasuite`**, la base clonée à chaque création de site. Son contenu d'exemple est défini dans
  `config/site-content/reference.php` (français et anglais, prix en cents) et écrit par
  `app:site-content:build-reference`.
- **Création d'un site** : la base est clonée de `gmasuite`, puis tout son contenu est marqué `is_demo`
  (`TenantConnectionManager::markDemoContent`). Le front n'a rien à appeler.
- **Site existant** : `POST /api/site-content/seed` copie le contenu de `gmasuite` dans le site
  (`SiteContentCopier`, nouveaux identifiants).
- **Marquage** : colonne `is_demo` sur 13 tables (`SiteContentSpec`), renvoyée `isDemo` dans les GET. Une modification
  par le client rend la fiche définitive (`DemoContentMarker`) : PATCH d'un contenu, variante, valeur ou combinaison de
  personnalisation (le produit ou l'option qui la porte), présentations d'un groupe, édition EasyAdmin. Les écritures
  automatiques (stock décompté par une commande, note d'un avis) ne la changent pas.
- **Images** : fichiers communs `assets/uploads/<dossier>/exemple-*.jpg` (dégradé et formes, aucun texte), partagés en
  lecture par tous les sites, jamais recopiés ni effacés. Une image remplacée par le client est un nouveau fichier,
  propre à son site.
- Pas de formule d'abonnement (liée à Stripe), de commande, d'avis ni de client ; ni la fiche entreprise ni les
  réglages du site ne sont touchés.

## Ressources

| Ressource (nom de l'éditeur) | Table | Portée |
|---|---|---|
| `presentations`, `presentation-groups` (avec leurs présentations) | `presentation`, `presentation_group` | landing |
| `baniere-statiques`, `bannieres`, `videos`, `service-offers` | tables du même nom | landing |
| `marques` (avec leur catégorie de marques) | `marque` | landing |
| `explore-cards` | `explore_card` | landing et boutique |
| `category`, `products` (traductions, galerie, variantes couleur/taille avec stock, promotion datée) | `categories`, `product` | boutique |
| `customization-options` (copiées avec les produits qui les utilisent, valeurs et combinaisons) | `product_option` | boutique |
| `homeslider`, `features` | `home_slider`, `feature` | boutique |

Contenu actuel de `gmasuite` : 9 présentations, 2 groupes, 1 bannière statique, 3 bannières, 1 vidéo (image
d'attente, sans vidéo), 4 services, 6 marques, 3 cartes, 3 catégories, 8 produits dont une gourde personnalisable
(2 options, 6 combinaisons), 3 diapositives, 4 atouts (sans icône : pictogramme par défaut du frontend).
`gmasuite` a aussi les référentiels de la boutique (statuts de commande et de paiement, moyens de paiement, taxes,
transporteurs, unité de vente), copiés de `demo` : sans eux, un site boutique neuf n'avait aucun statut de commande.

## Routes (`ROLE_ADMIN`, `SiteContentController`)

- `POST /api/site-content/seed` `{ "scope": ["landing", "boutique"], "mode": "empty" | "add" }` → 201
  `{ created: { ressource: nombre }, ids: { ressource: { idRéférence: nouvelId } } }` (200, `created: {}` si rien).
  - `empty` : seulement les ressources vides du site, jamais d'écrasement ; un second appel ne crée rien. Exception : un
    groupe de présentations est copié avec ses présentations.
  - `add` : à côté de l'existant ; slugs des produits rendus uniques (`-2`, `-3`…), atouts placés après ceux du site.
  - Un produit n'est rangé que dans les catégories copiées par le même appel. Couleurs, tailles, unité de vente et
    catégories de marques sont reprises par leur code ou leur nom si le site les a déjà, créées sinon.
  - 409 sur `gmasuite` lui-même ; 422 si `scope` ou `mode` est invalide.
- `GET /api/site-content/demo?locale=` → `[{ resource, id, label, fields }]` : fiches encore d'exemple ; `fields` =
  champs à remplacer (noms de l'API de l'éditeur).
- `DELETE /api/site-content/demo` → `{ deleted: { ressource: nombre }, kept: [{ resource, id, label, reason }] }`.
  - 409 `{ error, errors: [{ resource, id, label, usedBy }] }` et **rien n'est supprimé** si un produit d'exemple sert
    ailleurs : commande, paiement en cours (`checkout_session` ouverte), réservation, formule d'abonnement, avis, véhicule,
    produit lié, livraison.
  - Gardée et rendue définitive (`kept`) : une fiche d'exemple qu'utilise une fiche du client (présentation dans un
    groupe du client, catégorie d'un produit du client, option d'un produit du client).
  - Les fichiers d'images ne sont jamais effacés (images communes).

Journal : `site-content` avec les actions `seed` et `demo-delete`. Ces écritures ne se rétablissent pas par le journal
(comme tout ajout ou suppression) : l'une s'annule par l'autre. Tout le cache de lecture du site est vidé après chaque
écriture.

## Modifier le contenu de référence

1. Modifier `config/site-content/reference.php` (images : dossier, fichier, taille, couleurs du dégradé).
2. Vider dans `gmasuite` les tables des ressources à refaire : la commande laisse telle quelle une ressource déjà
   remplie. Sauvegarder d'abord (`pg_dump`).
3. `docker exec -w /var/www symfony_app_v2 php bin/console app:site-content:build-reference` (`--redraw` pour
   redessiner les images, `--referentials-from=<base>` pour les référentiels, `--to=<base>_test` pour un essai). La
   commande refuse toute autre base que `gmasuite` ou une base `*_test`.

Seuls les sites créés **après** la modification en héritent ; un site existant la reçoit par `seed`.

## Code et tests

`src/Services/SiteContentService/` (`SiteContentSpec`, `SiteContentCopier`, `SiteDemoContentService`,
`DemoContentMarker`, `ReferenceContentBuilder`, `ReferenceImageGenerator`, `SqlRowWriter`),
`src/UseCase/SiteContentUseCase/`, `src/Controller/SiteContentController/`,
`src/Command/BuildReferenceContentCommand.php`, `src/Entity/Traits/DemoContentTrait.php` ; migration
`Version20261012100000`, script `scripts/migrate_all_v2_site_demo_content.sh`.

Tests : `tests/Functional/SiteContent/SiteContentApiTest.php`. La référence des tests est `db_site_reference_test`
(clone du modèle des tests, vidé par `tests/bootstrap.php`, rempli par le test), jamais `gmasuite`
(`SITE_CONTENT_REFERENCE_DB`).

Points d'attention :

- Beaucoup de bases de site n'ont pas de valeur par défaut sur `id` (Doctrine appelle la séquence lui-même) :
  `SqlRowWriter` appelle `nextval('<table>_id_seq')` explicitement.
- `ProductVariant` relance un `flush()` imbriqué après chaque écriture (quantité du produit) : il écrase les autres
  changements en attente du produit. Le marquage `isDemo` est donc enregistré à part, après l'écriture.
