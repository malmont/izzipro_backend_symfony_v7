# Application de gestion : audit et mise au niveau (cadrage du 09/11/2026)

Périmètre : les routes de gestion consommées par l'application IIZIMANAGER (.NET MAUI, puis clone web) :
collections, commandes fournisseurs, produits d'une commande, frais de port, fournisseurs, notes de frais, tableaux
de bord (commande, collection, combiné), statistiques (chiffre d'affaires, nombre de commandes, panier moyen,
paiements, taxes, transporteurs). Code : `Controller/{CommandeController, CollectionsController, FraisDePortController,
NoteDeFraisController, FournisseurController, TransporteurController, DashboardCommandeController,
DashboardListCollectionController, StatistiqueDashboard}`, services et use cases du même nom, entités `Collections`,
`Commande`, `Product` (partie achat), `FraisDePort`, `NoteDeFrais`, `Fournisseur`, `Transporteur`,
`CommandeStatistiques`, `CollectionStatistiques`.

Décision du propriétaire (09/11/2026) : le gel de la surface `/api/commandes` est levé ; l'application de gestion,
la plus ancienne, doit être mise au niveau des autres (caisse, boutique, applications mobiles) : **montants en cents,
devise du site, langue du site, fuseau du magasin, codes d'erreur stables, tests fonctionnels**.

## 1. Normes de la plateforme à appliquer (déjà en place ailleurs)

| Norme | Référence dans le code | État dans la gestion |
|---|---|---|
| Montants stockés et échangés en **cents** (entiers) | `docs/boutique.md` « Tous les prix sont stockés en cents » ; `ProductCommerceDto::cents` ; EasyAdmin `MoneyField::setStoredAsCents(true)` ; caisse et commandes clients (`Order.totalAmount`, `Payments.amount`) | Non : budgets, frais de port et notes de frais en unités monétaires ; prix d'achat des produits en cents |
| **Devise du site** (ISO 4217, verrouillée après la première vente) | `TenantCurrencyProvider::code()` ; exposée par `GET /api/app-profile/pos` (`currency`), `GET /api/entreprise`, `GET /api/boutique-settings` | Non : aucune réponse de gestion ne porte la devise ; EasyAdmin affiche `USD` (commandes, collections) et `EUR` (frais de port, notes de frais) codés en dur |
| **Langue** : `?locale=` (`fr` \| `en`), traductions d'entité, libellés dans `translations/*.yaml` | `EntrepriseTranslation`, `CarrierTranslation`, `CategoriesTranslation`… ; courriels `EmailLocale` | Non : `getProductsByCommande` fige `'fr'` ; noms de mois en anglais codés en dur dans les statistiques ; référentiels (`TypeFournisseur`, `TypeNoteDeFrais`) non traduits ; messages d'erreur en anglais ou en français selon la route |
| **Fuseau du magasin** pour les bornes de période | `StoreClock` (caisse, F14) ; `PaymentsRepository` déjà converti (01/11/2026) | Partiel : `StatistiqueRevenuService`, `StatistiqueCommandeService`, `StatistiquePanierService`, `CarrierStatisticsService` utilisent `new DateTime()` (heure du serveur, UTC) |
| **Codes d'erreur stables** `MAJUSCULES_SOULIGNÉES` + `message` | `docs/caisse.md` « Codes d'erreur de la caisse » ; `docs/api` memory (`VALIDATION_FAILED`, `PRODUCT_NOT_FOUND`…) | Non : `{"error": "Invalid JSON"}`, `{"success": "Commande created"}`, 500 avec le message de l'exception, 404 `No shipping cost associated with this order` |
| **Validation des entrées** (422 avec le champ en cause) | `Assert` + réponses 422 détaillées (caisse, boutique) | Non : aucune validation ; `budget`, `date`, `name` absents → erreur PHP (500) ; `userId` d'une collection pris dans le corps JSON, pas dans le jeton |
| **Tests fonctionnels** par route | `tests/Functional/*` (843 tests) | Quasi nulle : seule `Cache/OrdersAchatsCacheInvalidationTest` touche ces routes ; aucun test de calcul |
| Couches Repository > Service > UseCase > Controller > DTO | `docs/architecture.md` | Respectées dans l'ensemble (code ancien mais structuré) |

## 2. Constats détaillés

### 2.1 Unités monétaires mélangées (bloquant pour les calculs)

| Donnée | Colonne | Unité réelle aujourd'hui | Preuve |
|---|---|---|---|
| Prix d'achat d'un produit | `product.purchase_price` (float) | **cents** | `ProductCrudController` : `MoneyField('purchasePrice')->setStoredAsCents(true)` ; `Product::updatePrice()` dérive `price` (cents, boutique) = `purchasePrice × coefficientMultiplier` |
| Prix de vente | `product.price` | cents | `docs/boutique.md`, `ProductCommerceDto` |
| Budget d'une commande | `commande.budget` (float) | **unités monétaires** | `CommandeCrudController` : `setStoredAsCents(false)`, devise `USD` |
| Budget d'une collection | `collections.budget_collection` (float) | unités monétaires | `CollectionsCrudController` : idem |
| Frais de port d'une commande | `frais_de_port.price` (float) | unités monétaires | `FraisDePortCrudController` : `setStoredAsCents(false)`, devise `EUR` |
| Note de frais | `note_de_frais.montant` (float) | unités monétaires | `NoteDeFraisCrudController` : idem, `EUR` |
| Statistiques figées | `commande_statistiques.*`, `collection_statistiques.*` | mélange (résultat des calculs ci-dessous) | |

Les API de gestion ne convertissent rien : la valeur reçue est écrite telle quelle (`ProductInputDTO`,
`CommandeService::createCommande`, `FraisDePortInputDTO`, `NoteDeFraisInputDTO`, `CollectionInputDTO`). L'application
MAUI envoie « tel que saisi » : un prix d'achat saisi `12.50` donne un prix de vente boutique de `0,125 × coefficient`
sur le site web.

### 2.2 Calculs des tableaux de bord faux par construction

`CommandeDashboardService::calculateCommandeMetrics` et `CollectionDashboardService::calculateDashboardMetrics` :

1. `totalItemCost` et `usedBudget` additionnent `product.price` (prix de **vente** = achat × coefficient) × quantité, pas
   le prix d'achat : le « budget utilisé » est un chiffre de vente.
2. `stockValue = totalItemCost × coefficient` (commande : coefficient moyen ; collection : coefficient du produit) :
   le coefficient est appliqué **deux fois** (déjà dans `price`).
3. `usedBudget` ajoute des frais de port et des notes de frais en unités monétaires à des produits en cents.
4. `marge`, `tauxMarge`, `tauxMarque` découlent de 1 à 3.
5. `CombinedCollectionDashboardService` additionne les `tauxMarge` de chaque collection puis les recalcule : la somme
   intermédiaire est inutile ; `averageMultiplier` est une moyenne de moyennes non pondérée.
6. Tout est en `float` PHP (pas d'arrondi contrôlé) ; les statistiques figées (`close`) conservent ces valeurs.

Note : si `commande_cost_cents` (lot « rattachement », § 4) est posé, le calcul doit lire **le coût d'achat** (jamais
`price`) : `commande_cost_cents ?? purchase_price`.

### 2.3 Devise et langue absentes

- Aucune réponse de gestion ne dit la devise ; l'application affiche ce qu'elle veut. Norme : `currency` (code ISO) dans
  chaque réponse qui porte un montant, comme `GET /api/app-profile/pos`.
- `ProductService::getProductsByCommande` appelle `ProductDetailedOutputDTO` avec `'fr'` codé en dur (les autres
  routes produits lisent `?locale=`).
- `CarrierStatisticsService`, `StatistiqueCommandeService`, `StatistiqueRevenuService`, `StatistiquePanierService` :
  noms de mois en anglais (`format('F')`, tableau `January…`) ; norme : la clé numérique du mois (`1..12`) ou une date
  ISO, l'application traduit.
- `TypeFournisseur.name`, `TypeNoteDeFrais.name` : référentiels non traduits (une seule langue en base), alors que
  `Carrier`, `Categories` ont une table de traduction.
- Messages : `Commande created`, `Fournisseur supprimé avec succès`, `La collection a été figée avec succès.` : mélange
  d'anglais et de français, à remplacer par des codes (l'application traduit).

### 2.4 Fuseau horaire

`StatistiqueRevenuService`, `StatistiqueCommandeService`, `StatistiquePanierService`, `CarrierStatisticsService` et
`TaxController` bornent semaines, mois et années avec `new DateTime()` (fuseau du conteneur, UTC) ; la caisse et
`PaymentsRepository` utilisent `StoreClock` (fuseau du magasin, `America/Toronto` par défaut). Pour une même journée,
`GET /api/payments/statistics/pos` et `GET /api/statistiques/chiffre-affaires/pos` ne couvrent pas les mêmes heures.
`getTotalRevenueBetweenDates` compare `o.orderDate` à des chaînes `Y-m-d 00:00:00` (heure murale), piège déjà
documenté (`docs/caisse.md`, « Doctrine wall-time »).

### 2.5 Contrat HTTP irrégulier (corrigé par le lot G4, sauf la forme API Platform de `GET /api/commandes`, inutilisée par l'application)

| Route | Constat | Norme |
|---|---|---|
| `POST /api/createcollections` (chaîne « Collection creer avec succes »), `POST /api/collections/{id}/commandes`, `POST …/frais-de-port`, `POST …/notes-de-frais`, `POST /api/transporteurs` | 201 avec un message sans l'objet créé ni son `id` : l'application doit relire la liste | 201 avec l'objet créé (seul `POST /api/fournisseurs` rend un `fournisseur_id`) |
| `DELETE /api/commandes/{id}/frais-de-port`, `DELETE /api/notes-de-frais/{id}`, `DELETE /api/fournisseurs/{id}`, `DELETE /api/transporteurs/{id}` | 204 avec un corps que Symfony vide ; `DELETE /api/collections/{id}` et `DELETE /api/products/{id}` répondent 200 avec un corps | une seule convention : 204 sans corps |
| `GET /api/commandes/{id}/frais-de-port` | 404 quand la commande n'a pas de frais (cas normal) | 200 `null` ou 200 `{}`, 404 réservé à la commande inconnue |
| `POST /api/commandes/{id}/frais-de-port` sur une commande qui en a déjà un | contrainte unique (OneToOne) → 500 | 409 `SHIPPING_COST_ALREADY_SET`, ou remplacement explicite (`PUT`) |
| `POST /api/dashboard/collection/{id}/close` | **n'a jamais fonctionné** : `CloseCollectionUseCase` lit `$this->dashboardCollectionUseCase` sans l'injecter → 400 `Undefined property` (prouvé par le test G0 le 09/11/2026) | corrigé dans G1 : injection de `DashboardCollectionUseCase` |
| `DELETE /api/commandes/{id}/frais-de-port` | **supprimait la commande et ses produits** (`FraisDePort::commande` portait `cascade: ['remove']`, chaîne frais → commande → produits ; prouvé par le test G0 le 09/11/2026 ; aucune donnée touchée, tables vides) | corrigé dans G1 : plus de `remove` en cascade |
| `POST /api/commandes/{id}/products` | aucune `quantity` reçue (le produit est créé sans quantité : les tableaux de bord comptent 0 article tant que l'application ne la pose pas par `PATCH /api/products/{id}`) ; toute exception → 500 avec le message brut (`Style not found`, `Category not found`) ; `purchasePrice` avec virgule → `TypeError` 500 | 404 `STYLE_NOT_FOUND`, `CATEGORY_NOT_FOUND`, 422 `VALIDATION_FAILED` |
| `POST /api/createcollections` | `userId` lu dans le corps : un administrateur peut créer une collection au nom d'un autre utilisateur ; `budgetCollection` absent → 0 sans erreur | propriétaire = utilisateur du jeton |
| `POST /api/collections/{id}/commandes` | `data['fournisseur']['id']` inconnu → `null` passé à `setFournisseur` → 500 ; `date` absente → 500 | 404 `FOURNISSEUR_NOT_FOUND`, 422 |
| `DELETE /api/collections/{id}` | aucune vérification : les commandes de la collection gardent une clé étrangère → violation (500) ou orphelins | 409 `COLLECTION_NOT_EMPTY`, ou suppression logique (`del` existe déjà et n'est pas utilisé) |
| `DELETE /api/commandes/{id}` (API Platform) | `cascade: remove` sur `products` : supprime les **produits de la boutique** rattachés, y compris ceux qui ont des lignes de commande client, des variantes, des avis | détacher (`commande = null`) ou 409 `PRODUCTS_SOLD` |
| `DELETE /api/fournisseurs/{id}` | fournisseur avec commandes → violation de clé (500) | 409 `FOURNISSEUR_IN_USE` |
| `POST /api/dashboard/commande/{id}/close`, `…/collection/{id}/close` | figent des chiffres faux (§ 2.2) ; déjà fermé → 400 `{"error": "La commande est déjà figée."}` (message en français, pas de code) | recalcul avec les règles corrigées, 409 `ALREADY_CLOSED` |
| `GET /api/commandes` (API Platform, `#[ApiResource]` sans groupes) | sérialise l'entité entière (produits, statistiques, fournisseur, image) : lourd et différent des DTO des autres routes | groupes de sérialisation ou retrait au profit des contrôleurs dédiés (décision propriétaire : MAUI utilise `GET/POST/PUT/PATCH/DELETE`) |
| `PUT /api/notes-de-frais/{id}`, `DELETE …` | rôle `ROLE_USER_INTERNET ou ROLE_USER_POS ou …` avec garde-fou du propriétaire dans le contrôleur ; toutes les autres routes exigent `ROLE_ADMIN` | aligner sur `ROLE_ADMIN` (la gestion est réservée à l'administrateur) sauf besoin contraire |
| `GET /api/type-fournisseurs`, `GET /api/type-note-de-frais` | rôles larges, publics de fait pour tout client connecté | `ROLE_ADMIN` |
| `GET /api/transporteurs` | `PUBLIC_ACCESS` (liste des transporteurs d'achat avec contacts) | `ROLE_ADMIN` |
| `GET /api/dashboard/collections-combined` | sans cache alors que chaque collection recalcule tout ; `findAll()` sans filtre `del` | cache 60 s comme les autres tableaux de bord |
| `GET /api/frais/total` | **cassée** : `FraisDePortRepository::getTotalFraisByYear` / `getTotalFraisByMonth` et `NoteDeFraisRepository::getTotalFraisByDay` passent des chaînes littérales (`'endDate'`, `'startDate'`, `'endOfDay'`) à `setParameter` au lieu des dates (relevé le 09/11/2026) ; une requête par mois et par jour ; frais de port datés par `commande.date` | corrigé dans G3 : agrégats par période en une requête, fuseau du magasin |
| `GET /api/taxes/monthly` | borne de fin = dernier jour du mois à 00:00 (les commandes du dernier jour sont exclues) ; 12 requêtes | G3 : une requête groupée par mois |
| `GET /api/carrier/statistics` | `carrier_count` = `SUM(carrier.price)` par mois (montant en cents mal nommé) ; 12 requêtes ; borne de fin à 00:00 | G3 : `shippingAmount` ajouté (clé gardée), une requête |
| `GET /api/type-note-de-frais` | référentiel **vide** dans `gmasuite` (donc sur tout nouveau site) : aucune note de frais ne peut être saisie | G3 : référentiel de départ (Déplacement, Repas, Hébergement, Fournitures, Autre) posé sur toutes les bases, traduit |

### 2.6 Modèle de données

- `Transporteur` (gestion : transporteur d'un achat) et `Carrier` (boutique : transporteur de livraison, traduit,
  `kind`, `isPickup`) coexistent. À garder séparés (sens différents) mais à nommer clairement dans le contrat
  (`purchaseCarrier`).
- `Commande.commandepictures` / `Collections.photoCollections` : image aléatoire tirée dans `collection_picture` par
  SQL natif à la création ; la table est vide sur `demo` (0 ligne, lecture du 09/11/2026) : `photo` vaut toujours `null`
  (`getRandomCollectionPicture` renvoie `null` sans erreur). À alimenter dans `gmasuite` ou à retirer du contrat.
- `Collections.del` existe (suppression logique) mais `getCollections` liste tout (`findAll`).
- `Fournisseur` : `adresse`, `ville`, `pays`, `tel` libres ; pas de courriel, pas de devise du fournisseur (utile si les
  achats se font dans une autre devise que celle du site : décision à prendre, voir § 5).
- `CommandeService::getCommandesByCollection` utilise `$em->merge()` (retiré de Doctrine ORM 3) ; à remplacer par une
  requête du dépôt.
- Les tableaux de bord sont en cache 60 s (ouvert) / 3 600 s (fermé) avec des étiquettes correctes ; à conserver.

### 2.7 Tests

Aucun test fonctionnel ne couvre la création d'une collection, d'une commande, d'un produit de commande, d'un frais de
port, d'une note de frais, ni les calculs des tableaux de bord et des statistiques (hors invalidation de cache).
Toute correction de calcul doit commencer par un test qui fixe les chiffres attendus.

## 3. Recommandations : lots proposés

**État (10/11/2026)** : G0, G1, G2 poussés (70150e36) ; G3 (334f41e9) ; G4 (bd6d1c2d) ; jeu de données demo (890598c4) ; G5 poussé (6e0c2fe7) ; G6 devise d'achat livré (commit séparé) ; reste la fenêtre glissante du jeton de rafraîchissement pour « admin » et « pos » (validée par le propriétaire). Un commit poussé par lot (demande du propriétaire).

Ordre pensé pour ne rien casser côté MAUI pendant la transition : chaque lot est additif, la conversion de données est
faite une fois par script, et le contrat est annoncé à « new easymakemoney » avant d'être activé.

### Lot G0 : filet de sécurité (avant tout changement)

- Tests fonctionnels des routes de gestion sur l'état actuel (création, lecture, suppression, tableau de bord d'une
  commande et d'une collection avec chiffres attendus calculés à la main), pour détecter toute régression.
- Doc : cette fiche + `docs/endpoints.md` ; entrée dans `AGENTS.md`.

### Lot G1 : cents partout, devise du site

- Colonnes converties **en place** (float → cents entiers), une seule fois, par script
  `scripts/migrate_all_v2_gestion_cents.sh` appliqué à toutes les bases (`gmasuite` et modèle des tests compris) :
  `commande.budget`, `collections.budget_collection`, `frais_de_port.price`, `note_de_frais.montant`,
  `commande_statistiques.*` et `collection_statistiques.*` (montants) × 100, arrondis. `product.purchase_price` est déjà
  en cents : inchangé. Migration Doctrine idempotente (marqueur : type de colonne `integer`/`bigint` ou table de
  contrôle), retour arrière = division par 100.
- EasyAdmin : `setStoredAsCents(true)` et devise du site (`TenantCurrencyProvider`) sur les cinq `MoneyField`.
- API : entrées et sorties en cents entiers ; `currency` dans chaque réponse portant un montant (collections,
  commandes, frais de port, notes de frais, produits de commande, tableaux de bord, statistiques) ; `422
  VALIDATION_FAILED` si un montant n'est pas un entier ≥ 0.
- Transition MAUI : tant que l'application envoie des unités monétaires, elle doit être livrée en même temps (c'est
  un changement de contrat) ; alternative sans coupure : en-tête `X-Money-Unit: cents` pendant une version, les
  requêtes sans en-tête sont converties (× 100) côté serveur, puis retrait. Recommandation : livraison simultanée
  (l'application n'est pas encore en production chez des clients), sans mode de transition.

### Lot G2 : calculs justes et précision

- **Déjà porté par « posflutter backend » (09/11/2026, lot « rattachement », décision du propriétaire)** :
  `Product::commandeUnitCost()` = `commande_cost_cents ?? purchase_price ?? price`, lu par `CommandeDashboardService` et
  `CollectionDashboardService` : budget utilisé au coût d'achat, valeur de stock = coût × quantité × coefficient, marge
  et taux qui en découlent. Les statistiques figées existantes ne sont pas réécrites (il n'y en a aucune en base).
- **Performance (demande du propriétaire, 09/11/2026)** : plus de calcul en parcourant les entités (chargement paresseux
  commande → produits → variantes, une requête par commande) : les tableaux de bord sont calculés par **agrégats SQL**
  (`SUM`, `GROUP BY`) dans les dépôts (`CommandeRepository`, `CollectionsRepository`), une requête par tableau de bord
  (produits + frais de port + notes de frais joints), et le combiné en une seule requête sur toutes les collections.
- Reste à faire dans G2 : un seul service `PurchaseMetricsCalculator` partagé par commande, collection et combiné
  (fin de la duplication) ; arithmétique en entiers (cents) et taux calculés en fin de chaîne, arrondis à 2 décimales ;
  `averageMultiplier` pondéré par les quantités ; combiné = sommes des collections ouvertes, taux recalculés sur les
  sommes (plus de somme de taux) ; `close` : 409 `ALREADY_CLOSED`, réponse 200 avec les chiffres figés ; clés
  `purchaseCost` et `currency` ajoutées.

### Lot G3 : langue et fuseau

- `?locale=` honoré sur toutes les lectures (produits d'une commande, référentiels) ; traductions `fr`/`en` de
  `TypeFournisseur` et `TypeNoteDeFrais` (table de traduction sur le modèle de `CarrierTranslation`, référentiels de
  `gmasuite` remplis dans les deux langues).
- Statistiques : périodes bornées par `StoreClock` (fuseau du magasin), mois rendus par leur numéro et dates ISO, plus
  aucun libellé de langue dans les réponses.
- **Performance** : aujourd'hui chaque série (7 jours, semaines du mois, 12 mois) fait une requête par point, et chaque
  route en enchaîne plusieurs (jusqu'à ~30 requêtes par appel) ; à remplacer par une requête agrégée par série
  (`GROUP BY` jour / semaine / mois sur la période, fuseau du magasin appliqué en SQL), les points vides complétés en PHP.
- Messages de succès retirés ; erreurs = `{error: CODE, message}` ; codes documentés dans `docs/gestion.md`.

### Lot G4 : contrat HTTP et robustesse

- 201 avec l'objet créé ; 204 sans corps pour toute suppression ; 200 `null` quand une commande n'a pas de frais de port.
- Validation (422) de toutes les entrées ; 404 à code pour fournisseur, type, style, catégorie, transporteur inconnus ;
  409 pour frais de port déjà présent, collection non vide, fournisseur utilisé, commande fermée, produits vendus.
- Suppression d'une commande : détacher les produits (jamais les supprimer) ; suppression logique des collections
  (`del`) et liste filtrée.
- Rôles : `ROLE_ADMIN` sur toutes les routes de gestion (notes de frais, référentiels, transporteurs).
- `GET /api/commandes` API Platform : groupes de sérialisation alignés sur `CommandeOutputDTO`, ou retrait si MAUI
  passe aux contrôleurs dédiés (décision propriétaire).
- Propriétaire d'une collection = utilisateur du jeton.

### Lot G5 : préparation du clone web et de l'espace partenaire

**Livré le 10/11/2026** (pagination et filtres, export CSV, API Platform réduit) ; devise d'achat par fournisseur livrée ensuite (lot G6 : voir la ligne « Gestion : devise d'achat » de `docs/architecture.md`).

- Pagination et filtres sur `GET /api/collections` (ouvertes / fermées / supprimées), `GET /api/fournisseurs` (`q`).
- Export comptable des achats (CSV) sur le modèle de l'export des ventes de la caisse (F14).
- Devise d'achat par fournisseur (si décidé) avec taux saisi à la commande et montants convertis en devise du site
  pour les tableaux de bord.

## 4. Lot « rattachement de produits existants » (porté par « posflutter backend »)

Codé et déployé sur demo le 09/11/2026 avec le go du propriétaire : `GET /api/commandes/products/disponibles`,
`POST /api/commandes/{id}/products/rattacher`, `DELETE /api/commandes/{id}/products/{productId}` ; colonne additive
`product.commande_cost_cents` posée seulement au rattachement ; `Product::commandeUnitCost()` (voir G2). Les tableaux de
bord lisent désormais le coût d'achat : le § 2.2 points 1 et 2 est corrigé par ce lot. Cette session ne touche pas à
ces routes ; G1 convertit les autres colonnes (budgets, frais de port, notes de frais, statistiques figées).

## 5. Décisions attendues du propriétaire

1. **Unités** : cents partout, conversion en place des données existantes (G1) ; livraison simultanée avec MAUI sans
   mode de transition. Oui / non ?
2. **Règles de calcul** du § 3 (lot G2) : budget utilisé au prix d'achat, valeur de stock au prix de vente, marge et
   taux. Confirmer ou préciser (par exemple : la valeur de stock doit-elle tenir compte du prix promotionnel ?).
3. ~~Devise des achats~~ : tranché le 10/11/2026 (G6) : devise par fournisseur (CAD, EUR, USD) avec taux saisi sur la commande, tableaux de bord et export dans la devise du site.
4. ~~`GET /api/commandes` API Platform~~ : tranché le 10/11/2026 (G5), seule la suppression reste ; l'application n'appelait pas les autres opérations.
5. **Suppression d'une commande** : détacher les produits (recommandé) ou interdire si des produits ont été vendus ?
6. ~~Rattachement~~ : tranché le 09/11/2026, porté par « posflutter backend » (§ 4).
7. **Rôles** : la gestion reste réservée à `ROLE_ADMIN` (recommandé) ; les notes de frais par un utilisateur non
   administrateur sont-elles un cas réel ?

## 6. Méthode

Worktree `var/devtree-gestion` ; un lot = une branche de travail, tests avant/après, suite complète annoncée aux autres
sessions, copie fichier par fichier puis `cache:clear` et `lint:container` ; contrat envoyé à « new easymakemoney »
avant activation ; `docs/endpoints.md` régénéré ; cette fiche tenue à jour (devient `docs/gestion.md` à la fin de G1).

## 7. Contrat convenu avec « new easymakemoney » (09/11/2026)

Faits de l'application (MAUI, commit 932748c) : elle ne lit jamais `GET /api/commandes` ni `GET /api/commandes/{id}` (API
Platform) ; `price` du frais de port n'est pas affiché ; `month` et `date` des statistiques sont affichés tels quels (chaînes) ;
conversion ÷100 / ×100 en six endroits de l'application ; devise formatée par la culture de l'appareil (fausse aujourd'hui).

Décisions de contrat prises pour les lots :
- G1 : un nombre intégral est accepté sous toute forme (`1250`, `1250.0`, et la chaîne `"1250"` puisque le multipart n'envoie
  que des chaînes) = 1250 cents ; refusés : décimal non entier (`12.5`, `"12.50"`), texte, négatif, absent quand requis → 422
  `VALIDATION_FAILED` avec `fields.<champ> = MUST_BE_INTEGER_CENTS` (`REQUIRED`, `INVALID_DATE`, `INVALID_ID`, `MUST_BE_NUMBER`). Les réponses
  rendent des entiers JSON. `currency` dans toute réponse portant un montant, y compris `stock-value`, `stock-evolution`,
  `frais/total`, `taxes/monthly`, `payments/statistics`.
- G2 : `close` (commande et collection) répond 200 avec le tableau de bord figé (objet, même forme que le `GET`), 409
  `ALREADY_CLOSED` sinon.
- G3 : `month` devient un entier JSON 1..12 dans toutes les statistiques ; `date` reste une chaîne ISO ; `week` entier ;
  aucune clé renommée (les clés accentuées de `nombre-commandes` sont gardées).
- G4 : les champs en trop dans un corps restent ignorés (l'application sérialise ses modèles entiers) ; `quantity` (entier
  ≥ 0) accepté sur `POST /api/commandes/{id}/products`.
- Autres lecteurs des statistiques (G3) : le front Next n'en lit aucune ; l'application mobile e-commerce non plus ; la **caisse Flutter**
  lit `chiffre-affaires/pos` et `nombre-commandes/pos` et a adapté son modèle au mois en entier (commit 71a1d87, « posflutter front »,
  10/11/2026) ; aucune caisse en production.
- `carrier_count` (`GET /api/carrier/statistics`) est en réalité un **montant en cents** (`SUM(carrier.price)` des commandes clients du mois,
  `OrderRepository::getTotalCarrierByMonth`), malgré son nom : l'application divise par 100 à bon droit ; G3 ajoute `shippingAmount` (même
  valeur) et `currency`, `carrier_count` conservé.

## 8. Jeu de données de démonstration (demande du propriétaire, 09/11/2026)

Après G1 (montants en cents) : remplir le site `demo` (seul site où l'écriture est permise) pour voir la gestion fonctionner
dans l'application avec les **produits actuels** de demo : collections (ouvertes et fermée), commandes fournisseurs avec
fournisseurs et types, produits rattachés aux commandes (`POST /api/commandes/{id}/products/rattacher` : coût d'achat
`commande_cost_cents`, coefficient), frais de port avec transporteurs, notes de frais, puis **des ventes** (boutique web ou
caisse sur demo) pour que les tableaux de bord et les statistiques de la gestion montrent des achats et des ventes réels.
Moyen (livré le 09/11/2026) : commande `app:gestion:seed-demo --tenant=demo` (`Command/SeedGestionDemoCommand`,
`UseCase/GestionUseCase/SeedGestionDemoUseCase` qui refuse tout site autre que `demo`, `Services/GestionService/GestionDemoSeeder`) :
types de fournisseur et de note de frais, trois fournisseurs (Atelier Nord Textile, Distribution Laurentides, Logistique
Saint-Laurent), deux transporteurs d'achat (Purolator, Nationex), **quatre collections** sur l'année (Hiver 2025-2026 et
Printemps 2026 figées avec leurs commandes ; Été 2026 et Automne 2026 ouvertes) appartenant au premier administrateur du
site, **huit commandes fournisseurs**, les **produits existants sans commande** plus, si le site compte moins de 32 produits,
jusqu'à 36 **produits de démonstration** (`is_demo`, vêtements et accessoires, effacés par « Effacer le contenu d'exemple »),
répartis sur les commandes ouvertes par `CommandeProductAttachmentService` (coût d'achat 55 % du prix de vente, coefficient 1,8
posé sur les produits qui n'en ont pas, prix de vente intacts), un frais de port par commande et onze notes de frais, en cents ;
le cache du site est invalidé en fin de semis (un semis en console ne passe pas par l'invalidation des requêtes HTTP : avant
le 10/11/2026, l'application voyait des listes vides pendant cinq minutes). Rejouable : chaque élément est reconnu par son nom,
rien n'est effacé ni recréé ; s'il n'y a plus de produit libre, rien n'est rattaché. Les ventes existantes du site (commandes clients) alimentent déjà les statistiques. Test :
`tests/Functional/Gestion/GestionDemoSeederTest.php`. Pas de données sur un site client.

Depuis les périodes (lots P1–P3, 10/11/2026) : les quatre collections sont les **périodes du calendrier** (Hiver 2025-2026,
Printemps 2026, Été 2026, Automne 2026 ; les anciennes collections manuelles « Démo gestion — … » sont fusionnées dedans), les
périodes passées sont refigées à chaque rejeu (commandes, tableau de bord et résultat de période recalculés). À la demande de
l'application (10/11/2026) : **sept produits « Trouvaille — … »** (`is_demo`, sans prix d'achat ni commande, jamais rattachés par
le semis : matière pour la reprise d'historique) et **dix-huit ventes de démonstration** réparties sur Hiver, Printemps et Été
(six par période, caisse / web / mobile, dont un retour au printemps d'une vente d'hiver ; références `DEMO-GESTION-<clé>-<n°>`,
statut complétée, lignes aux prix de vente, totaux hors taxes ; une variante créée par produit vendu avec le stock du produit, la
quantité ne change pas). Les commandes clients n'ont pas de marque `is_demo` : « Effacer le contenu d'exemple » ne les retire
pas ; elles se reconnaissent à leur référence.
