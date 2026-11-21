# Module Boutique

Boutique en ligne des sites (produits, variantes, réservation, panier, paiement Stripe, commandes, livraison,
compte client) et, depuis le 08/10/2026, **réglages de la boutique réglable** : le frontend compose toutes les pages
de la boutique avec le moteur des landing pages (`docs/landingpage.md`). Aucune boutique n'est en production : les
routes du commerce sont listées dans `docs/endpoints.md` (section « Boutique et commun ») et décrites au fil des
chantiers ci-dessous. Côté frontend : `src/components/Boutique/README.md` et `docs/boutique-reglable-plan.md`
(dépôt `Izzipro_next`). Demandes du frontend au backend : `docs/boutique-reglable-backend-demandes.md`.

## Réglages de la boutique réglable (08/10/2026)

| Besoin | Endpoint | Accès |
|---|---|---|
| Lire / enregistrer les réglages | `GET` / `PUT /api/boutique-settings` | public / `ROLE_ADMIN` |
| Historique et retour en arrière | `GET /api/landingpage-audit?resource=boutique-settings`, `GET …/{id}`, `POST …/{id}/restore` | `ROLE_ADMIN` |
| Modèles de site de la boutique | `GET`, `POST /api/boutique-site-models` ; `GET`, `PUT`, `DELETE …/{id}` | `ROLE_ADMIN` |

Code : `Controller/BoutiqueSettingsController/`, `UseCase/BoutiqueSettingsUseCase/`, `Services/BoutiqueSettingsService/`
(`BoutiqueSettingsService` : lecture et écriture ; `BoutiqueConfigurationValidator` : contrôles), entité `BoutiqueSetting`
(table `boutique_setting` par tenant, une ligne JSON), DTO `BoutiqueSettingsInputDto`, CRUD EasyAdmin
`Admin/BoutiqueSettingCrudController` (secours, sans contrôle). Schéma : migration `Version20261008100000`, script
`scripts/migrate_all_v2_boutique_settings.sh`.

### Contrat

- `PUT { configuration }` : la configuration est enregistrée **telle quelle** (document complet remplacé) et `GET` la
  restitue à l'identique (`{}` et `1.0` conservés, ordre des clés gardé) ; `null` tant que rien n'est enregistré.
  Corps de plus de 4 Mo (`BoutiqueSettingsService::MAX_CONFIGURATION_BYTES`) : 413. Réponse du PUT :
  `{ status }` ; refus : 422 `{ error: "Configuration de la boutique invalide", message, errors: [{ path, message }] }`,
  rien n'est enregistré.
- Forme attendue (celle du frontend, `boutiqueConfig.js`) : `navbar`, `footer` (`componentTypeKey: typeReglable`,
  `reglableConfig`), `tabs[]` (onglets du menu et pages système), `reglablePresets[]`, `charter`, `commerce`. Tout
  champ hors des points contrôlés ci-dessous est conservé sans contrôle.
- **Compositions** (`tabs[].sections[].reglableConfig` des sections `typeReglable`, `navbar`, `footer`,
  `reglablePresets[].config`) et **noms de section** : mêmes règles que `PUT /api/landingpage-settings` ; **verrou de version** (09/11/2026,
  `Services/SettingsVersion/SettingsVersion`) : `GET` rend `X-Settings-Version` (empreinte du JSON enregistré, hors clés de commerce ajoutées en
  lecture ; l'`ETag` reste celui du cache HTTP), le `PUT` accepte `X-Settings-Version` et répond 409 `SETTINGS_CHANGED { currentVersion }` si les réglages ont
  changé depuis la lecture (rien d'écrit) ; sans en-tête, écriture comme avant ; réponse du `PUT` : `{ status, version }` + mêmes en-têtes
  (`ReglableCompositionValidator` : schéma synchronisé, règles entre blocs, `RichTextPolicy`). Les types de blocs de la
  boutique (`stripePayment`, `cartLines`, `bookingCalendar`…) viennent du schéma publié par le frontend : rien à déclarer
  côté backend.
- **Pages système** (onglets portant `system`, catalogue IA `systemPages`, lu par `LandingAiCatalogue::systemPages`) :
  - `system` doit être une page connue (`product`, `customization`, `catalogue`, `cart`, `checkout`, `confirmation`,
    `account`, `address`, `order`, `financing`, `login`, `register`, `forgotPassword` au 08/10/2026) :
    `tabs[i].system : page système inconnue (connues : …)` ;
  - `variant` (fiche alternative) : texte de 1 à 40 caractères (lettres, chiffres, `_`, `-`) ; une seule page par couple
    (`system`, `variant`) : `tabs[i] : page système « product » (variante « rental ») déjà définie par tabs[j]` ;
  - chaque **bloc obligatoire** de la page doit figurer dans l'une de ses sections :
    `tabs[i].sections : page système « checkout » : bloc(s) obligatoire(s) manquant(s) : stripePayment`. Un bloc
    obligatoire est un type de bloc du schéma, ou l'un des deux groupes que le catalogue nomme sans qu'ils soient des
    types : `modeGroup` = container portant `mode` (`sale`, `rental`, `subscription`), `productList` = container
    `repeat.source: products`. Un nom qui n'est ni l'un ni l'autre n'est pas exigé (à signaler au frontend).
  - Catalogue sans `systemPages` (version antérieure) : aucun contrôle des pages système.
  - L'assistant IA compose une page système (`POST /api/landingpage-ai/compose`, `mode: page` + `system`) ou un site
    complet (`mode: site`, accueil puis pages système, charte déduite) avec la même règle des blocs obligatoires ; charte
    de la boutique acceptée en entrée (`charter`) : `config/landingpage/README.md`, « Fonctionnement » (15/10/2026).
- **Charte** (`charter`, clés connues seulement ; `null` = non réglé) : `primaryColor`, `accentColor`, `textColor`,
  `backgroundColor` : couleur (`#rgb`, `#rrggbb`, `#rrggbbaa`, `rgb()`, `rgba()`, `transparent`) ; `headingFont`,
  `bodyFont` : 1 à 100 caractères sans `<` ni `>` ; `radius` : nombre de 0 à 100 ou taille CSS (`px`, `rem`, `em`,
  `%`) ; `buttonStyle` : `solid`, `outline` ou `pill`. Depuis le 31/10/2026, la charte sert aussi de **repli au thème de
  l'application e-commerce mobile** (`GET /api/mobile/app-theme`, `docs/architecture.md`, « Thème de l'application
  e-commerce mobile ») : `primaryColor`, `accentColor` (ramenées à `#rrggbb`), `headingFont` (si la police est dans la
  liste de l'application), `radius` (borné à 28) et `buttonStyle`, chacun seulement quand le profil d'application
  `mobile` ne règle pas le champ. Lecture en SQL de la seule clé `charter`, sans passer par le cache des réglages.
- **Commerce** (`commerce`) : `guestCheckout`, `subscriptionsEnabled` : booléens ; `currency` : code ISO 4217
  (3 majuscules). Ces réglages ne sont pas encore appliqués par les routes du commerce (`order/create-guest`…) :
  voir les demandes § 6 et § 7.

### Journal et retour en arrière

Chaque PUT est inscrit dans `content_audit_log` (`ContentAuditRecorder`, resource `boutique-settings`, configuration
complète avant / après, clés de premier niveau modifiées dans `fields`). `POST /api/landingpage-audit/{id}/restore`
rétablit l'état d'avant (`ContentAuditRestorer::boutiqueSettings`) après l'avoir recontrôlé (422 s'il ne passe plus le
contrat actuel) ; 409 si les réglages ont changé depuis cette écriture, sauf `?force=1`. La première écriture d'un
site n'a pas d'état d'avant (`restorable: false`).

### Modèles de site

Même bibliothèque que les landing pages (`LandingSiteModelController`, route `/api/{app}-site-models` avec
`app` ∈ {`landingpage`, `boutique`}, colonne `landing_site_model.app`) : listes séparées, 30 modèles par application
et par site, configuration de 2 Mo au plus, contrôlée par `BoutiqueConfigurationValidator` pour la boutique (un modèle
enregistré doit pouvoir être rechargé). Journal : resource `boutique-site-models`. Un modèle de l'autre application
est introuvable (404). Détail des champs et des réponses : `docs/landingpage.md`, « Modèles de site ».

### Synchronisation du contrat

`POST /api/landingpage-config/sync` contrôle avec le nouveau schéma, en plus des réglages des landing pages, les
réglages de la boutique de tous les sites (chemins `boutique.…`) et tous les modèles de site des deux applications
(`landingpage-site-models[id].…`, `boutique-site-models[id].…`) ; une seule composition refusée bloque l'activation
(409). Les pages système ne sont pas recontrôlées à la synchronisation : un bloc devenu obligatoire sera signalé au
prochain PUT du site concerné. `app:landingpage:check-reglable` suit la même liste.

### Tester

`tests/Functional/Boutique/BoutiqueSettingsApiTest.php` (contrat, pages système, charte, commerce, journal,
restauration, accès) ; `tests/Functional/LandingPage/LandingSiteModelApiTest.php` (modèles des deux applications).
Essais réels : site `demo` uniquement.

## Liste générique des produits : `GET /api/products` fermée (S8, 26/10/2026 ; API Platform retiré le 20/11/2026)

Depuis le retrait d'API Platform (lot APIP-RETRAIT, `docs/architecture.md`, « API Platform retiré »), `GET /api/products`
n'existe plus pour personne (ni la liste d'API Platform, ni la route maison `get_all_products` qu'elle masquait : décision du
20/11/2026, aucun front ne l'appelle), pas plus que `/api/products.json` et `/api/products/{id}.{format}`. Historique : la liste
générée par API Platform était publique et imbriquait toutes les relations, une requête occupait un processus du serveur plusieurs
minutes (incident du 26/10/2026), puis réservée à l'administrateur (S8). **Le site et les applications lisent les routes
dédiées, inchangées** : `/api/products/{id}` (fiche, DTO), `/api/products/by-slug/{slug}`, `/api/products/by-category`,
`/api/products/{bestsellers|newarrivals|…}`, `/api/productsid/{id}`, avis et éligibilité, variantes ; catégories : `/api/category`.
Le site ne relit plus jamais la liste complète en repli d'une fiche en erreur (correction côté Next.js). Véhicules de la boutique : `GET /api/vehicle_products` et `/{id}` (tableau simple, routes maison ; véhicules en ligne seulement, sans `vin`, `quantity` ni `stockQuantity` de location, lot VEHICULES-PUBLIC du 21/11/2026 ; le NIV reste sur la fiche, `vehicleDetails.vin` de `/api/products/{id}`, décision du propriétaire). Le carrousel du bloc « financement » est `GET /api/vehicles/carousel` (entité `Vehicle`, véhicules `webDisplay`).

## Article hors ligne : invisible au public (lot FICHES-HORS-LIGNE, 21/11/2026)

Un article **hors ligne** (`isWeb` faux ou vide) est servi à l'**équipe** du site et à personne d'autre. L'équipe suit la règle de la
boutique en construction (`StorefrontViewer::isTeam` : administrateur, caisse, support, rôles d'agence, entrée d'agence ou de
directeur ; rôles relus en base depuis le jeton) : l'éditeur de pages, la gestion, MAUI et la caisse (articles de caisse seule) lisent
toujours tout. Le visiteur, le compte client et le **lien d'aperçu** (qui montre ce que verra le public à l'ouverture) reçoivent
EXACTEMENT la réponse d'un article absent, statut et corps, pour ne pas révéler son existence. Service : `PublicProductVisibility`
(`src/Services/Storefront/`), une requête sur la clé primaire, faite **avant** tout cache (les fiches sont en cache sous une clé
partagée par tous). Essai : `tests/Functional/Boutique/FichesHorsLigneTest.php`.

| Route | Pour le public, un article hors ligne… |
|---|---|
| `GET /api/products/{id}`, `/api/productsid/{id}`, `/api/products/by-slug/{slug}` | la réponse d'un article absent (404) |
| `GET /api/products/{id}/variants`, `/reviews`, `/reviews/eligibility`, `POST /api/products/{id}/reviews` | la réponse d'un article absent ; aucun avis donné |
| `GET /api/customization/config/{variantId}`, `/api/booking/check/{id}`, `/api/booking/calendar/{id}` | la réponse d'un article absent |
| `GET /api/products/by-category` (recherche comprise) | absent : `isWeb=true` forcé pour le public, quel que soit le paramètre ; l'équipe garde le paramètre, et sans lui tout le catalogue (la caisse l'appelle ainsi) |
| `GET /api/landingpage` | absent (articles « page d'accueil » en ligne seulement ; aucun site concerné, mesuré) |
| `POST /api/cart/quote` (sans poste de caisse), `POST /api/stripe/create-intent` | refus de la ligne `NOT_SELLABLE` (422) ; aucune intention ouverte |
| Devis de caisse (`cashRegisterId`), vente en caisse | inchangés (règle `isPos` de la caisse) |

La création de la commande (`/api/order/create`, `create-guest`) n'ajoute pas de refus : elle exige une intention de paiement
VÉRIFIÉE, qui ne se crée plus pour un article hors ligne, et elle se crée toujours depuis le panier gardé avec l'intention (lot
CHECKOUT-PANIER, « Paiement d'un panier »). Un article
passé hors ligne APRÈS l'intention donne sa commande au client qui a payé (jamais de paiement sans commande). Le devis
`POST /api/cart/quote` applique la règle à toute la route, même avec un `cashRegisterId` (un visiteur peut en poser un ; le caissier
est un compte d'équipe). Les lectures dont le corps dépend de qui lit (fiche, by-slug, by-category, avis) portent
`Vary: Authorization, Cookie` (`PublicReadCacheSubscriber::VIEWER_DEPENDENT`). Les listes `GET /api/products/{offer}` filtraient déjà `isWeb`.

## Le coût ne sort pas des routes ouvertes (lot COST-1, 16/11/2026)

Jusqu'à ce lot, les fiches produit publiques rendaient le **prix d'achat** et le **coefficient** (`purchasePrice`,
`coefficientMultiplier`) à un visiteur sans connexion, depuis l'origine du code. Ils sont retirés, pour tous les lecteurs :

| Réponse | Routes | Coût |
|---|---|---|
| `ProductDetailedOutputDTO` | `GET /api/products/{id}`, `/api/productsid/{id}`, `/api/products/by-slug/{slug}`, `/api/products/{offre}`, réponse de `PATCH /api/products/{id}` (éditeur de contenu) | **jamais** |
| `ProductOutputCategoryDto` | `GET /api/products/by-category` | **jamais** |
| `ProductPurchaseOutputDTO` (la fiche publique plus les deux champs) | `GET /api/commandes/{id}/products`, `ROLE_ADMIN` (gestion) | oui |
| `ProductOutputDTO` | réponse de `POST /api/commandes/{id}/products`, `ROLE_ADMIN` (gestion) | oui |

- Les deux champs n'existent plus dans les réponses publiques (pas même à `null`). Aucun front ne les lisait par une route publique.
- Ces réponses sont gardées en cache sous une clé qui ne dépend pas du lecteur : une entrée écrite avant le lot perd son coût à la
  relecture (`__wakeup` des deux objets), sans attendre la fin de sa durée ; le cache des sites est tout de même vidé à la bascule.
- La documentation d'API Platform, ses contextes JSON-LD et son point d'entrée (`/api/docs`, `/api/contexts/…`, `/api`,
  `/api/index.html` : la page Swagger), qui nomment tous les champs des entités, étaient réservés à l'administrateur (un simple
  compte client les lisait) ; ils n'existent plus depuis le retrait d'API Platform (20/11/2026, 404).
- Trouvé par la relecture du lot : un caissier lisait les statistiques de vente du SITE ENTIER en collant un saut de ligne à sa source
  (`/api/statistiques/chiffre-affaires/pos%0A`, de même `nombre-commandes`, `panier-moyen` et `/api/payments/statistics/`). La règle
  d'accès de la caisse finit maintenant par `\z` (un `$` accepte un saut de ligne final), et la source d'une route de statistiques
  est un mot (`[A-Za-z_]+`). Garde-fou : `tests/Functional/Security/PosStatisticsScopeTest.php`.
- **Garde-fou** : `tests/Functional/Security/PublicCostLeakTest.php` crée un article au coût, au coefficient, au coût de commande et
  au fournisseur reconnaissables, puis appelle TOUTES les routes de lecture de l'application sans connexion et avec un simple compte
  client : aucune réponse ne porte ces valeurs, ni un nom de champ de coût ou de marge. Une route nouvelle est balayée d'office.
- Règle : ne jamais ajouter un coût, une marge, un fournisseur ou un chiffre de vente à une réponse lue sans rôle d'administration ;
  une réponse d'administration qui en a besoin a sa propre classe, jamais celle de la fiche publique.

## Contrat d'un produit (08/10/2026, `ProductCommerceDto`)

Toutes les routes qui renvoient un produit (`/api/products/by-slug/{slug}`, `/api/productsid/{id}`,
`/api/products/{sélection}`, `/api/products/by-category`, `/api/products/{id}`, DTO `ProductDetailedOutputDTO`,
`ProductOutputDTO`, `ProductOutputCategoryDto`) portent, en plus des anciens champs (`mode`, `price` en dollars,
`bookingConfig`, `category`, mêmes unités qu'avant), gardés pour le carrousel des landing pages :

| Champ | Contenu |
|---|---|
| `kind` | `standard` ou `vehicle` (un `VehicleProduct`, même table : les véhicules sont servis par `by-slug`) |
| `sale.enabled`, `rental.enabled`, `subscription.enabled` | modes explicites, cumulables (colonnes `product.sale_enabled`, `rental_enabled`, `subscription_enabled` ; EasyAdmin « Modes de vente »). L'ancien `mode` est dérivé : `booking` = location seule, `retail` sinon ; `isBookable()` = location activée |
| `customizable` | **calculé** (09/10/2026, `Product::hasCustomizationConfig`) : vrai dès qu'une variante du produit a au moins une combinaison de personnalisation portant au moins une valeur d'option (ce que `GET /api/customization/config/{variantId}` affiche), quel que soit le nombre de variantes ; même règle dans la fiche, les listes, les sélections et par catégorie. La case `product.customizable` ne compte plus et a quitté les formulaires EasyAdmin (jamais cochée sur les sites existants, elle masquait la personnalisation de Kara & B) ; combinaisons préchargées dans les requêtes de fiche et de liste |
| `rental` (si activée) | `bookingConfig` complet, montants en **cents** : `granularity` (`days` \| `hours` \| `minutes_30` \| `minutes_15` \| `datetime`), `timeStepMinutes` (pas des heures : 60 / 30 / 15 selon la grille horaire, réglage de la grille `datetime`, null à la journée), `returnToleranceMinutes` (grille `datetime` seulement, sinon null), `minDuration`, `maxDuration`, `stockQuantity`, `bufferTime`, `rates[]` (`hourRate`…`monthRate`), `openingHours { start, end }`, `halfDays[]`, `allowedDates` (null = toutes), `eveningSlot`, `minDaysStandard`, `deposit`, `extraPassengerFee`, `arrivalLeadMinutes`, `cancellationPolicy`, `included[]`, `excluded[]`, `notes` (colonnes de `booking_configuration`, EasyAdmin « Configuration de location », panneau « Boutique réglable ») ; `promotion { percent, startDate, endDate, startsAt, endsAt, weekdays }` (lot LOC3) : promotion de location en cours, sinon la prochaine, **clé absente sans promotion** ; `startDate` / `endDate` jours du magasin inclus, `startsAt` / `endsAt` instants UTC (fin exclusive), `weekdays` 1 lundi … 7 dimanche |
| `pricing` | `{ currency, regular, special: { amount, from, to, active } | null, amount }` en cents ; `amount` = promotion en cours, sinon prix de vente |
| `variants[]` | `price` en cents (null = prix du produit, colonne `product_variant.price`), `stockQuantity`, `options[] { code, name, value, … anciens noms }` ; `promotion { id, percent, price, basePrice, startDate, endDate, startsAt, endsAt, active }` seulement si une promotion de variante est en cours ou à venir et l'interrupteur du site allumé (lot VAR, 11/11/2026 ; afficher `basePrice` barré, `price`, « −percent % », « jusqu'au endDate »). Ordre (31/10/2026, `ProductCommerceDto::orderedVariants`) : variantes par identifiant croissant, options de chaque variante par type d'option puis valeur, identique dans la fiche (`by-slug`, `/api/products/{id}`), les listes, les sélections et `/api/products/{id}/variants` ; aucune position saisie dans EasyAdmin (la fiche rendait « L, XL, M, S » : aucun `ORDER BY`) |
| `vehicleDetails` | `{ year, brand, model, vin, transmission, gasType, enginePower, hoursOrMileage, condition, color }` ou null |

Devise (§ 7) : une seule par site, `entreprise.currency` (ISO 4217, défaut `CAD`), exposée par `GET /api/entreprise/{id}`
(`currency`), modifiable par `PUT /api/entreprise/{id}` (3 lettres, journalisée ; la même route refuse **403 `SUPER_ADMIN_REQUIRED`** à un administrateur de site toute activation de module `isBoutiqueActive`, `isLandingPageActive`, `isBoussoleEsgActive`, `isMemoireVivanteActive` différente de l'actuelle, voir `docs/console-pilotage.md`) et renvoyée par `GET /api/stripe-config`
(`currency`). `TenantCurrencyProvider` la lit pour `pricing.currency`. **Langue du site** (04/11/2026, demande du site Next.js) :
`locale` (`fr` | `en`) sur `GET /api/entreprise/{id}` : langue choisie par l'administrateur si elle est renseignée (`siteLocale` du `PUT /api/entreprise/{id}`, ou `site.locale` de `PUT /api/site/features`, console de pilotage lot 2 du 04/11/2026 ; `null` = déduite), sinon règle de `Services/CaisseService/SiteLocaleResolver` d'après l'adresse du site
(Québec ou Canada sans province → `fr`, autre province → `en`, États-Unis → `en`, autre pays ou sans adresse → `fr`) ; indépendante de
`?locale=` de la requête (langue du site, pas du visiteur) ; lecture seule (ignorée par `PUT`). Aussi `commerce.locale` dans
`GET /api/boutique-settings`. Test : `SiteLocaleApiTest`. Tous les prix sont stockés **en cents**
(colonnes décimales : `product.price`, `special_price`, forfaits `rental_pack`, `carrier.price` ; EasyAdmin
`MoneyField::setStoredAsCents`) ; `ProductCommerceDto::cents` ne fait qu'arrondir. Jusqu'au 08/10/2026, le prix d'un
véhicule se saisissait en dollars (`NumberField`) alors que `create-intent` le lit en cents : corrigé (aucun véhicule
n'existait).
Schéma : migration `Version20261008120000`, script `scripts/migrate_all_v2_product_modes.sh` (reprise de l'ancien
mode une seule fois). Tests : `tests/Functional/Boutique/ProductContractApiTest.php`.
Drapeaux `isnewarrival`, `isfeatured`, `isspecialoffer` : colonnes nullables sans défaut (`isbestseller` est NOT NULL) ; NULL se lit
`false` dans `ProductOutputCategoryDto` et `ProductDetailedOutputDTO` (12/11/2026 : un NULL faisait répondre 500 à la liste d'une
catégorie et aux fiches). Aucune base de site n'en avait ; les bases ne sont pas corrigées, le code tolère NULL. Test `ProductNullFlagsTest`.

## Données de la boutique éditées depuis la page (09/10/2026, suite du § 9)

Même modèle que `PATCH /api/products/{id}` : ROLE_ADMIN du site, `?locale=` pour les champs traduits (en `fr`, la base
aussi), corps = champs modifiés seulement, `null` ou `""` vide un champ facultatif, 200 = l'objet tel que sa lecture
publique le renvoie, 404 hors du site, 422 `{ error, errors: [{ path, message }] }`, chaque écriture journalisée
(`GET /api/landingpage-audit?resource=…`) ; les modifications (`update`) et l'ordre des atouts se rétablissent, les
ajouts et suppressions non. Images : clé de la médiathèque ou URL https. Lignes de texte : sans balise.

| Route | Champs et règles | Réponse |
|---|---|---|
| `PATCH /api/product-variants/{id}` | `stockQuantity` (entier 0 à 1 000 000, mouvement d'inventaire enregistré), `price` (cents, `null` = prix du produit), `colorId`, `sizeId` (422 si une autre variante du produit a ce couple) | la variante comme `variants[]` |
| `POST /api/products/{id}/variants` | `colorId`, `sizeId`, `stockQuantity` (0 par défaut), `price` ; ancienne forme `color.id` / `size.id` acceptée | 201, la variante |
| `DELETE /api/product-variants/{id}` | suppression réelle (pas d'état « désactivé » : stock 0 pour retirer de la vente) ; depuis le 12/11/2026 (ART-SUPPR) : 409 `PRODUCT_IN_USE { productId, variantId, uses }` si elle a des ventes, des lignes d'achat, des mouvements de stock ou un paiement en cours (avant : 409 `{error: message}` pour une commande ou un paiement, et l'historique de stock était effacé) ; combinaisons supprimées avec elle ; détail : `docs/lignes-achat-gestion.md` | 204 |
| `GET /api/colors`, `GET /api/sizes` | existants | listes |
| `POST /api/colors`, `POST /api/sizes` | `name*` (une ligne, 255), `codeHexa` (#rrggbb, couleurs) ; nom écrit aussi dans la langue demandée | 201 |
| `PATCH /api/customization-options/{id}` | `name*` (traduit, 255) | configuration de `?variantId=`, sinon `{ id, code, name }` |
| `PATCH /api/customization-values/{id}` | `name*` (traduit), `priceDelta` (cents ≥ 0 ; stocké en dollars), `icon` (image) | configuration de `?variantId=`, sinon la valeur |
| `POST /api/customization-options/{id}/values` | `name*`, `priceDelta`, `icon` | 201, idem |
| `DELETE /api/customization-values/{id}` | 409 si une combinaison ou une variante l'utilise (pas de retrait silencieux) | configuration de `?variantId=` ou 204 |
| `PATCH /api/customization-combinations/{id}` | `image*`, `stock` (entier ≥ 0) | configuration de la variante |
| `POST /api/product-variants/{id}/customization-combinations` | `optionIds*` (valeurs d'option, distinctes), `image*`, `stock` ; doublon 422 | 201, configuration |
| `DELETE /api/customization-combinations/{id}` | | 200, configuration |
| `PATCH /api/subscription-plans/{id}` | `name*` (255), `description` (160), `features` (20 lignes de 120), `badge` (40), `highlighted` (une seule par produit), par langue ; prix, périodicité, essai : 422 | la formule comme `GET /api/subscription-plans` |
| `PATCH /api/features/{id}` | `title*` (traduit, 100 : limite de la colonne), `icon` | `{ id, title, iconUrl }` |
| `POST /api/features` ; `DELETE /api/features/{id}` ; `PUT /api/features/order { order }` | 50 atouts au plus ; ordre = tous les atouts, une fois (`feature.position`, migration `Version20261009230000`) | 201 ; 204 ; la liste ordonnée |

Code : `LandingContentSpec` (ressources et natures `integer`, `plain`, `relation`, `dollars`, `localized`,
`localized-lines`, `boolean`), `LandingContentEditor`, `LandingContentHooks` (couple couleur + taille, mouvement de
stock, formule recommandée), `LandingContentOutput` (réponses), `BoutiqueCatalogEditor` et
`ManageBoutiqueCatalogUseCase` (créations, suppressions, ordre), `BoutiqueCatalogController`. Sécurité : écritures sur
`product-variants`, `customization-*`, `colors`, `sizes`, `subscription-plans` réservées à ROLE_ADMIN (avant le
09/10/2026, `DELETE /api/product-variants/{id}` était ouvert à tout client connecté) ; seule la liste
`GET /api/subscription-plans` reste hors du pare-feu JWT. Tests : `tests/Functional/Boutique/BoutiqueCatalogEditApiTest.php`.

## Personnalisation : configuration et images (09/10/2026)

`GET /api/customization/config/{variantId}` (`CustomizationFactoryService`) : adresses des images construites par
`CustomizationMediaResolver` d'après le dossier qui contient réellement le fichier. Combinaisons : `customization/`
(écran « Combinaisons »), puis `products/` (ancien formulaire de variante) ; icônes de valeur d'option : `options/`, puis
`icons/`. Fichier introuvable : `null`, et le site affiche l'image du produit. Un seul résultat par ensemble de valeurs
d'options : celui dont le fichier existe, sinon le plus ancien. Enregistrer un doublon (même variante, mêmes valeurs
d'options) est refusé (`Validator/UniqueCustomizationCombination`, vérifié aussi dans le formulaire de variante grâce à
`Assert\Valid`). Le formulaire de variante enregistre désormais dans `customization/` (chemin absolu ; avant : `products/`,
chemin relatif). Les autres sérialisations (`ProductCommerceDto::variants`, `ProductVariantDTO`) exposent le nom de fichier
de l'icône (`image_preview`), pas une adresse. Tests : `tests/Functional/Boutique/CustomizationConfigTest.php`.

## Modifier les données de la boutique depuis la page (08/10/2026, § 9)

Mêmes routes, règles et journal que les contenus des landing pages (`docs/landingpage.md`, « Modifier les contenus
depuis l'éditeur ») : `PATCH /api/{ressource}/{id}?locale=` (`ROLE_ADMIN`), corps = champs à changer seulement, 422
`{ error, errors: [{ path, message }] }` sans rien écrire, réponse = l'objet tel que son GET le renvoie, écriture
inscrite au journal (`GET /api/landingpage-audit?resource=products`…) et restaurable (`POST …/{id}/restore`).

| Ressource | Champs (traduits en gras) |
|---|---|
| `products` | **`name`**, **`description`**, **`moreinformations`**, `price` (cents, obligatoire), `specialPrice` (cents ou null), `specialPriceFrom`, `specialPriceTo` (ISO 8601 ou null), `image`, `pictures` (liste de clés de la médiathèque ou d'URL https : remplace la galerie, 10 au plus) ; depuis le 11/11/2026 : avec décalage = cet instant, heure sans décalage = heure du magasin, date seule = jour du magasin ; stockés en UTC, `docs/promotions-gestion.md` § 7) |
| `category` | **`name`**, **`description`**, `image` |
| `homeslider` | **`title`**, **`description`**, **`buttonMessage`**, **`buttonUrl`**, `image` (il n'existe pas de champ `imageMobile`) |
| `explore-cards` | **`standardTitle`**, **`differentTitle`**, **`description`**, `link`, `imageUrl`, `videoUrl` (noms de l'API ; enregistrés dans `imagePath`, `videoPath`) |

Une image venue de la médiathèque est enregistrée sous `/media/secure/{clé}` et rendue en URL complète par tous les
DTO de la boutique (`MediaUrlResolver::joinStored`) ; un nom de fichier téléversé dans l'administration reste servi
sous `/assets/uploads/<dossier>/`. Les caches des lectures publiques sont invalidés à
chaque écriture, composant par composant (`CacheInvalidationSubscriber` et registre `Cache/CacheTags` : un produit écrit
vide les listes de produits et sa fiche, pas la landing page ; voir `docs/architecture.md`). Code : `LandingContentSpec` (liste blanche, `localeField`,
`titleField`, `aliases`), `LandingContentEditor` (natures `number`, `date`, `images` ajoutées). Tests :
`tests/Functional/Boutique/BoutiqueContentEditApiTest.php`.

## Devis du panier et montants (08/10/2026, `CartQuoteCalculator`)

Une seule source de vérité pour les montants, **en cents** : `Services/OrderService/CartQuoteCalculator` (DTO
`CartQuoteInputDto`), utilisé par `POST /api/cart/quote` (public, lecture seule : `CartQuoteController`,
`QuoteCartUseCase`), par `POST /api/stripe/create-intent` (montant autorisé = `total` du devis) et par la création de
commande (`ProcessOrderItemsUseCase` : prix des lignes et frais de livraison). Le navigateur n'envoie **aucun montant
d'article**.

- Entrée : `{ items: [{ productVariantId, quantity, booking?: { start, end, rateId?, durationType?, passengers? },
  customizationId? }], carrierId?, shippingPrice? }`. Anciens noms encore lus : `priceShipping`, `rental`, `booking`
  global du panier, `duration_type`, `rentalPackId`.
- **Promotion de location** (lot LOC3, 11/11/2026, `docs/locations-gestion.md` § 3) : si l'article a une promotion dont la fenêtre contient
  le **jour de début** de la location (fuseau de la boutique) et que ce jour est coché, la ligne gagne `booking.promotion { id, percent, amount,
  catalogUnitPrice }` : `amount` = remise sur `rate × units` (jamais sur `passengerFee` ni la caution), `unitPrice` = `rate × units − amount +
  passengerFee`, `catalogUnitPrice` = prix sans remise. Sans promotion qui s'applique, la ligne est exactement celle d'avant (aucune clé de
  plus). Même calcul pour le devis de la caisse, le paiement Stripe et la commande (prix figé dans la ligne).
- Sortie : `{ lines: [{ productVariantId, productId, name, kind: sale | rental, quantity, unitPrice, total,
  customizationId?, customizationPrice?, booking?: { start, end, rateId, rateName, durationType, units, rate, passengers,
  passengerFee, deposit, promotion? } }], subtotal, shipping, taxes: [{ label, rate, amount }], deposit, total, currency, carrier: { id, name,
  isFree, kind, freeShippingApplied } | null, shippingAddress, taxAddress, taxBasis: shipping | store }`. `total` = articles + livraison + taxes ;
  la **caution** (`deposit`) est rapportée à part. `taxAddress` est l'adresse qui a servi aux taxes (B20, 08/11/2026) : l'adresse de
  livraison normalisée, ou celle de la boutique pour un retrait en magasin (`taxBasis: store`).
- Vente : `unitPrice` = prix de la variante (`product_variant.price`) sinon prix effectif du produit (promotion en
  cours) ; stock de la variante vérifié. **Promotion par variante** (lot VAR, 11/11/2026, interrupteur du site allumé par le super admin,
  `docs/promotions-gestion.md` § 8) : si une promotion de variante est en vigueur, `unitPrice` = son prix (elle remplace la promotion de
  l'article) et la ligne gagne `promotion { id, kind: "variant", percent, amount, catalogUnitPrice }` ; sans elle, la ligne est exactement celle
  d'avant (aucune clé de plus). Les fronts affichent `catalogUnitPrice` barré et ne recalculent jamais le prix. Personnalisation (`customizationId`, 09/10/2026) : la combinaison doit appartenir à la
  variante (422 sinon) ; son supplément `customizationPrice` (somme des `price_delta` de ses options, en dollars en
  base, `ProductCustomizationImage::priceDeltaCents`) s'ajoute à `unitPrice`, donc au devis, à l'intent Stripe, au prix
  unitaire de la ligne de commande et aux taxes. Une ligne est une location si la location est activée et que la ligne porte
  des dates, ou si le produit ne se vend pas (`RentalLineResolver`) ; un produit vendu et loué sans dates = achat.
- Location : `unitPrice` = tarif × unités (+ `extraPassengerFee` × `passengers`) ; `booking.passengerFee` du devis est ce **total des
  passagers** (5 000 pour 2 × 2 500), jamais le prix par passager (précision du 11/11/2026, relevée par l'app mobile). Tarif : forfait `rateId` (doit
  appartenir aux catégories du produit) sinon le premier forfait des catégories ; `durationType` : `hour`, `halfDay`,
  `day`, `week`, `month`, par défaut `hour` (grille horaire) ou `day` ; unités = durée arrondie au-dessus en longueurs
  d'unité (heure 1 h, demi-journée 4 h, jour, semaine 7 j, mois 30 j). Règles de `booking_configuration`, 422
  `errors[{ path, message }]` : `minDuration` / `maxDuration` (heures ou jours selon la grille), `minDaysStandard`,
  `allowedDates`, `openingHours` ou `eveningSlot` (grille horaire), disponibilité (`remaining` dans l'erreur). Grille `datetime` :
  voir « Prise et retour avec date et heure » ci-dessous (tolérance de retard, pas des heures, heures de prise et de retour).
- Livraison : 0 sans `carrierId` ; prix fixe du transporteur ; transporteur EasyPost (`carrierAccountId`) : tarif
  choisi dans `shipping/summary`, envoyé en `shippingPrice`. `GET /api/Carrier` : `isFree`, `estimatedDays`
  (colonne `carrier.estimated_days`, migration `Version20261008140000`, script
  `scripts/migrate_all_v2_carrier_estimated_days.sh`). `shipping/summary[].totalPrice` est en cents.
- Taxes : selon l'adresse de livraison (`shippingAddress`), voir « Taxes par région » ci-dessous ; sans adresse,
  `taxes: []` et `taxStatus: address_required`. **Retrait en magasin** (`carrierId` d'un transporteur `kind: pickup`, B20, 08/11/2026) :
  taxes à l'adresse de la fiche entreprise du site, avec ou sans `shippingAddress` (`taxStatus: calculated`, `taxBasis: store`).
- Statuts : 400 corps illisible, 404 variante ou transporteur inconnu, 422 règle non respectée ; rien n'est réservé. Depuis le
  02/11/2026, la réponse porte `code` de premier niveau (`INVALID_BODY`, `VARIANT_NOT_FOUND`, `CARRIER_NOT_FOUND`, sinon `QUOTE_INVALID`,
  comme la caisse) et chaque entrée de `errors[]` porte `code` (+ `params`) : voir « Codes du devis, de l'adresse et de la commande ».
- `order/create` : `orderSource`, `typeOrder` et `paymentMethod` facultatifs (vente en ligne par défaut) ;
  `carrierId` nullable. `order/create-guest` : 403 si `commerce.guestCheckout` est `false` dans les réglages de la
  boutique (`BoutiqueSettingsService::isGuestCheckoutAllowed`). `GET /api/booking/check/{id}` : **409** avec
  `remaining_stock` quand la quantité n'est pas disponible (200 auparavant).
- Tests : `tests/Functional/Boutique/CartQuoteApiTest.php`.

### Prise et retour avec date et heure (grille `datetime`, 08/11/2026, réponse à Next, à l'application mobile et à la caisse)

Le modèle le plus courant : le client choisit **une date et une heure de prise, puis une date et une heure de retour**, sur un ou
plusieurs jours (du 2 à 9 h au 9 à 10 h). Réglage du backend, pas du front : `booking_configuration.granularity = 'datetime'`
(questionnaire « Configurer une location pas à pas », option « Avec date et heure de prise et de retour » ; écran Règles de location),
exposé dans `rental.granularity` de la fiche produit, pour que le site, l'application mobile et la caisse montrent le même écran.
Colonnes ajoutées (migration `Version20261120100000`, script `scripts/migrate_all_v2_booking_datetime.sh` appliqué partout) :
`return_tolerance_minutes`, `time_step_minutes`.

1. **Calcul des jours, règle exacte** (`CartQuoteCalculator::rental`) : `heuresFacturées = max(0, heures − toléranceMinutes / 60)` ;
   `jours = ceil(heuresFacturées / 24)` ; unités = `max(1, ceil(jours / longueur))` avec jour 1, semaine 7, mois 30 ;
   formule `hour` : `max(1, ceil(heuresFacturées))` ; `halfDay` : `max(1, ceil(heuresFacturées / 4))`. Prix = tarif de la formule
   × unités (+ frais par passager). La tolérance (`returnToleranceMinutes`, 0 par défaut, au plus 1 439) **s'applique à toutes
   les formules**, semaine et mois compris. Sans tolérance : 169 h → 8 jours, 167 h → 7 jours, 16 h → 1 jour. Avec 60 minutes :
   169 h → 7 jours, 170 h → 8 jours, 16 h → 1 jour (ou 15 h à l'heure). Pas d'heures supplémentaires au tarif horaire au-delà des
   jours entiers : une formule, un arrondi. `minDuration` / `maxDuration` sont **en heures** sur cette grille et se vérifient sur la
   durée réelle (pas la durée facturée) ; **`minDaysStandard` sur les jours facturés après tolérance** (règle tranchée le 08/11/2026,
   réponse (a) : 422 `DAYS_MINIMUM`, `items[i].booking.end`, `params { value }`, contrat inchangé ; grille à la journée
   inchangée ; grilles à l'heure : jamais). Ponton de demo (`minDaysStandard` 2, tolérance 60) : 25 h → 1 jour facturé → refusé ; 49 h →
   2 jours → accepté. `durationType` par défaut : `day`.
2. **Heures de prise et de retour** : si `openingHours` (ou `eveningSlot`) est réglé, l'heure de prise **et** l'heure de retour
   doivent chacune tomber dans ces plages, **n'importe quel jour** (pas de contrainte « même jour », contrairement aux grilles
   horaires). Codes 422 `OUTSIDE_PICKUP_HOURS` (`items[i].booking.start`) et `OUTSIDE_RETURN_HOURS` (`items[i].booking.end`),
   `params { start, end, eveningStart?, eveningEnd? }`. Pas d'heures de prise et de retour distinctes des heures d'ouverture : les
   heures d'ouverture servent aux deux. Sans heures d'ouverture : à toute heure.
3. **Disponibilité** : à l'heure près sur plusieurs jours, avec `bufferTime` : un retour le 9 à 10 h et un battement de 30 min
   laissent le produit pris jusqu'à 10 h 30 et libre à 11 h (pas des heures). Avant le devis : `GET /api/booking/check/{productId}
   ?start=&end=&quantity=` → 200 disponible, 409 `{ available: false, remaining_stock }`. `GET /api/booking/calendar/{productId}`
   rend des créneaux au pas réglé (`timeStepMinutes`), filtrés par les heures d'ouverture, utilisables sur plusieurs jours (lourd sur
   une longue plage : préférer `check` pour la plage choisie). Le devis répond `DATES_UNAVAILABLE` avec `remaining`.
4. **Pas des heures** : `timeStepMinutes` (`time_step_minutes`, 60 par défaut ; 5, 10, 15, 20, 30, 60 ou 120 dans le questionnaire).
   Les minutes de la prise et du retour doivent être des multiples du pas : 422 `TIME_STEP_INVALID` (`items[i].booking.start` /
   `.end`, `params { stepMinutes }`). Exposé pour toutes les grilles (`hours` → 60, `minutes_30` → 30, `minutes_15` → 15, `days` → null).
5. **Activation** : `granularity: "datetime"` dans la fiche produit ; le front affiche alors deux sélecteurs date + heure (prise, retour)
   au pas `timeStepMinutes`, dans `openingHours` si présent, et calcule l'affichage du prix avec la règle du point 1 ; le serveur
   reste la référence (devis, intent, commande). Les autres grilles ne changent pas (`days` : jours de calendrier bornes incluses envoyés
   à l'ouverture et à la fermeture ; `hours` : même jour).
   **Où lire les réglages** : `rental` de la fiche produit est la **seule source** (`granularity`, `timeStepMinutes`, `returnToleranceMinutes`,
   `minDaysStandard`…). L'ancien champ de premier niveau `bookingConfig` (carrousel des landing pages) ne change pas et ne porte pas ces champs.
6. **Essai sur demo** : le Ponton (`DEMO-PONTON`) est réglé en `datetime` sur demo (prise et retour de 08:00 à 20:00, pas 60 min,
   tolérance 60 min, 24 h minimum, caution et frais par passager inchangés). Test : `tests/Functional/Boutique/RentalDatetimeApiTest.php`.

### Location au comptoir (caisse, 09/11/2026)

La caisse vend les locations (catalogue `GET /api/pos/rentals`, devis `POST /api/pos/quote` avec `booking`, vente `create-multi-payment` avec
`deposit`), confirme les réservations (`CONFIRMED`), encaisse et restitue la caution (espèces, lecteur Stripe ou carte déclarée), finalise la
réservation au retour du matériel (`RETURNED`) ; annulation et retours libèrent les créneaux (`CANCELLED`), **web compris**. Contrat complet :
`docs/caisse.md`, « Location au comptoir et caution ». Statuts de `booking.status` : `PENDING_PAYMENT` (créée), `CONFIRMED` (vente au
comptoir), `RETURNED` (matériel rendu, `is_finalized`), `CANCELLED` ; la disponibilité ignore `CANCELLED`, `REFUNDED`, `CART_ABANDONED`.

### Fuseau horaire de la boutique et dates des réservations (04/11/2026, application e-commerce mobile, lot L7b)

- **Un seul fuseau**, `Services/Booking/BookingClock` : réglage du site (fiche Entreprise « Fuseau horaire du magasin », sinon
  province ou pays de l'adresse, comme la caisse : `StoreClock`), **`America/Toronto` à défaut** (valeur qui était écrite en dur
  dans le calendrier des réservations, le courriel de commande et l'administration des réservations). Exposé en lecture seule :
  `commerce.timezone` (identifiant IANA) dans `GET /api/boutique-settings` ; ignoré dans un `PUT` (comme `commerce.locale`, langue du site).
- **Dates reçues** (`booking.start` / `booking.end` du devis, de `create-intent`, de `order/create` et `create-guest`,
  `start` / `end` de `GET /api/booking/check`) : une date **sans fuseau** (« 2027-06-07T16:00:00 », « 2027-06-07 16:00 ») est une
  heure de la boutique ; une date **avec fuseau ou en UTC** (« 2027-06-07T20:00:00.000Z », « …-04:00 ») est convertie dans le fuseau
  de la boutique, même instant. Tous les contrôles (heures d'ouverture, `eveningSlot`, `allowedDates`, durées, jours) et
  l'enregistrement du `Booking` se font après cette conversion. Avant, le devis comparait dans le fuseau de la chaîne reçue
  (UTC par défaut) : 16 h-18 h de Montréal envoyé en UTC était refusé, 8 h-10 h accepté.
- **Dates rendues** : `booking.start` / `booking.end` du devis (`lines[].booking`), de la commande et du détail de commande
  (`items[].booking`) en ATOM **dans le fuseau de la boutique, avec son décalage** (« 2027-06-07T16:00:00-04:00 » ; « -05:00 » en
  hiver). Avant : UTC. `GET /api/booking/calendar` **inchangé** : créneaux en heures de la boutique, `Y-m-d H:i:s` sans décalage.
- Tests : `tests/Functional/Boutique/BookingTimezoneApiTest.php` (devis en UTC, avec décalage et sans fuseau, spectacle aux dates
  proposées, changements d'heure de mars et de novembre, `check`, calendrier, commande d'invité, réglage du site).

## Paiement d'un panier (09/10/2026, commande unique par paiement)

Pratique standard : le serveur garde une **trace de chaque paiement en cours** (table `checkout_session`, une ligne par
intent Stripe) et la commande naît **une seule fois par paiement**, du navigateur ou du webhook.

- `POST /api/stripe/create-intent { items, carrierId?, shippingAddress?, priceShipping?, paymentIntentId?, order? }` →
  `{ success, clientSecret, paymentIntentId, calculatedAmount, quote, reused }`. `paymentIntentId` : l'intent du même
  panier est **repris** et remis au nouveau montant tant qu'il n'est pas payé (`reused: true`) ; sinon un nouvel intent.
  `order` : **le corps que le navigateur enverra ensuite** à `order/create` (client : `addressId`, `carrierId`, `items`,
  permis…) ou `order/create-guest` (invité : `guestInfo`, `shippingAddress`, `billingAddress?`, `carrierId`, `items`),
  sans `paymentIntentId` ; gardé pour le webhook (renvoyé une fois suffit : un panier modifié garde le corps du premier
  appel). Sans `shippingAddress`, les taxes viennent de l'adresse de `order`. Le client connecté est reconnu par son jeton
  du site (`OptionalCustomerResolver` : la route reste hors du pare-feu JWT, un jeton expiré ne bloque pas un invité).
- `POST /api/order/create-guest` : `termsVersion` facultatif (14/11/2026, APPS-MEMBRES M1, `docs/apps-membres.md`) : la version des conditions de
  la boutique acceptées au paiement (`GET /api/apps/web/terms`), gardée sur la commande si elle est celle en vigueur. La commande en
  invité ne crée aucune appartenance ; elle naît le jour où ce client choisit son mot de passe.
- `POST /api/order/create` (client) et `/api/order/create-guest` (invité) : **idempotents**. Depuis le 30/10/2026 (audit S2.7), un
  caissier ou responsable de caisse sans `ROLE_ADMIN` n'y crée qu'une vente web (403 `POS_ROUTE_REQUIRED` pour `orderSource` 2 ou
  `typeOrder` ≠ 1 : les opérations de caisse passent par `create-multi-payment` et `/api/pos/returns`) ; `POST /api/shipping/buy`
  exige la commande de l'utilisateur connecté ou `ROLE_ADMIN` (403 `FORBIDDEN`, 404 `ORDER_NOT_FOUND`) ; `PUT`/`DELETE
  /api/notes-de-frais/{id}` exigent `ROLE_ADMIN` ou le propriétaire de la collection de la note (403 `FORBIDDEN`) ; les pages HTML `/adress/*` ne montrent que ses adresses. Comportement du
  tunnel web inchangé pour un client. Premier appel : 201
  `{ success, orderId, guestToken? (invité), alreadyCreated: false }`. Appel suivant pour le même paiement : **200** et la
  même commande (`alreadyCreated: true`), avec le `guestToken` pour l'invité **à la même adresse e-mail** ; paiement d'un
  autre client ou autre e-mail : 409. Deux appels simultanés (navigateur et webhook) : réservation atomique de la trace
  (`open` → `processing`), le second attend la commande (8 s au plus, sinon 409 « en cours »). Montant autorisé ≠ total
  calculé (1 centime de tolérance), stock insuffisant : 400 et **autorisation annulée** (client non débité) ; donnée
  invalide (adresse, transporteur) : l'autorisation reste, le navigateur peut corriger. **`authorizationCanceled`** (01/11/2026) :
  booléen ajouté à toute réponse 400 (et au 402 d'un intent devenu impayable) de `order/create` et `create-guest` : vrai seulement si
  l'annulation a réussi pendant cet appel ou si l'intent est déjà `canceled` (relu chez Stripe), faux sinon (données invalides,
  annulation en échec : journalisée en erreur avec l'identifiant de l'intent par `StripeCheckoutPaymentGateway::cancel`, qui rend
  désormais un booléen). L'application n'a plus à relire l'intent. Après une commande refusée, un second appel répond 402 avec
  `authorizationCanceled: true`. Personnel sans paiement Stripe
  (caisse) : ancien chemin, inchangé.
- **Webhook** `payment_intent.amount_capturable_updated` (capture manuelle) et `payment_intent.succeeded` : site lu dans
  les métadonnées de l'intent (`tenant_code`), signature exigée hors tests ; crée la commande si le navigateur ne l'a pas
  fait et que `order` a été gardé (sinon ignoré : l'autorisation expire au bout de 7 jours). **À cocher dans Stripe** :
  ces deux évènements sur l'écouteur « Comptes connectés » (et « Votre compte » pour un site interne).
- Code : `Entity/CheckoutSession`, `Repository/CheckoutSessionRepository` (`claim`), `Services/CheckoutService/`
  (`CheckoutPaymentGatewayInterface` + `StripeCheckoutPaymentGateway`, simulé en test par `FakeCheckoutPaymentGateway` ;
  `CheckoutSessionService` ; `CheckoutCustomerService` : adresse du client, compte et adresses de l'invité),
  `UseCase/CheckoutUseCase/` (`PrepareCheckoutUseCase`, `FinalizeCheckoutOrderUseCase`), `Security/OptionalCustomerResolver`.
  Migration `Version20261009190000`, script `scripts/migrate_all_v2_checkout_sessions.sh`. Tests :
  `tests/Functional/Boutique/CheckoutApiTest.php`. Vérifié en réel sur `demo` (Stripe test) : commandes 11 et 13
  (client, appel répété), 12 (invité, créée par le webhook).
- **La commande est celle du panier PAYÉ** (lot CHECKOUT-PANIER, 21/11/2026, faille relevée par la relecture de
  FICHES-HORS-LIGNE) : avant ce lot, seul le MONTANT liait la commande au paiement, et un client pouvait payer un article puis
  commander un autre article au même total (hors ligne, autre variante, article de caisse seule, autre transporteur). Désormais la
  commande se crée TOUJOURS depuis le panier coté et gardé avec l'intention (`checkout_session.cart` : articles, transporteur,
  livraison, réservation), quel que soit le chemin ; le corps n'est plus qu'un contrôle (`CheckoutCartGuard`), comparé sur des
  lignes normalisées (variante, quantité, personnalisation ; location : début, fin, forfait, type de durée, passagers ; ordre et
  champs en plus ignorés, `productId` compris), sur le transporteur et les frais de livraison s'ils sont envoyés, et sur l'adresse
  (pays, province, code postal connus au devis).
  - `create-intent` : un `order` envoyé qui diffère du panier coté → **409 `CART_MISMATCH`**, AVANT tout paiement (aucune
    intention). Le corps est gardé sans ses clés de panier : la commande les prendra du panier.
  - `order/create`, `create-guest` : un corps différent du panier payé → **409 `CART_MISMATCH`**, avant la réservation du paiement :
    l'autorisation reste entière (un corps juste la consomme ; le webhook crée la commande du panier payé si `order` a été gardé ;
    sinon elle expire sans débit). Aucun panier gardé avec ce paiement : 409 `CART_MISMATCH`.
  - Webhook : **jamais de refus** (l'argent est pris) ; un écart avec le corps gardé (session d'avant le lot) est noté en incident de
    la plateforme (`checkout/panier`, avertissement, sans donnée du client) et la commande se crée sur le panier payé.
  - Le tunnel de la boutique envoie les mêmes lignes à `create-intent` et à la commande (vérifié par le chef) : il ne voit jamais
    le refus. Essai : `tests/Functional/Boutique/CheckoutPanierTest.php`.
- **Pays absent** (commandes invité 9 et 10 de demo, hors taxes) : une province canadienne (code ou nom) ou un code
  postal canadien suffit à reconnaître le Canada (`TaxEngine::normalizeAddress`, adresse de l'invité).
- **Abonnement en double** (`POST /api/subscriptions`) : un abonnement encore `incomplete` à la même formule et quantité
  est **repris** (200, même `clientSecret`, `reused: true`) ; un incomplet d'une autre formule, ou expiré chez Stripe
  (`incomplete_expired`, 23 h sans paiement), est annulé et remplacé ; un abonnement en cours au même produit (essai,
  actif, impayé `past_due`/`unpaid`, en pause, résiliation programmée comprise), quelle que soit la formule : **409**
  `{ error, errors, reason: "already_subscribed", subscriptionId, status }` (changer de formule :
  `POST /api/subscriptions/{id}/change-plan`). Deux souscriptions simultanées du même client au même produit : verrou
  PostgreSQL (`pg_advisory_lock`, clé client + produit, 13/10/2026), la seconde attend la première puis la trouve.

## Adresses du client (31/10/2026)

Routes `GET|POST /api/adresses`, `PUT|DELETE /api/adresses/{id}`, `PUT /api/adresses/{id}/set-primary` (`AdressApiController`,
`AdressService`), lues en base à chaque appel (aucun cache depuis le 28/10/2026). Contrat inchangé pour le site web et la caisse ;
ajouts pour l'application e-commerce mobile :

| Point | Règle |
|---|---|
| Vérification EasyPost | `AddressVerificationService::verifyOrKeep` : avec une clé EasyPost sur le site (`EasyPostService::hasConfiguration`), vérification et normalisation inchangées (400 `error.address` si l'adresse est refusée) ; **sans EasyPost** (demo, sites sans expédition), l'adresse est gardée telle quelle, province ramenée à son code quand le nom est connu (`ProvinceCodes` : provinces canadiennes et États américains, FR/EN, sans accent). Avant : 500 (`LogicException`) à toute création |
| Réponse de POST, PUT et set-primary | `success` (inchangé) **et** `address` : l'adresse telle que la rend `GET /api/adresses` (`AdressOutputDTO`, `isPrimary` à jour) |
| Code postal | par pays (01/11/2026, `PostalCodeRules`, décision du propriétaire : format international) : Canada `A1A 1A1` inchangé (`CA`, `Canada`), États-Unis `12345` ou `12345-6789` (`US`, `USA`, `États-Unis`), tout autre pays ou pays non reconnu 3 à 10 lettres ou chiffres (espaces et tirets admis). 400 `error.zipCode` avec le message du pays. Avant, le format canadien était exigé quel que soit le pays |
| `GET /api/adresses/autocomplete?q=` | suggestions `{ description, placeId }` ; `[]` sans clé Google Places sur le site ou en cas d'erreur (inchangé) |
| `GET /api/adresses/details?place_id=` | `{ street1, street2, city, province, postalCode, country }` ; **jamais 500** : 400 `PLACE_ID_REQUIRED`, 404 `PLACE_NOT_FOUND` (identifiant inconnu ou mal formé : statuts Google `NOT_FOUND`, `INVALID_REQUEST`, `ZERO_RESULTS`) ou 404 `AUTOCOMPLETE_NOT_CONFIGURED` (aucune clé `google_places_config` sur le site ; en 4xx car nginx remplace le corps de toute réponse 5xx), 503 `AUTOCOMPLETE_UNAVAILABLE` (API injoignable, corps neutre de nginx) |
| `DELETE /api/adresses/{id}` | 204 ; répondait 500 depuis l'origine (entité détachée par un `clear()` avant la suppression), corrigé le 31/10/2026 |
| Codes par champ (02/11/2026) | tout refus de POST et PUT garde `error` (`{champ: message}`, ou `{address: message}` pour EasyPost) et gagne `code` (`VALIDATION_FAILED` ou `ADDRESS_UNDELIVERABLE`), `message` et `errors[{path, code, message, params?}]` : `FIELD_REQUIRED`, `PHONE_INVALID`, `POSTAL_CODE_INVALID {country}`, `ADDRESS_UNDELIVERABLE` (table ci-dessous) |
| Clé Google Places | table `google_places_config` du site (EasyAdmin « Google Places ») : `google_api_key_test` hors production, clé chiffrée en production ; aucune clé sur `demo` au 31/10/2026 |

Tests : `tests/Functional/Boutique/AdressApiTest.php` (Google Places simulé par `tests/Fake/FakeGooglePlacesHttpClient.php`, un seul
`place_id` connu ; le modèle de base de test porte une clé EasyPost sandbox, retirée le temps des scénarios « sans EasyPost »).

## Codes du devis, de l'adresse et de la commande (02/11/2026)

Contrat retenu avec les applications (caisse, e-commerce mobile) : **codes stables, chaque application traduit elle-même**. Le serveur ne
traduit rien ; les messages français, les chemins, les statuts HTTP et les champs existants ne changent pas, seuls `code`, `message` et
`params` s'ajoutent. Règle de lecture : `code` de premier niveau pour le motif global, puis `errors[].code` pour chaque article ou champ ;
`params` (objet, absent quand le motif n'a pas de détail variable) donne les valeurs que le texte traduit doit citer.

### Devis : `POST /api/cart/quote`, `POST /api/stripe/create-intent`, commande refusée par le devis

Premier niveau : `{error, code, message, errors[{path, message, code, params?}]}`. `code` est celui de la caisse : `VARIANT_NOT_FOUND` et
`CARRIER_NOT_FOUND` (404), `INVALID_BODY` (corps illisible, 400), `PAYLOAD_TOO_LARGE` (413), sinon **`QUOTE_INVALID`** (400 ou 422) ;
`error` reste « première erreur (et N autre(s) erreur(s)) ». Source : `CartQuoteException::error()` dans `CartQuoteCalculator`,
`CartQuoteInputDto`, `PosLineService`, `PosDiscountCalculator`.

| `errors[].code` | Statut | `path` | Message français | `params` |
|---|---|---|---|---|
| `ITEMS_REQUIRED` | 400 | `items` | au moins un article attendu | — |
| `ITEM_INVALID` | 400 | `items[i]` | objet attendu | — |
| `SHIPPING_PRICE_INVALID` | 400 | `shippingPrice` | montant en cents, positif ou nul, attendu | — |
| `INVALID_BODY` | 400 | `` (vide) | objet JSON attendu | — |
| `VARIANT_NOT_FOUND` | 404 | `items[i].productVariantId` | variante introuvable | — |
| `CARRIER_NOT_FOUND` | 404 | `carrierId` | transporteur introuvable | — |
| `QUANTITY_INVALID` | 422 | `items[i].quantity` | entier positif attendu | — |
| `BOOKING_REQUIRED` | 422 | `items[i].booking` | ce produit se loue seulement : dates de réservation attendues (start, end) | — |
| `NOT_RENTABLE` | 422 | `items[i].booking` | ce produit ne se loue pas | — |
| `NOT_SELLABLE` | 422 | `items[i]` | ce produit ne se vend pas | — |
| `STOCK_INSUFFICIENT` | 422 | `items[i].quantity` | stock insuffisant (%d disponible(s)) | `{available}` |
| `CUSTOMIZATION_NOT_FOUND` | 422 | `items[i].customizationId` | combinaison de personnalisation inconnue pour cette variante | — |
| `BOOKING_DATES_INVALID` | 422 | `items[i].booking` | dates start et end attendues (ISO 8601) | — |
| `BOOKING_END_BEFORE_START` | 422 | `items[i].booking.end` | la fin doit suivre le début | — |
| `DURATION_TYPE_INVALID` | 422 | `items[i].booking.durationType` | valeur attendue : hour, halfDay, day, week, month | `{allowed: [hour, halfDay, day, week, month]}` |
| `DURATION_TOO_SHORT` | 422 | `items[i].booking.end` | durée minimale : %d heure(s) / jour(s) | `{value, unit: hour | day}` |
| `DURATION_TOO_LONG` | 422 | `items[i].booking.end` | durée maximale : %d heure(s) / jour(s) | `{value, unit: hour | day}` |
| `DAYS_MINIMUM` | 422 | `items[i].booking.end` | %d jour(s) minimum à la journée | `{value}` |
| `DATE_NOT_OFFERED` | 422 | `items[i].booking.start` | date non proposée (voir rental.allowedDates) | — |
| `OUTSIDE_OPENING_HOURS` | 422 | `items[i].booking.start` | créneau hors des heures d'ouverture (%s à %s, ou %s à %s) | `{start, end, eveningStart?, eveningEnd?}` (`HH:MM`) |
| `DATES_UNAVAILABLE` | 422 | `items[i].quantity` | indisponible sur ces dates : il reste %d place(s) | `{remaining}` (le champ `remaining` de l'erreur reste) |
| `RATE_UNKNOWN` | 422 | `items[i].booking.rateId` | tarif inconnu pour ce produit (voir rental.rates) | `{rateId}` |
| `NO_RENTAL_RATE` | 422 | `items[i].booking.rateId` | aucun tarif de location pour ce produit | — |
| `RATE_NOT_FOUND` | 422 | `items[i].booking.durationType` | aucun tarif « %s » pour ce produit | `{durationType}` |
| `CUSTOM_LABEL_INVALID` | 422 | `items[i].custom.label` | (caisse, article libre) libellé requis (120 caractères au plus) | — |
| `CUSTOM_PRICE_INVALID` | 422 | `items[i].custom.unitPrice` | (caisse) prix unitaire en cents (entier positif ou nul) attendu | — |
| `CUSTOM_TAXABLE_INVALID` | 422 | `items[i].custom.taxable` | (caisse) booléen attendu | — |
| `CUSTOM_TAX_CATEGORY_INVALID` | 422 | `items[i].custom.taxCategory` | (caisse) valeur attendue : standard, reduced… | `{allowed}` |
| `PRICE_OVERRIDE_INVALID` | 422 | `items[i].priceOverride.unitPrice` | (caisse, prix manuel) prix unitaire en cents (entier positif ou nul) attendu | — |
| `DISCOUNTS_LIST_INVALID` / `DISCOUNT_INVALID` / `DISCOUNT_PERCENT_INVALID` / `DISCOUNT_AMOUNT_INVALID` | 422 | `items[i].discounts…`, `ticketDiscount` | (caisse, remises F1) liste attendue / {type: percent \| amount, value > 0} / pourcentage invalide / cents attendus | — |

### Adresses du compte : `POST /api/adresses`, `PUT /api/adresses/{id}` (400)

Forme retenue, la moins risquée pour le site Next.js : `error` **inchangé** (`{champ: message}` pour la validation, `{address: message}` pour
EasyPost) et, à côté, `code` (`VALIDATION_FAILED` ou `ADDRESS_UNDELIVERABLE`), `message` (« Adresse invalide », ou le message d'EasyPost) et `errors[{path, code, message, params?}]` (même
forme que le devis et la commande). `PROVINCE_INVALID` et `COUNTRY_INVALID` n'existent pas : ces champs ne sont validés que comme
obligatoires (ajouter une validation refuserait des adresses acceptées aujourd'hui). Source : `AdressInputDTO::errorOf`.

| `errors[].code` | `path` | Message français | `params` |
|---|---|---|---|
| `FIELD_REQUIRED` | `firstname`, `lastname`, `addressLineOne`, `city`, `province`, `zipCode`, `country`, `contactNumber` | Le prénom est obligatoire. / Le nom de famille… / La première ligne d'adresse… / La ville… / La province / région… / Le code postal… / Le pays… / Le numéro de contact est obligatoire. | — |
| `PHONE_INVALID` | `contactNumber` | Le numéro de contact doit contenir entre 7 et 20 chiffres et peut comporter +, espaces ou tirets. | — |
| `POSTAL_CODE_INVALID` | `zipCode` | Le code postal doit être au format canadien A1A 1A1. / … américain 12345 ou 12345-6789. / … compter de 3 à 10 lettres ou chiffres. | `{country}` (tel qu'envoyé) |
| `ADDRESS_UNDELIVERABLE` | `address` | Adresse invalide : <motifs EasyPost> | — |

### Commande : `POST /api/order/create`, `POST /api/order/create-guest` (`CheckoutException`)

`code` de premier niveau (source : `CheckoutException::code()`, `CheckoutCustomerService`, `FinalizeCheckoutOrderUseCase`) ; `error`,
`errors[]` (quand le devis a refusé : mêmes codes par article que ci-dessus) et `authorizationCanceled` (400 et 402) inchangés.

| `code` | Statut | Message |
|---|---|---|
| `FIELDS_REQUIRED` | 400 | Missing required fields (addressId, items) |
| `ADDRESS_FORBIDDEN` | 403 | Unauthorized: Invalid address |
| `GUEST_FIELDS_REQUIRED` | 400 | Missing required guest fields |
| `EMAIL_REQUIRED` | 400 | Email is required |
| `LICENSE_REQUIRED` | 400 | License number and expiration date are required for rental orders. |
| `CARRIER_NOT_FOUND` | 404 | Carrier not found |
| `SHIPPING_PRICE_INVALID` | 400 | Frais de livraison invalides |
| `AMOUNT_INVALID` | 400 | Montant invalide calculé (`create-intent`) |
| `PAYMENT_NOT_VERIFIED` | 402 | Payment verification failed or payment not completed |
| `PAYMENT_REQUIRED` | 402 | Un paiement en ligne est requis pour cette commande. |
| `PAYMENT_ALREADY_USED` | 409 | Ce paiement est déjà associé à une commande. |
| `ORDER_IN_PROGRESS` | 409 | La commande de ce paiement est en cours de création : réessayez dans un instant. |
| `CART_MISMATCH` | 409 | La commande ne correspond pas au panier payé (ou à payer, à `create-intent`) : corps différent du panier coté (articles, quantités, réservation, transporteur, livraison, adresse) ; lot CHECKOUT-PANIER |
| `QUOTE_INVALID`, `VARIANT_NOT_FOUND`, `CARRIER_NOT_FOUND`, `BAD_REQUEST` | 400 | commande refusée après le paiement (autorisation annulée) : code, message et `errors[]` du devis repris tels quels |
| `ORDER_REFUSED` | 400 | Commande refusée. / dernier message d'échec gardé sur la trace du paiement (`checkout_session.last_error`) |
| codes par statut (`BAD_REQUEST`, `FORBIDDEN`…) | — | tout autre refus sans code propre |

Tests : `tests/Functional/Boutique/CartQuoteApiTest.php` (`quoteErrorCases`, un cas par code), `AdressApiTest::testRefusedFieldsCarryTheirCodes`,
`CheckoutApiTest::testRefusalsOfTheIntentAndOfTheOrderCarryTheirCodes`. `docs/endpoints.md` est généré sans détail des réponses : la table
de référence est ici.

## Courriels du client en français et en anglais (03/11/2026, application e-commerce mobile, lot L6b)

Les courriels **destinés au client** partent dans sa langue, `fr` ou `en`. Ceux **destinés au marchand** (avis d'expédition
`emails/shipping_notification.html.twig`, notifications d'administration) restent en français.

- **Langue** (`Services/EmailConfigurationService/EmailLocale`) : `?locale=fr` ou `?locale=en` sur la requête qui déclenche l'envoi
  (`fr-CA`, `EN`… acceptés) ; pour une commande, la locale enregistrée avec la session de paiement (`checkout_session.locale`, donc
  celle de `create-intent`, lue aussi par le webhook). Absente ou autre valeur : **langue du site**, même règle que les tickets de
  caisse (`Services/CaisseService/SiteLocaleResolver`, adresse de la fiche Entreprise : Québec, France et DROM → fr ; autres
  provinces du Canada et États-Unis → en ; sans adresse → fr). Aucun écouteur ne fixe `$request->getLocale()` (toujours `en`,
  défaut du framework) : chaque point d'envoi résout la langue lui-même, aucune autre réponse de l'API ne change.
- **Textes** : traducteur de Symfony, domaine `emails` (`translations/emails.fr.yaml`, `emails.en.yaml`), sujet dans le service
  (`EmailSenderService::sendCustomerEmail`), corps par `|trans` dans les gabarits avec la locale passée explicitement. Le fichier
  français reprend **mot pour mot** les textes qui étaient écrits dans le PHP et les gabarits : le texte rendu en français ne
  change pas. Seule exception, voulue par le propriétaire : le sujet du mot de passe oublié, « Password Reset » hérité, devient
  « Réinitialisation du mot de passe ».
- **Montants et dates** : devise du site (`TenantCurrencyProvider` : fiche Entreprise, CAD à défaut ; la commande n'enregistre
  pas de devise ; avant, `CAD` était écrit en dur dans les gabarits) au format de la langue **et** du pays du site (`intlLocale` :
  `fr_CA` → 12,00 $, `fr_FR` → 12,50 €, `en_CA` → $12.00, `en_US` → $12.50 ; avant, tout était rendu en `en` : CA$12.00). Les dates
  françaises gardent leurs formats (`d/m/Y à H:i`, réservation `Y-m-d H:i`) ; en anglais, format Intl moyen de la locale.

| Courriel | Déclencheur | Langue lue | Gabarit |
|---|---|---|---|
| Code de vérification (site, puis repli plateforme) | `POST /api/login` (otpEnabled), confirmation d'un changement d'adresse ou d'une suppression de compte, formulaire de connexion web | `?locale=` de la requête | `security/2fa_email.html.twig` |
| Validation de l'inscription, nouveau lien | `POST /api/register`, `POST /api/resend-verification` | `?locale=` | `verification/validation_email.html.twig` |
| Mot de passe oublié | `POST /api/password-reset/request` | `?locale=` (le lien vers le site garde `locale=` comme avant) | `reset_password/reset.html.twig` |
| Adresse courriel modifiée (à l'ancienne adresse) | `PATCH /api/profile` | `?locale=` | `emails/email_changed_notice.html.twig` |
| Compte supprimé | `DELETE /api/account` | `?locale=` | `emails/account_deleted_notice.html.twig` |
| Confirmation de commande | `POST /api/order/create`, `create-guest`, webhook Stripe | locale de la session de paiement (`create-intent?locale=`, sinon `order.locale`, sinon langue du site) | `emails/order_confirmation.html.twig` |

Hors lot : le formulaire web de réinitialisation du backend (`reset_password/form.html.twig`, `success.html.twig`, atteint seulement
sans page de réinitialisation sur le site) et le courriel du formulaire `/register` du backend (`registration/confirmation_email.html.twig`,
anglais, VerifyEmailBundle) ne suivent pas `?locale=` : inchangés. **Suggestions d'adresse** (`GET /api/adresses/autocomplete`,
`/details`) : `language` (fr ou en, même règle) transmis à Google Places, suggestions et noms de pays dans la langue du client.

Tests : `tests/Functional/Email/CustomerEmailLanguageTest` (chaque courriel en en et en fr, repli sur la langue du site Ontario → en et
France → fr, devise EUR d'un site en France, `language` envoyé à Google Places avec un client HTTP simulé).

À venir (décision du propriétaire, 02/11/2026, après la clé EasyPost de production du lot M2) : courriel **« commande expédiée »** au
client, avec le numéro de suivi EasyPost, dans sa langue. La commande n'enregistre pas de langue aujourd'hui (aucun courriel n'est
envoyé au client après la création) : ajouter alors `order.locale` (nullable, migration additive + script `migrate_all_v2`), remplie à la
création (client, invité, webhook) depuis `checkout_session.locale`, langue du site pour les anciennes commandes ; vérifier à ce
moment-là les transporteurs français couverts par EasyPost (Colissimo, Chronopost, Mondial Relay).

### Compte client : inscription, vérification, mot de passe, code de connexion, profil (04/11/2026, site Next.js)

Même contrat : `code` (majuscules) et `message` ajoutés au premier niveau, `params` quand le texte cite une valeur ; `error`, les champs
existants et les statuts ne changent pas. Les messages marqués **(en)** sont en anglais dans `error` : le site les traduit par leur code.
Les codes du changement de courriel et de la suppression du compte (`PASSWORD_REQUIRED`, `PASSWORD_INVALID`, `OTP_REQUIRED`, `OTP_INVALID`,
`TOO_MANY_ATTEMPTS`, `RESERVATION_UPCOMING`…) existent déjà dans `error` et sont inchangés.

| Code | Statut | Route | `error` | `params` |
|---|---|---|---|---|
| `FIELDS_REQUIRED` | 400 | `POST /api/register` | Invalid data **(en)** | `{fields: [email, password, firstName, lastName]}` |
| `PASSWORD_TOO_SHORT` | 400 | `POST /api/register`, `POST /api/password-reset/confirm` | `PASSWORD_TOO_SHORT` (inscription, `message` en français) ; Le mot de passe doit comporter au moins 8 caractères. | `{min: 8}` |
| `EMAIL_INVALID` | 400 | `POST /api/register`, `PATCH /api/profile` | Invalid email format **(en)** ; Invalid email **(en)** | — |
| `EMAIL_DOMAIN_INVALID` | 400 | `POST /api/register` | Email domain appears invalid **(en)** | — |
| `EMAIL_ALREADY_USED` | 409 | `POST /api/register`, `PATCH /api/profile` | User already exists **(en)** ; Email already in use **(en)** | — |
| `EMAIL_REQUIRED` | 400 | `POST /api/resend-verification`, `POST /api/password-reset/request` | Email manquant ; Email is required. **(en)** | — |
| `RATE_LIMITED` | 429 | `POST /api/resend-verification` (B16 : 3 par heure par site et courriel, 20 par site et IP, plafond global par IP) | Trop de tentatives. Réessayez dans %d minute(s). | `retryAfterSeconds` |
| `EMAIL_SEND_FAILED` | 500 | `POST /api/resend-verification` | Erreur lors de l'envoi de l'email. (corps remplacé par nginx en production) | — |
| `FIELDS_REQUIRED` | 400 | `POST /api/password-reset/confirm` | token et password attendus. | `{fields: [token, password]}` |
| `RESET_LINK_INVALID` | 400 | `POST /api/password-reset/confirm` | Lien invalide ou expiré : demandez un nouveau lien. | — |
| `OTP_INVALID` | 401 | `POST /api/otp-verify` | Code OTP invalide ou expiré (code faux, expiré **ou courriel inconnu** : même réponse depuis B16, 04/11/2026 ; `UNAUTHORIZED` ne sort plus) | — |
| `UNAUTHENTICATED` | 401 | `GET`/`PATCH /api/profile` | Not authenticated **(en)** (en pratique le pare-feu répond avant : `JWT_NOT_FOUND`) | — |
| `INVALID_BODY` | 400 | `PATCH /api/profile` | Invalid JSON **(en)** | — |
| `LICENSE_DATE_INVALID` | 400 | `PATCH /api/profile` | Invalid date format for licenseExpirationDate. Use YYYY-MM-DD. **(en)** | `{format: YYYY-MM-DD}` |

**Ne pas révéler si un compte existe (B16, 04/11/2026)** : `POST /api/resend-verification` répond toujours 200
`{ message: "Si un compte à activer existe pour ce courriel, un nouveau lien vient d'être envoyé." }`, que le compte soit à activer
(courriel envoyé), inconnu ou déjà activé (rien n'est envoyé) ; avant : 404 `USER_NOT_FOUND`, 400 `ACCOUNT_ALREADY_VERIFIED`, 200
« Email de vérification renvoyé avec succès. ». `POST /api/otp-verify` répond 401 `OTP_INVALID` pour un courriel inconnu comme pour
un code faux (audit `invalid_otp`, limite `otp_verify_account` inchangée).

Hors API (formulaire web du backend `POST /password-reset/confirm`) : inchangé. Tests : `AccountErrorCodesApiTest` (un cas par code),
`AccountEnumerationTest` (réponses identiques du renvoi, limiteur, otp-verify).

## Taxes par région (09/10/2026, `TaxEngine`)

Le devis, `create-intent` et la commande calculent les taxes de la même façon, selon l'adresse de livraison
(`shippingAddress { country, province|state, city, postalCode }` dans le devis et `create-intent` ; adresse de la
commande ensuite). Réglage `commerce.taxProvider` des réglages de la boutique : `table` (défaut) ou `stripe`.

- **Retrait en magasin (B20, 08/11/2026)** : le bien est remis au magasin, donc les taxes suivent la province de la **boutique**
  (adresse de la fiche entreprise), pas celle du client : règle du lieu de fourniture, la même qu'une vente au comptoir. Un client
  de l'Ontario qui retire au Québec paie TPS et TVQ, pas la TVH ; un client hors Canada paie les taxes du magasin. La règle vaut
  pour le devis (`taxBasis: store`), `create-intent` (montant toutes taxes comprises même sans `shippingAddress`) et la commande
  (`OrderTaxAddressResolver::forOrder` : transporteur `pickup` → adresse de la boutique, même si la commande a une adresse).
  Sans fiche entreprise, l'adresse du client sert comme avant. Stripe Tax reçoit la même adresse.
- **Sans adresse** : `taxes: []`, `taxStatus: 'address_required'`, `total` hors taxes (le frontend affiche « taxes
  calculées au paiement »). Avec adresse : `taxStatus` = `calculated`, `no_tax` (aucune taxe pour cette région) ou
  `fallback_table` (Stripe demandé mais indisponible : table utilisée). Le devis renvoie aussi `taxProvider`,
  `taxCalculationId` (Stripe) et `shippingAddress` normalisée (pays ISO, province en code : « Québec » → `QC`).
- **`table`** : table `tax` du site (EasyAdmin « Taxes »), une taxe s'applique si `country` (ISO, vide = tous) et
  `province` (« Toutes », ou codes séparés par des virgules : `QC` ; `ON,NB,NL,PE`) correspondent à l'adresse ; les
  noms des provinces canadiennes sont acceptés. Taux sur articles + livraison. Table canadienne complète posée sur
  `demo` (`BoutiqueDemoCatalog::TAXES` : TPS hors provinces à TVH, TVQ, TVH 13/14/15 %, TVP BC/MB/SK) ; **les sites
  clients gardent leurs lignes** (Kara & B : TVQ à 10 % et TPS « Toutes », à corriger dans leur administration).
- **Catégories fiscales (F18 partie 2a, 01/11/2026)** : `tax.category` et `product.tax_category` (`standard`, `intermediate`,
  `reduced`, `super_reduced`, `exempt` ; vide = standard pour un produit, « tous les produits non exonérés » pour une taxe) ; assiette
  par taxe (`taxes[].base`, `taxes[].total`, `taxes[].category`, `lines[].taxCategory` ajoutés au devis ; `pricesIncludeTax: false`
  tant que la partie 2b n'existe pas), catégorie figée sur `order_items.tax_category`, `order_tax.base`. Sans catégorie, montants et
  totaux inchangés au centime. **Clés de `commerce` en lecture seule** (`BoutiqueSettingsService::READ_ONLY_COMMERCE`), ajoutées
  à `GET /api/boutique-settings` depuis la fiche Entreprise et **ignorées par le PUT** (jamais enregistrées ni journalisées, donc jamais
  remises à faux parce que le front ne les renvoie pas) : `commerce.pricesIncludeTax` (`entreprise.prices_include_tax`) et
  `commerce.siteCountry` (pays ISO de l'adresse du site, `address_entreprise.country`, null sans adresse ; c'est aussi le pays des taxes
  d'une vente au comptoir). Le PUT continue de remplacer l'objet `commerce` tel quel (seul l'éditeur Next.js écrit cette route).
  Détail et cas chiffrés : `docs/caisse.md`, « Catégories fiscales ».
- **Prix taxes comprises (F18 partie 2b, 02/11/2026)** : réglage de site `entreprise.prices_include_tax` (fiche Entreprise ; refusé avec Stripe
  Tax ou un taux non multiple de 0,01 % ; verrouillé après la première vente). Pour un site TTC, les prix du catalogue sont TTC tels que saisis ;
  le devis renvoie `pricesIncludeTax: true`, `subtotal` et `shipping` TTC, **`total = subtotal + shipping`**, `taxes[]` avec `base`/`amount`/`total`
  par groupe de taux (livraison répartie au prorata des TTC ; seules les taxes qui frappent une ligne sont servies) ; `create-intent` autorise ce
  total ; la commande porte `prices_include_tax` et se lit avec `pricesIncludeTax`, `subTotal` (lignes seules : depuis le 02/11/2026 la livraison
  n'est plus comprise dans `sub_total`, dans les deux modes ; `totalAmount = subTotal + priceShipping (+ priceTax en HT)`), `priceTax` inclus,
  `taxes[]`. Fronts : afficher « TTC » et « dont TVA » quand `pricesIncludeTax` est vrai ;
  rien ne change pour les sites hors taxes. Détail, formule et cas : `docs/caisse.md`, « Prix taxes comprises ».
- **Codes d'erreur (02/11/2026)** : toute réponse JSON 4xx de l'API porte une clé `code` (chaîne stable) en plus de `error`/`message`
  inchangés ; `GET /api/orders`, `/api/payments`, l'annulation, la connexion (plateforme refusée), `/api/token/refresh` et le 429 gagnent
  `code` + `message` ; les 401 de Lexik gardent `code: 401` et reçoivent `error` (chaîne). Table complète : `docs/caisse.md`, « Codes d'erreur » ;
  codes par article du devis, par champ de l'adresse et par motif de la commande : « Codes du devis, de l'adresse et de la commande » ci-dessus.
- **`stripe`** : Stripe Tax (`Tax Calculations`) sur le compte connecté du site, une ligne par article (code fiscal
  « bien physique » `txcd_99999999`, « service » `txcd_20030000` pour une location, livraison `txcd_92010001`),
  montants par juridiction (`taxes[].jurisdiction`), calcul gardé 10 min pour un même panier et une même adresse. À la
  commande payée, la transaction fiscale est enregistrée chez Stripe (`order.tax_transaction_id`, rapports de
  déclaration du site). Prérequis par site (compte Express, sans tableau de bord Tax) :
  `app:boutique:stripe-setup --tenant=<code> --tax` pose le siège et les inscriptions (`CA`, `CA-QC` par défaut ; `US-NY`,
  `FR`…) ; le statut passe de `pending` à `active`. Coût : facturé par Stripe au compte connecté.
- Commande : `TaxCalculationService` reprend les taxes du devis (`Order::pendingTaxes`) et crée une ligne `OrderTax`
  par taxe (rattachée à la table pour `table`, sans rattachement pour Stripe) ; une commande de caisse ou un retour sans
  devis est calculé sur son sous-total. Schéma : `tax.country`, `order.tax_transaction_id` (migration
  `Version20261009110000`, script `scripts/migrate_all_v2_tax_regions.sh`).
- Tests : `CartQuoteApiTest::testTaxesFollowTheShippingAddressRegion` (QC, ON, NB, FR, sans adresse, repli Stripe → table).

### Taxes gelées, utilisées, datées (PAYS-1, 12/11/2026)
- Chaque ligne `order_tax` gèle la taxe appliquée : libellé, taux en millionièmes, code, juridiction, catégorie, mode TTC et source. Le
  détail de la commande (`OrderDTO.taxes`) gagne `code`, `ratePpm`, `jurisdiction` et `rateSource` (ajout seulement).
- Une taxe déjà utilisée ne change plus de taux, de nom, de code, de catégorie, de pays, de région ni de début de validité, et ne se
  supprime plus (`TAX_IN_USE`). Elle reste archivable et bornable (`valid_to`).
- Pour un nouveau taux : une nouvelle taxe datée (`valid_from`). Les taxes archivées ou hors de leurs dates (jours du magasin) ne
  s'appliquent plus.
- Détails, déclencheur et clés du ticket : `docs/caisse.md`, « Taxes gelées à la vente ».
- Prix taxes comprises (PAYS-1c, 12/11/2026) : calcul au millionième, donc permis au Québec (TVQ 9,975 %) ; résultats inchangés pour les
  taux multiples de 0,01 %. Détails : `docs/caisse.md`, « Prix taxes comprises au Québec et règle hors ligne v5 ».

## Annulation d'une commande web (`POST /api/order/cancel/{id}`)

Réservée à l'auteur de la commande (client du site Next.js ou de l'application e-commerce) ; un administrateur annule
depuis EasyAdmin en passant le statut à « Annulé ». La première annulation est inchangée : statut 7, montants remis à 0,
remboursement enregistré, stock réintégré, réponse `{message}`. Une **seconde annulation** est refusée :
**409 `ORDER_NOT_REFUNDABLE`** `{orderId, status: 7}`, rien n'est écrit (avant : 200 et stock réintégré une seconde fois).
Détails dans `docs/caisse.md`.

- PAYS-1b (12/11/2026) : la commande annulée **garde ses montants** (totaux et taxes ; `canceled_at` la marque). `OrderDTO` sert le total de
  la commande avec `status` 7 et `paymentStatus` « canceled » : le front affiche « Annulée » et le remboursement, jamais « à payer ». Refus
  `ORDER_HAS_RETURNS` si des articles ont déjà été retournés. Détails : `docs/caisse.md`, « Annulation sans remise à zéro ».

## Compte client (08/10/2026, § 8)

- **Nouveau mot de passe** : `POST /api/password-reset/request { email }` envoie un lien vers la page du frontend du
  site : `https://<hôte du site>/reset-password?token=…&locale=` (chemin `FRONTEND_PASSWORD_RESET_PATH`, défaut
  `/reset-password`, `ResetPasswordController::DEFAULT_FRONTEND_RESET_PATH` ; vide = ancien formulaire du backend).
  La page appelle `POST /api/password-reset/confirm { token, password }` (ou `newPassword`) : 200 `{ message }`,
  400 jeton inconnu ou expiré (1 h) ou mot de passe de moins de 8 caractères ; même limite de débit que la demande.
  L'ancien `POST /password-reset/confirm` (formulaire Twig) reste.
- **Détail d'une commande** : `GET /api/orders/{id}` pour le client connecté propriétaire, ou pour un invité avec
  `?token=` (`order.guest_token`, renvoyé **une seule fois** dans la réponse de `order/create-guest` : `guestToken`) ;
  autre client ou jeton faux : 404, sans client ni jeton : 401. Même forme que les éléments de `GET /api/ordersuser`
  (`OrderPresenter`). Colonne : migration `Version20261009090000`, script `scripts/migrate_all_v2_order_guest_token.sh`.
- **Statuts** : `GET /api/order-statuses?locale=` → `[{ id, name, description }]` (identifiants communs à tous les
  sites : 1 Incomplete … 7 Annulation).
- **`GET /api/ordersuser` : pagination facultative (01/11/2026, application mobile)** : `?page=1&limit=20` (limite 1 à 100, 20 par
  défaut) → même corps (tableau des commandes de la page, de la plus récente à la plus ancienne), total dans les en-têtes
  `X-Total-Count`, `X-Page`, `X-Limit` (exposés par nginx, `Access-Control-Expose-Headers`). Sans ces paramètres : tableau complet,
  inchangé, sans en-tête. Test : `tests/Functional/Boutique/CustomerOrdersApiTest.php`.
- **Champs ajoutés à la commande (liste et détail, 01/11/2026, `OrderPresenter`)** : `paymentStatus` (valeurs stables :
  `canceled` commande annulée, statut 7 ; `refunded` commande de retour, ou commande ayant un retour ou un paiement de type
  remboursement ; `paid` un paiement client complété, statut « Complété » ou Stripe `succeeded` ; `authorized` paiement Stripe
  autorisé non capturé, `requires_capture` ; `failed` seulement des paiements en échec ; `pending` aucun paiement ou en cours) ;
  `shipment` : `{carrier, trackingNumber, trackingUrl}` dès qu'une étiquette (`shipping_label`) existe sur un colis de la commande
  (transporteur dans la langue demandée ; page de suivi connue pour Postes Canada, Purolator, UPS, FedEx, USPS, DHL, sinon
  `trackingUrl` null), sinon `null`.
- **`GET /api/ordersuser`** : montants en **cents entiers** (`totalAmount`, `subTotal`, `priceTax`, `priceShipping`,
  lignes `unitPrice`, `totalPrice`), `currency`, `statusId`, `carrier { id, name, kind, isPickup }` (`kind` et `isPickup` : B20,
  08/11/2026, liste et détail ; « Retrait en magasin » à afficher quand `isPickup` est vrai), lignes avec `productVariantId`,
  `booking { start, end, status, rateId }` et `customizationId` (combinaison de `GET /api/customization/config/{variantId}`
  envoyée dans l'article du devis et de la commande ; vérifiée contre la variante, 422 sinon ; colonne
  `order_items.customization_id`, migration `Version20261009150000`).
- Tests : `tests/Functional/Boutique/CustomerAccountApiTest.php`.

## Compte : inscription, session mobile, suppression (01/11/2026, application e-commerce mobile)

### `POST /api/register`
- **Faille fermée** : `platform: "pos"` créait un compte de caisse (`ROLE_USER_POS`) pour n'importe qui. Désormais 400
  `{error: "PLATFORM_INVALID"}` (casse et espaces indifférents) ; tout compte créé ici porte `ROLE_USER_INTERNET`. Les comptes de
  caisse se créent dans EasyAdmin ; la caisse (posflutter) n'appelle jamais cette route (vérifié le 01/11/2026). Sites : aucun
  compte `ROLE_USER_POS` ne porte `signup_channel = web_register` (tous créés avant le 16/10/2026 ou par EasyAdmin).
- **Mot de passe** : 8 caractères au moins (400 `{error: "PASSWORD_TOO_SHORT", message}`), comme `/api/password-reset/confirm` ;
  avant, une chaîne vide passait.
- **Origine du compte** : `app: "mobile"` → `user.signup_channel = mobile_shop` (`User::SIGNUP_MOBILE_SHOP`) ; `app: "livingmemory"`
  → `mobile` (inchangé) ; sinon `web_register`.

### Session de l'application mobile
- `POST /api/token/refresh { refresh_token, platform: "mobile" }` : le jeton de rafraîchissement est **prolongé de 7 jours** à chaque
  rafraîchissement (expiration glissante, même jeton, pas de rotation : le corps reste `{message, csrf_token, token}`). Sans `platform`,
  rien ne change (jeton de 7 jours fixes) ; session web par cookie inchangée. Living Memory (plateforme « mobile ») peut l'envoyer aussi.
- `POST /api/password-reset/confirm` révoque tous les jetons de rafraîchissement du compte : chaque appareil se reconnecte avec le
  nouveau mot de passe. La suppression du compte les révoque aussi.

### `DELETE /api/account` (ROLE_USER_INTERNET) : suppression du compte
Exigence App Store 5.1.1(v) et Google Play. `AccountApiController`, `DeleteAccountUseCase`, `AccountDeletionGuard` (conditions),
`PersonalDataEraser` (effacement, partagé avec Mémoires Vivantes).

| Réponse | Cas |
|---|---|
| 403 `ADMIN_ACCOUNT` | administrateur : se supprime depuis la console |
| 429 `TOO_MANY_ATTEMPTS` + `retryAfterSeconds` + `Retry-After` | limiteur `account_delete_account`, 10 essais par 15 min par compte |
| 409 `SUBSCRIPTION_ACTIVE` (+ `count`) | abonnement Stripe `active`, `trialing`, `past_due` ou `paused` : le client le résilie d'abord (rien n'est résilié à son insu) |
| 409 `RESERVATION_UPCOMING` (+ `count`) | réservation à venir non annulée au courriel du compte : à annuler d'abord |
| 403 `PASSWORD_REQUIRED` / `PASSWORD_INVALID` | compte avec mot de passe choisi : `{password}` (ou `currentPassword`) |
| 403 `OTP_REQUIRED` (`sentTo`, `lifetimeSeconds: 300`) / `OTP_INVALID` | compte sans mot de passe choisi (`passwordSet` faux) ou `otpEnabled` : code envoyé au courriel du compte, puis `{otpCode}` ; même mécanique que le changement de courriel |
| 200 `{deleted: true, addressesAnonymized, reviewsAnonymized, reservationsAnonymized, newsletterRemoved, stripeCustomerDeleted}` | effacé |

Avant l'effacement, courriel de confirmation à l'adresse du compte (`emails/account_deleted_notice.html.twig`). Effacé ou anonymisé :
courriel (`supprime-<id>@compte-supprime.invalid`), nom, mot de passe, rôles, jetons de vérification et de réinitialisation, permis,
adresses (**anonymisées sur place** : nom, rue, téléphone « - », ville, code postal, province ; le pays reste pour les taxes ; les commandes
qui les désignent gardent donc une ligne sans donnée personnelle), client Stripe (supprimé chez Stripe sur le compte connecté du site),
avis (`author_name` « Client anonyme », lien au compte rompu, avis conservé), inscription à l'infolettre (retirée), réservations passées
(nom, courriel, téléphone), sessions de paiement en cours, PIN de caisse, jetons de rafraîchissement, codes à usage unique, demandes de
réinitialisation ; `deleted_at` posé. **Conservé** pour la comptabilité : la ligne du compte anonymisée, commandes, paiements, abonnements
terminés. Le jeton d'accès en cours cesse de fonctionner (le courriel qu'il désigne n'existe plus).

Tests : `tests/Functional/Security/RegisterRulesApiTest.php`, `tests/Functional/Security/MobileSessionLifetimeTest.php`,
`tests/Functional/Boutique/AccountDeletionApiTest.php`.

### « Effacer ce compte client » depuis le back-office (B19, 06/11/2026)
Google Play exige une page publique qui explique comment demander la suppression de son compte sans l'application (côté Next :
`/suppression-compte`) ; elle propose d'écrire à la boutique. Trois voies mènent donc au **même effaceur** : `DELETE /api/account`
(application et site web, bouton « Supprimer mon compte »), `DELETE /api/memoires/mobile/account` (Living Memory) et l'action
**« Effacer ce compte client »** d'EasyAdmin (liste et fiche Utilisateurs, `UserCrudController`), qui ouvre la page de confirmation
`GET`/`POST /admin/users/{id}/effacer` (`Controller/Admin/CustomerAccountEraseAdminController`, `ROLE_ADMIN`, lien habillé EasyAdmin) :
- **Même effacement que l'API**, sans mot de passe ni code (c'est l'administrateur qui agit) : `UseCase/AccountUseCase/AdminEraseCustomerAccountUseCase`
  → `MemoiresVivantes/Services/AccountDeletionService::erase()` (livres Mémoires Vivantes effacés comme depuis l'application, puis
  `PersonalDataEraser`). Ordre : effacement d'abord, **courriel de confirmation ensuite** à l'adresse relevée avant, dans la langue du
  site (jamais envoyé si l'effacement a échoué ; un échec d'envoi n'annule rien, il est journalisé et affiché à l'administrateur).
- **Mêmes refus**, affichés en clair avec un lien vers la fiche à régler : abonnement en cours (`SUBSCRIPTION_ACTIVE`, fiche Abonnements,
  « Résilier maintenant » ou « en fin de période ») et réservation à venir (`RESERVATION_UPCOMING`, fiche Réservations, « Annuler ») ;
  `AccountDeletionGuard::blockers()` / `blockingItems()` sont partagés avec l'API. Tant que ça bloque, la page n'a pas de formulaire.
- **Jamais** sur un compte interne (`AccountDeletionGuard::isCustomerAccount` : `ROLE_USER_INTERNET` sans `ROLE_ADMIN`, `ROLE_SUPER_ADMIN`,
  `ROLE_USER_POS` ni `ROLE_POS_MANAGER` ; `NOT_CUSTOMER_ACCOUNT`), sur soi-même (`SELF_ACCOUNT`) ni sur un compte déjà effacé
  (`ALREADY_ERASED`) : l'action n'est pas proposée et la page refuse même une requête forgée.
- **Confirmation explicite** : formulaire CSRF et case « irréversible » (pas de simple boîte de dialogue).
- **« Supprimer » d'EasyAdmin** n'est plus proposé pour un compte client et `deleteEntity` le refuse (403) même par une URL forgée : la
  suppression physique perdrait les commandes ; elle reste disponible pour les comptes internes.
- **Trace** sans donnée personnelle du client : journal sécurité `account.deleted_by_admin` et ligne `content_audit_log` (ressource `user`,
  action `erase`, identifiant du compte, identifiant de l'administrateur, compteurs).

Écart connu, non traité dans ce lot : `DELETE /api/account` envoie le courriel de confirmation **avant** d'effacer (un effacement raté
après l'envoi annoncerait une suppression qui n'a pas eu lieu) et n'efface pas les livres Mémoires Vivantes du compte (voie
`/api/memoires/mobile/account`). Tests : `tests/Functional/Admin/CustomerAccountEraseAdminTest.php`.

### Codes à usage unique et limites de débit (01/11/2026, suite du lot « Compte »)
- **Usage des codes** : colonne `otp_code.purpose` (`login`, `email_change`, `account_deletion` ; `login` pour les codes existants ;
  migration `Version20261107100000`, script `scripts/migrate_all_v2_otp_purpose.sh`). `/api/otp-verify` et le formulaire OTP du
  back-office n'acceptent que `login` ; `EmailChangeGuard` que `email_change` ; `AccountDeletionGuard` que `account_deletion`. Un
  code d'un autre usage répond comme un code invalide (401 « Code OTP invalide ou expiré », 403 `OTP_INVALID`). Générer un code
  (`OtpService::generateAndSendOtp(user, request, purpose)`) supprime les codes non utilisés du même compte et du même usage.
- **Limites par compte, par site** : connexion (`api_login_account`), code (`otp_verify_account`), réinitialisation
  (`password_reset_account`), changement de courriel et suppression sont comptés par site **et** par compte : clé `code du site`
  (tenant résolu par `X-Tenant-Host` ; à défaut l'hôte appelé) + courriel ou identifiant. Avant, la clé était l'hôte du backend : un
  même courriel partageait son compteur entre toutes les boutiques. **Par site et IP** aussi (suite du 01/11/2026) : connexion 50 par
  15 min, réinitialisation 20 par heure, formulaires publics 10 envois acceptés par 10 min ; puis **plafond global par IP seule**,
  toutes boutiques confondues (connexion 200 par 15 min, réinitialisation 60 par heure, formulaires 30 envois acceptés par 10 min),
  que rien ne contourne (ni la répartition sur plusieurs sites, ni l'absence de `X-Tenant-Host`). Inchangés : PIN de caisse,
  activation, code court.
- Tests : `tests/Functional/Security/OtpPurposeTest.php`, `tests/Functional/Security/RateLimitPerSiteTest.php`.

## Sécurité du compte et du paiement (S7, 31/10/2026)

Code : `Services/PaymentService/PaymentOwnershipService`, `Services/AccountService/EmailChangeGuard`, `Controller/paymentController/PaymentsController::processPayment`,
`Controller/Account/ProfileApiController::updateProfile` ; tests `tests/Functional/Boutique/CustomerAccountApiTest.php`.

### `POST /api/payment { paymentIntentId, guestToken? }` : propriété du paiement

Avant, tout utilisateur connecté obtenait l'état, la marque et les quatre derniers chiffres de la carte, et l'URL du reçu de
**n'importe quel** paiement du site à partir de son identifiant `pi_…`. Désormais le paiement doit appartenir à l'appelant : session
de paiement du panier à son nom (`create-intent`), ou commande créée pour ce paiement qui est la sienne, ou commande d'invité dont il
présente le jeton (`guestToken`, celui rendu une seule fois par `order/create-guest`) ; l'administrateur du site voit tout. Sinon
**404 `PAYMENT_NOT_FOUND`** `{success: false, error, message}`, identique pour un paiement d'autrui et pour un identifiant inconnu ;
journal sécurité `payment.verify.not_owner`. Réponse inchangée pour un client légitime (`{success, payment {stripePaymentId, status,
receiptUrl, cardBrand, last4, riskLevel}}`, 500 `{success: false, errors}` si le paiement n'a pas abouti). La route est conservée (appelants
MAUI non vérifiables) ; le site Next.js ne l'appelle pas.

### `PATCH /api/profile` : changement d'adresse courriel confirmé

- La confirmation n'est exigée que si l'adresse **change réellement** : comparaison après `trim` et en minuscules, la même que le site
  (`emailChange.js`). Le tunnel de paiement du site Next.js (`checkoutFlow.js`, `completeOrder`) envoie l'adresse **actuelle** : ce cas,
  comme une adresse absente ou vide, est **sans effet** (28/10/2026 : l'adresse n'est plus réécrite avec la casse ou les espaces envoyés,
  et aucun nouveau jeton n'est rendu ; avant, elle l'était telle quelle, ce qui pouvait casser la connexion). Une adresse réellement
  nouvelle est validée (400 `Invalid email` sinon, ou si `email` n'est pas une chaîne) puis enregistrée telle que saisie, sans espaces.
- Compte avec mot de passe : champ **`currentPassword`** obligatoire dans le corps → 403 `PASSWORD_REQUIRED` (absent) ou 403
  `PASSWORD_INVALID` (faux), message en clair, rien n'est modifié.
- Compte en connexion par code (`otpEnabled`) ou sans mot de passe utilisable : un **code OTP est envoyé à l'ancienne adresse** par le
  mécanisme existant (`OtpService`, 6 chiffres, 5 minutes) → 403 `OTP_REQUIRED {message, sentTo (masqué), lifetimeSeconds}` ; renvoyer la
  même demande avec **`otpCode`** → 403 `OTP_INVALID` si faux ou expiré ; le code est consommé. Le mot de passe ne suffit pas pour ces comptes.
- Essais limités **par compte** : 10 par 15 minutes (`profile_email_change_account`, comme la connexion) → 429 `TOO_MANY_ATTEMPTS
  {retryAfterSeconds}` ; remis à zéro au succès.
- Après le changement : courriel d'information à l'**ancienne** adresse (`emails/email_changed_notice.html.twig`, serveur d'envoi du site,
  sinon celui de la plateforme ; sans lien d'annulation pour l'instant) et journal sécurité `profile.email_changed {userId, from, to,
  method, noticeSent, ip}` (`var/log/dev-security.log`). L'unicité de l'adresse (409 `Email already in use`) reste vérifiée après la confirmation.
- Comptes créés par un achat invité (mot de passe que le client n'a jamais choisi : `passwordSet` faux) : confirmation **par code**, comme
  les comptes OTP (`otpEnabled` vrai) ; « mot de passe oublié » (`password-reset/confirm`) leur donne un mot de passe (`passwordSet` vrai,
  confirmation par mot de passe ensuite). Le mot de passe de ces comptes est un hachage d'un secret aléatoire jeté aussitôt : inutilisable
  (avant le 04/11/2026 : 20 caractères en clair, jamais envoyés ni affichés, inutilisables aussi pour se connecter ; remplacés par un hachage).

### `GET /api/profile` : `otpEnabled` et `passwordSet` (ajouts)

- **`otpEnabled`** : vrai si un changement d'adresse sera confirmé par un **code** envoyé à l'adresse actuelle (compte OTP, sans mot de
  passe ou `passwordSet` faux) ; faux : par le **mot de passe actuel** (`currentPassword`). Le front choisit le champ à afficher.
- **`passwordSet`** : faux seulement pour un compte créé par un achat invité dont le client n'a pas encore choisi de mot de passe ; vrai
  partout ailleurs (comptes existants d'origine incertaine compris) ; vrai après `password-reset/confirm` ou l'activation d'une invitation.
- Les autres champs ne changent pas.

### Session après un changement d'adresse (`PATCH /api/profile`)

Le jeton d'accès porte le nom d'utilisateur (en général égal à l'adresse) et l'identifiant du compte ; le jeton de renouvellement est
lié à l'adresse. **Quand l'adresse enregistrée change réellement (casse comprise)**, la réponse du `PATCH` ajoute de nouveaux jetons, sous
la forme de la connexion, les jetons de renouvellement de l'utilisateur suivent la nouvelle adresse (ses autres appareils restent connectés)
et un nom d'utilisateur égal à l'ancienne adresse la suit aussi (unicité vérifiée, 409 sinon). **Sans changement d'adresse, la réponse est
identique à avant.**

Une différence de casse ou d'espaces seulement n'est pas un changement : rien n'est réécrit, aucun jeton n'est rendu (28/10/2026).

Sécurité (27/10/2026) : un jeton d'accès n'ouvre que le compte de son identifiant (`id`), et seulement si ce compte porte encore le nom
d'utilisateur ou l'adresse du jeton. Après un changement d'adresse, l'ancien jeton n'ouvre donc ni un compte créé ensuite avec l'ancienne
adresse, ni l'ancien compte quand son nom d'utilisateur a suivi l'adresse (le client renouvelle sa session ou utilise les jetons rendus) ;
un nom d'utilisateur choisi, différent de l'adresse, reste inchangé et son ancien jeton continue d'ouvrir ce seul compte.

| Client | Ce que contient la réponse en cas de changement | À faire côté front |
|---|---|---|
| Site Next.js (session par cookies) | nouveaux cookies `auth_token_<site>`, `refresh_token_<site>`, `XSRF-TOKEN_<site>` ; corps `{message, user, csrf_token}` (aucun jeton dans le corps d'une session par cookies depuis le 14/11/2026) | rien pour les cookies (le navigateur les remplace) ; **reprendre le nouveau jeton CSRF** (`csrf_token`, ou le cookie `XSRF-TOKEN_<site>`) pour l'en-tête `X-XSRF-TOKEN` des requêtes suivantes |
| Application e-commerce, Mémoires Vivantes, MAUI (jeton `Bearer`) | corps `{message, user, token, refresh_token}` | **enregistrer `token` et `refresh_token`** à la place des anciens |

Changement d'adresse par un administrateur (console Mémoires Vivantes `PUT /api/memoires/admin/users/{id}`, EasyAdmin) : les jetons de
renouvellement de l'utilisateur suivent aussi la nouvelle adresse ; aucun jeton n'est rendu (ce n'est pas sa session) : son application
renouvelle son jeton comme d'habitude. Test : `EmailChangeSessionApiTest`.

**À faire côté fronts (site Next.js et application e-commerce Flutter), écran du profil** : quand l'utilisateur modifie son adresse courriel,
demander son mot de passe actuel et l'envoyer dans `currentPassword` avec le `PATCH` ; afficher les messages des 403 `PASSWORD_REQUIRED`,
`PASSWORD_INVALID` et du 429. Pour un compte OTP : sur 403 `OTP_REQUIRED`, afficher « un code a été envoyé à votre adresse actuelle » et un
champ de saisie, puis renvoyer le `PATCH` avec `otpCode`. Les autres champs du profil (nom, téléphone, permis) et le paiement ne changent pas.

## Abonnements (09/10/2026, § 11)

Stripe Billing sur le compte connecté du site. Code : `Controller/SubscriptionController/`, `UseCase/SubscriptionUseCase/`
(`ListSubscriptionPlansUseCase`, `ManageSubscriptionUseCase`, `HandleSubscriptionWebhookUseCase`),
`Services/SubscriptionService/` (`SubscriptionService`, `SubscriptionStripeGateway` derrière
`SubscriptionStripeGatewayInterface` — `tests/Fake/FakeSubscriptionStripeGateway` en test —, `SubscriptionOrderFactory`,
`SubscriptionMailer`), entités `SubscriptionPlan` et `Subscription`, DTO `SubscriptionPlanOutputDto`,
`SubscriptionOutputDto`, EasyAdmin « Formules d'abonnement » et « Abonnements » (actions, voir plus bas). Schéma : tables
`subscription_plan`, `subscription`, colonnes `order.subscription_id`, `order.stripe_invoice_id`,
`user.stripe_customer_id` (migration `Version20261009130000`, script `scripts/migrate_all_v2_subscriptions.sh`) ;
`subscription.shipping_amount` et `order_items.customization_id` (migration `Version20261009150000`, script
`scripts/migrate_all_v2_subscription_shipping_customization.sh`) ; `subscription_plan.fulfillment_type` / `fulfillment_ref` et
`subscription.fulfillment_target` (console de pilotage, lot 3 bis du 04/11/2026 : migration `Version20261114100000`, script
`scripts/migrate_all_v2_subscription_fulfillment.sh`).

**Branchement d'une formule sur un service (lot 3 bis, 04/11/2026, `docs/console-pilotage.md`).** La boutique reste générique : une
formule porte `fulfillmentType` (champs réservés au super administrateur dans EasyAdmin ; seul le site désigné `shop_tenant` de la
plateforme produit des droits, 05/11/2026) (`none` par défaut ; `platform_offer` : la formule vend une offre de la plateforme dont `fulfillmentRef`
est le code ; `webhook` : réservé) ; `GET /api/subscription-plans` rend `fulfillmentType`. Pour une formule branchée, `POST
/api/subscriptions` exige depuis le lot PROVISION-1 (18/11/2026, `docs/console-pilotage.md`) **`fulfillmentLink`**, le lien signé de
« Mettre à jour mon forfait » : 422 `FULFILLMENT_LINK_REQUIRED` | `FULFILLMENT_LINK_INVALID` | `FULFILLMENT_LINK_EXPIRED` |
`FULFILLMENT_LINK_USED` | `FULFILLMENT_LINK_NOT_ADMIN` ; `fulfillmentTarget` (code du site à activer, existant) n'est plus accepté que d'un
super administrateur : 422 `FULFILLMENT_TARGET_REQUIRED` | `FULFILLMENT_TARGET_UNKNOWN`. Une formule branchée n'a jamais de jours d'essai
Stripe (`trialDays` rendu à 0). Le cycle de vie de tout abonnement émet `Event\SubscriptionStatusChangedEvent` après le flush
(`EventSubscriber\SubscriptionLifecycleSubscriber`, écouteur Doctrine : aucun des points d'écriture du statut n'est modifié) ; seul
`EventSubscriber\PlatformOfferFulfillmentSubscriber` traite `platform_offer` et accorde, prolonge, met en grâce, suspend, rétablit ou
retire les droits d'usage du site cible (`Services/Platform/OfferFulfillmentService`). Une formule `none` n'est pas concernée. Test :
`tests/Functional/Platform/OfferFulfillmentTest.php`.

| Route | Accès | Contenu |
|---|---|---|
| `GET /api/subscription-plans?productId=&locale=` | public | formules actives (sans `productId` : toutes celles de la boutique, produits encore vendus par abonnement ; grille de formules `PlanGrid`) `{ id, productId, productName, name, interval (week\|month\|year), intervalCount, price (cents), currency, trialDays, minimumTerms, active, description (sous-titre), features (liste d'avantages, 20 au plus), highlighted (formule recommandée, une par produit), badge (texte du badge ou null) }`, textes en clair dans la langue demandée |
| `POST /api/subscriptions { planId, quantity?, addressId?, carrierId? }` | client connecté | 201 `{ subscriptionId, clientSecret, intentType, status, subscription }` ; abonnement Stripe créé en `default_incomplete`, première facture payée par le `clientSecret` (Payment Element) ; `trialDays` > 0 → `trialing`, rien à payer : la carte s'enregistre par le `clientSecret` d'un SetupIntent. `intentType` (25/10/2026) dit lequel : `payment` → `stripe.confirmPayment`, `setup` → `stripe.confirmSetup` (sans cette confirmation, aucune carte n'est gardée et la fin de l'essai part en impayé). Un essai sans carte à la même formule et quantité est **repris** (200, `reused: true`, même `clientSecret` du SetupIntent, `intentType: setup`) au lieu d'un 409, depuis n'importe quel appareil ; un essai avec carte, ou à une autre formule ou quantité, reste refusé (409 `already_subscribed`) ; 403 si `commerce.subscriptionsEnabled` est `false` ; 404 formule ; 422 produit sans abonnement, quantité, adresse d'un autre client |
| `GET /api/subscriptions`, `GET …/{id}` | client connecté | `{ id, plan, quantity, status, currentPeriodEnd, cancelAtPeriodEnd, address, carrier: { id, name, kind, isPickup } (kind et isPickup : B20, 08/11/2026), shippingAmount, createdAt, canceledAt, orders: [{ id, reference, date, total, status }], latestInvoice, hasPaymentMethod }` ; `hasPaymentMethod` (25/10/2026) : carte enregistrée chez Stripe comme moyen par défaut de l'abonnement, recopiée à chaque lecture ou webhook de l'abonnement (`subscription.has_payment_method`, migration `Version20261025110000`, script `scripts/migrate_all_v2_subscription_payment_method.sh`) ; `null` : inconnu (abonnement antérieur, pas encore relu) ; autre client : 404 |
| `POST …/{id}/cancel { atPeriodEnd?: true }` | client | fin de période par défaut (`cancelAtPeriodEnd`), ou immédiate (`canceled`, courriel) |
| `POST …/{id}/resume`, `…/{id}/pause` | client | reprise (annulation programmée levée, collecte reprise) ; pause (`pause_collection: void`, courriel) ; 409 si résilié ou pas actif |
| `POST …/{id}/change-plan { planId }` | client | formule du même produit, prorata Stripe |
| `POST /api/subscriptions/portal-session { returnUrl? }` | client | `{ url }` du portail client Stripe (carte, factures ; sans annulation depuis ABO-1 : configuration de la plateforme) |

- Statuts : `incomplete`, `trialing`, `active`, `past_due`, `paused`, `canceled` (recopiés de Stripe :
  `SubscriptionService::applyStripeState`).
- Taxes : fournisseur `stripe` → `automatic_tax` de Stripe Billing ; `table` → taux de taxe Stripe créés d'après la table
  du site pour l'adresse de l'abonnement (`default_tax_rates`) ; sans adresse, pas de taxe.
- Livraison de chaque échéance : `carrierId` doit être un transporteur **à prix fixe ou gratuit** (un transporteur EasyPost,
  au tarif variable, est refusé en 422). Son prix, figé à la souscription (`subscription.shipping_amount`, cents, exposé
  `shippingAmount`), devient une seconde ligne récurrente de l'abonnement Stripe (prix « Livraison — <transporteur> »,
  code fiscal livraison, même rythme que la formule) ; la commande de l'échéance porte `shipping_cost` = ce montant et
  le prix unitaire des articles = (sous-total − livraison) / quantité. Le changement de formule ne touche que la ligne
  de la formule (`metadata.role = plan`).
- Webhooks (`POST /api/stripe/webhook`, `HandleSubscriptionWebhookUseCase::TYPES`) : le site vient des métadonnées
  `tenant_code` posées à la souscription ; **signature exigée** (hors tests). `invoice.paid` → commande de l'échéance
  (`SubscriptionOrderFactory` : aucune commande pour une facture à 0, ouverture d'un essai ou changement de
  formule sans montant dû ; première variante du produit, quantité, montants de la facture en cents, paiement
  carte, stock décrémenté s'il suffit ; rejouable par `stripe_invoice_id`) + courriel de confirmation, statut `active` ;
  `invoice.payment_failed` → voir « Paiement refusé » ; `customer.subscription.*` → état recopié, courriel de
  résiliation (`paused`/`resumed` arrivent aussi par `updated` : seuls `invoice.paid`, `invoice.payment_failed`,
  `customer.subscription.updated` et `deleted` sont déclarés sur l'écouteur Connect, vérifié le 13/10/2026).
  Depuis le lot PROVISION-3 (18/11/2026, `docs/console-pilotage.md`, « Paiements des offres ») : journal des événements sans contenu
  (un événement déjà traité répond `duplicate`), événement plus ancien que le dernier appliqué ignoré (`out_of_order`), fin de période
  jamais reculée, abonnement résilié jamais ressuscité par une facture, « période payée » émise une fois par facture.
- **Paiement refusé** (13/10/2026, `HandleSubscriptionWebhookUseCase::paymentFailed`) :
  - première facture (`incomplete`, `billing_reason` `subscription_create`) : l'abonnement reste `incomplete`, le client
    réessaie (`POST /api/subscriptions` reprend le même abonnement et son `clientSecret`) ; Stripe l'expire au bout de
    23 h (`incomplete_expired` → `canceled`) ;
  - échéance : `past_due`, impayé gardé dans `subscription.payment_issue` (montant dû, devise, `hosted_invoice_url`,
    prochain essai, numéro de l'essai), renvoyé par `GET /api/subscriptions` en `latestInvoice` tant que le statut est
    `past_due` (null sinon), courriel avec le montant, l'essai « n sur 4 », la date du prochain essai et un bouton
    « Payer maintenant » (page de facture Stripe) ; effacé par `invoice.paid` ;
  - carte par défaut (25/10/2026) : à chaque `invoice.paid` avec un montant, si l'abonnement n'a pas de carte par défaut
    chez Stripe, celle qui vient de payer le devient (`SubscriptionStripeGateway::ensureDefaultPaymentMethod`, paiement lu
    dans `invoice.payments`) ; une facture payée depuis le courriel d'impayé rend ainsi les échéances suivantes
    automatiques. Une erreur Stripe à ce moment est journalisée et ne bloque pas le webhook. Cas réel : abonnement n° 8 de
    `demo`, essai gratuit dont le SetupIntent n'avait jamais été confirmé, échéance du 25/10/2026 refusée faute de carte ;
  - au 4e essai refusé (`SubscriptionService::MAX_FAILED_PAYMENTS`) ou quand Stripe ne prévoit plus d'essai
    (`next_payment_attempt` null) : résiliation immédiate par le site (appel à Stripe) et courriel « résilié faute de
    paiement ». La règle ne dépend donc pas des réglages de relance du compte Stripe. Migration
    `Version20261013100000`, script `scripts/migrate_all_v2_subscription_payment_issue.sh`.
  À déclarer dans Stripe : point de terminaison Connect (évènements des comptes connectés) sur
  `https://backend-strapi.online/api/stripe/webhook` (même serveur que `v2.` : les deux écouteurs du tableau de bord,
  « Votre compte » et « Comptes connectés », pointent déjà dessus), secret dans `STRIPE_CONNECT_WEBHOOK_SECRET`.
- Prérequis par site : les comptes connectés sont des comptes **Express**, sans réglage Billing, portail ni Tax dans
  leur tableau de bord : c'est la plateforme qui les configure par l'API (`StripeConnectSetupService`) : la
  configuration du portail client est créée à la première ouverture du portail, et
  `php bin/console app:boutique:stripe-setup --tenant=<code> [--profile] [--tax --registrations=CA,CA-QC]` la crée
  d'avance et pose Stripe Tax (siège = adresse de la fiche entreprise, ou `--line1 --city --province --postal-code
  --country`). `--profile` pose le profil public (nom de la fiche entreprise sur la page de paiement, le portail et les
  reçus ; adresse du site, `--site-url` pour un domaine propre ; libellé de relevé bancaire en capitales sans accent).
  Le nom affiché du tableau de bord Express n'est pas réglable par la plateforme.
  Billing est disponible d'office sur un compte Express ; le produit et le prix Stripe d'une formule sont créés à la
  première souscription (`stripe_product_id`, `stripe_price_id`).
- Forme des objets Stripe (API 2025-03+, vérifiée en réel sur `demo` le 09/10/2026) : le secret de la première facture
  est dans `latest_invoice.confirmation_secret` (plus `payment_intent`) ; la facture d'un webhook n'a plus `subscription`
  ni `tax` (lire `parent.subscription_details.subscription`, taxes = `total − subtotal`, intention dans `payments`) ;
  l'objet signé du SDK se convertit par `toArray()` (le transtypage `(array)` ne donne que ses propriétés internes).
- Démo : trois formules sur « Panier bio de la semaine » (`BoutiqueDemoCatalog::SUBSCRIPTION_PLANS`).
- Grille de formules (09/10/2026) : colonnes `subscription_plan.features`, `descriptions`, `badges` (JSON par langue) et
  `highlighted` (migration `Version20261009210000`, script `scripts/migrate_all_v2_subscription_plan_grid.sh`), saisies
  dans EasyAdmin « Formules d'abonnement » ; texte en clair (balises retirées), sous-titre 160 caractères, badge 40,
  20 avantages de 120 caractères au plus ; cocher « Recommandée » décoche les autres formules du produit. Démo :
  « Panier aux deux semaines » recommandée (`BoutiqueDemoCatalog::SUBSCRIPTION_PLAN_EXTRAS`).
- **Actions de l'administration** (13/10/2026, EasyAdmin « Abonnements », fiche d'un abonnement ; `AdminSubscriptionUseCase`,
  liens protégés par jeton CSRF) : résilier en fin de période, résilier maintenant (sans remboursement), reprendre,
  mettre en pause, changer de formule (formules actives du même produit, prorata de Stripe), relire l'état chez Stripe
  (webhook manqué, action faite dans le tableau de bord Stripe), rembourser en entier le dernier paiement encaissé
  (`refundLatestPayment` : dernière facture payée, `invoice.payments` de l'API 2025-03+ ; l'abonnement ne change pas ;
  déjà remboursé : 409). Le client reçoit les mêmes courriels que s'il avait agi ; chaque action est tracée dans le
  journal applicatif (`[Subscription] Action de l'administration`, avec l'administrateur). Ni création, ni modification
  directe, ni suppression : l'état resterait différent de celui de Stripe. Remboursement vérifié sur `demo` (Stripe en
  mode test, 13/10/2026).
- Tests : `tests/Functional/Boutique/SubscriptionApiTest.php` (Stripe simulé : souscription, gestion, webhooks, refus). ; actions EasyAdmin : `testAdministratorManagesASubscriptionFromEasyAdmin`.

### Prestations des formules et suivi des abonnements (lot ABO-0, 18/11/2026, `docs/plan-abo-universel.md`)

Socle de l'abonnement universel : une formule livre des **prestations** ; aucun effet sur la facturation ni sur les droits à ce lot.

- **Tables de site** (migration `Version20270201400000`, script `scripts/migrate_all_v2_abo_benefits.sh`) :
  - `benefit` : la prestation du site, type `session` (séance planifiée), `service` (service à rendre) ou `quota` (carte ou forfait
    d'usages) ; `module` est réservé jusqu'à ABO-5 (BENEFIT_KIND_RESERVED). Noms et descriptions par langue, durée, lieu, intervenant par
    défaut (jamais montré aux abonnés ni au modèle), active, ordre ;
  - `plan_benefit` : ce qu'une formule livre, une ligne par prestation : quantité par période (1 à 999, vide = sans limite, séances
    seulement), dates (`none`, `client_at_subscription`, `admin_after`, `fixed_slots` avec `slot_choice` client ou admin, séances
    seulement), délai d'annulation (24 h par défaut), report au plus (vide = la quantité), preuve (`none`, `note`, `photo`).
- **Service** `App\Abo\Services\BenefitService` : validation et codes stables (BENEFIT_NAME_REQUIRED, BENEFIT_KIND_INVALID,
  BENEFIT_KIND_RESERVED, PLAN_BENEFIT_QUANTITY_INVALID, PLAN_BENEFIT_SCHEDULING_INVALID, PLAN_BENEFIT_RULES_INVALID,
  PLAN_BENEFIT_DUPLICATE ; 409 BENEFIT_IN_USE : une prestation livrée ne se supprime pas, elle se désactive) et phrase « ce que verra
  l'abonné » calculée par le serveur, en français et en anglais (« 8 séances Yoga par semaine ; vous choisissez vos créneaux ;
  annulation sans frais jusqu'à 24 h avant ; jusqu'à 8 reportées à la période suivante. »). Rythme accordé par une seule règle
  (`App\Abo\Services\AboWording`, 20/11/2026) : « par semaine / mois / an » pour une période, « toutes les 2 semaines », « tous les
  3 mois », « tous les 2 ans » pour plusieurs ; « per week », « every 2 weeks » ; une carte à une seule période : « valable 2 semaines ».
- **EasyAdmin** (section « Boutique : accueil et pages ») : « Prestations », « Prestations des formules » (filtres formule et prestation,
  colonne « Ce que verra l'abonné »), et sur la fiche d'une formule, « Prestations livrées ».
- **Grille publique** : `GET /api/subscription-plans` rend `benefits[]` pour chaque formule (`[]` sans prestation) : `{ id, benefitId,
  kind, name, description, durationMinutes, location, quantity, scheduling, slotChoice, cancelDeadlineHours, carryOverMax, proof,
  summary }`, dans la langue demandée ; jamais l'intervenant ni un coût. Cache : `Benefit` et `PlanBenefit` étiquetés avec les formules.
- **Dates des fiches d'abonnement** (lot ABO-DATES, 20/11/2026) : toutes à l'heure du magasin, avec le décalage
  (`2026-12-20T13:00:00-05:00`), dans la fiche de gestion (`/api/site/abonnements/...`) comme dans celle de l'abonné
  (`/api/subscriptions`, `…/{id}`) : `currentPeriodEnd`, `createdAt`, `canceledAt`, `pausedAt`, `orders[].date`, le prochain essai de
  paiement (`paymentIssue` / `latestInvoice.nextPaymentAttempt`), les pauses (`from`, `until`, `resumedAt`) ; une seule règle,
  `StoreClock::atom()`. Avant ce lot, la fiche de l'abonné les rendait en UTC (`+00:00`).
- **Suivi** pour la gestion web ET l'application de gestion mobile, mêmes routes (`ROLE_ADMIN`, module Boutique, lecture seule,
  `?locale=en` possible, dates ATOM à l'heure du magasin) ; écran du registre `abonnements` (famille vente) :
  - `GET /api/site/abonnements/formules` → `{ plans: [{ id, name, price, currency, interval, intervalCount, active, fulfillmentType,
    benefits[], counts: { incomplete, trialing, active, past_due, paused, canceled }, toFollow }] }` (`toFollow` = impayés + en pause) ;
  - `GET /api/site/abonnements/formules/{id}/abonnes?status=&page=&limit=` (25 par défaut, 100 au plus) → `{ plan, subscriptions:
    [{ subscriptionId, status, quantity, currentPeriodEnd, cancelAtPeriodEnd, pausedAt, createdAt, paymentIssue: { amountDue, currency,
    attemptCount, maxAttempts, nextPaymentAttempt } | null, customer: { id, firstname, lastname, email } }], total, page, limit }` ;
    jamais le lien de paiement ; 404 PLAN_NOT_FOUND, 422 STATUS_INVALID ;
  - `GET /api/site/abonnements/{id}` → la ligne ci-dessus + `plan`, `benefits[]`, `canceledAt`, `fulfillmentTarget`, `orders` (commandes
    d'échéance : id, référence, date, total en centimes), et `todo`, `history`, `balance` (remplis depuis ABO-1, ci-dessous) ; 404
    SUBSCRIPTION_NOT_FOUND.
- **Assistant du site (IA-A)**, outils donnés seulement avec le module Boutique : lectures `get_subscription_plans` (avec les produits
  porteurs possibles), `get_benefits`, `get_plan_subscribers`, `get_subscription`, **sans aucune identité** (un abonné = son numéro,
  l'écran lié montre l'identité) ; propositions confirmées par l'administrateur `propose_benefit`, `propose_plan` (sur un produit porteur
  EXISTANT dont l'abonnement est activé, sinon PRODUCT_NOT_SUBSCRIBABLE et renvoi à l'écran Produits), `set_plan_benefits`, appliquées par
  le service de l'écran (mêmes refus) et tracées au journal du site. Textes lus = données (essai d'injection).
- Essais : `tests/Functional/Abo/AboBenefitsTest.php`.

### Droits par période, solde, report et pause (lot ABO-1, 19/11/2026, `docs/plan-abo-universel.md` § 3, § 4, § 7)

Les prestations à quantité deviennent des **droits** que l'abonné consomme ; la facturation ne change pas.

- **Tables de site** (migration `Version20270201500000`, script `scripts/migrate_all_v2_abo_rights.sh`) :
  - `benefit_occurrence` : un droit par ligne (abonnement, ligne de formule et prestation, facture d'origine, rang, `origin` `period` ou
    `gift`, `status` `available` / `done` / `expired`, début et fin de période, `valid_until` UTC, `carried`, `trial`, qui et quand pour
    un droit utilisé, note, motif d'un droit offert) ; unicité (abonnement, ligne de formule, facture, rang) ;
  - `subscription_abo_event` : le journal du suivi (`born`, `used`, `use_undone`, `gift`, `carried`, `expired`, `pause`, `resume`,
    `cancel`, `trial_without_rights`) ;
  - `subscription_plan.single_period` (carte de séances), `max_pause_days` (90), `pauses_per_year` (1) ; `subscription.pauses` (JSON) ;
    `subscription.cancel_at` (résiliation programmée à une date : fin d'engagement).
- **Naissance** (`AboRightsService::birth`, à l'écoute de « période payée » de PROVISION-3, une fois par facture) : quantité de la formule
  × quantité de l'abonnement, valables jusqu'à la fin de la période. Une prestation sans limite ne crée aucune ligne (`unlimited`).
  La période est celle de la **ligne d'abonnement hors prorata** de la facture (jamais une ligne de prorata au passé ; une fin déjà
  passée est remplacée par la fin de période de l'abonnement). Changement de formule (`subscription_update`) : un **prorata pur**
  (aucune ligne hors prorata) ne donne aucun droit et n'est pas une période payée ; un changement qui **ouvre une période** (passage du
  mois à l'année : cycle remis à zéro, période payée) donne ses droits. **Essai gratuit** : ses droits une fois par client et par
  formule, jamais reportés ; un second essai gratuit ne donne rien (événement `trial_without_rights`). Rejouer une facture ne crée rien
  (unicité).
- **Passage horaire** `app:abo:periodes` (`--site` possible ; minuteur `ops/systemd/abo-periodes.timer`, chaque heure à :25 UTC) : réémet les
  « périodes payées » restées sans suite depuis 10 minutes, puis, pour chaque droit arrivé à `valid_until`, le reporte UNE fois d'une
  période (dans la limite du report de la ligne PAR PLACE, × quantité de l'abonnement ; si l'abonnement continue, ni en fin programmée,
  ni carte ; jamais un droit d'essai) ou le perd. Sous le verrou de l'abonnement. Simplification : une période réémise relit la formule
  et la quantité du moment.
- **Prestation retirée de la formule** : ses droits déjà payés restent au solde et utilisables jusqu'à leur échéance, puis sont perdus
  (sans report). Une prestation qui porte des droits ne se supprime pas (409 `BENEFIT_IN_USE`) : elle se désactive.
- **Suivi** (`GET /api/site/abonnements/{id}`, gestion web et MAUI) : `balance[]` par prestation `{ benefitId, name, kind, available,
  used, carried, unlimited, nextExpiry }`, `todo[]` (droits qui expirent sous 7 jours), `history[]` (50 derniers événements, `by` et
  `note` pour l'équipe ; une ligne `used` porte aussi `occurrenceId` et `undoUntil`, pour « Annuler l'utilisation » après un
  rechargement, `null` pour une ligne écrite avant l'ajout du 19/11/2026 : migration `Version20270201700000`), `pauses`.
- **Gestes** (`ROLE_ADMIN`, module Boutique, sous le verrou de l'abonnement ; la fiche est relue une fois le verrou pris, et chaque contrôle
  refait : deux gestes simultanés ne prolongent ni ne comptent jamais deux fois ; un abonnement tenu trop longtemps par un autre traitement
  rend 409 `SUBSCRIPTION_BUSY`, à refaire) :
  - `POST /api/site/abonnements/{id}/utilisations { benefitId, note? }` (201) : marque utilisé le droit qui expire le premier ; deux gestes
    simultanés ne prennent jamais le même droit. 409 `BALANCE_EMPTY`, 422 `BENEFIT_NOT_IN_PLAN`. Annulable 24 h :
    `DELETE …/utilisations/{occurrenceId}` (404 `OCCURRENCE_NOT_FOUND`, 409 `USE_UNDO_EXPIRED` ; 409 `OCCURRENCE_SCHEDULED` pour une date
    marquée « fait » par ABO-3a, qui se corrige par `…/correction`) ;
  - `POST /api/site/abonnements/{id}/droits { benefitId, quantity (1 à 10), reason }` (201) : droits offerts jusqu'à la fin de la
    période en cours ; motif obligatoire (`GIFT_INVALID`, `REASON_REQUIRED`) ; écrit au journal du site ;
  - `POST /api/site/abonnements/{id}/pause { until }` (date du magasin) : 90 jours au plus et une pause par an glissant, réglables par
    formule (422 `PAUSE_DATE_INVALID`, `PAUSE_TOO_LONG`, `PAUSE_LIMIT_REACHED` ; 409 `SUBSCRIPTION_NOT_ACTIVE`) ; Stripe
    `pause_collection` avec `resumes_at` ; les droits disponibles sont prolongés de la durée de la pause (aussi depuis EasyAdmin, qui
    applique les mêmes limites) ;
  - `POST /api/site/abonnements/{id}/reprise` : reprend une PAUSE seulement (409 `SUBSCRIPTION_NOT_PAUSED`) ; une résiliation
    programmée (carte de séances, motif de l'équipe) reste programmée ; reprise avant la date prévue : la prolongation non consommée est
    retirée aux droits ;
  - `POST /api/site/abonnements/{id}/resiliation { atPeriodEnd: true, reason? }` : en fin de période seulement
    (`CANCEL_AT_PERIOD_END_ONLY`) ; pendant l'engagement minimal, l'équipe résilie quand même, motif obligatoire (422 `REASON_REQUIRED`),
    écrit au journal du site.
- **Côté client** : `GET /api/subscriptions` et `/{id}` rendent `balance[]` (nombres et échéances seulement, aucun motif ni note) et
  `cancellation: { allowed, cancellableFrom, scheduledAt }` ; `POST /api/subscriptions/{id}/pause` accepte `until` (sans `until` : la
  durée maximale de la formule), mêmes limites ; `…/resume` garde son sens (lève aussi une résiliation programmée), sauf pour une carte
  de séances.
- **Résiliation simple** (décision du chef du 19/11/2026 ; règles de résiliation en ligne facile : France, « click to cancel » de
  certains États américains) : « Résilier » visible dans la fiche de l'abonnement, en deux gestes (bouton, puis confirmation qui affiche
  la date). `POST /api/subscriptions/{id}/cancel` : sans engagement en cours, comme avant ; PENDANT l'engagement minimal, la résiliation
  est PROGRAMMÉE à `cancellableFrom` (Stripe `cancel_at`, sans prorata), réponse 200 avec `cancellation.scheduledAt`, jamais refusée ;
  courriel de confirmation avec la date (`cancel_at_term_end`). L'engagement compte les périodes PAYÉES (une facture à 0, l'essai, ne
  compte pas ; simplification : seules les périodes notées depuis PROVISION-3). Le code `SUBSCRIPTION_MINIMUM_TERM` ne sert plus qu'à
  l'équipe et à l'assistant.
- **Portail Stripe du client** : la plateforme utilise SA configuration du portail sur le compte connecté (métadonnée `izzi_platform=1`,
  créée ou mise à jour, passée à la session), où l'annulation est coupée : le client résilie par nos écrans, qui appliquent
  l'engagement. Une configuration créée par le commerçant n'est jamais lue ni modifiée.
- **Carte de séances** (`single_period`) : la fin est posée à la création de l'abonnement Stripe (`cancel_at_period_end`), payée une
  fois, droits jamais reportés.
- **Simplification** : pause et reprise appellent Stripe avant l'écriture locale ; une écriture locale en échec après un Stripe réussi
  laisse l'abonnement en pause chez Stripe sans prolongation (rare, visible au journal).
- **Assistant du site (IA-A)**, sans aucune identité : lectures `get_subscription` (solde, à faire, historique sans « par qui ») et
  `get_expiring_rights` ; propositions confirmées `propose_use`, `propose_gift`, `propose_pause`, `propose_resume`,
  `propose_cancel_subscription` (fin de période seulement, en rouge), appliquées par le même service. Absents : résilier tout de suite,
  rembourser, retirer des droits payés. Une note ou un motif porteur d'une consigne n'entraîne rien (essai d'injection).
- Essais : `tests/Functional/Abo/AboRightsTest.php`.

### Créer et configurer les formules dans la gestion web (lot ABO-CONF, 19/11/2026, décision du propriétaire)

La création et la configuration des formules du commerçant se font AUSSI dans la gestion web ; les mobiles (MAUI, commerce mobile) voient,
ajustent et répondent, mais ne créent rien. Les formules de la plateforme et des agences (formule « branchée », `fulfillmentType` ≠
`none`) restent dans EasyAdmin seulement.

- **Service commun** `App\Abo\Services\AboCatalogService` (formules) avec `BenefitService` (prestations, lien formule ↔ prestations) :
  EasyAdmin (écrans « Formules d'abonnement » et « Prestations »), les routes et l'assistant passent par eux ; mêmes contrôles et codes :
  PLAN_NAME_REQUIRED, PLAN_PRICE_INVALID, PLAN_INTERVAL_INVALID, PLAN_TRIAL_INVALID, PLAN_TERMS_INVALID, PLAN_PAUSE_INVALID,
  PRODUCT_NOT_SUBSCRIBABLE, PLAN_PLATFORM_ONLY (403), FIELD_UNKNOWN, MUST_BE_BOOLEAN, PLAN_HAS_SUBSCRIBERS (409), BENEFIT_IN_USE (409),
  BENEFIT_NOT_FOUND. Devise : celle du site, imposée (jamais un champ de l'écran).
- **Routes** (`ROLE_ADMIN`, module Boutique ; écran du registre `abonnements.formules`, famille construction) :
  - `POST /api/site/abonnements/formules/apercu[?planId=]` : AUCUNE écriture ; `{ plan, summary, priceChange, warnings }` : la formule telle
    qu'elle sera et la phrase « ce que verra l'abonné » (prix et rythme, essai, engagement, carte, prestations), fr / en ;
  - `POST /api/site/abonnements/formules` (201), `GET` et `PATCH …/formules/{id}` : la fiche `{ plan, benefits, priceHistory, summary,
    hasSubscribers, warnings }` ; champs : `productId`, `names`, `descriptions`, `features`, `badges`, `price` (cents), `interval`,
    `intervalCount`, `trialDays`, `minimumTerms`, `maxPauseDays`, `pausesPerYear`, `singlePeriod`, `highlighted`, `active` ;
  - `POST …/formules/{id}/archiver` et `…/reactiver` ; `DELETE …/formules/{id}` (204 ; 409 PLAN_HAS_SUBSCRIBERS dès qu'elle a eu un
    abonné : elle s'archive, n'est plus vendue, les abonnés continuent) ;
  - **réglages figés dès qu'une formule a des abonnés** (relecture neuve) : engagement, rythme (`interval`, `intervalCount`), carte de
    séances, produit porteur et devise (409 PLAN_HAS_SUBSCRIBERS, `field` = le champ ; l'aperçu le dit aussi ; la même règle dans
    EasyAdmin) : ils gouvernent les abonnements en cours (date de fin d'engagement, reprise, renouvellement) ; un autre réglage se vend par
    une formule nouvelle. Le PRIX, lui, peut changer (nouveaux abonnés seulement) ;
  - `PUT …/formules/{id}/prestations { benefits: [...] }` : la liste COMPLÈTE ; une prestation retirée garde les droits nés au solde ;
  - `GET` et `POST /api/site/abonnements/prestations`, `PATCH` et `DELETE …/prestations/{id}` (409 BENEFIT_IN_USE si une formule la
    livre ou si des droits la citent) ; désactiver une prestation dont des droits sont encore à utiliser : 409 BENEFIT_IN_USE avec
    `pendingRights` (la retirer de la formule à la place) ; la même règle dans EasyAdmin.
- **Prix et Stripe** : produit et prix Stripe naissent à la première souscription, sur le compte connecté du site (sinon celui de la
  plateforme), un produit par formule. Taux de taxe et prix de livraison des abonnements (lot CACHE-ETAT, 20/11/2026) : gardés dans
  `stripe_object_ref` de la base maître (compte + mode test/live + genre + clé de TOUS les paramètres de création), créés sous verrou
  avec une clé d'idempotence fixe, vérifiés chez Stripe avant usage et recréés (génération suivante) s'ils y ont disparu ou sont archivés
  (`StripeObjectRefStore`) ; avant, dans le cache : un cache vidé recréait chaque objet chez Stripe. Prix, devise ou rythme modifiés : la ligne en cours de l'historique `subscription_plan_price`
  (migration `Version20270201800000`, script `scripts/migrate_all_v2_abo_catalog.sh`) se clôt avec le prix Stripe de son époque, une autre
  s'ouvre, et le prix Stripe de la formule repart à zéro : les NOUVEAUX abonnés paient le nouveau prix (sur le même produit Stripe), les
  abonnés en cours gardent le leur. Défaut corrigé : avant, un prix modifié dans EasyAdmin gardait l'ancien prix Stripe, même pour les
  nouveaux abonnés. La migration des abonnés en cours vers un nouveau prix (préavis) est un lot à part, après l'avis de l'avocat (ABO-PRIX).
  **Prix payé** : un abonné en cours garde le prix de son époque ; la fiche du client (`GET /api/subscriptions/{id}`) et celle de l'équipe
  (`GET /api/site/abonnements/{id}`) portent `billedPrice { price, currency, interval, intervalCount }` (époque de l'historique où il a
  souscrit) ; `plan.price` est le prix du jour, à ne pas montrer à l'abonné comme le sien. Simplification : un prix changé pendant qu'une
  souscription crée son prix Stripe peut laisser l'ancien prix aux nouveaux abonnés jusqu'au changement suivant (rare).
- **Droits** : écritures `ROLE_ADMIN` du site ; le directeur d'un groupe entré dans une boutique : aucune (raison `payment`, l'aperçu
  reste ouvert) et, dans EasyAdmin, les formules, prestations et prestations des formules en lecture seule ; l'agence entrée chez son
  client crée et configure, mais ne supprime jamais une formule (ni par l'API ni par EasyAdmin) ; le personnel : lecture, avec le rôle de
  l'intervenant d'ABO-3.
- **Assistant du site** : `propose_plan` passe par le service commun ; `propose_plan_update` (prix, rythme, noms, archivage) dont
  l'aperçu dit « nouveau prix pour les nouveaux abonnés seulement » ; jamais de prix sans confirmation.
- **Avertissement** à la création (gestion web et EasyAdmin) : un site extérieur sans compte Stripe connecté actif ne pourra pas vendre
  en réel (`warnings: [{ code: "STRIPE_ACCOUNT_REQUIRED" }]`).
- Essais : `tests/Functional/Abo/AboCatalogTest.php`.

#### Produits porteurs (lot ABO-CONF-b, 20/11/2026, contrat validé par le chef)

- **Service commun** `App\Abo\Services\AboCarrierService` (gestion web, case « Abonnement » d'EasyAdmin, assistant) : mêmes règles partout.
- `GET /api/site/abonnements/produits?porteurs=oui|non|tous&q=&page=&pageSize=` (ROLE_ADMIN, module Boutique, écran
  `abonnements.formules`) → `{ items: [{ id, name, slug, imageUrl, subscriptionEnabled, subscriptionOnly, plans: { active, archived,
  withSubscribers }, canEnable, canDisable, reason }], total, page, pageSize }` (porteurs d'abord, puis par nom ; pageSize ≤ 100).
- `PATCH /api/site/abonnements/produits/{id} { subscriptionEnabled: bool }` : idempotent, journalisé (`product_subscription`) ; activer :
  422 `PRODUCT_NOT_ELIGIBLE` (véhicule, porteur d'une formule branchée) ; désactiver : 409 `PRODUCT_HAS_SUBSCRIBERS` dès qu'une formule de ce
  produit a eu un abonné, sinon permis avec `warnings: [{ code: "PLANS_HIDDEN", count, plans: [noms] }]` (ses formules sortent de la vente) ;
  422 `MUST_BE_BOOLEAN`, 404 `PRODUCT_NOT_FOUND`. Directeur entré : refusé (raison `payment`) ; agence entrée : permis, mêmes règles.
- **Porteur créé avec sa formule** : `POST …/formules` (et l'aperçu) accepte `newProduct: { names: { fr, en? } }` À LA PLACE de `productId`
  (ni l'un ni l'autre, ou les deux : 422 `PRODUCT_CHOICE_REQUIRED` ; à la modification : 422 `FIELD_UNKNOWN`). Créé dans la même transaction
  (formule refusée = aucun produit ni catégorie) : prix 0, vente directe coupée, abonnement activé, sans expédition, une variante à prix 0 et
  stock 0 (la commande née d'une période payée la demande), catégorie « Abonnements » (liée et créée au besoin dans la transaction seulement,
  jamais à l'aperçu) ; VISIBLE dans la boutique
  (décision du propriétaire, `AboCarrierService::CARRIER_VISIBLE`) : sa fiche mène à la grille des formules. L'aperçu dit « Un produit
  porteur sera créé : <nom>. » et rend `newProduct: { name }`.
- **Jamais achetable seul à 0** (`Product::isSubscriptionOnly`, `isSellableAlone`) : un porteur à vente coupée, ou sans AUCUN prix positif
  (ni le produit, promotion en cours comprise, ni une de ses variantes), est refusé par le devis du panier, de la commande et de la caisse
  (422 `QUOTE_INVALID`, ligne `SUBSCRIPTION_ONLY`), écarté de la caisse hors ligne et des favoris ; sa fiche donne `sale.enabled` faux et
  `subscription.only` vrai (le front montre la grille, et « sans stock » n'est pas une rupture). La ligne est aussi jugée au prix de la
  variante vendue : sur un produit dont l'abonnement est activé, une ligne à 0 est refusée (`SUBSCRIPTION_ONLY`) même si le produit a un
  prix, et le paquet hors ligne écarte ses variantes à 0 ; un produit à 0 dont une variante a un prix se vend seul normalement. Caisse hors
  ligne : un paquet émis AVANT qu'un produit devienne porteur à 0 (ou avant la mise en ligne du lot) le vend encore jusqu'à son échéance
  (`packValidityHours`), la synchronisation acceptant les variantes du paquet déclaré ; c'est le fonctionnement du hors ligne, sans
  correction prévue. Aucun flux ni export du catalogue n'existe aujourd'hui.
- **Aperçu avec prestations** : `POST …/formules/apercu` accepte `benefits` (même forme que le PUT des prestations) et la phrase les cite ;
  une prestation inconnue est un avertissement `BENEFIT_NOT_FOUND`.
- **EasyAdmin** : la case « Abonnement » des fiches produit ET véhicule suit les mêmes règles (`SubscriptionToggleGuardTrait` : un porteur à
  abonnés n'est plus décoché en silence, un véhicule ne s'active pas, même à la création) ; le geste accepté est journalisé
  (`product_subscription`) comme par l'API ; un directeur de groupe entré ne change pas la case (comme la route, raison `payment`).
- **Assistant** : `list_subscription_products`, `propose_subscription_product` (l'aperçu donne le nombre de formules qui sortent de la
  vente et nomme les trois premières ; la confirmation est refusée si la liste a changé depuis la proposition : `PLANS_CHANGED`),
  `propose_plan` avec `newProduct` ; toujours confirmé.
- Essais : `tests/Functional/Abo/AboCarrierTest.php`.

### Avant le passage en réel des abonnements (liste tenue avec le chef, 19/11/2026)

- **Compte Stripe** : une vente de la boutique d'un site extérieur sans compte connecté actif est REFUSÉE (seul un site interne de la
  plateforme encaisse sur le compte de la plateforme) ; jusqu'à ABO-CONF, un abonnement passait alors sur le compte de la plateforme.
  Décision du propriétaire, CODÉE (ABO-CONF, `SubscriptionService::assertCanSellLive`) : en réel (clé du serveur `sk_live`/`rk_live`), un
  site extérieur sans compte connecté actif voit la souscription refusée (409 `STRIPE_ACCOUNT_REQUIRED`, texte pour l'abonné) ; un site
  interne de la plateforme encaisse comme avant ; en test, rien ne change. Le commerçant en est prévenu à la création d'une formule.
- **Mode Stripe par site** : la boutique et les abonnements suivent le mode de la clé du serveur (test ou réel pour tous les sites à la
  fois) ; la caisse a son propre réglage test / réel PAR SITE (`Entreprise.posStripeMode`, clés réelles à part, compte réel distinct,
  passage en réel par le super administrateur, journal `STRIPE_MODE_CHANGED`). Au passage en réel, la boutique et les abonnements doivent
  prendre ce modèle par site (`StripeAccountResolver`, canal `shop`), pour que `demo` et les sites d'essai restent en test.
- **Commission de la plateforme sur les abonnements** : voir ci-dessous.

### Commission de la plateforme sur les abonnements (lot ABO-CONF C, 19/11/2026, décision du propriétaire)

- Réglage de la plateforme « Commission abonnements (%) » (console Plateforme d'EasyAdmin ; `platform_settings.subscription_fee_bp` de la
  base maître), **1 % sans réglage** ; réglage d'un site (écran Stripe du site, champ du SEUL super administrateur, jamais montré au
  commerçant ; `stripe_config.subscription_fee_bp`) qui le remplace s'il est rempli ; 0 permis ; bornes 0 à 20 %. Réglage À PART de la
  commission de la boutique (`online_fee_bp`).
- Seulement avec un compte Stripe connecté : un site interne de la plateforme ou sans compte n'a pas de commission (et reste hors du
  rattrapage).
- Nouvelles souscriptions : la commission du moment (`application_fee_percent`, notée `subscription.fee_bp_applied`). Abonnements en
  cours : rattrapés par le passage horaire `app:abo:periodes` (aucun nouveau minuteur), 50 par site et par passage (jamais essayés d'abord,
  puis les plus anciennement essayés : `subscription.fee_attempted_at`, un échec permanent ne bloque pas les autres), une clé d'idempotence
  par abonnement, commission voulue et VERSION du réglage (le dernier changement du journal : un aller-retour dans la journée est bien
  reposé) ; une erreur Stripe n'arrête pas le lot, l'abonnement est repris plus tard. L'écran
  Stripe du site montre « X abonnement(s) en attente de la nouvelle commission ».
- Chaque changement au journal `platform_fee_log` de la base maître (qui, ancien, nouveau, site ; SQL `ops/sql/subscription_fee_master.sql`,
  script `scripts/migrate_master_v2_subscription_fee.sh`), lisible du seul super administrateur (console Plateforme).
- Code : `App\Services\SubscriptionService\SubscriptionFeeService`.

### Agenda des prestations (lot ABO-2, 20/11/2026, `docs/plan-abo-universel.md` § 3, § 7 ; Q1 à Q4 validées)

Code : `src/Abo/Services/AboAgendaService.php`, routes `src/Abo/Controller/AboAgendaController.php`, essais `tests/Functional/Abo/AboAgendaTest.php`.
Tables de site (migration `Version20270201900000`, script `scripts/migrate_all_v2_abo_agenda.sh`) : `benefit_slot_rule`, `benefit_slot`,
`benefit_slot_hold` ; `benefit_occurrence` gagne `slot_id`, `scheduled_at`, `scheduled_end` (UTC), `scheduled_by`, `scheduled_source`
(`client` ou `team`) et les états `scheduled` (planifié) et `late_cancelled` (annulé trop tard).

- **Règles de créneaux** (EasyAdmin « Règles de créneaux », outil `propose_slot_rule`) : une séance ou un service, jours ISO (1 = lundi),
  heure **locale** du magasin `HH:MM`, durée (celle de la prestation par défaut), places (1 à 500), lieu, intervenant (compte du site,
  facultatif, jamais montré aux abonnés), du / au. Les créneaux datés naissent **8 semaines devant**, à l'enregistrement de la règle et à
  chaque passage horaire `app:abo:periodes` ; l'heure locale est convertie en UTC **jour par jour** (18:00 reste 18:00 après le passage à
  l'heure d'été) ; unicité règle + début : rejouer ne crée rien. Modifier une règle relit chaque créneau futur **sans** réservation
  ni place retenue **sous son verrou** : s'il tombe encore un jour et à l'heure de la règle, il est gardé (même numéro) et mis à jour (fin,
  places, lieu, intervenant), sinon retiré ; les créneaux manquants sont créés. Ceux qui ont une réservation restent tels quels (heure,
  places, lieu). La règle et ses créneaux s'enregistrent d'un seul tenant : un créneau en cours de réservation rend 409 `SLOT_BUSY` et la
  règle reste telle qu'elle était. Désactiver ou supprimer une règle ne garde que ses créneaux réservés. Créneau ponctuel : EasyAdmin
  « Créneaux », « Créer ».
- **Trois modes de dates** (`plan_benefit.scheduling`) : `admin_after` : l'équipe donne une date libre (`startsAt`), durée de la
  prestation, **sans contrôle de capacité** (Q4 ; l'intervenant sans chevauchement viendra avec ABO-3) ; `fixed_slots` : un créneau,
  réservé par l'abonné si `slot_choice` = `client`, sinon attribué par l'équipe ; `client_at_subscription` : l'abonné choisit à la
  souscription (places retenues, ci-dessous), puis réserve comme un créneau à son choix. L'équipe peut toujours donner un créneau.
- **Capacité** : sous le verrou de l'abonnement puis celui du créneau (`pg_advisory_xact_lock`, attente bornée à 3 s : au-delà 409
  `SLOT_BUSY`, à refaire), compte des droits `scheduled` et des places retenues encore valables ; au-delà 409 `SLOT_FULL`. Un abonnement ne
  prend jamais deux places du même créneau (409 `SLOT_ALREADY_BOOKED`). Créneau annulé 409 `SLOT_CANCELLED`, commencé 409 `SLOT_PAST`.
- **Quel droit** : celui demandé (`occurrenceId`, équipe) ou le droit à planifier qui expire le premier. L'abonné ne réserve qu'un
  créneau qui commence **avant la fin de validité** de son droit (409 `SLOT_AFTER_VALIDITY`) ; l'équipe n'a pas cette limite.
- **Annuler, déplacer** (délai `cancel_deadline_hours` de la prestation dans la formule, 24 h par défaut) : l'abonné avant le délai : le
  droit redevient à planifier ; après : `late_cancelled`, **compté comme utilisé** (décision 1) ; déplacer = annuler + réserver d'un seul
  tenant (un refus garde l'ancienne date), refusé après le délai (409 `CANCEL_DEADLINE_PASSED`). L'équipe annule et déplace **à tout
  moment, sans perte**, même une date déjà passée ou un droit dont la validité est passée, au journal du site (`appointment_set`, `appointment_move`, `appointment_cancel`, Q3).
- **Un droit rendu reste dû** : s'il redevient à planifier après sa fin de validité (date de l'équipe au-delà, créneau annulé), il vaut
  jusqu'à la fin de la période en cours (ou 30 jours).
- **Pause** (décision 3) : les dates planifiées entre maintenant et la reprise redeviennent à planifier, sans perte, puis sont
  prolongées comme les autres droits ; les dates d'après la reprise restent.
- **Créneau annulé par la boutique** (`POST /api/site/abonnements/creneaux/{slotId}/annulation { reason }`, motif obligatoire, ou
  EasyAdmin « Créneaux » : motif dans la fiche puis « Enregistrer ») : ses droits planifiés ou annulés trop tard redeviennent à planifier,
  ses places retenues sont rendues, chaque abonné reçoit le courriel « Votre séance est annulée » (`SubscriptionMailer`, sujet
  `subscription_notice`, **indispensable**, Q2), au journal. Un créneau ne se supprime pas.
- **Passage horaire** : un droit `scheduled` n'est jamais reporté ni perdu, même échu ; `late_cancelled` et `done` comptent dans « utilisé ».
- **Places retenues à la souscription** (Q1, décision 5) : `POST /api/subscriptions` accepte `slots: [{ benefitId, slotId }]` (prestations
  `client_at_subscription` de la formule, autant de dates au plus que de séances d'une période) ; chaque place est retenue **30 minutes**
  avant tout appel à Stripe (refus 409 `SLOT_FULL`, 422 `SLOTS_INVALID`, 403 `SCHEDULING_NOT_BY_CLIENT`), rendue aussitôt si la souscription
  échoue, rattachée à l'abonnement créé ; à la naissance des droits (facture payée), elle devient le rendez-vous. Une place échue n'est
  convertie que s'il reste de la place (sinon le droit reste à planifier, événement `hold_lost`). Les places échues depuis un jour sont
  effacées par le passage. Une place que la naissance des droits n'a pas pu convertir parce que le créneau était tenu un instant est
  reprise au passage horaire ; une prestation sans limite ne se choisit pas à la souscription (422 `SLOTS_INVALID`). Créneaux à choisir avant d'être abonné : `GET /api/subscription-plans/{id}/creneaux?benefitId=`.
- **Solde** : `balance[]` gagne `scheduled` ; `available` reste « à planifier ». La fiche (gestion `GET /api/site/abonnements/{id}` et
  abonné) gagne `upcoming[]` : les 10 prochaines dates (`occurrenceId` pour déplacer ou annuler, prestation, début, fin, lieu,
  `cancellableUntil`) ; l'équipe y voit aussi l'intervenant (`staffUserId`) et la source (`client` ou `team`), jamais l'abonné ni le modèle.
- **Décisions laissées** (relecture neuve, à trancher plus tard) : un abonnement résilié garde ses dates futures déjà planifiées (des
  droits payés restent utilisables jusqu'à la fin de la période payée, décision 4) ; l'abonné peut annuler une date que l'équipe lui a
  donnée, avec la même règle de délai ; les courriels « séance annulée » partent dans la requête, dans la langue de l'administrateur.

Routes (dates ATOM à l'heure du magasin) :

| Qui | Route | Rôle |
|---|---|---|
| équipe | `GET /api/site/abonnements/creneaux?benefitId=&from=&to=` | créneaux, places restantes (`remaining`, `booked`, `held`), 14 jours par défaut, 62 au plus |
| équipe | `GET /api/site/abonnements/a-planifier` | droits à planifier par abonnement (prénom et nom de l'abonné, mode, prochaine échéance) |
| équipe | `POST /api/site/abonnements/{id}/rendez-vous { benefitId, occurrenceId?, slotId \| startsAt }` | 201 `{ appointment, subscription }` |
| équipe | `PATCH` / `DELETE /api/site/abonnements/{id}/rendez-vous/{occurrenceId}` | déplacer, annuler sans perte |
| équipe | `POST /api/site/abonnements/creneaux/{slotId}/annulation { reason }` | 200 `{ slotId, released }` |
| abonné | `GET /api/subscriptions/{id}/creneaux?benefitId=` | créneaux ouverts à son choix, 8 semaines, sans intervenant |
| abonné | `POST /api/subscriptions/{id}/rendez-vous { benefitId, slotId }` | 201 `{ appointment, subscription }` |
| abonné | `PATCH` / `DELETE /api/subscriptions/{id}/rendez-vous/{occurrenceId}` | déplacer avant le délai ; annuler (`cancellation.status` : `available` ou `late_cancelled`) |
| futur abonné | `GET /api/subscription-plans/{id}/creneaux?benefitId=` | créneaux à choisir à la souscription |

Réserver et déplacer (abonné) exigent le module Boutique (`ModuleAccessService::METHOD_ROUTES`) ; annuler reste servi. Pour le directeur
d'un groupe entré dans une boutique, réserver, déplacer, régler les créneaux et annuler un créneau restent ouverts (ce n'est pas de
l'argent) ; l'agence, elle, n'entre pas dans les abonnements (famille vente).

Outils de l'assistant du site (module Boutique, aucune identité, jamais l'intervenant) : lectures `get_slots`, `get_to_schedule` ;
propositions confirmées par l'administrateur, appliquées par le même service : `propose_slot_rule`, `propose_assign_date` (place vérifiée
à la confirmation), `propose_move_occurrence`. Un lieu, un nom ou un motif lus sont des données : un lieu porteur d'une consigne n'entraîne
rien (essai). L'assistant ne déplace une date choisie par l'abonné qu'avant le délai d'annulation (409 `CANCEL_DEADLINE_PASSED`, à
l'aperçu et à la confirmation) ; une date donnée par l'équipe, à tout moment.

### « Fait » et suivi des droits (lot ABO-3a, 20/11/2026, `docs/plan-abo-universel.md` § 4, § 7 ; contrat validé par le chef)

Code : `src/Abo/Services/AboDeliveryService.php`, photos `src/Abo/Services/AboProofStorage.php`, routes
`src/Abo/Controller/AboDeliveryController.php`, essais `tests/Functional/Abo/AboDeliveryTest.php`, réponses complètes de chaque route
(données fictives) : `docs/abo-3a-exemples-reponses.json`. Colonnes de site (migration
`Version20270202100000`, script `scripts/migrate_all_v2_abo_fait.sh`) : sur `benefit_occurrence`, la preuve (`proof_path`, `proof_sha256`,
`proof_mime`, `proof_at`), `done_notified_at`, l'avis de l'abonné (`customer_ack`, `customer_ack_comment`, `customer_ack_at`,
`ack_ticket_ref`) et la décision (`ack_resolution`, `ack_resolved_at`, `ack_resolved_by`) ; `subscription.done_emails` (vrai par défaut).
Nouvel état `missed` (absent).

- **Fait / absent** (équipe) : une date **planifiée**, le jour même (heure du magasin) ou après (sinon 409 `NOT_YET_DUE`). « Fait » : note
  facultative (300 caractères), photo facultative ; « absent » : compté comme **utilisé** (décision 1, défaut accepté), sans courriel. Un
  droit déjà marqué de l'autre façon : 409 `OCCURRENCE_ALREADY_MARKED` (le corriger d'abord) ; un droit sans date : 409
  `OCCURRENCE_NOT_SCHEDULED` (une utilisation sans date passe par « Utiliser » d'ABO-1). **Deux fois le même geste : 200, `changed: false`**,
  rien d'écrit, aucun second courriel. Un nouveau constat repart d'un avis vierge. Un « fait » ou un « absent » **garde sa place** dans
  le créneau (les places comptent les dates planifiées, faites et absentes : aucune surréservation si le constat est corrigé).
- **Photo de preuve** : multipart, champ `photo`, JPEG, PNG ou WebP (un HEIC d'iPhone se convertit en JPEG avant l'envoi), 10 Mo au plus
  (422 `PROOF_TOO_LARGE`, `PROOF_FORMAT` ; 12 000 pixels au plus de côté et 50 millions de pixels en tout, contrôlés avant le décodage). Relue puis **réencodée** en JPEG par GD : aucune métadonnée gardée (position GPS, appareil,
  date) ; l'orientation de l'appareil est appliquée aux pixels ; 2000 pixels au plus de côté ; empreinte SHA-256 gardée. Stockage privé
  `var/storage/private_media/abo-proofs/<site>/`, jamais d'adresse publique, servie par les deux routes `…/preuve` seulement
  (`Cache-Control: private, no-store`). Visible de l'abonné (décision 9). **Effacée après 12 mois** au passage `app:abo:periodes`
  (l'empreinte et la date restent ; durée à faire relire par l'avocat, liste « avant le réel »). Jamais envoyée à l'IA.
- **Courriel « Votre prestation est faite »** (`SubscriptionMailer`, type `subscription_done`, indispensable comme tout courriel à un
  client) : **un seul par droit**, réservé par la base (`done_notified_at`), même après une correction puis un nouveau « fait » ; une
  séance entière envoie **un courriel par abonné**, à lui seul (jamais groupé). L'abonné le **coupe** depuis son compte
  (`PATCH /api/subscriptions/{id}/courriels { doneEmails: false }`) : les niveaux de courriels (NOTIF) ne règlent pas les courriels aux
  clients, ce choix par abonnement en tient lieu.
- **Séance entière** : chaque inscrit planifié est marqué fait, sauf les absents désignés par leur numéro d'abonnement (422
  `ABSENT_NOT_ON_SLOT` pour un abonnement qui n'y est pas) ; un droit déjà marqué reste tel quel (`unchanged`) ; un abonnement tenu un
  instant par un autre traitement est rendu dans `failed` sans arrêter les autres.
- **Corriger un constat** (« fait » ou « absent » posé par erreur ; administrateur, **motif obligatoire**, au journal `occurrence_undo` et à
  l'historique `done_undone` / `missed_undone`) : la date redevient planifiée, le droit revient au solde, la photo est effacée. Permis tant
  que la période du droit court, et toujours dans les **7 jours** qui suivent le constat (sinon 409 `UNDO_PERIOD_CLOSED`) ; **refusé
  pendant une contestation** (409 `CONTESTATION_PENDING`). Le créneau annulé entre-temps : le droit redevient à planifier, sans perte.
  « Annuler l'utilisation » d'ABO-1 ne touche jamais une date marquée (409 `OCCURRENCE_SCHEDULED`).
- **Avis de l'abonné** : `confirmed` ou `contested` (commentaire de 10 à 1000 caractères), dans les **30 jours** du constat (409
  `ACK_TOO_LATE`) ; le même avis une seconde fois : 200 sans rien changer ; un autre avis : 409 `ACK_ALREADY_GIVEN`. Une contestation
  ouvre une **demande d'Assistance** quand le module est ouvert (sujet « Contestation : <prestation> du <date> », catégorie `other` ; son
  numéro est rendu dans `ticket`) ; si le plafond de demandes est atteint, la contestation est gardée quand même. **Jamais de
  remboursement automatique** (décision 6).
- **Trancher une contestation** (administrateur) : `kept` (constat maintenu) ou `returned` (le droit redevient **à planifier**, valable au
  moins jusqu'à la fin de la période en cours ; photo et note effacées ; replanifié puis refait, c'est une nouvelle prestation : nouveau
  courriel, nouvel avis). Au journal et à l'historique (`contest_kept`, `contest_returned`).
- **Fiches** : gestion `GET /api/site/abonnements/{id}` et abonné `GET /api/subscriptions/{id}` gagnent `delivered[]` (90 jours, 20 au plus,
  les plus récentes d'abord) et `doneEmails`. Abonné : `occurrenceId`, `benefitId`, `name`, `status` (`done` | `missed`), `startsAt`,
  `doneAt`, `note`, `hasProof`, `customerAck`, `ackUntil` (null une fois l'avis donné) ; l'équipe voit en plus `doneBy`, `ackComment`,
  `ackAt`, `ticket`, `resolution`. Le modèle (assistant) ne voit dans la fiche ni « par qui », ni la note, ni le commentaire ; seul `get_contestations` lui donne le
  commentaire, courriels et téléphones masqués, pour qu'il résume la contestation (l'administrateur tranche).
- **Entrées** : le directeur d'un groupe entré lit « À faire aujourd'hui », les contestations et les photos ; il ne marque, ne corrige ni ne
  tranche rien (`EntryModeGuardSubscriber`, famille « droits »). L'agence n'entre pas dans les abonnements (famille vente), comme pour l'agenda.

Routes (dates ATOM à l'heure du magasin) :

| Qui | Route | Rôle |
|---|---|---|
| équipe | `GET /api/site/abonnements/aujourdhui?date=AAAA-MM-JJ` | séances du jour (même sans inscrit) et leurs inscrits, dates libres, compteurs ; 422 `DATE_INVALID` |
| équipe | `POST /api/site/abonnements/{id}/occurrences/{occurrenceId}/fait` | JSON `{ note? }` ou multipart (`note`, `photo`) → 200 `{ occurrence, changed, subscription }` |
| équipe | `POST /api/site/abonnements/{id}/occurrences/{occurrenceId}/absent { note? }` | 200 `{ occurrence, changed, subscription }` |
| équipe | `POST /api/site/abonnements/creneaux/{slotId}/fait { absentSubscriptionIds?, note? }` | 200 `{ slotId, done, missed, unchanged, failed }` |
| équipe | `POST /api/site/abonnements/{id}/occurrences/{occurrenceId}/correction { reason }` | 200 `{ occurrence, subscription }` |
| équipe | `GET /api/site/abonnements/contestations` | contestations en attente (identité de l'abonné, commentaire, demande d'Assistance) |
| équipe | `POST /api/site/abonnements/{id}/occurrences/{occurrenceId}/contestation { decision: kept \| returned, note? }` | 200 `{ occurrence, subscription }` |
| équipe | `GET /api/site/abonnements/{id}/occurrences/{occurrenceId}/preuve` | la photo (image/jpeg) ; 404 `PROOF_NOT_FOUND` |
| abonné | `POST /api/subscriptions/{id}/occurrences/{occurrenceId}/avis { decision, comment? }` | 200 `{ acknowledgement: { occurrenceId, customerAck, ticket, changed }, subscription }` |
| abonné | `GET /api/subscriptions/{id}/occurrences/{occurrenceId}/preuve` | la photo de sa prestation |
| abonné | `PATCH /api/subscriptions/{id}/courriels { doneEmails }` | 200 `{ doneEmails }` ; 422 `DONE_EMAILS_INVALID` |

Exemple `GET /api/site/abonnements/aujourdhui` (onglet Abonnements de l'application de gestion mobile et gestion web) :

```json
{
  "date": "2026-11-20", "timezone": "America/Toronto",
  "counts": { "toMark": 2, "done": 1, "missed": 0 },
  "sessions": [
    { "slotId": 412, "benefitId": 7, "name": "Yoga", "startsAt": "2026-11-20T18:00:00-05:00", "endsAt": "2026-11-20T19:00:00-05:00",
      "location": "Salle B", "capacity": 12, "staffUserId": 3, "booked": 3, "done": 1, "missed": 0, "lateCancelled": 0, "toMark": 2,
      "appointments": [
        { "occurrenceId": 9001, "subscriptionId": 42, "benefitId": 7, "name": "Yoga", "status": "done", "startsAt": "2026-11-20T18:00:00-05:00",
          "endsAt": "2026-11-20T19:00:00-05:00", "doneAt": "2026-11-20T19:05:12-05:00", "hasProof": false, "customerAck": null,
          "contestationPending": false, "note": null, "customer": { "id": 88, "firstname": "Julie", "lastname": "Martin" } },
        { "occurrenceId": 9002, "subscriptionId": 43, "benefitId": 7, "name": "Yoga", "status": "scheduled", "startsAt": "2026-11-20T18:00:00-05:00",
          "endsAt": "2026-11-20T19:00:00-05:00", "doneAt": null, "hasProof": false, "customerAck": null, "contestationPending": false,
          "note": null, "customer": { "id": 91, "firstname": "Marc", "lastname": "Roy" } }
      ] }
  ],
  "appointments": [
    { "occurrenceId": 9100, "subscriptionId": 57, "benefitId": 9, "name": "Ménage", "status": "scheduled", "startsAt": "2026-11-20T10:30:00-05:00",
      "endsAt": "2026-11-20T12:30:00-05:00", "doneAt": null, "hasProof": false, "customerAck": null, "contestationPending": false,
      "note": null, "customer": { "id": 102, "firstname": "Anne", "lastname": "Côté" } }
  ]
}
```

Exemple `POST /api/site/abonnements/43/occurrences/9002/fait` (multipart `note`, `photo`) → 200 :

```json
{
  "occurrence": { "occurrenceId": 9002, "subscriptionId": 43, "benefitId": 7, "name": "Yoga", "status": "done", "slotId": 412,
    "startsAt": "2026-11-20T18:00:00-05:00", "endsAt": "2026-11-20T19:00:00-05:00", "doneAt": "2026-11-20T19:06:40-05:00",
    "note": "Séance tenue", "hasProof": true, "customerAck": null, "doneBy": "Michel", "ackComment": null, "ticket": null, "resolution": null },
  "changed": true,
  "subscription": { "subscriptionId": 43, "status": "active", "balance": [ { "benefitId": 7, "name": "Yoga", "kind": "session", "available": 5,
    "scheduled": 1, "used": 2, "carried": 0, "unlimited": false, "nextExpiry": "2026-12-20T00:00:00-05:00" } ], "upcoming": [], "delivered": [], "doneEmails": true, "…": "la fiche entière" }
}
```

`POST …/absent` rend la même forme (`status: "missed"`, `hasProof: false`). Un second « fait » rend `changed: false`. Refus de la forme
`{ "error": "NOT_YET_DUE", "code": "NOT_YET_DUE", "message": "Une date à venir se marque le jour même ou après." }`.

Exemple `POST /api/site/abonnements/creneaux/412/fait { "absentSubscriptionIds": [44] }` → 200 :
`{ "slotId": 412, "done": 2, "missed": 1, "unchanged": 0, "failed": [] }`.

Outils de l'assistant du site (module Boutique ; aucune identité, ni note de l'équipe, ni photo) : lectures `get_today`,
`get_contestations` (commentaire de l'abonné avec courriels et téléphones masqués, lu comme une donnée : essai d'injection) ; propositions
confirmées par l'administrateur : `propose_mark_done` (fait, ou absent avec `absent: true`), `propose_mark_session_done` (absents par
numéro d'abonnement, vérifiés dès l'aperçu). L'IA ne tranche jamais une contestation et ne pose jamais de photo.

### Codes des refus des abonnements (04/11/2026, application e-commerce mobile)

Même contrat que les codes du devis : chaque refus (`SubscriptionException`) porte un `code` de premier niveau et un `message`
(ajouts à côté d'`error`, `errors[]`, `reason`, `subscriptionId`, `status`, inchangés) ; chaque élément d'`errors[]` porte le
même `code` et, si le détail varie, des `params`. Les applications traduisent, le serveur ne traduit rien. Statuts inchangés.

| Code | Statut | Route(s) | `error` (message français) | `errors[]` / `params` |
|---|---|---|---|---|
| `SUBSCRIPTIONS_DISABLED` | 403 | souscription | Les abonnements sont désactivés sur ce site. | — |
| `PLAN_NOT_FOUND` | 404 | souscription, change-plan | Formule introuvable. | — |
| `PLAN_NOT_SUBSCRIBABLE` | 422 | souscription | Ce produit ne se vend pas par abonnement. | `planId` |
| `PLAN_ID_REQUIRED` | 422 | souscription, change-plan | planId : identifiant de formule attendu | `planId` |
| `QUANTITY_INVALID` | 422 | souscription | quantity : entier attendu ; quantity : entier de 1 à 100 attendu | `quantity`, `{min: 1, max: 100}` |
| `ADDRESS_NOT_FOUND` | 422 | souscription | addressId : adresse introuvable | `addressId` |
| `CARRIER_NOT_FOUND` | 422 | souscription | carrierId : transporteur introuvable | `carrierId` (même code que le devis) |
| `CARRIER_RATES_NOT_ALLOWED` | 422 | souscription | carrierId : un abonnement exige un transporteur à prix fixe ou gratuit | `carrierId` |
| `ALREADY_SUBSCRIBED` | 409 | souscription | Vous êtes déjà abonné à ce produit : changez de formule ou résiliez depuis votre compte. | `planId`, `{subscriptionId, status}` ; `reason: already_subscribed`, `subscriptionId`, `status` conservés au premier niveau |
| `SUBSCRIPTION_NOT_FOUND` | 404 | détail, cancel, resume, pause, change-plan | Abonnement introuvable. | — |
| `SUBSCRIPTION_CANCELED` | 409 | resume, change-plan | Abonnement déjà résilié : souscrivez de nouveau. ; Abonnement résilié : souscrivez de nouveau. | — |
| `SUBSCRIPTION_NOT_ACTIVE` | 409 | pause | Seul un abonnement actif peut être mis en pause. | — |
| `PLAN_OTHER_PRODUCT` | 422 | change-plan | planId : la nouvelle formule doit concerner le même produit | `planId` |
| `SUBSCRIPTION_NOT_STARTED` | 409 | cancel, resume, pause, change-plan | Abonnement sans abonnement Stripe : souscription non terminée. | — |
| `NOTHING_TO_REFUND` | 409 | administration (remboursement) | Aucun paiement encaissé à rembourser pour cet abonnement. | — |
| `ALREADY_REFUNDED` | 409 | administration (remboursement) | Le dernier paiement de cet abonnement est déjà remboursé. | — |
| `STRIPE_ERROR` | 502 | toute route qui appelle Stripe (souscription, gestion, portail) | Stripe : <message de l'API> | — ; en production, nginx remplace le corps des réponses 5xx par un message neutre |

Sans code explicite, `SubscriptionException::code()` rend le code par statut d'`ApiExceptionSubscriber` (`CONFLICT`, `NOT_FOUND`…).
Tests : `SubscriptionErrorCodesApiTest` (un test par code ; corps complet vérifié pour le 403, le 409 `already_subscribed` et un 422).

## Registre des fonctionnalités du site : `GET`/`PUT /api/site/features` (console de pilotage, lot 2, 04/11/2026)

Une seule route pour lire et régler ce qui s'active sur le site, miroir du panneau « Commerce » de l'éditeur (fiche complète :
`docs/console-pilotage.md`). `ROLE_ADMIN`. `GET` → `{ platformMode: demo | live, groups: [localisation, modules, apps, commerce,
services], features: [{ key, label, group, scope, type, options, nullable, value, effective, details, status, prerequisites: [{ key,
label, ok }], href }] }`. `scope` : `site` (réglable ici), `platform` (accordé par la plateforme, super administrateur), `service`
(état d'un service externe ou d'un quota, réglé sur son écran). `status` : `ready`, `incomplete` (activé mais un prérequis manque),
`off`, `info`. `effective` : valeur déduite quand la valeur réglée est vide (langue, fuseau).

Clés `site` : `site.locale` (`fr` | `en` | null → `entreprise.locale`), `site.timezone` (IANA | null → `entreprise.posTimezone`,
fuseau de la boutique et de la caisse), `site.currency`, `site.prices_include_tax`, `commerce.sales_modes` (liste parmi `sale`,
`rental`, `subscription` → `commerce.salesModes` des réglages, absent = les trois), `commerce.guest_checkout`,
`commerce.subscriptions` (prérequis affichés : module Boutique, mode abonnement accepté, compte Stripe prêt, une formule active, un
transporteur à prix fixe), `commerce.tax_provider` (`table` | `stripe`). Clés `platform` : `module.landing`, `module.boutique`,
`module.memoires`, `module.boussole` (écrites par le super administrateur seulement), `module.pos` et `app.*` (lecture : la fiche
Entreprise génère et révoque les clés). `PUT { values: { clé: valeur } }` → même réponse après écriture ; les clés `site` sont
écrites là où elles vivent (fiche Entreprise par `UpdateEntrepriseUseCase`, réglages de la boutique par
`UpdateBoutiqueSettingsUseCase`), donc validées et journalisées comme par leurs routes propres ; tout ou rien par cible. Refus :
422 `VALIDATION_FAILED` (`errors[{ path: clé du registre, message }]`, toutes les erreurs), 403 `SUPER_ADMIN_REQUIRED` (`fields`)
pour une clé `platform` modifiée par un administrateur de site (valeur inchangée : ignorée). `commerce.salesModes` est aussi accepté
par `PUT /api/boutique-settings` (validé). Test : `tests/Functional/Boutique/SiteFeaturesApiTest.php`. Depuis le lot 3 (04/11/2026), chaque clé `platform` porte aussi `entitlement` (droit d'usage effectif : `source`, `status`, `endsAt`, `graceUntil`, `offerCode`, ou null) et `request` (`pending` si le site a demandé la fonctionnalité) ; `POST /api/site/features/{clé}/request { message? }` → 201 `{ kind: trial, entitlement }` (essai gratuit ouvert aussitôt si la politique le permet, règle du 05/11/2026) ou `{ kind: request, request }`, 409 `ALREADY_ENABLED` | `REQUEST_PENDING`, 422 clé non plateforme (test `tests/Functional/Platform/EntitlementsTest.php`).

Garde-fou des modules (lot 1 bis, resserré le 05/11/2026) : en mode `live` de la plateforme, devis (`cart/quote`),
`stripe/create-intent`, `POST /api/subscriptions` (souscrire) et `POST /api/subscriptions/{id}/change-plan` ou `resume` répondent **403 `MODULE_INACTIVE`** (`module: boutique`) sur un site sans module Boutique ; lire, résilier, mettre en pause ou ouvrir le portail d'un abonnement existant reste servi ;
`order/create`, `order/create-guest` et `payment` restent servis pour qu'un paiement Stripe déjà autorisé soit toujours
finalisable en commande (aucun paiement en suspens) ; le catalogue public, `boutique-settings` et `subscription-plans` restent
servis ; l'indicateur lisible avant tout devis est `isBoutiqueActive` de `GET /api/entreprise/{id}`. Mode `demo` (défaut) : rien n'est gardé. Voir `docs/architecture.md`, « Modules et mode de la plateforme ».

## Avis clients (09/10/2026)

Pratique des grandes boutiques, ramenée à l'essentiel : **avis vérifiés** (par défaut, seul un client dont une commande
payée et non annulée contient le produit peut écrire : statuts 2 à 6, vente, location ou échéance d'abonnement ; badge
`verifiedPurchase`), **un avis par client et par produit** (note 1 à 5, titre facultatif 120 car., texte 2 000 car. en
clair : balises retirées), **modération sur le contenu, jamais sur la note** (la loi interdit d'écarter les avis
négatifs en France, dans l'UE et aux États-Unis ; la politique affichée dit comment les avis sont contrôlés), **réponse
publique du commerçant**, nom affiché « Marie D. ». Moyenne et nombre tenus sur le produit à chaque publication.

| Route | Accès | Réponse |
|---|---|---|
| `GET /api/products/{id}/reviews?page=&perPage=(≤ 50)&sort=recent\|highest\|lowest&rating=1-5&locale=` | public | `{ enabled, summary: { average (0,1 près) \| null, count, distribution: {"5": n … "1": n} }, policy, verifiedOnly, items: [{ id, rating, title, body, author, verifiedPurchase, date, updatedAt (si modifié), locale, reply: { body, date } \| null }], page, perPage, total, pages, sort, rating }` ; avis désactivés : `enabled: false`, liste vide |
| `GET /api/products/{id}/reviews/eligibility` | client connecté | `{ canReview, reason: null \| disabled \| already_reviewed \| not_purchased, verifiedPurchase, minLength, review (le sien, avec status) }` |
| `POST /api/products/{id}/reviews { rating, title?, body }` | client connecté | 201 `{ status: pending \| approved, review }` ; 403 `reason` disabled / not_purchased ; 409 already_reviewed ; 422 champs ; 429 au-delà de 5 avis par heure |
| `GET /api/reviews/mine` | client connecté | ses avis, avec `status`, `rejectionReason`, `product { id, name, slug }` |
| `PUT /api/reviews/{id}` (champs envoyés seulement) | auteur | `{ status, review }` ; repasse en modération si le site modère ; 404 pour l'avis d'un autre |
| `DELETE /api/reviews/{id}` | auteur | 204 |

- Produits (`ProductCommerceDto`, les trois DTO produit) : `rating` (moyenne à 0,1 près, `null` sans avis publié) et
  `reviewCount`, lus par le bloc Étoiles du front (`item.rating`).
- Réglages par site (EasyAdmin « Réglages des avis », table `review_setting`, une ligne ; sans ligne : valeurs par
  défaut) : avis activés, acheteurs vérifiés seulement (défaut), modération `manual` (défaut, avant publication) ou
  `auto` (publié aussitôt, retrait possible), longueur minimale (20), politique affichée `{"fr": …, "en": …}`.
- Modération : EasyAdmin « Avis clients » (catalogue), en attente d'abord, compteur dans le menu ; Publier / Refuser
  depuis la liste (liens protégés par CSRF), réponse publique et motif de refus (montré au seul auteur) dans le
  formulaire (après « Refuser », le formulaire de l'avis s'ouvre pour saisir le motif ; sans motif, `rejectionReason`
  reste `null`) ; filtre `?status=pending|approved|rejected`. Pas de création d'avis dans l'administration.
- Code : `Entity/ReviewsProduct` (table historique `reviews_product`, colonnes `note` = rating et `comment` = body),
  `Entity/ReviewSetting`, `Repository/ReviewsProductRepository`, `OrderRepository::findLatestPurchaseOf`,
  `Services/ReviewService/` (`ReviewService` : droit d'écrire, dépôt, modification ; `ReviewModerationService` ;
  `ReviewAggregateService` : moyenne recopiée sur le produit, dont l'écriture vide les listes ; `ReviewSettingsProvider`), `UseCase/ReviewUseCase/`,
  `Controller/ReviewController/ReviewController`, `Dto/ReviewInputDto`, `Dto/ReviewOutputDto`, EasyAdmin
  `ReviewCrudController`, `ReviewSettingCrudController`. Limite `review_submit` (`rate_limiter.yaml`). Migration
  `Version20261009170000`, script `scripts/migrate_all_v2_reviews.sh`.
- Démo : 13 avis publiés (dont des notes basses, certaines avec réponse) d'auteurs fictifs `@example.invalid`, plus un
  avis vérifié du client de démonstration sur les produits qu'il a commandés ; politique affichée
  (`BoutiqueDemoCatalog::REVIEWS`, `REVIEW_POLICY`).
- **Codes des refus (B15, 04/11/2026, application mobile)** : chaque refus porte `code` et `message` au premier niveau, à côté
  d'`error`, `errors[{path, message, code, params?}]` et `reason` (minuscules, inchangé) ; les statuts ne changent pas. Dans
  `GET …/reviews/eligibility` (200), `reason` garde `disabled` | `already_reviewed` | `not_purchased`, et ces raisons en majuscules sont
  les codes des refus correspondants.

  | Code | Statut | Route(s) | `error` (message français) | `params` |
  |---|---|---|---|---|
  | `UNAUTHENTICATED` | 401 | éligibilité, dépôt, mes avis, modification, suppression | Connexion requise. En pratique le pare-feu JWT répond avant, forme inchangée : `{ code: 401, error: JWT_NOT_FOUND, message }` | — |
  | `PRODUCT_NOT_FOUND` | 404 | liste, éligibilité, dépôt | Produit introuvable. | — |
  | `REVIEW_NOT_FOUND` | 404 | modification, suppression (avis absent ou d'un autre client) | Avis introuvable. | — |
  | `DISABLED` | 403 | dépôt, modification | Les avis sont désactivés sur ce site. | `reason: disabled` |
  | `NOT_PURCHASED` | 403 | dépôt | Seuls les clients qui ont acheté ce produit peuvent donner leur avis. | `reason: not_purchased` |
  | `ALREADY_REVIEWED` | 409 | dépôt | Vous avez déjà donné votre avis sur ce produit : modifiez-le. | `reason: already_reviewed` |
  | `RATING_INVALID` | 422 | dépôt, modification | rating : entier de 1 à 5 attendu | `{min: 1, max: 5}` |
  | `TITLE_INVALID` | 422 | dépôt, modification | title : texte attendu | — |
  | `TITLE_TOO_LONG` | 422 | dépôt, modification | title : 120 caractères au plus | `{max: 120}` |
  | `BODY_INVALID` | 422 | dépôt, modification | body : texte attendu | — |
  | `BODY_TOO_LONG` | 422 | dépôt, modification | body : 2000 caractères au plus | `{max: 2000}` |
  | `BODY_TOO_SHORT` | 422 | dépôt, modification | body : %d caractères au moins (`minLength` du site) | `{min}` |
  | `TOO_MANY_REVIEWS` | 429 | dépôt (`review_submit`, 5 par heure et par compte, refus compris) | Trop d'avis envoyés : réessayez plus tard. | `retryAfter` (secondes, premier niveau) |

  Plusieurs champs fautifs : `errors[]` les porte tous, le `code` de premier niveau est celui du premier. Routes et rôles
  (`ROLE_USER_INTERNET` pour le client) : `docs/endpoints.md`, inchangés.
- Tests : `tests/Functional/Boutique/ReviewApiTest.php`, `ReviewErrorCodesApiTest.php` (un cas par code).
- À venir (seconde version) : photos, votes « utile », signalement, courriel d'invitation après livraison.

## Boutique de démonstration (site `demo`, 08/10/2026)

À ne pas confondre avec le contenu d'exemple des nouveaux sites (site de référence `gmasuite`, marquage `isDemo`) :
`docs/contenu-exemple.md`.

`php bin/console app:boutique:seed-demo [--customer-email=…] [--reset-password] [--otp]` (`SeedBoutiqueDemoCommand`,
`SeedBoutiqueDemoUseCase`, `Services/BoutiqueDemoService/` : `BoutiqueDemoCatalog` pour les données,
`BoutiqueDemoSeeder`, `DemoImageGenerator`). Refusé sur tout autre site que `demo` (`ALLOWED_TENANTS`) et les sites d'une agence de test (`App\Services\Platform\TestSites` :
site d'attache ou site géré d'une agence `is_test`, relu dans la base maître ; lot ESSAI-DONNÉES, 17/11/2026), base lue dans
`tenants`. Rejouable : n'ajoute que ce qui manque (produits repérés par leur code `DEMO-…`), n'efface rien ; les
caches des lectures publiques sont vidés par ses écritures (registre `Cache/CacheTags`). Le mot de passe du client n'est
affiché qu'à sa création (ou avec `--reset-password`).

| Cas (§ 10) | Produit (code) |
|---|---|
| vente simple, une variante | Casquette brodée (`DEMO-CASQUETTE`) |
| variantes taille × couleur, prix de variante (XL), variante épuisée | T-shirt Horizon (`DEMO-TSHIRT`) |
| promotion en cours / expirée ; unité de vente | Sweat Brise (`DEMO-SWEAT`) / Café en grains (`DEMO-CAFE`, kg) |
| épuisé ; plusieurs photos | Veste (`DEMO-VESTE`) ; Sac de voyage (`DEMO-SAC`, 5 photos) |
| personnalisable (combinaisons, `/customization/config/{variantId}`) | Gourde à graver (`DEMO-GOURDE`) |
| location à l'heure, demi-journées | Kayak (`DEMO-KAYAK`) |
| vente **et** location à la journée, caution | Planche à pagaie (`DEMO-PADDLE`) |
| location jour / semaine / mois, caution, frais par passager | Ponton (`DEMO-PONTON`) |
| dates autorisées et créneau du soir | Spectacle pyrotechnique (`DEMO-SPECTACLE`) |
| véhicule à louer / à vendre | Sea-Doo (`DEMO-SEADOO`) / Remorque (`DEMO-REMORQUE`) |
| abonnement (drapeau seulement : module § 11 à construire) | Panier bio (`DEMO-PANIER`) |

Aussi : 6 catégories avec image (3 de location, forfaits `rental_pack` en cents), options `taille`, `couleur`,
`gravure`, `bouchon`, 3 diapositives, 3 cartes « explorer », données de référence du tunnel (sources, types, statuts,
moyens de paiement, mêmes identifiants que les autres sites), TPS 5 % et TVQ 9,975 %, transporteurs à prix fixe
1 (15 $), 2 (29 $) et **6 (gratuit)**, client `client.demo@example.com` (domaine réservé : aucun courrier ne part) avec
une adresse à Montréal. Images dessinées localement (`demo-boutique-*.jpg` dans le stockage public partagé).

Paiement : clés Stripe de la plateforme en mode test ; compte connecté de `demo` (Express, test) : paiements
acceptés, virements bloqués (pièce d'identité « liveness » demandée, sans effet sur les essais). Essai du 08/10/2026 :
connexion du client → `create-intent` → carte `pm_card_visa` → `order/create` → capture : commande n° 1, 124,17 $
(108 $ + taxes), stock décrémenté. `shipping/summary` (EasyPost) n'est pas disponible sur `demo` (aucune
configuration EasyPost) : utiliser le prix fixe des transporteurs.

Corrigé en chemin (08/10/2026) : le calendrier et la vérification de disponibilité exigeaient un ancien
enregistrement `Vehicle` lié au produit (stock nul sinon) et se fiaient à l'ancien `mode` ; une ligne de commande d'un
produit vendu **et** loué était toujours traitée comme une location. Règle commune désormais :
`RentalLineResolver::isRental` (location activée et dates sur la ligne, ou produit qui ne se vend pas).

## Commandes et rendez-vous de la boutique dans la gestion web (lot CMD-WEB-1, 20/11/2026)

- `GET /api/site/commandes/a-traiter` et `GET /api/site/commandes/{id}` : « À traiter » et la fiche d'une commande, en lecture seule, par
  `OrderDeskService` (le même service que `/admin/commandes/…`). Chaque lecteur reçoit sa vue, construite en liste blanche par
  `OrderDeskView`.
- `/api/site/reservations-boutique` : les rendez-vous de la boutique, c'est-à-dire l'écran « Réservations de Prestations », jamais un
  entretien de Mémoires Vivantes. On peut les lire, les confirmer et les annuler. EasyAdmin passe par le même service
  (`ShopBookingService`).
- `GET /api/payments` : pagination facultative, `orderSource=all` (administrateur, par page), `?locale=fr|en`.
- `lienCv` des candidatures : une adresse http ou https de 255 caractères au plus.

Le contrat, les formes et les droits sont dans `docs/contrat-web-commandes.md`.

## Livraison, transporteurs et commandes du client (fiche complétée le 03/11/2026)

Les autres chantiers de la boutique ont leur section plus haut (réglages, produits, réservation et fuseau, panier et devis, paiement,
adresses, codes d'erreur, courriels, taxes, annulation, compte, abonnements, avis, démonstration). Ce qui suit couvre le reste du
module tel qu'il existe ; aucune de ces routes n'est en production avec de vrais envois (compte EasyPost de test).

### Livraison (`ShippingController`, `Services/ShippingService/`)

| Route | Rôle | Rôle exigé | Entrée (JSON) |
|---|---|---|---|
| `POST /api/shipping/parcels` | colis calculés pour un panier (`PackagingService` : types d'emballage et classes d'expédition des produits) | public | `cartItems` |
| `POST /api/shipping/rates` | tarifs EasyPost pour ces colis, agrégés par transporteur et service | public | `cartItems`, `to`, `from`, `carriers` |
| `POST /api/shipping/summary` | résumé des colis et des tarifs en une réponse (écran de livraison du site et de l'application) | public | `cartItems`, `to`, `from`, `carriers` |
| `POST /api/shipping/buy` | achat des étiquettes d'une commande du client connecté (`ShippingLabel`, `Parcel`, `ShippingOrder`) | connecté | `orderId`, `cartItems`, `to`, `from`, `carrierAccountId`, `service` |

- `/api/shipping/buy` vérifie que la commande appartient à l'utilisateur connecté (`ORDER_NOT_FOUND` 404, `FORBIDDEN` 403) ; chaque
  achat d'étiquette sur une clé de production EasyPost est facturé réellement. Les clés EasyPost du site (`EasyPostConfiguration`,
  sandbox et production chiffrée) ne se modifient plus que par le super administrateur dans EasyAdmin (lot 2b, 03/11/2026) ; les
  adresses d'expédition sont normalisées par `ShipmentAddressBuilder`.
- Référentiels EasyAdmin de la livraison : classes d'expédition, types d'emballage, expéditions de produits, commandes d'expédition,
  colis, étiquettes (menu « Livraison »).

### Transporteurs

- `GET /api/Carrier` (public, `CarrierControleur`) : transporteurs du site avec leurs traductions, pour le choix de livraison. Depuis le
  07/11/2026 : `kind` (`fixed` | `pickup` | `easypost`) et `isPickup` ; un transporteur **sans traduction** dans la langue demandée reste
  proposé avec son nom de base (avant, il disparaissait de la liste : `CarrierRepository::findAllByLocale`, jointure conditionnelle).
- `GET /api/carrier/statistics` (administrateur) : statistiques par transporteur du tableau de bord.

### Livraison, lot 1 (07/11/2026) : réglages, estimation, « j'expédie moi-même »

Trois niveaux voulus par le propriétaire : **sans rien** (transporteurs à prix fixe et retrait en magasin, le marchand expédie lui-même et
saisit le suivi), **estimation** (poids et dimensions des produits, gabarits d'emballage et classes d'expédition), **étiquettes** (clé
EasyPost du site, ou, au lot 2, étiquettes achetées par la plateforme avec un solde prépayé). Page du back-office **« Livraison »**
(`GET /admin/livraison`, `POST /admin/livraison/regler`, `Controller/Admin/ShippingConsoleAdminController`, menu Livraison) : cheminement
(`Services/ShippingService/ShippingSetupService` : modes de remise, retrait, gratuité, poids, gabarits, clé EasyPost, niveau atteint),
réglages, transporteurs, compteurs, liens vers les écrans.

- **Réglages** (clés `commerce` des réglages de la boutique, validées par `BoutiqueConfigurationValidator`, lues par
  `BoutiqueSettingsService::shippingSettings()`), exposées dans le registre (`GET`/`PUT /api/site/features`, panneau Commerce de
  l'éditeur, assistant) : `shipping.mode` (`none` défaut | `own_key` | `platform`), `shipping.easypost_environment` (`test` défaut |
  `live`), `shipping.default_weight_kg` (0,5 défaut), `shipping.free_above` (cents ; **`null`, le défaut, = la livraison n'est jamais offerte**, chaque
  transporteur garde son prix ; avec un montant, les frais du transporteur payant passent à 0 dès que le total des articles, avant taxes et
  livraison, l'atteint). **L'environnement EasyPost est
  un réglage du site**, plus l'environnement Symfony (`EasyPostService::environment()` ; le serveur tourne en `dev`, la clé de
  production n'était jamais utilisée).
- **Transporteurs** : colonne `carrier.kind` (`fixed`, `pickup`, `easypost` ; migration `Version20261115100000`, script
  `scripts/migrate_all_v2_shipping_lot1.sh`). Retrait en magasin : prix forcé à 0, aucun compte EasyPost ; CRUD « Transporteurs et
  retrait » (nature, devise du site). Produit : `product.requires_shipping` (vrai par défaut ; case « À expédier » de la fiche produit :
  service, numérique ou retiré sur place → aucun colis ni étiquette).
- **Devis** (`POST /api/cart/quote`, `CartQuoteCalculator::shipping`) : retrait en magasin → `shipping: 0` ; livraison offerte quand les
  lignes atteignent `freeShippingAbove` sur un transporteur payant (`carrier.freeShippingApplied` vrai, `carrier.kind` rendu) ; sinon
  prix fixe ou tarif EasyPost comme avant.

#### Retrait en magasin : réponses B20 à l'application mobile et à Next (08/11/2026)

1. **Taxes d'un retrait** : à l'adresse de la boutique (fiche entreprise), jamais celle du client ; sans `shippingAddress`, le devis
   répond quand même `taxStatus: calculated` et `taxBasis: store` (voir « Taxes par région »). Test
   `ShippingLot1Test::testPickupIsTaxedAtTheStoreAddressWithOrWithoutACustomerAddress`.
2. **`POST /api/stripe/create-intent` avec un transporteur `pickup`** : accepté sans `shippingAddress` ; le montant de l'intent est le
   `total` du devis, **taxes de la boutique comprises** (avant le 08/11/2026 il était hors taxes dans ce cas). Aucun champ ne remplace
   `shippingAddress` : ne rien envoyer. Codes : ceux du devis (`VARIANT_NOT_FOUND`, `CARRIER_NOT_FOUND`, `QUOTE_INVALID`, 422), `AMOUNT_INVALID`,
   `STRIPE_ACCOUNT_MISSING`. **Depuis le 08/11/2026 (B20 bis), avec un transporteur `pickup` l'adresse n'est plus exigée** : `POST /api/order/create`
   sans `addressId` et `POST /api/order/create-guest` sans `shippingAddress` créent la commande sans adresse de livraison (taxes de la boutique,
   `shippingAdress: null`). Sans transporteur de retrait, rien ne change : 400 `FIELDS_REQUIRED` (client) / `GUEST_FIELDS_REQUIRED` (invité).
3. **Invité en retrait** sans `shippingAddress` : `guestInfo { email, firstName, lastName, phone? }` ; `EMAIL_REQUIRED` sans courriel,
   400 `GUEST_NAME_REQUIRED` (`errors[0].path = guestInfo.firstName`) sans prénom ou nom. Aucune adresse n'est créée dans le carnet du client
   (une `billingAddress` fournie est gardée, facultative). Le front peut donc n'afficher que « Vos coordonnées ». Avec `shippingAddress`,
   l'ancien comportement reste (adresse créée, nom et prénom pris dans `guestInfo` sinon dans l'adresse).
4. **Détail et liste d'une commande** (`GET /api/orders/{id}`, `GET /api/ordersuser`) : `shippingAdress` = l'adresse donnée à la
   commande (jamais celle de la boutique ; `null` pour une vente au comptoir ou un retrait sans adresse) ; `carrier { id, name, kind, isPickup }`.
   Fiche commande du back-office, courriels et expédition manuelle tolèrent l'absence d'adresse (« Marquer expédiée » reste possible pour
   noter la remise). Test : `tests/Functional/Boutique/PickupCheckoutApiTest.php`.
5. **`kind: easypost`** : inchangé, un transporteur avec `carrierAccountId` est tarifé par `shipping/summary` et le tarif choisi est
   envoyé en `shippingPrice` ; `kind: fixed` : prix du transporteur ; `kind: pickup` : 0.
6. **Abonnements** : `GET /api/subscriptions` et `GET /api/subscriptions/{id}` rendent `carrier { id, name, kind, isPickup }` ; un
   abonnement en retrait a `shippingAmount` égal au prix du transporteur, soit 0 (prix forcé à 0 pour un retrait).
- **« Marquer expédiée »** : action sur la liste et la fiche Commandes (commande payée, en cours ou en préparation, jamais une vente au
  comptoir) → page `GET`/`POST /admin/orders/{id}/expedier` (`Controller/Admin/OrderShipmentAdminController`, CSRF) : transporteur ou mode
  de remise, numéro de suivi (lien de suivi déduit pour Postes Canada, Purolator, UPS, FedEx, USPS, DHL ; sinon lien saisi), courriel
  au client dans la langue du site (`emails/order_shipped.html.twig`, clés `order_shipped.*`). `UseCase/OrderUseCase/AdminOrderShipmentUseCase`
  → `Services/OrderService/OrderShipmentService` : statut **5 « En cours de livraison »**, `order.shipped_at`, `tracking_carrier`,
  `tracking_number`, `tracking_url` ; refus `COUNTER_SALE`, `ORDER_CANCELLED`, `ORDER_UNPAID`, `ALREADY_SHIPPED`. Puis **« Marquer
  livrée »** (`POST /admin/orders/{id}/livree`, depuis la même page) → statut **6**. Trace `content_audit_log` (ressource `order`,
  actions `ship` et `deliver`).
- **Lecture par le client et les applications** : `GET /api/orders/{id}`, `/api/ordersuser`, `/api/orders` rendent `shipment:
  {carrier, trackingNumber, trackingUrl}` aussi pour une expédition manuelle (`OrderPresenter::shipment`, repli sur les colonnes de la
  commande quand aucune étiquette EasyPost n'existe) : aucun changement de forme.
- **Cheminement du site** (`GET /api/site/readiness`) : l'étape `boutique.carrier` mène à l'écran `shipping` (page Livraison) ; en mode
  `own_key` ou `platform`, étape `boutique.shipping_weights` (produits à expédier sans poids, écran `product_shipping`).
- **Référentiels fournis d'office** (`scripts/seed_all_v2_shipping_referentials.sh`, idempotent) : classes d'expédition (Standard, Fragile,
  Volumineux, Lourd), gabarits d'emballage (enveloppe matelassée, petite, moyenne et grande boîte), types de fournisseur, et, sur les
  seules bases modèles et d'essai (`gmasuite`, `db_demo`, `db_mv_test_booktypes`), le transporteur « Retrait en magasin » avec ses
  traductions fr/en (jamais ajouté à un site client : un transporteur est visible de ses clients aussitôt).

Tests : `tests/Functional/Boutique/ShippingLot1Test.php`. À venir : lot 2 (clé EasyPost de la plateforme, solde prépayé, « Acheter
l'étiquette » depuis la commande, suivi automatique).

### Livraison, lot 1 bis (07/11/2026) : poids et dimensions sans effort

- **Valeurs effectives d'un produit** (`Services/ShippingService/ProductShippingResolver`) : sa fiche `product_shipping` si elle porte un
  poids, sinon les **valeurs par défaut de sa classe d'expédition** (`shipping_class.default_weight_kg`, `default_length_cm`,
  `default_width_cm`, `default_height_cm` ; migration `Version20261116100000`, script `scripts/migrate_all_v2_shipping_class_defaults.sh`,
  valeurs posées pour Standard 0,5 kg, Fragile 1 kg, Volumineux 8 kg, Lourd 15 kg), sinon le poids par défaut du site
  (`shipping.default_weight_kg`) avec des dimensions types (20 × 15 × 10 cm). Les colis, tarifs, résumés et achats d'étiquettes
  (`UseCase/ShippingUseCase/*`) passent par le résolveur : **un produit sans fiche ne fait plus échouer le panier** (avant : erreur
  « aucune configuration d'expédition »). L'objet de repli est transitoire, jamais persisté.
- **Grille de saisie rapide** `GET`/`POST /admin/livraison/poids` (`?tous=1` pour tous les produits, sinon ceux sans poids ;
  `Services/ShippingService/ProductDimensionsService`) : une ligne par produit à expédier (photo, catégories, source des valeurs),
  enregistrement en un envoi, tout ou rien ; une ligne sans poids est ignorée ; dimensions absentes = celles de la classe choisie, sinon
  types. Trace `content_audit_log` (ressource `product_shipping`, action `bulk_grid` ou `bulk_assistant`).
- **Estimation par l'IA** (`Services/ShippingService/ShippingDimensionsEstimator`, modèle Sonnet 5.5, outil forcé `propose_dimensions`,
  25 produits et 8 photos au plus par appel, **un crédit IA du site** par appel, remboursé si l'IA échoue, aucun crédit pour le
  super administrateur) : à partir du nom, de la description, des catégories et de la photo, poids, dimensions (remises dans l'ordre
  longueur ≥ largeur ≥ hauteur), classe et niveau de confiance. Bouton « Laisser l'IA estimer » de la grille : les propositions
  préremplissent les lignes (surbrillance, confiance, note) **sans rien enregistrer** ; l'administrateur corrige puis enregistre.
- **Assistant** : outil `estimate_product_dimensions` (limite ≤ 25) → propositions + action `apply_product_dimensions` confirmée par
  `POST /api/site/assistant/actions/{id}/confirm` (mêmes règles que la grille) ; lien vers l'écran `product_weights`.
- Écrans : `product_weights` (grille) dans la carte de Mon site, de l'assistant et du cheminement (`boutique.shipping_weights`) ; fiches
  une par une : écran `product_shipping`. Les écrans Expéditions, Colis et Étiquettes sont en **consultation seule** (ils naissent de
  l'achat d'une étiquette) ; libellés en français et devise du site sur tout le menu Livraison.
- Pages du back-office hors CRUD (Livraison, grille, expédition d'une commande, effacement d'un compte) : habillage EasyAdmin quand elles
  sont ouvertes depuis le menu ou un lien habillé, redirection habillée après chaque POST (sinon page nue).

Tests : `tests/Functional/Boutique/ShippingWeightsTest.php`.

### Livraison, lot 2 (07/11/2026) : étiquettes par la plateforme et solde prépayé

- **Passerelle** `Services/ShippingService/ShippingCarrierGatewayInterface` (EasyPost en production : `EasyPostService` ; simulée dans les
  tests : `tests/Fake/FakeEasyPostGateway`). Clé selon le mode du site : `own_key` → clé du site pour l'environnement choisi ; `platform` →
  clé EasyPost de la plateforme (variables `EASYPOST_SECRET_KEY`, `EASYPOST_PLATFORM_ENVIRONMENT` test | live, lues à la création du
  conteneur). Achat : **tarif exact** (compte transporteur + service), `RateUnavailableException` sinon (plus de repli silencieux sur le
  moins cher) ; étiquette en PDF ; suivi créé avec le transporteur ; journal applicatif.
- **Plateforme** (base maître, `ops/sql/platform_master.sql`, idempotent) : `platform_shipping_balances` (solde prépayé par site),
  `platform_shipping_balance_events` (credit | label | refund), `platform_shipping_charges` (chaque étiquette : coût EasyPost, frais,
  total, suivis, environnement) ; réglages `shipping_fee_bp` (marge en points de base), `shipping_fee_fixed_cents` (par étiquette),
  `easypost_platform_carriers` (JSON des comptes transporteurs de la clé de la plateforme, à propager dans `gmasuite` et les sites en
  mode plateforme quand le propriétaire aura ses comptes). `Services/Platform/PlatformShippingService`, `ShippingBalanceRepository`
  (débit sous verrou, refus `InsufficientShippingBalanceException`).
- **Routes plateforme** (`ROLE_SUPER_ADMIN`) : `GET /api/platform/shipping` → `{settings: {feeBp, feeFixedCents, carriers}, balances}` ;
  `PUT /api/platform/shipping/settings {feeBp?, feeFixedCents?, carriers?: [{id, carrier, label}]}` ; `GET /api/platform/shipping/balances/{code}`
  → `{balance, events, charges}` ; `POST /api/platform/shipping/balances/{code}/credit {amountCents, note?}`. Page Plateforme : section
  « Étiquettes par la plateforme » (frais, comptes, soldes, crédit). Un crédit par la boutique de forfaits viendra avec son lancement.
- **Site** (`ROLE_ADMIN`) : `GET /api/site/shipping` → `{overview, balance, charges}` ; page Livraison : bloc solde en mode plateforme,
  étape `shipping.platform` (faite quand clé + comptes de la plateforme et solde positif), étape `shipping.sender_phone` (téléphone de
  l'expéditeur, exigé par certains transporteurs) ; registre `shipping.mode = platform` : prérequis `shipping.platform` et `shipping.balance`.
- **« Acheter l'étiquette »** : action sur la commande (payée, avec adresse de livraison, hors caisse) → page `GET`/`POST
  /admin/orders/{id}/etiquette` (`Controller/Admin/OrderLabelAdminController`, `Services/ShippingService/AdminLabelPurchaseService`) :
  colis (poids effectifs des produits), tarifs **filtrés sur la devise du site**, frais de service en mode plateforme, solde, bloqueurs
  (`SHIPPING_MODE_NONE`, `NO_SHIPPING_ADDRESS`, `NO_SENDER_ADDRESS`, `NO_ITEMS`, `EASYPOST_NOT_CONFIGURED`, `PLATFORM_NOT_READY`,
  `NO_RATES`, `RATE_UNAVAILABLE`, `BALANCE_INSUFFICIENT`, `PURCHASE_FAILED`) ; achat : débit du solde **avant** l'achat (remboursé si
  l'achat échoue), lignes `ShippingOrder` / `Parcel` / `ShippingLabel`, frais enregistrés, puis `OrderShipmentService::ship()` (statut 5,
  suivi, courriel au client). Envoi international : `customs_info` (marchandise, lignes avec valeur et poids, pays d'origine = expéditeur).
  Une commande déjà étiquetée montre ses PDF et ses suivis, sans second achat.
- **Suivi automatique** : `app:shipping:track [--tenant=code]` (toutes les commandes « En cours de livraison » avec étiquette EasyPost ;
  toutes livrées → « Livrée ») ; unités `ops/systemd/shipping-track.{service,timer}` (toutes les 6 h), à installer par le propriétaire.

Tests : `tests/Functional/Boutique/ShippingLabelsTest.php`. Validation avec de vraies clés (bac à sable EasyPost : vraies étiquettes de
test, rien n'est facturé) au lot des comptes tiers.

### Opérations de support sur une commande web (lot 3, 07/11/2026)

`Services/OrderService/OrderOperationsService` (jamais sur une vente au comptoir : la caisse a ses retours), pages du back-office
`Controller/Admin/OrderOperationsAdminController` (actions sur la liste et la fiche Commandes ; formulaire CSRF, redirection habillée,
résultat en message), trace `content_audit_log` (ressource `order`, actions `refund`, `capture`, `cancel`, `resend_*`), courriels au client
dans la langue du site (`emails/order_notice.html.twig`, clés `order_refunded.*`, `order_cancelled.*`, `order_receipt.*`) :
- **Rembourser** (`GET`/`POST /admin/orders/{id}/rembourser`) : montant total ou partiel (au plus le capturé moins le déjà remboursé), motif,
  courriel. Remboursement réel chez Stripe (`CheckoutPaymentGatewayInterface::refund`, clé d'idempotence, commission de la plateforme rendue
  au prorata), réparti sur les paiements capturés, **une écriture de remboursement par part** (`payments` : montant négatif, type 1,
  `stripe_payment_id` = identifiant du remboursement, `stripe_status` `refunded`). Refus : `COUNTER_SALE`, `ORDER_CANCELLED`,
  `NO_ONLINE_PAYMENT`, `AMOUNT_INVALID`, `REFUND_EXCEEDS` (+ `refundableCents`), `REFUND_FAILED` (rien n'est enregistré). La commande,
  son statut et le stock ne changent pas.
- **Annuler** (`GET`/`POST /admin/orders/{id}/annuler`) : motif, confirmation, courriel ; passe par `CancelOrderUseCase` (statut 7, montants à
  zéro, stock réintégré, écriture de remboursement) qui **rembourse désormais réellement chez Stripe** chaque paiement capturé d'une
  commande web (avant : seule l'écriture existait, le client restait débité) ; si Stripe refuse, toute l'annulation est rendue (400) :
  jamais d'écriture de remboursement sans remboursement. Refus : `ALREADY_SHIPPED` (rembourser plutôt), `ORDER_CANCELLED`, `COUNTER_SALE`.
- **Capturer le paiement** (`POST /admin/orders/{id}/capturer`, depuis la page Rembourser) : paiement Stripe autorisé non capturé
  (`requires_capture`, webhook arrivé sans capture) → capture, écriture `succeeded` + reçu, commande « Complétée » si elle était incomplète
  ou en cours. Refus : `NOTHING_TO_CAPTURE`, `CAPTURE_FAILED` (autorisation expirée après 7 jours).
- **Renvoyer un courriel** (`GET`/`POST /admin/orders/{id}/courriels`, `kind` = `confirmation` | `receipt` | `shipped`) : confirmation de
  commande (gabarit existant), reçu (total, payé, remboursé, liens des reçus Stripe), avis d'expédition avec le suivi enregistré
  (`OrderShipmentService::notifyShipped`). Refus : `NO_EMAIL`, `NOT_SHIPPED`.
- Écran Paiements : libellés en français, devise du site, actions « Rembourser » (mène à la page de la commande) et « Reçu Stripe ».
- Lecture par le client et les applications : `paymentStatus` passe à `refunded` dès qu'une écriture de remboursement existe (inchangé).

Tests : `tests/Functional/Boutique/OrderOperationsTest.php` (remboursement partiel puis total, dépassement refusé, annulation qui
rembourse ou ne fait rien si Stripe refuse, capture, renvois, vente au comptoir exclue).

### Fiche commande refondue et vue « À traiter » (lot 4, 07/11/2026)

Une seule page par commande pour le support, et une liste de ce qui attend une action. Code : `Services/OrderService/OrderDeskService`
(lecture seule : les actions restent celles des lots 1 à 3) et `Controller/Admin/OrderDeskAdminController` (`ROLE_ADMIN`).
Test : `tests/Functional/Boutique/OrderDeskTest.php`.

- **Fiche** `GET /admin/commandes/{id}` (`admin_order_sheet`) : statut en français (`OrderDeskService::STATUS_LABELS`), état du paiement,
  client (nombre de commandes, lien vers le compte), articles et totaux dans la devise du site, paiements et remboursements (carte, état
  Stripe, reçu), expédition (adresse, mode choisi, suivi, étiquettes PDF des colis), chronologie (commande, encaissements, remboursements,
  expédition, livraison, annulation, courriels renvoyés : reconstruite depuis les paiements, les colonnes de suivi et le journal
  `content_audit_log` de la ressource `order`), journal des opérations. **Actions permises par l'état** seulement : Capturer (paiement
  autorisé), Acheter l'étiquette et Marquer expédiée (`OrderShipmentService::shipBlocker` nul et adresse), Marquer livrée (`deliverBlocker`
  nul, formulaire direct), Rembourser (remboursable > 0, non annulée), Annuler (statuts 1 à 4), Renvoyer un courriel (client avec un
  courriel réel), et toujours « Modifier (avancé) » vers le formulaire EasyAdmin. Une vente au comptoir (source 2) n'a aucune action : la
  caisse s'en charge. Les liens sont habillés EasyAdmin (`routeName=…`) pour garder le menu.
- **À traiter** `GET /admin/commandes/a-traiter` (`admin_orders_todo`), commandes web seulement, 100 lignes par liste : **à expédier**
  (statut 3 ou 4, adresse de livraison, `shipped_at` nul), **paiement à capturer** (un paiement `stripe_status = requires_capture`, non
  annulée), **incomplètes depuis plus d'une heure** (statut 1), **en transit depuis plus de 10 jours** (statut 5, `shipped_at` ou
  `status_updated_at`). Le tableau de bord affiche un bandeau « Commandes à traiter » avec le total et les parts quand il y en a.
- **Liste EasyAdmin des commandes** : « Commandes », plus récentes d'abord, recherche par référence, courriel, nom et suivi, montant dans
  la devise du site, colonne « Suivi », aide en tête ; l'action « Fiche » ouvre la fiche refondue depuis la liste et le détail ; les
  actions d'opération ne sont plus répétées dans la liste (elles sont sur la fiche et sur le détail).
- **Menus** : section « Commandes » (À traiter, Commandes, Paiements, Paiements en ligne (Stripe)) ; les tables techniques (création
  interne, articles, paniers, statuts, sources, types, méthodes, devises) passent sous « Réglages avancés (commandes) ».

### Retours web et changement d'adresse (lot 7, 07/11/2026)

Code : `Services/OrderService/OrderReturnService`, `Services/OrderService/OrderAddressService`, `Controller/Admin/OrderReturnAdminController`
(`ROLE_ADMIN`), entité `OrderReturn` (table `order_return`, migration `Version20261118100000` + `scripts/migrate_all_v2_order_returns.sh`,
appliqué partout le 07/11/2026). Test : `tests/Functional/Boutique/OrderReturnsTest.php`. Les ventes au comptoir gardent les retours de la
caisse (`pos_return`, lot F2).

- **Retour d'articles** `GET|POST /admin/orders/{id}/retour` (CSRF `admin_order_return`, depuis la fiche de la commande) : le marchand coche
  les quantités rendues par ligne (`quantity[orderItemId]`), un motif, « remettre en stock » (décoché pour un article défectueux) et « prévenir
  le client ». Montant = prix payé des unités rendues (net des remises) **plus leur part des taxes de la commande**, au prorata et en cumul
  exact comme en caisse (`PosReturnService::taxForQuantity`) ; en TTC la taxe est dans le net. **Les frais de livraison ne sont jamais
  repris** (« Rembourser » sert pour un geste) ; le montant est plafonné à ce qui reste remboursable. Le remboursement est réel chez Stripe
  (`OrderOperationsService::refund`, écritures négatives), la ligne `order_return` garde lignes, montant, motif, remboursements ; remise en stock
  par `UpdateStockAndInventoryUseCase` (mouvement d'inventaire) ; courriel `order_returned` fr/en avec la liste des articles ; journal
  `content_audit_log` action `return`. Refus : `COUNTER_SALE`, `ORDER_CANCELLED`, `NOT_PAID` (statuts 1 et 2), `NO_ONLINE_PAYMENT`,
  `NOTHING_TO_RETURN`, `LINES_REQUIRED`, `LINE_UNKNOWN`, `QUANTITY_EXCEEDED`, `NOTHING_REFUNDABLE`. Un **échange web** = un retour puis une
  nouvelle commande passée par le client. Le client voit ses retours : `GET /api/orders/{id}` → `returns: [{id, createdAt, amountCents, lines:
  [{orderItemId, name, quantity, amountCents}]}]` (web seulement ; `paymentStatus` passe à `refunded`).
- **Changement d'adresse de livraison** `GET|POST /admin/orders/{id}/adresse` (CSRF `admin_order_address`) tant que la commande n'est ni
  expédiée, ni livrée, ni annulée : une **nouvelle ligne `adress`** est créée pour la commande (le carnet du client n'est pas touché ; téléphone
  repris de l'ancienne adresse s'il manque), champs obligatoires prénom, nom, adresse, ville, code postal, pays ISO à deux lettres ; frais de
  livraison et taxes déjà payés restent inchangés ; journal action `address`. Refus : `COUNTER_SALE`, `ALREADY_SHIPPED`, `VALIDATION`.
- La fiche commande (lot 4) montre les retours sous les paiements et propose « Retour d'articles » et « Changer l'adresse » selon l'état ;
  mêmes actions sur le détail EasyAdmin.

### Fiche produit par IA : photo → fiche, traduction automatique (07/11/2026)

Pour le back-office, le site et l'application de gestion. Code : `Services/ProductAiService/ProductSheetDrafter` (Claude Sonnet 5.5 en
vision : photos en base64, outil `propose_product_sheet` ; traduction `translate_product`), `Services/ProductAiService/ProductSheetCreator`
(création du produit), `Controller/ProductAiController/ProductAiController` (API, `ROLE_ADMIN`), `Controller/Admin/ProductPhotoAdminController`
(page « Créer un produit depuis une photo », menu Catalogue, deux étapes : photos et indications, puis fiche à corriger avec prix, stock,
catégorie, canaux, modes, traduction automatique, puis ouverture de la fiche produit). Crédits IA du site : **1 par fiche, 1 par traduction**
(toutes les langues d'un coup) ; refus 402 `AI_CREDITS_EXHAUSTED` avant tout appel ; le propriétaire de la plateforme ne paie pas. Test :
`tests/Functional/Boutique/ProductAiSheetTest.php`.

| Route | Corps | Réponse |
|---|---|---|
| `POST /api/products/ai/sheet` | multipart : `photos[]` (1 à 4, JPEG/PNG/WebP/GIF ≤ 8 Mo), `comment?` (matière, origine, coût d'achat…), `categoryId?`, `locale?` (fr, en ; défaut : langue du site), `store?` (défaut 1 : les photos sont déposées dans la médiathèque) | 200 `{ draft: { name, shortDescription, description (HTML simple), moreInformations, categoryName, categoryId, attributes {material, colors, sizes, gender, season}, tags, suggestedPriceCents, currency, shippingClass, confidence, note, language }, pictures: [{ id, url }], usage, credits }` ; 422 `PHOTO_REQUIRED` / `PHOTO_INVALID`, 402, 429, 503 |
| `POST /api/products/ai/translate` | `{ name, description?, moreInformations?, tags?, source? (langue de la fiche), targets? (défaut : les autres langues du site) }` | 200 `{ translations: { en: { name, description, moreInformations, tags } }, usage, credits }` |
| `POST /api/products/ai/create` | `{ name, description?, moreInformations?, tags?, language?, translations?, priceCents, stockQuantity?, categoryIds?, pictures? (URL https de la médiathèque ou nom de fichier), channels? { web, pos, landing }, modes? { sale, rental, subscription }, barcode?, requiresShipping?, translate? (bool : traduction automatique, un crédit), variants? { sizes?: string[], colors?: string[] } (une variante par combinaison, 20 tailles et 20 couleurs au plus, 100 combinaisons au plus) }` | 201 `{ productId, variantId (la première), variants (nombre), sizes, colors (noms retenus), slug, code (IA-xxxxxxxx si absent), languages, pictures, translated }` ; 422 `NAME_REQUIRED` / `PRICE_INVALID` / `CATEGORY_UNKNOWN` / `PICTURE_INVALID` / `VARIANTS_TOO_MANY` |

Le produit créé : une variante avec prix et stock, ou **une variante par combinaison taille × couleur** quand `variants` est fourni (lot
« variantes IA », 07/11/2026 : les tailles et couleurs relevées par l'IA, corrigées par le marchand ; une taille ou couleur existante du site
est réutilisée au nom près, sans tenir compte de la casse, sinon créée avec un code dérivé du nom ; même prix et même stock sur chaque
variante, à ajuster ensuite ; page « Produit depuis une photo » : champs Tailles / Couleurs préremplis et case « Créer les variantes »), traductions
`product_translation` (langue de rédaction + traductions), photos `product_picture` (la première devient l'image principale), catégorie,
canaux `is_web` / `is_pos` / `is_landing_page`, modes de vente, journal `content_audit_log` (`product`, `create`, `source: ai_sheet`). Le
slug est rendu unique (`-2`, `-3`…). À venir : mannequin ou personne portant le vêtement photographié (génération d'image, clé Gemini à
fournir, cinq crédits, traitement asynchrone) et le même outil dans l'assistant.

### IA en lot : toutes les fiches, toutes les traductions, toutes les images (07/11/2026)

`Services/ProductAiService/ProductAiBulkService` : `plan(kind)` compte les produits concernés et les crédits ; `start()` crée une tâche
`ai_job` (réservation à coût nul) et envoie `ProductAiBulkMessage` sur le transport `landing_ai` (worker IA, `ProductAiBulkHandler` qui
sélectionne le site puis `run()`) ; `run()` traite produit par produit avec **un crédit réservé à la fois** (fiche : 1, traduction : 1,
image : 5), met l'avancement dans `ai_job.result` (`done`, `failed`, `skipped`, `creditsUsed`, `stoppedForCredits`, `items`, `current`) et
s'arrête proprement quand les crédits sont épuisés (402) ou après trop d'échecs (429). Candidats : `sheets` = produits dont la description
fait moins de 40 caractères (ignorés sans photo) ; `translations` = produits sans traduction dans l'autre langue du site ; `images` =
produits sans photo `product_picture.source = 'ai'` (disponible seulement quand `ProductImageGeneratorInterface::isConfigured()` ;
aujourd'hui `NullProductImageGenerator`). `overwrite` réécrit même les fiches complètes. Photos envoyées à l'IA : fichiers locaux
(`var/storage/public_bucket/assets/uploads/products`) en base64, adresses https telles quelles. Test : `tests/Functional/Boutique/ProductAiBulkTest.php`.

| Route (`ROLE_ADMIN`) | Réponse |
|---|---|
| `GET /api/products/ai/bulk/plan?kind=sheets\|translations\|images&overwrite=0` | `{ kind, label, available, unavailableReason, total, items (50 premiers : id, name, reason), skipped, languages, creditsPerItem, creditsNeeded, creditsRemaining, monthlyCredits, resetAt, enough, missingCredits, doableNow, charge }` |
| `POST /api/products/ai/bulk { kind, overwrite?, partial? (traiter ce que les crédits permettent), limit? }` | 202 `{ jobId, kind, products, creditsNeeded, partial }` ; 402 `AI_CREDITS_EXHAUSTED` (avec `plan`), 409 `NOTHING_TO_DO` / `IMAGE_PROVIDER_MISSING`, 422 `KIND_INVALID` |
| `GET /api/products/ai/bulk/{jobId}` | `{ jobId, status: pending \| running \| done \| failed, kind, total, done, failed, skipped, creditsUsed, stoppedForCredits, items, current, error }` ; 404 `JOB_NOT_FOUND` |

**Photos d'un produit** (action « Photos » de la fiche, 07/11/2026) : page `/admin/produits/{id}/photos` (`ProductPhotosAdminController`,
`Services/ProductAiService/ProductPhotoService`) : galerie (photo principale `product.image` puis `product_picture`), dépôt de plusieurs photos
d'un coup dans `var/storage/public_bucket/assets/uploads/products` (la première photo d'un produit nu devient principale), « Principale » (échange
avec l'ancienne), « Retirer », et génération par l'IA avec options : vue (`face`, `back`, `detail`, `worn` = personne portant l'article,
`packshot`), environnement (`studio`, `street`, `indoor`, `nature`), consigne libre ; `POST /admin/produits/{id}/photos/{ajouter|principale|supprimer|generer}`,
CSRF `admin_product_photos` ; la génération est refusée sans fournisseur d'images (aucun crédit). La galerie de l'onglet « Organisation & Média »
de la fiche reste disponible. Test : `tests/Functional/Boutique/ProductPhotosPageTest.php`.

**Depuis la fiche d'un produit** (action « IA » de la liste, de l'édition et du détail, 07/11/2026) : page `/admin/produits/{id}/ia`
(`ProductAiAdminController`) avec trois boutons et leur coût : rédiger la description depuis les photos du produit (remplace la description,
les informations et les mots-clés ; 1 crédit, `describeProduct`), traduire en français et en anglais (langues manquantes, ou toutes avec
« retraduire » ; 1 crédit, `translateProduct`), produire une image depuis une photo existante (packshot sans mannequin, ou personne portant le
vêtement ; 5 crédits, `generateProductImage`, disponible seulement avec un fournisseur d'images). `POST /admin/produits/{id}/ia/{decrire|traduire|image}`,
CSRF `admin_product_ai`. Test : `tests/Functional/Boutique/ProductAiSingleTest.php`.

Back-office : page « IA en lot : fiches, traductions, images » (menu Catalogue, `/admin/produits/ia-en-lot`) : une carte par famille avec
le nombre de produits, le coût, les crédits restants, ce qui manque et « Tout lancer » ou « Lancer pour N produit(s) » ; l'avancement du
dernier lot se rafraîchit toutes les quatre secondes. Cheminement du site (groupe Boutique, améliorations) : « Fiches produit complètes »,
« Fiches traduites », « Images mannequin » comptent les produits **en ligne** (`is_web`) incomplets et mènent à cette page.

### Commandes du client

- `GET /api/ordersuser` (connecté) : commandes de l'utilisateur connecté, jamais servies par le cache (lot 4 de l'invalidation ciblée,
  28/10/2026) ; `GET /api/orders/{id}` (public) : détail d'une commande pour son client connecté ou pour un invité muni de son jeton
  (vérifiés par le contrôleur, voir « Paiement d'un panier ») ; `GET /api/order-statuses` : liste des statuts.
- Réservations d'une commande : dates rendues en ATOM dans le fuseau de la boutique (voir « Fuseau horaire de la boutique »).

Toute nouvelle route de ce module s'ajoute ici et dans `docs/endpoints.md` (régénéré).
