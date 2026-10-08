# Boutique réglable : demandes au backend (version complète du 08/10/2026, après les lots 1 à 10 du front)

Message préparé par le frontend (`Izzipro_next`) pour le backend (`izzipro_backend_symfony_v7`, branche `dev`). Il accompagne la refonte de la boutique en mode réglable, calquée sur la landing page (plan complet côté front : `docs/boutique-reglable-plan.md`). Rien n'est en production côté boutique : aucune compatibilité à préserver, les anciens réglages et composants disparaissent.

**État côté front (08/10/2026)** : les lots 1 à 10 sont faits et commités (non poussés). Toute la boutique est composée de blocs réglables avec le même moteur que la landing page : fiche produit (avec fiches alternatives par type), catalogue, panier, paiement (Stripe), confirmation, compte (commandes, adresses, transporteurs, profil, détail de commande, formulaire d'adresse), connexion avec code, inscription, mot de passe oublié, personnalisation (options à combinaisons), demande de financement, accueil (diaporama, cartes explorer, catégories, atouts, infolettre, carrousel de produits), navbar et pied de page. Les anciennes vues sont supprimées (314 fichiers). Le front attend du backend les points ci-dessous ; **sans la route § 2, les réglages de la boutique vivent dans un brouillon du navigateur** et rien n'est partagé entre administrateurs.

**Résumé des points, par priorité** : § 2 (route des réglages, bloquante), § 1 (synchronisation du contrat, automatique au déploiement), § 10 (jeu de données de démonstration pour tester), § 13 (assistant IA), § 3 à § 5 (contrat produit, réservation, devis), § 9 (écriture des données depuis la page), § 8, § 6, § 7, § 11 (abonnement, module entier), § 12, § 14 (routes utilisées et retirables).

Chaque point se termine par ce qui est attendu en retour (une confirmation, une forme de réponse, ou une date). Les numéros servent à répondre point par point. Les montants sont en **cents** sauf mention contraire.

## 1. Configuration partagée (schéma, catalogue IA, libellés)

Le front reste la source du contrat : à chaque build il publie dans `public/reglable-config/` le schéma des compositions, le catalogue IA, le jeu d'essai, les libellés du panneau et `manifest.json`, et le déploiement appelle `POST /api/landingpage-config/sync`.

**Fait côté front le 08/10/2026 (lot 10)** : le catalogue IA (version 2) contient les familles de la boutique (`app: "boutique"`), les modèles de navbar et de pied de page de la boutique, et `systemPages` (pages système avec leurs blocs obligatoires) ; la console IA et l'assistant de section sont les mêmes composants que sur la landing page, et appellent les mêmes routes `POST /api/landingpage-ai/compose` et `GET /api/landingpage-ai/jobs/{id}` avec les `componentKey` de la boutique. La boutique utilisera **le même schéma**, **le même catalogue IA** et **le même fichier de libellés** : ses nouveaux types de blocs (galerie produit, prix, calendrier de réservation, panier, paiement…) et ses familles y seront ajoutés. Aucun fichier nouveau dans le manifeste.

À confirmer :
- a. La synchronisation recontrôle aussi **toutes les compositions de la boutique** (navbar, footer, onglets, pages système, modèles personnels, voir § 2) avec le nouveau schéma, et bloque de la même façon (409 avec `errors`).
- b. Un nouveau type de bloc de la boutique n'a besoin **que** du schéma publié : rien à déclarer à la main côté backend.
- c. Si un fichier propre à la boutique devenait nécessaire dans le manifeste, sous quel nom l'accepteriez-vous ? (Aujourd'hui : aucun prévu.)

## 2. Réglages de la boutique

Demande : une route de réglages propre à la boutique, de même forme que `landingpage-settings`, validée par le même schéma (`reglableConfig` de chaque section) et inscrite dans le journal (`landingpage-audit` ou équivalent).

Forme **réellement envoyée par le front** (`GET /api/boutique-settings` → `{ configuration }` ou l'objet nu ; `PUT /api/boutique-settings` `{ configuration }`, code `src/theme/Boutique/boutiqueSettingsService.js` et `boutiqueConfig.js`) :

```
configuration: {
  navbar: { componentTypeKey: 'typeReglable', reglableConfig },
  footer: { componentTypeKey: 'typeReglable', reglableConfig },
  tabs: [
    // onglets du menu, composés par l'administrateur
    { id, title: { fr, en }, isVisible: true, sections: [ { id, name?, componentKey, componentTypeKey: 'typeReglable', dataType, reglableConfig } ] },
    // pages système : mêmes onglets, cachés du menu, repérés par `system` (adresse fixe) ; `variant` = fiche produit alternative
    { id: 'sys-product', system: 'product', isVisible: false, title: { fr, en }, sections: [ … ] },
    { id: 'sys-product-vehicle', system: 'product', variant: 'vehicle' | 'rental' | 'subscription' | 'customizable', isVisible: false, sections: [ … ] },
    { id: 'sys-customization', system: 'customization', … }, { system: 'catalogue' }, { system: 'cart' }, { system: 'checkout' }, { system: 'confirmation' },
    { system: 'account' }, { system: 'address' }, { system: 'order' }, { system: 'financing' }, { system: 'login' }, { system: 'register' }, { system: 'forgotPassword' }
  ],
  reglablePresets: [ … ],                                            // modèles personnels, même forme que la landing page
  charter: { primaryColor, accentColor, textColor, backgroundColor, headingFont, bodyFont, radius, buttonStyle: 'solid'|'outline'|'pill' },
  commerce: { guestCheckout: bool, currency: 'CAD', subscriptionsEnabled: bool }
}
```

Les pages système et leurs blocs obligatoires sont publiés dans le catalogue IA (`systemPages`, § 1 et § 13) : `product` (`modeGroup`), `customization` (`optionGroups`, `customAddToCart`), `catalogue` (`productList`), `cart` (`cartLines`, `cartTotals`), `checkout` (`stripePayment`), `confirmation` (`orderConfirmation`), `account` (`orderList`), `address` (`addressForm`), `order` (`orderDetail`), `financing` (`financingForm`), `login` (`loginForm`), `register` (`registerForm`), `forgotPassword` (`passwordResetForm`).

Demande complémentaire : un **historique** de ces réglages avec restauration, comme `landingpage-settings` (la carte « 🕘 Historique du site » du panneau est prête à être branchée dès que la route existe : `GET /api/boutique-settings/history`, `POST /api/boutique-settings/history/{id}/restore`, ou les noms que vous choisissez).

- a. Acceptez-vous une route séparée (`/api/boutique-settings`), ou préférez-vous étendre `landingpage-settings` d'une clé `boutique` ? Le front préfère la route séparée (deux applications indépendantes, deux panneaux).
- b. Les **pages système** ont une adresse fixe et des blocs obligatoires (la page de paiement doit contenir le bloc de paiement). Le front validera ; souhaitez-vous aussi le valider côté serveur ? Si oui, le front fournira la liste `{ page: [types de blocs obligatoires] }` dans la configuration publiée (§ 1).
- c. Modèles de site de la boutique : même mécanisme que `/api/landingpage-site-models` (route `/api/boutique-site-models` ?).
- d. Sort de `GET/PUT /admin-settings` et de `/admin-settings/presets` : la boutique ne les utilise plus (lot 9 fait) ; seul `AdminContext` les lit encore pour le thème du carrousel de produits de la landing page (`themeChoice`) et le bouton ⚙️. Le front les retirera quand vous le direz ; et **aucun modèle (preset) existant n'est repris** (pas de boutique en production). Vous pouvez les retirer quand la refonte sera déployée ; dites-nous si vous voulez les garder pour autre chose.

## 3. Modes et options d'un produit (fin des déductions par le nom)

Aujourd'hui le front devine le mode et les cas particuliers d'un produit d'après **des mots dans le nom du produit, des catégories ou du tarif** (`artifice`, `motomarine`, `sea-doo`, `spark`, `gti`, `remorque`, `trailer`, `bateau`, `capitaine`, option nommée « Prix », codes `taille` / `couleur`). Tout cela disparaît : il faut des champs explicites.

Côté front, les modes sont des composants de la boîte à outils posés sur la page produit (plan § 3.7, validé le 08/10/2026) : la composition dit quels modes la page offre, **la fiche produit dit lesquels ce produit propose**. D'où les champs ci-dessous. Un produit peut cumuler plusieurs modes (vente et location, par exemple).

Demande (forme proposée, validée côté front ; **le front la lit déjà** : `commerce/productMode.js` applique `kind`, `sale.enabled`, `rental.enabled`, `subscription.enabled`, `customizable` dès qu'un produit les porte, sinon il retombe sur les anciennes déductions) :

```
product: {
  kind: 'standard' | 'vehicle',             // remplace productType / vehicleDetails pour choisir la fiche
  sale:         { enabled: bool },          // vente (mode 'retail' actuel)
  rental:       { enabled: bool, … bookingConfig ci-dessous },   // location (mode 'booking' actuel)
  subscription: { enabled: bool },          // voir § 11
  customizable: bool,                       // bouton « Personnaliser » (aujourd'hui : « plus d'un variant »)
  variants: [ { id, price?: cents, stockQuantity, options: [ { code, name, value } ] } ]  // prix de variant explicite, plus d'option « Prix »
}
```

- a. Un produit peut être à la fois en vente et en location (deux options activées) : le front le prévoit. Dites-nous si le modèle actuel (`mode` unique) l'interdit et ce qu'il faut pour le permettre.
- b. Un seul nom de champ pour la configuration de réservation (`bookingConfig`, pas `bookingConfiguration`) et pour les catégories (`categories`, pas `category`), les images (`pictures[]`), dans **toutes** les routes qui renvoient un produit.
- c. Les véhicules : peuvent-ils être servis par `/products/by-slug/{slug}` (avec `vehicleDetails` et `kind: 'vehicle'`) pour que le front abandonne la cascade actuelle (`by-slug` → `/vehicle_products` → `/products/{id}` → `/vehicle_products/{id}` → `/products/by-category?limit=250` → `/productsid/{id}`) ?

## 4. Réservation (location)

Ce que le front applique aujourd'hui en dur, à faire venir du backend (`bookingConfig` du produit, ou de sa catégorie) :

```
bookingConfig: {
  granularity: 'hours' | 'days',
  minDuration, maxDuration, stockQuantity, bufferTime (minutes),
  rates: [ { id, name, hourRate, halfDayRate, dayRate, weekRate, monthRate } ],   // cents
  openingHours: { start: '09:00', end: '18:00' },            // aujourd'hui 09:00-18:00, fermeture 19:00
  halfDays: [ { label, start: '09:00', end: '13:00' }, { label, start: '14:00', end: '18:00' } ],
  allowedDates?: [ 'YYYY-MM-DD' ],                            // aujourd'hui 8 dates 2026 en dur pour les feux d'artifice
  eveningSlot?: { start: '20:30', end: '23:00' },             // idem
  minDaysStandard: 2,                                         // aujourd'hui : 2 jours minimum à la journée (carte E seulement)
  deposit: cents,                                             // caution (1 500 $, 500 $, 5 000 $ en dur selon le cas)
  extraPassengerFee?: cents,                                  // 25 $ par passager en dur
  arrivalLeadMinutes: 45,                                     // 20, 30 ou 45 selon l'écran aujourd'hui
  cancellationPolicy?: texte riche,                           // « aucun remboursement dans les 48 h »
  included?: [texte], excluded?: [texte], notes?: texte riche // essence, nourriture, FAQ…
}
```

- a. Quelles sont les règles de calcul que **vous** appliquez pour le prix d'une réservation ? Le front envoie aujourd'hui `booking.price = tarif × durée` avec `duration_type ∈ {hour, half_day, day, week, month}` ; la semaine est facturée 6 × `weekRate` et le mois 29 × `monthRate` (durée arrondie), ce qui est probablement faux. **Demande : le backend recalcule le prix et le front n'envoie que `{ start, end, rateId, quantity }`.** Confirmez la forme attendue de `booking` dans `create-intent` et `order/create`.
- b. `GET /booking/check/{id}` : en 409, renvoyer aussi `remaining_stock` (le front affiche « il ne reste que undefined place(s) »).
- c. `GET /booking/calendar/{id}?start&end` : fonctionne-t-il en mode jour (plages multi-jours) ? Le front ne l'appelle aujourd'hui qu'en mode heure, et génère lui-même une grille 09:00-18:00 si vous renvoyez un seul créneau « journée ».
- d. `minDuration` et `maxDuration` sont-ils appliqués côté serveur ? (Le front les affiche sans les appliquer.)
- e. Quantité maximale d'une ligne (stock du variant, `stockQuantity` de la réservation) : vérifiée côté serveur à la commande ? Le front n'a aucun plafond aujourd'hui.

## 5. Totaux, taxes, unités

Aujourd'hui : taxe **15 % codée en dur** (20 % sur certains écrans), sous-total + livraison, et unités mélangées (`priceShipping` en dollars pour `create-intent`, en cents pour `order/create` ; `shipping/summary` renvoie des dollars).

Demande, par ordre de préférence :
- a. **Un devis calculé par le serveur** : `POST /api/cart/quote` `{ items: [{ productVariantId, quantity, booking?, customizationId? }], shippingAddress?, carrierId?, service? }` → `{ lines: [{ …, unitPrice, total }], subtotal, shipping, taxes: [{ label, rate, amount }], deposit?, total, currency }`, en cents. Le front n'affiche que ce que vous renvoyez ; `create-intent` utilise le même calcul. Une seule source de vérité.
- b. À défaut : `GET /api/tax-rates?country&province` → `[{ label, rate }]`, et confirmation que `create-intent` recalcule le montant côté serveur (le front n'envoie aucun montant).
- c. **Cents partout** : `create-intent.priceShipping`, `order/create.priceShipping`, `shipping/summary[].totalPrice`. Confirmez le changement de `create-intent` et de `shipping/summary` (aujourd'hui en dollars), ou dites-nous lesquels restent en dollars.
- d. Transporteur « gratuit » : le front traite l'`id` 6 en dur comme « Livraison Standard, 0 $, 3-7 jours » et envoie `carrierId: 6` quand la commande ne contient que des locations. Demande : un champ `isFree` (et `estimatedDays`) sur `Carrier`, et `carrierId` **nullable** dans `order/create` pour une commande sans livraison.
- e. `paymentMethod` (1 Visa, 2 Mastercard, 3 Amex) est choisi à la main avant Stripe et ne sert à rien d'utile : peut-on le rendre facultatif, ou le déduire du `paymentIntent` ?

## 6. Paiement sans compte (invité)

Réglage `commerce.guestCheckout` (§ 2). Demande : `POST /order/create-guest` refusé (403) quand il est désactivé pour l'entreprise, pour que la règle tienne même sans le front. Le front masque le parcours invité de son côté.

Question : quelle est votre règle pour un invité qui loue (permis de conduire obligatoire) ? Le front l'exige aujourd'hui dans `guestInfo.licenseNumber` / `licenseExpirationDate` ; confirmez le nom des champs attendus dans `guestInfo`.

## 7. Devise

Aujourd'hui tout est `CAD` / `fr-CA` en dur côté front (avec des restes en USD et en EUR sur certains écrans), et `CurrencyContext` n'a pas de fournisseur.

Demande : la **devise de l'entreprise** dans sa fiche (`entreprise.currency`, code ISO) ou dans `/stripe-config` (`currency`), et tous les prix renvoyés dans cette devise. Le front formate selon devise et langue, sans conversion. Question : une entreprise peut-elle avoir plusieurs devises ? Si non, une seule par site suffit (recommandé).

## 8. Compte client et authentification

- a. **Nouveau mot de passe** : `POST /password-reset/request` envoie un lien ; vers quelle adresse du front doit-il pointer, et quelle est la route de confirmation (`POST /password-reset/confirm { token, password }` ?). Aucune page n'existe aujourd'hui côté Next.
- b. Détail d'une commande : une route `GET /orders/{id}` pour le client connecté (aujourd'hui cherché dans `GET /ordersuser`), et un accès pour un **invité** depuis la page de confirmation (lien avec jeton envoyé par e-mail ?).
- c. Statuts de commande : libellés ou liste (`GET /order-statuses`), aujourd'hui en dur côté front.
- d. `GET /ordersuser` : renvoyer les montants en cents avec la devise, et les lignes avec `booking` (dates) et `customizationId`.

## 9. Modifier les données de la boutique depuis la page (journal)

Exigence validée par l'utilisateur (08/10/2026) : comme pour la landing page (`docs/landingpage-edition-donnees.md`), le front doit pouvoir ouvrir dans la fenêtre « Modifier … » les fiches affichées par un bloc lié : **produit** (nom, description, « plus d'informations », prix, promo, images via la médiathèque), **catégorie** (nom, image), **diapositive** de l'accueil (`/homeslider` : `title`, `description`, `image`, `imageMobile`, `buttonMessage`, `buttonUrl`), **carte explorer** (`/explore-cards` : `standardTitle`, `differentTitle`, `description`, `imageUrl`, `link`, `videoUrl`). Les atouts n'ont plus de données backend (contenu libre composé) : `/features` n'est plus appelé. Demande : routes `PATCH` par langue inscrites dans le journal (`landingpage-audit`) avec restauration, sur le même modèle que les présentations. Dites-nous lesquelles sont possibles et dans quel ordre.

## 10. Site de démonstration

Pour tous les essais (le front local écrit en production) : le site `demo` a **la boutique active depuis le 08/10/2026**, mais aucun produit ni catégorie. Demande de l'utilisateur : **remplir la base de la boutique de démonstration pour couvrir tous les cas**, avec Stripe **en mode test** (et un compte connecté test capable d'encaisser : sur Kara & B, le compte test refuse les paiements, `requirements.disabled_reason`) :
- vente simple (un variant) ; vente avec variants (taille, couleur, prix de variant) ; produit personnalisable ;
- location à l'heure (`granularity: hours`, tarifs heure et demi-journée) ; location à la journée, semaine, mois ; location avec dates autorisées et créneau du soir ; location avec caution ;
- véhicule (`kind: vehicle`) ;
- un produit en vente **et** en location ;
- une formule d'abonnement (§ 11), en mode test de Stripe ;
- produits **en promotion** (prix spécial avec dates, dont une promo expirée), produits **épuisés**, produit avec plusieurs **photos**, produit avec **unité de vente** ;
- produit **avec options** (taille, couleur, et options à combinaisons : `/customization/config/{variantId}` rempli) ;
- deux transporteurs dont un gratuit ; une adresse ; un compte client de démonstration avec code de connexion ; une commande passée.
- **Photos et images réelles partout** (adresses servies par le backend de la v7, pas l'ancien domaine `backend-strapi.online`, dont les images ne répondent plus sur Kara & B) : chaque produit avec au moins une image, les variants de couleur avec leur image, les options de personnalisation avec leurs icônes et les combinaisons avec leur image.
- **Données de l'accueil** du site `demo`, pour que les familles composées aient de quoi s'afficher : 3 diapositives (`/homeslider`, avec image bureau et image mobile, bouton), 2 ou 3 cartes « explorer » (`/explore-cards`, image, lien vers une catégorie), 5 ou 6 catégories visibles avec image, et des produits marqués nouveautés, meilleures ventes, offres spéciales, en vedette et accessoires (sélections du carrousel `/products/{sélection}`).

Question : qui crée ces données (EasyAdmin ou fixtures), et à quelle date ? Des fixtures rejouables seraient préférables : le site `demo` est remis à zéro à la demande.

## 11. Module « Abonnement » (nouveau, à construire des deux côtés)

Rien n'existe. Résumé de la proposition du front (détail : `docs/boutique-reglable-plan.md` § 3.6) :
- Modèle : `SubscriptionPlan` (produit ou variant, `interval` week|month|year, `intervalCount`, prix en cents, devise, `trialDays`, `minimumTerms`, `stripePriceId`, `active`) et `Subscription` (client, formule, quantité, statut `trialing|active|past_due|paused|canceled`, `currentPeriodEnd`, `cancelAtPeriodEnd`, adresse et transporteur pour un produit physique, `stripeSubscriptionId`, commandes créées à chaque échéance).
- Stripe Billing sur le compte connecté de l'entreprise ; webhooks `invoice.paid`, `invoice.payment_failed`, `customer.subscription.updated`, `customer.subscription.deleted` ; e-mails (reçu, échec de paiement, annulation) ; gestion dans EasyAdmin ; écritures dans le journal.
- Routes : `GET /subscription-plans?productId=` ; `POST /subscriptions { planId, quantity, addressId?, carrierId? } → { subscriptionId, clientSecret }` ; `GET /subscriptions` ; `GET /subscriptions/{id}` ; `POST /subscriptions/{id}/cancel { atPeriodEnd }` ; `POST /subscriptions/{id}/resume` ; `POST /subscriptions/{id}/change-plan` ; `POST /subscriptions/portal-session` ; `POST /stripe/webhook`.
- Compte client obligatoire (pas d'invité). Essais avec les horloges de test de Stripe.

Choix validés par l'utilisateur le 08/10/2026 (décision D10) :
- produits physiques **et** services ;
- périodicités : semaine, mois, année (avec `intervalCount` pour « tous les N ») ;
- essai gratuit facultatif (`trialDays`) ; engagement minimal facultatif (`minimumTerms`) ;
- annulation **à la fin de la période** par défaut (`atPeriodEnd: true`), reprise possible avant la fin ;
- pause permise (statut `paused`) ;
- prorata au changement de formule (comportement Stripe par défaut) ;
- pas de panier mêlant achat et abonnement : la souscription est un parcours séparé ;
- carte et factures par le **portail client Stripe** (`/subscriptions/portal-session`), pas d'écrans dans le site ;
- sites concernés : tous les sites dont la boutique est active, module activable par site (`commerce.subscriptionsEnabled`).

Demande : valider ensemble ce contrat avant le lot 6 du front.

## 12. Divers (petits points relevés pendant l'audit)

- `/order/create` : `orderSource: 1` et `typeOrder: 1` sont envoyés en dur ; à quoi servent-ils, faut-il les garder ?
- `shipping/summary` attend `carriers: [carrierAccountId]` (pas l'`id` du transporteur) : confirmez.
- `/stripe/create-intent` : le `paymentIntent` peut revenir en `requires_capture` ; capturez-vous à la création de la commande ?
- Square (`/square-config`, `/payment`) et `/shipping/buy` : inutilisés par le front ; à retirer de votre côté si plus rien ne s'en sert.
- Descriptions HTML des produits : le front les nettoiera (DOMPurify). Appliquez-vous déjà la RichTextPolicy à `description` et `moreinformations` ?

## 13. Assistant IA de la boutique (lot 10 côté front, fait le 08/10/2026)

Côté front, l'assistant de section (« ✨ Assistant IA » du panneau) et la console IA (« ✨ Console IA ») sont **les mêmes composants** que ceux de la landing page, posés dans la mise en page de la boutique. Ils appellent les mêmes routes : `POST /api/landingpage-ai/compose` (modes `edit`, `create`, `page`), `GET /api/landingpage-ai/jobs/{id}`, `GET /api/landingpage-ai/usage`. Ce qui change pour vous :

- a. **Catalogue IA version 2** (`landingpage-ia-catalogue.json`, publié au build et synchronisé au déploiement, § 1) : 31 familles, 122 modèles. Chaque famille porte `app: "landingpage"` (commune, utilisable aussi sur les pages de la boutique) ou `app: "boutique"`. Familles de la boutique (`componentKey`) : `ProductPage`, `Catalogue`, `CartPage`, `Checkout`, `OrderConfirmation`, `AccountPage`, `AuthPage`, `Customization`, `Financing`, `HomeSlider`, `ExploreCards`, `CategoryList`, `Features`, `Newsletter`. Les familles `Navbar` et `Footer` ont des modèles supplémentaires `app: "boutique"` (badge du panier, menu compte, infolettre). Demande : **accepter ces `componentKey`** dans `compose` (contrôle « componentKey présent dans le catalogue » : rien d'autre à faire si vous lisez le catalogue synchronisé).
- b. `systemPages` (clé nouvelle du catalogue) : `[{ key, route, title, required }]`. Une composition d'une page système doit **garder ses blocs obligatoires** (`required`) : à faire respecter dans la validation de la réponse de l'IA (nouvel essai si un bloc obligatoire manque) et, si vous validez aussi les réglages (§ 2 b), dans `PUT /api/boutique-settings` (422).
- c. **Blocs du commerce** (types de blocs du schéma, préfixe sans ambiguïté) : `modeSwitch, price, optionPicker, quantity, addToCart, gallery, stock, rentalPack, bookingCalendar, deposit, techSpecs, share, customizeButton, optionGroups, customPreview, customPrice, customAddToCart, categoryPicker, pagination, cartLines, cartTotals, checkoutButton, cartBadge, checkoutIdentity, checkoutAddress, checkoutLicense, checkoutShipping, checkoutSummary, stripePayment, orderConfirmation, accountMenu, orderList, addressList, carrierList, profileForm, addressForm, orderDetail, newsletterForm, financingForm, loginForm, registerForm, passwordResetForm, resendVerificationForm`. Ils lisent les données de la page (produit, panier, compte) : l'IA ne règle que leur apparence et leurs options (`mode` d'un conteneur : `sale` | `rental` | `subscription` ; `optionStyle`, `showRegular`, `galleryStyle`, `pickerStyle`, `showImages`, `showShipping`, `showTaxes`, `badgeStyle`, `showDelta`, `saleLabel`…, tous décrits dans le schéma). Consigne à ajouter au prompt système : *ne jamais inventer de données de produit ou de prix dans un bloc du commerce ; les textes libres restent dans des blocs titre/texte/bouton*.
- d. Listes répétées de la boutique (`repeat.source`) : `products` (catalogue), `slides` (diaporama de l'accueil), `cards` (cartes explorer), `categories` (catégories), en plus de celles de la landing page.
- e. Libellés cités par l'assistant (« Ce que je ne peux pas faire ») : section « Boutique réglable » de `ia-libelles-editeur.md` (publié, 40 000 octets au plus : nous sommes en dessous ; dites-nous si la limite doit monter).
- f. Quota : le même crédit IA par site couvre landing page et boutique (site `demo` : 1 000 crédits par mois). Confirmez, ou dites si vous voulez un compteur séparé.
- h. **Champ `app` dans chaque requête `compose`** (fait côté front le 08/10/2026) : `app: "landingpage" | "boutique"`, application d'où part la demande (panneau de la landing page ou panneau de la boutique). Demande : choisir d'après ce champ le catalogue et les libellés à mettre dans le prompt (catalogue boutique séparé si l'option § 1 c est retenue), et refuser un `componentKey` qui n'appartient pas à cette application. Un backend qui ignore le champ fonctionne comme aujourd'hui.
- g. Jeu d'essai : `ia-assistant-jeu-essai.md` n'a pas encore de cas « boutique » ; le front en ajoutera 6 (fiche produit achat et location, panier, page d'accueil complète, navbar boutique, page système incomplète refusée) à votre demande, avant la mise en service.

## 14. Routes utilisées par la boutique réglable, et routes retirables

Appelées par les blocs et contextes du front (à garder, formes actuelles) : `GET /entreprise/{id}`, `PUT /entreprise/{id}` ; `GET /category`, `/products/by-category`, `/products/by-slug/{slug}`, `/products/{sélection}` (carrousel : `bestsellers`, `newarrivals`, `specialoffers`, `isfeatured`, `isAccessory`), `/products/{id}`, `/productsid/{id}`, `/vehicle_products`, `/vehicle_products/{slug}`, `/vehicles/carousel`, `/customization/config/{variantId}` ; `GET /booking/calendar/{id}`, `/booking/check/{id}` ; `GET /homeslider`, `/explore-cards` ; `POST /newsletter/subscribe`, `/financement/submit` ; `GET /Carrier`, `POST /shipping/summary` ; `GET /stripe-config`, `POST /stripe/create-intent`, `/order/create`, `/order/create-guest`, `GET /ordersuser` ; `GET/POST /adresses`, `PUT/DELETE /adresses/{id}`, `GET /adresses/autocomplete`, `/adresses/details` ; `POST /register`, `/otp-verify`, `/resend-verification`, `/password-reset/request`, `GET/PATCH /profile` ; `GET/PUT /boutique-settings` (§ 2, à créer).

Plus appelées par la boutique (retirables quand vous voulez, après vérification de vos autres clients) : `GET /features` ; `GET /square-config`, `POST /payment` (Square) ; `POST /shipping/buy` ; `GET/PUT /admin-settings` et `/admin-settings/presets` (voir § 2 d, encore lus par `AdminContext` pour la landing page).

## 15. Ce que le front attend en retour

Une réponse point par point (numéros ci-dessus), avec pour chaque route : nom définitif, forme de la réponse, date de livraison sur `dev`. Ordre souhaité : § 2 → § 10 → § 1 a → § 13 a-b → § 3 → § 5 → § 9 → le reste. Le front est prêt à adapter ses modules purs (`src/components/Boutique/commerce/*.js`, testés) dès réception du contrat ; aucune interface n'est à refaire.

