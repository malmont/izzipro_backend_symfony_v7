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
  `reglablePresets[].config`) et **noms de section** : mêmes règles que `PUT /api/landingpage-settings`
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

## Liste générique des produits : `GET /api/products` fermée (S8, 26/10/2026)

La liste générée par API Platform (`GET /api/products`, `/api/products.json`, `/api/products/{id}.{format}`) est **réservée à
l'administrateur** (401 sans connexion, 403 pour un client, refus avant toute requête), avec des champs simples (groupe `product:list`,
aucune relation) et 30 produits par page (`itemsPerPage` jusqu'à 100). Avant, elle était publique et imbriquait toutes les relations :
une requête occupait un processus du serveur plusieurs minutes (incident du 26/10/2026). **Le site et les applications lisent les routes
dédiées, inchangées** : `/api/products/{id}` (fiche, DTO), `/api/products/by-slug/{slug}`, `/api/products/by-category`,
`/api/products/{bestsellers|newarrivals|…}`, `/api/productsid/{id}`, avis et éligibilité, variantes ; catégories : `/api/category`.
Le site ne relit plus jamais la liste complète en repli d'une fiche en erreur (correction côté Next.js). Détail des ressources API
Platform : `docs/architecture.md`.

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
| `rental` (si activée) | `bookingConfig` complet, montants en **cents** : `granularity`, `minDuration`, `maxDuration`, `stockQuantity`, `bufferTime`, `rates[]` (`hourRate`…`monthRate`), `openingHours { start, end }`, `halfDays[]`, `allowedDates` (null = toutes), `eveningSlot`, `minDaysStandard`, `deposit`, `extraPassengerFee`, `arrivalLeadMinutes`, `cancellationPolicy`, `included[]`, `excluded[]`, `notes` (colonnes de `booking_configuration`, EasyAdmin « Configuration de location », panneau « Boutique réglable ») |
| `pricing` | `{ currency, regular, special: { amount, from, to, active } | null, amount }` en cents ; `amount` = promotion en cours, sinon prix de vente |
| `variants[]` | `price` en cents (null = prix du produit, colonne `product_variant.price`), `stockQuantity`, `options[] { code, name, value, … anciens noms }`. Ordre (31/10/2026, `ProductCommerceDto::orderedVariants`) : variantes par identifiant croissant, options de chaque variante par type d'option puis valeur, identique dans la fiche (`by-slug`, `/api/products/{id}`), les listes, les sélections et `/api/products/{id}/variants` ; aucune position saisie dans EasyAdmin (la fiche rendait « L, XL, M, S » : aucun `ORDER BY`) |
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
| `DELETE /api/product-variants/{id}` | suppression réelle (pas d'état « désactivé » : stock 0 pour retirer de la vente) ; 409 si une commande ou un paiement en cours la contient ; historique de stock et combinaisons supprimés avec elle | 204 |
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
| `products` | **`name`**, **`description`**, **`moreinformations`**, `price` (cents, obligatoire), `specialPrice` (cents ou null), `specialPriceFrom`, `specialPriceTo` (ISO 8601 ou null), `image`, `pictures` (liste de clés de la médiathèque ou d'URL https : remplace la galerie, 10 au plus) |
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
- Sortie : `{ lines: [{ productVariantId, productId, name, kind: sale | rental, quantity, unitPrice, total,
  customizationId?, customizationPrice?, booking?: { start, end, rateId, rateName, durationType, units, rate, passengers,
  passengerFee, deposit } }], subtotal, shipping, taxes: [{ label, rate, amount }], deposit, total, currency, carrier: { id, name,
  isFree } | null }`. `total` = articles + livraison + taxes ; la **caution** (`deposit`) est rapportée à part.
- Vente : `unitPrice` = prix de la variante (`product_variant.price`) sinon prix effectif du produit (promotion en
  cours) ; stock de la variante vérifié. Personnalisation (`customizationId`, 09/10/2026) : la combinaison doit appartenir à la
  variante (422 sinon) ; son supplément `customizationPrice` (somme des `price_delta` de ses options, en dollars en
  base, `ProductCustomizationImage::priceDeltaCents`) s'ajoute à `unitPrice`, donc au devis, à l'intent Stripe, au prix
  unitaire de la ligne de commande et aux taxes. Une ligne est une location si la location est activée et que la ligne porte
  des dates, ou si le produit ne se vend pas (`RentalLineResolver`) ; un produit vendu et loué sans dates = achat.
- Location : `unitPrice` = tarif × unités (+ `extraPassengerFee` × `passengers`). Tarif : forfait `rateId` (doit
  appartenir aux catégories du produit) sinon le premier forfait des catégories ; `durationType` : `hour`, `halfDay`,
  `day`, `week`, `month`, par défaut `hour` (grille horaire) ou `day` ; unités = durée arrondie au-dessus en longueurs
  d'unité (heure 1 h, demi-journée 4 h, jour, semaine 7 j, mois 30 j). Règles de `booking_configuration`, 422
  `errors[{ path, message }]` : `minDuration` / `maxDuration` (heures ou jours selon la grille), `minDaysStandard`,
  `allowedDates`, `openingHours` ou `eveningSlot` (grille horaire), disponibilité (`remaining` dans l'erreur).
- Livraison : 0 sans `carrierId` ; prix fixe du transporteur ; transporteur EasyPost (`carrierAccountId`) : tarif
  choisi dans `shipping/summary`, envoyé en `shippingPrice`. `GET /api/Carrier` : `isFree`, `estimatedDays`
  (colonne `carrier.estimated_days`, migration `Version20261008140000`, script
  `scripts/migrate_all_v2_carrier_estimated_days.sh`). `shipping/summary[].totalPrice` est en cents.
- Taxes : selon l'adresse de livraison (`shippingAddress`), voir « Taxes par région » ci-dessous ; sans adresse,
  `taxes: []` et `taxStatus: address_required`.
- Statuts : 400 corps illisible, 404 variante ou transporteur inconnu, 422 règle non respectée ; rien n'est réservé. Depuis le
  02/11/2026, la réponse porte `code` de premier niveau (`INVALID_BODY`, `VARIANT_NOT_FOUND`, `CARRIER_NOT_FOUND`, sinon `QUOTE_INVALID`,
  comme la caisse) et chaque entrée de `errors[]` porte `code` (+ `params`) : voir « Codes du devis, de l'adresse et de la commande ».
- `order/create` : `orderSource`, `typeOrder` et `paymentMethod` facultatifs (vente en ligne par défaut) ;
  `carrierId` nullable. `order/create-guest` : 403 si `commerce.guestCheckout` est `false` dans les réglages de la
  boutique (`BoutiqueSettingsService::isGuestCheckoutAllowed`). `GET /api/booking/check/{id}` : **409** avec
  `remaining_stock` quand la quantité n'est pas disponible (200 auparavant).
- Tests : `tests/Functional/Boutique/CartQuoteApiTest.php`.

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

## Annulation d'une commande web (`POST /api/order/cancel/{id}`)

Réservée à l'auteur de la commande (client du site Next.js ou de l'application e-commerce) ; un administrateur annule
depuis EasyAdmin en passant le statut à « Annulé ». La première annulation est inchangée : statut 7, montants remis à 0,
remboursement enregistré, stock réintégré, réponse `{message}`. Une **seconde annulation** est refusée :
**409 `ORDER_NOT_REFUNDABLE`** `{orderId, status: 7}`, rien n'est écrit (avant : 200 et stock réintégré une seconde fois).
Détails dans `docs/caisse.md`.

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
  lignes `unitPrice`, `totalPrice`), `currency`, `statusId`, `carrier { id, name }`, lignes avec `productVariantId`,
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
| Site Next.js (session par cookies) | nouveaux cookies `auth_token_<site>`, `refresh_token_<site>`, `XSRF-TOKEN_<site>` ; corps `{message, user, token, refresh_token, csrf_token}` | rien pour les cookies (le navigateur les remplace) ; **reprendre le nouveau jeton CSRF** (`csrf_token`, ou le cookie `XSRF-TOKEN_<site>`) pour l'en-tête `X-XSRF-TOKEN` des requêtes suivantes |
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
/api/subscriptions` exige `fulfillmentTarget` (code du site à activer, existant) : 422 `FULFILLMENT_TARGET_REQUIRED` |
`FULFILLMENT_TARGET_UNKNOWN`. Le cycle de vie de tout abonnement émet `Event\SubscriptionStatusChangedEvent` après le flush
(`EventSubscriber\SubscriptionLifecycleSubscriber`, écouteur Doctrine : aucun des points d'écriture du statut n'est modifié) ; seul
`EventSubscriber\PlatformOfferFulfillmentSubscriber` traite `platform_offer` et accorde, prolonge, met en grâce, suspend, rétablit ou
retire les droits d'usage du site cible (`Services/Platform/OfferFulfillmentService`). Une formule `none` n'est pas concernée. Test :
`tests/Functional/Platform/OfferFulfillmentTest.php`.

| Route | Accès | Contenu |
|---|---|---|
| `GET /api/subscription-plans?productId=&locale=` | public | formules actives (sans `productId` : toutes celles de la boutique, produits encore vendus par abonnement ; grille de formules `PlanGrid`) `{ id, productId, productName, name, interval (week\|month\|year), intervalCount, price (cents), currency, trialDays, minimumTerms, active, description (sous-titre), features (liste d'avantages, 20 au plus), highlighted (formule recommandée, une par produit), badge (texte du badge ou null) }`, textes en clair dans la langue demandée |
| `POST /api/subscriptions { planId, quantity?, addressId?, carrierId? }` | client connecté | 201 `{ subscriptionId, clientSecret, intentType, status, subscription }` ; abonnement Stripe créé en `default_incomplete`, première facture payée par le `clientSecret` (Payment Element) ; `trialDays` > 0 → `trialing`, rien à payer : la carte s'enregistre par le `clientSecret` d'un SetupIntent. `intentType` (25/10/2026) dit lequel : `payment` → `stripe.confirmPayment`, `setup` → `stripe.confirmSetup` (sans cette confirmation, aucune carte n'est gardée et la fin de l'essai part en impayé). Un essai sans carte à la même formule et quantité est **repris** (200, `reused: true`, même `clientSecret` du SetupIntent, `intentType: setup`) au lieu d'un 409, depuis n'importe quel appareil ; un essai avec carte, ou à une autre formule ou quantité, reste refusé (409 `already_subscribed`) ; 403 si `commerce.subscriptionsEnabled` est `false` ; 404 formule ; 422 produit sans abonnement, quantité, adresse d'un autre client |
| `GET /api/subscriptions`, `GET …/{id}` | client connecté | `{ id, plan, quantity, status, currentPeriodEnd, cancelAtPeriodEnd, address, carrier, shippingAmount, createdAt, canceledAt, orders: [{ id, reference, date, total, status }], latestInvoice, hasPaymentMethod }` ; `hasPaymentMethod` (25/10/2026) : carte enregistrée chez Stripe comme moyen par défaut de l'abonnement, recopiée à chaque lecture ou webhook de l'abonnement (`subscription.has_payment_method`, migration `Version20261025110000`, script `scripts/migrate_all_v2_subscription_payment_method.sh`) ; `null` : inconnu (abonnement antérieur, pas encore relu) ; autre client : 404 |
| `POST …/{id}/cancel { atPeriodEnd?: true }` | client | fin de période par défaut (`cancelAtPeriodEnd`), ou immédiate (`canceled`, courriel) |
| `POST …/{id}/resume`, `…/{id}/pause` | client | reprise (annulation programmée levée, collecte reprise) ; pause (`pause_collection: void`, courriel) ; 409 si résilié ou pas actif |
| `POST …/{id}/change-plan { planId }` | client | formule du même produit, prorata Stripe |
| `POST /api/subscriptions/portal-session { returnUrl? }` | client | `{ url }` du portail client Stripe (carte, factures) |

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
`BoutiqueDemoSeeder`, `DemoImageGenerator`). Refusé sur tout autre site que `demo` (`ALLOWED_TENANTS`, base lue dans
`tenants`). Rejouable : n'ajoute que ce qui manque (produits repérés par leur code `DEMO-…`), n'efface rien ; les
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
| `POST /api/products/ai/create` | `{ name, description?, moreInformations?, tags?, language?, translations?, priceCents, stockQuantity?, categoryIds?, pictures? (URL https de la médiathèque ou nom de fichier), channels? { web, pos, landing }, modes? { sale, rental, subscription }, barcode?, requiresShipping?, translate? (bool : traduction automatique, un crédit) }` | 201 `{ productId, variantId, slug, code (IA-xxxxxxxx si absent), languages, pictures, translated }` ; 422 `NAME_REQUIRED` / `PRICE_INVALID` / `CATEGORY_UNKNOWN` / `PICTURE_INVALID` |

Le produit créé : variante unique avec prix et stock (les tailles et couleurs se créent ensuite comme variantes), traductions
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
