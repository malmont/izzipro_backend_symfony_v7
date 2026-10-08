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
`bookingConfig`, `category`), gardés pour le carrousel des landing pages :

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
(`currency`). `TenantCurrencyProvider` la lit pour `pricing.currency`. Les prix restent stockés en dollars (float)
dans `product.price` et les forfaits ; la conversion en cents est faite à la sortie (`ProductCommerceDto::cents`).
Schéma : migration `Version20261008120000`, script `scripts/migrate_all_v2_product_modes.sh` (reprise de l'ancien
mode une seule fois). Tests : `tests/Functional/Boutique/ProductContractApiTest.php`.

## Reste du module (fiche à compléter)

Produits et variantes, réservation (`/api/booking`), panier et devis, Stripe (`/api/stripe/create-intent`,
`/api/order/create`, `/api/order/create-guest`), livraison (`/api/Carrier`, `/api/shipping/*`), compte client
(`/api/ordersuser`, `/api/adresses`, `/api/profile`), abonnement (à construire) : chaque chantier ajoute sa section ici
et dans `docs/endpoints.md`.
