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
| `sale.enabled`, `rental.enabled`, `subscription.enabled` | modes explicites, cumulables (colonnes `product.sale_enabled`, `rental_enabled`, `subscription_enabled` ; EasyAdmin « Modes de vente »). L'ancien `mode` est dérivé : `booking` = location seule, `retail` sinon ; `isBookable()` = location activée |
| `customizable` | **calculé** (09/10/2026, `Product::hasCustomizationConfig`) : vrai dès qu'une variante du produit a au moins une combinaison de personnalisation portant au moins une valeur d'option (ce que `GET /api/customization/config/{variantId}` affiche), quel que soit le nombre de variantes ; même règle dans la fiche, les listes, les sélections et par catégorie. La case `product.customizable` ne compte plus et a quitté les formulaires EasyAdmin (jamais cochée sur les sites existants, elle masquait la personnalisation de Kara & B) ; combinaisons préchargées dans les requêtes de fiche et de liste |
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
sous `/assets/uploads/<dossier>/`. Les caches des lectures publiques sont invalidés (étiquettes de
`LandingContentSpec` + `CacheInvalidationSubscriber`). Code : `LandingContentSpec` (liste blanche, `localeField`,
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
- Statuts : 400 corps illisible, 404 variante ou transporteur inconnu, 422 règle non respectée ; rien n'est réservé.
- `order/create` : `orderSource`, `typeOrder` et `paymentMethod` facultatifs (vente en ligne par défaut) ;
  `carrierId` nullable. `order/create-guest` : 403 si `commerce.guestCheckout` est `false` dans les réglages de la
  boutique (`BoutiqueSettingsService::isGuestCheckoutAllowed`). `GET /api/booking/check/{id}` : **409** avec
  `remaining_stock` quand la quantité n'est pas disponible (200 auparavant).
- Tests : `tests/Functional/Boutique/CartQuoteApiTest.php`.

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
- `POST /api/order/create` (client) et `/api/order/create-guest` (invité) : **idempotents**. Premier appel : 201
  `{ success, orderId, guestToken? (invité), alreadyCreated: false }`. Appel suivant pour le même paiement : **200** et la
  même commande (`alreadyCreated: true`), avec le `guestToken` pour l'invité **à la même adresse e-mail** ; paiement d'un
  autre client ou autre e-mail : 409. Deux appels simultanés (navigateur et webhook) : réservation atomique de la trace
  (`open` → `processing`), le second attend la commande (8 s au plus, sinon 409 « en cours »). Montant autorisé ≠ total
  calculé (1 centime de tolérance), stock insuffisant : 400 et **autorisation annulée** (client non débité) ; donnée
  invalide (adresse, transporteur) : l'autorisation reste, le navigateur peut corriger. Personnel sans paiement Stripe
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
  est **repris** (200, même `clientSecret`, `reused: true`) ; un incomplet d'une autre formule est annulé ; un abonnement
  en cours (essai, actif, impayé, en pause) au même produit : **409**.

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
- **`GET /api/ordersuser`** : montants en **cents entiers** (`totalAmount`, `subTotal`, `priceTax`, `priceShipping`,
  lignes `unitPrice`, `totalPrice`), `currency`, `statusId`, `carrier { id, name }`, lignes avec `productVariantId`,
  `booking { start, end, status, rateId }` et `customizationId` (combinaison de `GET /api/customization/config/{variantId}`
  envoyée dans l'article du devis et de la commande ; vérifiée contre la variante, 422 sinon ; colonne
  `order_items.customization_id`, migration `Version20261009150000`).
- Tests : `tests/Functional/Boutique/CustomerAccountApiTest.php`.

## Abonnements (09/10/2026, § 11)

Stripe Billing sur le compte connecté du site. Code : `Controller/SubscriptionController/`, `UseCase/SubscriptionUseCase/`
(`ListSubscriptionPlansUseCase`, `ManageSubscriptionUseCase`, `HandleSubscriptionWebhookUseCase`),
`Services/SubscriptionService/` (`SubscriptionService`, `SubscriptionStripeGateway` derrière
`SubscriptionStripeGatewayInterface` — `tests/Fake/FakeSubscriptionStripeGateway` en test —, `SubscriptionOrderFactory`,
`SubscriptionMailer`), entités `SubscriptionPlan` et `Subscription`, DTO `SubscriptionPlanOutputDto`,
`SubscriptionOutputDto`, EasyAdmin « Formules d'abonnement » et « Abonnements » (lecture). Schéma : tables
`subscription_plan`, `subscription`, colonnes `order.subscription_id`, `order.stripe_invoice_id`,
`user.stripe_customer_id` (migration `Version20261009130000`, script `scripts/migrate_all_v2_subscriptions.sh`) ;
`subscription.shipping_amount` et `order_items.customization_id` (migration `Version20261009150000`, script
`scripts/migrate_all_v2_subscription_shipping_customization.sh`).

| Route | Accès | Contenu |
|---|---|---|
| `GET /api/subscription-plans?productId=&locale=` | public | formules actives (sans `productId` : toutes celles de la boutique, produits encore vendus par abonnement ; grille de formules `PlanGrid`) `{ id, productId, productName, name, interval (week\|month\|year), intervalCount, price (cents), currency, trialDays, minimumTerms, active, description (sous-titre), features (liste d'avantages, 20 au plus), highlighted (formule recommandée, une par produit), badge (texte du badge ou null) }`, textes en clair dans la langue demandée |
| `POST /api/subscriptions { planId, quantity?, addressId?, carrierId? }` | client connecté | 201 `{ subscriptionId, clientSecret, status, subscription }` ; abonnement Stripe créé en `default_incomplete`, première facture payée par le `clientSecret` (Payment Element) ; `trialDays` > 0 → `trialing` (carte enregistrée, `clientSecret` du SetupIntent) ; 403 si `commerce.subscriptionsEnabled` est `false` ; 404 formule ; 422 produit sans abonnement, quantité, adresse d'un autre client |
| `GET /api/subscriptions`, `GET …/{id}` | client connecté | `{ id, plan, quantity, status, currentPeriodEnd, cancelAtPeriodEnd, address, carrier, createdAt, canceledAt, orders: [{ id, reference, date, total, status }] }` ; autre client : 404 |
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
  `invoice.payment_failed` → `past_due` + courriel ; `customer.subscription.*` → état recopié, courriel de résiliation.
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
- Tests : `tests/Functional/Boutique/SubscriptionApiTest.php` (Stripe simulé : souscription, gestion, webhooks, refus).

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
  `ReviewAggregateService` : moyenne du produit et caches ; `ReviewSettingsProvider`), `UseCase/ReviewUseCase/`,
  `Controller/ReviewController/ReviewController`, `Dto/ReviewInputDto`, `Dto/ReviewOutputDto`, EasyAdmin
  `ReviewCrudController`, `ReviewSettingCrudController`. Limite `review_submit` (`rate_limiter.yaml`). Migration
  `Version20261009170000`, script `scripts/migrate_all_v2_reviews.sh`.
- Démo : 13 avis publiés (dont des notes basses, certaines avec réponse) d'auteurs fictifs `@example.invalid`, plus un
  avis vérifié du client de démonstration sur les produits qu'il a commandés ; politique affichée
  (`BoutiqueDemoCatalog::REVIEWS`, `REVIEW_POLICY`).
- Tests : `tests/Functional/Boutique/ReviewApiTest.php`.
- À venir (seconde version) : photos, votes « utile », signalement, courriel d'invitation après livraison.

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
