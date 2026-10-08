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
- **Charte** (`charter`, clés connues seulement ; `null` = non réglé) : `primaryColor`, `accentColor`, `textColor`,
  `backgroundColor` : couleur (`#rgb`, `#rrggbb`, `#rrggbbaa`, `rgb()`, `rgba()`, `transparent`) ; `headingFont`,
  `bodyFont` : 1 à 100 caractères sans `<` ni `>` ; `radius` : nombre de 0 à 100 ou taille CSS (`px`, `rem`, `em`,
  `%`) ; `buttonStyle` : `solid`, `outline` ou `pill`.
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

## Contrat d'un produit (08/10/2026, `ProductCommerceDto`)

Toutes les routes qui renvoient un produit (`/api/products/by-slug/{slug}`, `/api/productsid/{id}`,
`/api/products/{sélection}`, `/api/products/by-category`, `/api/products`, DTO `ProductDetailedOutputDTO`,
`ProductOutputDTO`, `ProductOutputCategoryDto`) portent, en plus des anciens champs (`mode`, `price` en dollars,
`bookingConfig`, `category`, mêmes unités qu'avant), gardés pour le carrousel des landing pages :

| Champ | Contenu |
|---|---|
| `kind` | `standard` ou `vehicle` (un `VehicleProduct`, même table : les véhicules sont servis par `by-slug`) |
| `sale.enabled`, `rental.enabled`, `subscription.enabled`, `customizable` | modes explicites, cumulables (colonnes `product.sale_enabled`, `rental_enabled`, `subscription_enabled`, `customizable` ; EasyAdmin « Modes de vente »). L'ancien `mode` est dérivé : `booking` = location seule, `retail` sinon ; `isBookable()` = location activée |
| `rental` (si activée) | `bookingConfig` complet, montants en **cents** : `granularity`, `minDuration`, `maxDuration`, `stockQuantity`, `bufferTime`, `rates[]` (`hourRate`…`monthRate`), `openingHours { start, end }`, `halfDays[]`, `allowedDates` (null = toutes), `eveningSlot`, `minDaysStandard`, `deposit`, `extraPassengerFee`, `arrivalLeadMinutes`, `cancellationPolicy`, `included[]`, `excluded[]`, `notes` (colonnes de `booking_configuration`, EasyAdmin « Configuration de location », panneau « Boutique réglable ») |
| `pricing` | `{ currency, regular, special: { amount, from, to, active } | null, amount }` en cents ; `amount` = promotion en cours, sinon prix de vente |
| `variants[]` | `price` en cents (null = prix du produit, colonne `product_variant.price`), `stockQuantity`, `options[] { code, name, value, … anciens noms }` |
| `vehicleDetails` | `{ year, brand, model, vin, transmission, gasType, enginePower, hoursOrMileage, condition, color }` ou null |

Devise (§ 7) : une seule par site, `entreprise.currency` (ISO 4217, défaut `CAD`), exposée par `GET /api/entreprise/{id}`
(`currency`), modifiable par `PUT /api/entreprise/{id}` (3 lettres, journalisée) et renvoyée par `GET /api/stripe-config`
(`currency`). `TenantCurrencyProvider` la lit pour `pricing.currency`. Tous les prix sont stockés **en cents**
(colonnes décimales : `product.price`, `special_price`, forfaits `rental_pack`, `carrier.price` ; EasyAdmin
`MoneyField::setStoredAsCents`) ; `ProductCommerceDto::cents` ne fait qu'arrondir. Jusqu'au 08/10/2026, le prix d'un
véhicule se saisissait en dollars (`NumberField`) alors que `create-intent` le lit en cents : corrigé (aucun véhicule
n'existait).
Schéma : migration `Version20261008120000`, script `scripts/migrate_all_v2_product_modes.sh` (reprise de l'ancien
mode une seule fois). Tests : `tests/Functional/Boutique/ProductContractApiTest.php`.

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
  customizationId?, booking?: { start, end, rateId, rateName, durationType, units, rate, passengers, passengerFee,
  deposit } }], subtotal, shipping, taxes: [{ label, rate, amount }], deposit, total, currency, carrier: { id, name,
  isFree } | null }`. `total` = articles + livraison + taxes ; la **caution** (`deposit`) est rapportée à part.
- Vente : `unitPrice` = prix de la variante (`product_variant.price`) sinon prix effectif du produit (promotion en
  cours) ; stock de la variante vérifié. Une ligne est une location si la location est activée et que la ligne porte
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
- Taxes : toutes celles de la table `tax` du site, sur articles + livraison (comme `TaxCalculationService`), quelle
  que soit la province (à filtrer plus tard si besoin).
- Statuts : 400 corps illisible, 404 variante ou transporteur inconnu, 422 règle non respectée ; rien n'est réservé.
- `order/create` : `orderSource`, `typeOrder` et `paymentMethod` facultatifs (vente en ligne par défaut) ;
  `carrierId` nullable. `order/create-guest` : 403 si `commerce.guestCheckout` est `false` dans les réglages de la
  boutique (`BoutiqueSettingsService::isGuestCheckoutAllowed`). `GET /api/booking/check/{id}` : **409** avec
  `remaining_stock` quand la quantité n'est pas disponible (200 auparavant).
- Tests : `tests/Functional/Boutique/CartQuoteApiTest.php`.

## Boutique de démonstration (site `demo`, 08/10/2026)

`php bin/console app:boutique:seed-demo [--customer-email=…] [--reset-password] [--otp]` (`SeedBoutiqueDemoCommand`,
`SeedBoutiqueDemoUseCase`, `Services/BoutiqueDemoService/` : `BoutiqueDemoCatalog` pour les données,
`BoutiqueDemoSeeder`, `DemoImageGenerator`). Refusé sur tout autre site que `demo` (`ALLOWED_TENANTS`, base lue dans
`tenants`). Rejouable : n'ajoute que ce qui manque (produits repérés par leur code `DEMO-…`), n'efface rien, vide les
caches des lectures publiques. Le mot de passe du client n'est affiché qu'à sa création (ou avec `--reset-password`).

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

## Reste du module (fiche à compléter)

Produits et variantes, réservation (`/api/booking`), panier et devis, Stripe (`/api/stripe/create-intent`,
`/api/order/create`, `/api/order/create-guest`), livraison (`/api/Carrier`, `/api/shipping/*`), compte client
(`/api/ordersuser`, `/api/adresses`, `/api/profile`), abonnement (à construire) : chaque chantier ajoute sa section ici
et dans `docs/endpoints.md`.
