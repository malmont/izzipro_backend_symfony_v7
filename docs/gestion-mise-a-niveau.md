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
  `VALIDATION_FAILED` avec `fields.<champ> = MUST_BE_INTEGER_CENTS` (`REQUIRED`, `INVALID_DATE`, `INVALID_ID`, `MUST_BE_NUMBER`, `TOO_LARGE`). Les réponses
  rendent des entiers JSON. `currency` dans toute réponse portant un montant, y compris `stock-value`, `stock-evolution`,
  `frais/total`, `taxes/monthly`, `payments/statistics`.
- G2 : `close` (commande et collection) répond 200 avec le tableau de bord figé (objet, même forme que le `GET`), 409
  `ALREADY_CLOSED` sinon.
- G3 : `month` devient un entier JSON 1..12 dans toutes les statistiques ; `date` reste une chaîne ISO ; `week` entier ;
  aucune clé renommée (les clés accentuées de `nombre-commandes` sont gardées).
- G4 : les champs en trop dans un corps restent ignorés (l'application sérialise ses modèles entiers) ; `quantity` (entier
  ≥ 0) accepté sur `POST /api/commandes/{id}/products`.
- Lot B3 bis (12/11/2026, contrat validé par l'application ; `FraisDePortService::overview`, `GetFraisDePortByCollectionUseCase`, test
  `PurchaseCurrencyApiTest::testShippingCostsOfAPeriodInOneRead`) : **`GET /api/collections/{id}/frais-de-port`**, `ROLE_ADMIN`, lecture
  seule : les commandes de la période avec leur frais de port, en une lecture (avant : une lecture par commande).
  ```
  { currency, siteCurrency (devise du site, les deux),
    commandes: [{ commandeId, name, date, kind, currency (de la commande), siteCurrency, exchangeRate | null, fournisseur: { id, name } | null,
                  fraisDePort: <objet de GET /api/commandes/{id}/frais-de-port> | null }],
    totals: { commandes, withShipping, amount } }
  ```
  - Toutes les commandes de la période, dans l'ordre de `GET /api/collections/{id}/commandes` ; `fraisDePort` `null` sans frais de port.
  - `totals.amount` = Σ `priceConverted`, en cents de la devise du site : le total « frais de port » du tableau de bord de la période
    (`GeneralExpenses.totalShippingCost`). Les applications n'additionnent rien.
  - En cache 600 s ; toute écriture d'une commande, de son frais de port, d'un transporteur ou d'un fournisseur le vide. Période
    inconnue : 404.
  - **Écart possible d'un cent avec le résultat de la période, en devise étrangère seulement** : le résultat de période
    (`PurchaseMetricsCalculator::periodTotals`) tronque la somme non arrondie des frais convertis ; cette route additionne des prix
    convertis arrondis au cent chacun. Sans commande en devise étrangère, les deux sont égaux. Le calcul du résultat n'est pas modifié :
    changer un chiffre déjà affiché, périodes clôturées comprises, est une décision comptable du propriétaire (liste des décisions).
  - **Objet frais de port, sur toutes les réponses qui le portent** (`GET` et `POST /api/commandes/{id}/frais-de-port`, confirmation d'une
    facture ou d'un transport lus par IA) : `currency` est désormais **la devise de la commande** (avant : celle du site, fausse pour une
    commande en devise étrangère, dont `price` est dans la devise de la commande) ; ajout de `siteCurrency` (devise du site : l'application
    mobile y lit la devise du site avant `currency`) et de `priceConverted` (`price` × taux de la commande, arrondi au cent, en cents de
    la devise du site ; égal à `price` sans devise étrangère). Rien ne change pour une commande dans la devise du site.
- Lot BORNES (12/11/2026, `Gestion/Services/GestionInput`, test `PurchasesApiTest::testNumbersBeyondTheColumnRangeAreRefusedNotCrashed`) :
  un montant en cents, une quantité ou un identifiant au-delà de 2 147 483 647 (`GestionInput::MAX_INT`, la limite d'une colonne entière)
  → 422 `VALIDATION_FAILED`, `fields.<champ> = TOO_LARGE`, sur toutes les routes qui lisent leur corps par `GestionInput`. La borne
  elle-même est acceptée. Avant, la valeur passait la validation et l'enregistrement échouait en base (500). Les montants signés de la
  trésorerie (colonnes plus larges) sont bornés à 9 × 10¹⁵ en valeur absolue, même code (avant : `MUST_BE_INTEGER_CENTS`).
  Un identifiant trop grand **dans l'adresse** d'une route (`/api/commandes/99999999999/…`) est traité par le lot ROUTEID, pour toute
  la plateforme : 404 (`docs/architecture.md`, « Authentification et sécurité »).
- Correctif du 12/11/2026, corps de `POST /api/commandes/{id}/products` (multipart ; lu par `Dto/ProductInputDTO`, seul lecteur du
  corps ; test `PurchasesApiTest::testCreatingAnArticleInAnOrderNeverCrashesOnMissingEmptyOrMalformedFields`) : plus aucun 500 pour un
  corps incomplet ou mal formé.
  - **Obligatoires** : `name` (rogné, 255 caractères au plus), `purchasePrice` (cents) et `coefficientMultiplier` : le prix de vente,
    colonne non nulle, est leur produit ; sans l'un des deux, l'enregistrement échouait en 500. Absent ou vide → 422
    `fields.<champ> = REQUIRED`. Zéro est une valeur.
  - **Facultatifs** : `description` (absente → `null` ; avant : 500), `quantity`, `style_id` (vide ou `0` = aucun style), `category_ids`
    (tableau ou chaîne « 1,2 » ; éléments vides ignorés, doublons retirés), fichier `image`. Un champ de formulaire laissé vide vaut
    « absent ».
  - **Mal formés** → 422 `VALIDATION_FAILED`, toutes les erreurs en une réponse : `style_id` et `category_ids` `INVALID_ID` (avant : 500
    pour le style, 404 `CATEGORY_NOT_FOUND` pour une catégorie non numérique). Une référence bien formée mais inconnue reste en 404
    `STYLE_NOT_FOUND` / `CATEGORY_NOT_FOUND`.
  - **Image** : JPEG, PNG, WebP, GIF, AVIF, HEIC ou HEIF, type lu dans le fichier (ni son nom ni le type annoncé) ; autre chose → 422
    `fields.image = INVALID_IMAGE` ; envoi coupé ou trop lourd → `UPLOAD_FAILED`. Elle est rangée dans
    `var/storage/public_bucket/assets/uploads/products/`, le dossier servi sous `/assets/uploads/products/`. Avant, la route visait
    `public/assets/uploads/products/`, qui n'existe pas et que le serveur ne peut pas créer : **tout envoi d'image sortait en 500**.
- Autres lecteurs des statistiques (G3) : le front Next n'en lit aucune ; l'application mobile e-commerce non plus ; la **caisse Flutter**
  lit `chiffre-affaires/pos` et `nombre-commandes/pos` et a adapté son modèle au mois en entier (commit 71a1d87, « posflutter front »,
  10/11/2026) ; aucune caisse en production.
- Correctifs des statistiques du 11/11/2026 (demande de l'application, relevé B1–B8) :
  - B1 `GET /api/stock-value` : chaque élément de `stock_value_current_month` gagne `stock_value_cents` (entier, **par variante** : stock de la
    variante × son prix, sinon le prix de l'article ; article sans variante : sa quantité × son prix), `month_number` (1..12) et `year` au fuseau
    du magasin. `stock_value` (dollars, quantité et prix de l'article) et `month` (« November 2026 ») restent inchangés : les deux valeurs
    diffèrent dès qu'une variante a son propre prix ou que la quantité de l'article ne suit pas ses variantes (demo le 11/11/2026 : `stock_value`
    101 016,60 $ contre `stock_value_cents` 9 983 860, soit 99 838,60 $ = variantes 81 650,60 $ + articles sans variante 18 188,00 $). L'application lit `stock_value_cents` en priorité. Test : `tests/Functional/Gestion/StatisticsFixesTest.php`.
  - B6 `GET /api/payments/statistics/{source}` : seuls les paiements **réussis** (`status_payment` 2 « Complété ») comptent ; en cours (1) et
    échoués (3) exclus (correction, formes inchangées ; la caisse, qui lit `/pos`, en est prévenue).
  - B8 `GET /api/frais/total` : correctif du 09/11 (commit 334f41e9) en ligne.
  - B2 `GET /api/stock-evolution` : bornes en **jours du magasin** (semaine du lundi 0 h au dimanche inclus, mois et année civils, fin exclusive,
    `movement_date` en UTC ; avant : heure courante et fuseau du serveur) ; chaque élément `{ type, quantity, code }` : `code` stable additif
    `in` (Entrant), `out` (Sortant), `return` (Return, écrit par aucun chemin aujourd'hui : 0), `adjustment` (Ajustement) ; **5e élément**
    `{ type: "Réception fournisseur", code: "reception" }` (type 5, réception d'une ligne d'achat, lot R1). Les quatre premiers gardent ordre et
    `type`. L'application lit `code`. Code `StockEvolutionService` ; test `StatisticsFixesTest`.
  - Règle des ventes de B3 à B5 : commandes **incomplètes (statut 1) et annulées (7) exclues**, comme le résultat de période ; cartes-cadeaux
    (type 3) exclues. Code `SalesStatisticsService::KEPT_STATUS` ; test `StatisticsApiTest::testSalesFixesKeepOnlyRealSales`.
  - B3 `GET /api/statistiques/panier-moyen/{source}` : incomplètes et annulées exclues de **toutes** les clés (correction ; la caisse ne lit pas
    cette route) ; `lastYearAverage` (année civile précédente, cents) ajouté.
  - B4 `GET /api/statistiques/nombre-commandes/{source}` : anciennes clés inchangées (ventes et retours, tous statuts ; la caisse lit `/pos`) ;
    ajout de `currentMonthSalesCount`, `lastMonthSalesCount`, `currentYearSalesCount`, `lastYearSalesCount` et des séries
    `weeklySalesCountForCurrentMonth` `[{ week, orderCount }]`, `monthlySalesCountForCurrentYear` `[{ month, orderCount }]` : ventes (type 1) seulement.
  - B5 `GET /api/carrier/statistics` : chaque mois gagne `shippingBilled` (cents : `order.shipping_cost` réellement facturé des ventes et retours ;
    un remboursement de port, négatif, se déduirait ; aucun chemin n'en écrit aujourd'hui) et `orders` (ventes avec transporteur).
    `carrier_count` / `shippingAmount` (prix catalogue du transporteur, sans filtre) restent tels quels.
  - B7 `GET /api/caisse/transactions?asList=1` : toujours 200 `{ cashRegisterId, sessionOpen, transactions }` (mêmes éléments que la liste de la
    caisse), y compris sans session (`sessionOpen` false, liste vide) et sans poste actif (`cashRegisterId` null) ; plusieurs postes actifs sans
    `cashRegisterId` : 400 `CASH_REGISTER_REQUIRED` inchangé. Sans `asList`, le 404 de la caisse reste. Test `CashRegisterApiTest`.
- `carrier_count` (`GET /api/carrier/statistics`) est en réalité un **montant en cents** (`SUM(carrier.price)` des commandes clients du mois,
  `OrderRepository::getTotalCarrierByMonth`), malgré son nom : l'application divise par 100 à bon droit ; G3 ajoute `shippingAmount` (même
  valeur) et `currency`, `carrier_count` conservé.
- Notes de frais (12/11/2026, demande du web) : `NoteDeFraisOutputDTO` gagne `typeNoteDeFraisId` (entier, `null` sans type), rendu par la
  liste `GET /api/collections/{id}/notes-de-frais` et par les réponses de `POST` et `PUT` ; il préremplit le type exigé par
  `PUT /api/notes-de-frais/{id}`. Ajout seulement. Test `PurchasesApiTest`.
- **Lot EDIT (12/11/2026) : modifier un fournisseur, un transporteur d'achat, un frais de port.** Trois `PATCH` **partiels**, réservés à
  `ROLE_ADMIN` : seules les clés envoyées sont lues, les autres valeurs ne bougent pas ; une clé inconnue est ignorée ; corps illisible →
  400 `INVALID_JSON` ; toutes les erreurs de champ reviennent en une réponse 422 `VALIDATION_FAILED { fields }` et **un refus n'écrit rien**
  (même les clés valides du même envoi). La réponse 200 a la forme de la création. Un envoi qui ne change aucune valeur répond 200 sans rien
  inscrire au journal.
  - `PATCH /api/fournisseurs/{id}` : `name` (obligatoire s'il est envoyé), `typeFournisseur` (identifiant ; inconnu → 404
    `TYPE_FOURNISSEUR_NOT_FOUND`), `adresse`, `ville`, `pays`, `tel` (vide ou `null` : effacé), `currency` (code ISO, `null` ou vide = devise
    du site). **Devise figée dès la première commande** : la changer (y compris vers `null`, qui est un retour à la devise du site, donc un
    changement) → 409 `FOURNISSEUR_CURRENCY_LOCKED { commandes }` ; renvoyer la même devise n'est pas un changement. Fournisseur inconnu →
    404 `FOURNISSEUR_NOT_FOUND`. Réponse : `FournisseurOutputDTO`, comme la liste. Depuis le 12/11/2026 (demande du web), cet objet
    porte `typeFournisseurId` (entier, `null` sans type), en fin d'objet : rendu par `GET /api/fournisseurs`, par les réponses de `POST`
    et de `PATCH`, et dans le `fournisseur` d'une commande (même objet) ; il préremplit `typeFournisseur` de la modification.
  - `PATCH /api/transporteurs/{id}` : `name`, `logo` (texte), `contact` (vide ou `null` : effacé). Le transporteur réservé
    « Sur facture fournisseur » (`TransporteurService::RESERVED_NAME`, celui de la confirmation d'une facture lue par IA) ne se renomme
    pas : 409 `TRANSPORTEUR_RESERVED` ; son logo et son contact se modifient. Inconnu → 404 `TRANSPORTEUR_NOT_FOUND`.
  - `PATCH /api/commandes/{id}/frais-de-port` : `name`, `price` (cents entiers, dans la devise de la commande ; envoyé vide → 422
    `REQUIRED`), `transporteur` (`{ id }` ; inconnu → 404 `TRANSPORTEUR_NOT_FOUND`), `facture`, `tracknumber`. Mêmes gardes que la création
    et la suppression : 409 `COMMANDE_CLOSED` (commande figée), 409 `PERIOD_CLOSED { periodId }` (commande datée dans une période fermée).
    Commande inconnue → 404 `COMMANDE_NOT_FOUND` ; commande sans frais de port → 404 `FRAIS_DE_PORT_NOT_FOUND`. Le prix d'un frais de port
    venu d'un document lu par IA reste modifiable. Réponse : `FraisDePortOutputDTO`, comme la lecture (`priceConverted` recalculé).
  - **Journal des contenus** (`content_audit_log`, action `update`, ressources `fournisseurs`, `transporteurs`, `frais-de-port`) : une
    entrée par modification effective, avec l'état avant, l'état après et les champs changés ; pour un frais de port, `commandeId` et
    `documentId` (l'identifiant du document lu par IA, pour retrouver un écart entre le document et le prix corrigé).
  - **À savoir pour le comptable** : une commande garde un lien vers son fournisseur et un frais de port vers son transporteur, pas une
    copie du nom. Le **nouveau nom** d'un fournisseur ou d'un transporteur apparaît donc partout, **y compris dans les listes et dans l'export
    `achats.csv` des périodes déjà fermées** (l'export lit les deux noms au moment de l'export) ; les montants figés, eux, ne changent pas. L'ancien nom se retrouve au journal des contenus pendant sa durée de conservation (180 jours).
  - Code : `FournisseurService::update`, `TransporteurService::update`, `FraisDePortService::update` et leurs cas d'usage `Update…UseCase` ;
    test `PurchaseCurrencyApiTest::testSupplierCarrierAndShippingCostCanBeEdited`.
- **Lot EDIT-JOURNAL (12/11/2026) : suppressions au journal, nom réservé, champs vides, ordre des listes.** Sans changement de route.
  - **Suppressions au journal des contenus** (`content_audit_log`, action `delete`) : `DELETE /api/fournisseurs/{id}` (ressource
    `fournisseurs`), `DELETE /api/transporteurs/{id}` (`transporteurs`), `DELETE /api/commandes/{id}/frais-de-port` (`frais-de-port`).
    L'entrée porte qui, quand, et l'objet **avant** suppression ; pour un frais de port, `commandeId` et `documentId`. Un refus (409) ou
    une commande sans frais de port n'écrit rien.
  - **« Sur facture fournisseur »** (transporteur des factures lues par IA) : 409 `TRANSPORTEUR_RESERVED` pour le **supprimer** (utilisé
    ou non), pour **créer** un transporteur sous ce nom, et pour **renommer** un autre transporteur ainsi. Le nom est comparé sans tenir
    compte de la casse ni des espaces autour. Sans cette garde, la lecture de factures retrouverait le mauvais transporteur.
  - **`POST /api/transporteurs`** : nom obligatoire, 422 `VALIDATION_FAILED { fields.name = REQUIRED }` (avant : un nom vide était
    enregistré) ; le nom est rogné.
  - **Champ facultatif vide = `null` en base**, à la création comme à la modification : fournisseur (`adresse`, `ville`, `pays`, `tel`),
    transporteur (`logo`, `contact`), frais de port (`facture`, `tracknumber`). Un champ effacé a la même forme qu'un champ jamais rempli.
    **La sortie du frais de port ne change pas** : `facture` et `tracknumber` y restent des chaînes vides quand ils sont absents (forme
    d'origine de cet objet) ; le fournisseur et le transporteur rendent `null`. Les chaînes vides déjà en base ne sont pas corrigées.
  - **Ordre stable des listes** : `GET /api/transporteurs` et `GET /api/fournisseurs` sont triés par nom sans tenir compte de la casse,
    puis par identifiant. Avant, les transporteurs n'avaient aucun ordre (un transporteur modifié passait en fin de liste) et les
    fournisseurs suivaient la casse de la base.
  - Code : `TransporteurService` (`isReserved`, `createTransporteur`, `deleteTransporteur`, `getAllTransporteurs`), `FournisseurService`,
    `FournisseurRepository::search`, `FraisDePortService`, `GestionInput::blank` ; test
    `PurchaseCurrencyApiTest::testDeletionsAreJournaledReservedNameIsGuardedAndListsKeepTheirOrder`.
- **Lot LEX (13/11/2026) : langue de l'application de gestion (fr/en).** Sans migration ni changement de route.
  - **Refus encore en anglais dans la gestion** : les quatre qui restaient passent en français fixe. `CommandeController` et
    `CollectionController` : 401 « Authentification requise » ; `FraisDePortController` : 400 « Corps JSON invalide » ;
    `CollectionService` : « Utilisateur introuvable ». Le statut et la clé `error` ne changent pas ; `code` (`UNAUTHENTICATED`,
    `INVALID_JSON`) et `message` sont ajoutés aux trois réponses JSON. Les refus en anglais de la vente, de la caisse et de la fiche
    produit ne sont pas dans ce lot (`docs/erreurs-client.md`). Test : `LexErrorMessagesTest`.
  - **Export des achats** (`GET /api/gestion/exports/achats.csv`) : `?locale=fr|en`, sinon la langue du site, comme les autres routes
    de la gestion. Sont traduits : la première ligne, les valeurs de la colonne Type (« Produit », « Frais de port », « Note de frais »)
    et les quatre libellés de total (`translations/gestion.{fr,en}.yaml`, clés `export.purchases.*`). Ne changent pas : les noms de
    collection, de commande et de fournisseur, les libellés, les montants, le séparateur, le format des nombres et le nom du fichier.
    En français, le fichier est identique à avant. Test : `PurchasesCsvLocaleTest`.
  - **Noms de périodes en anglais** (`PeriodCalendar::displayName`) : les services qui acceptent `?locale` (`GestionBudgetService`,
    `GestionPreparationService`, `GestionPreparationAdviceService` — simulation et plans candidats —, `GestionSimulationService`,
    `GestionYearService`, `GestionAdviceService`) rendent les libellés de période traduits (`Spring 2026`, `Winter 2025-2026`,
    `First half 2026`, mois en anglais) sans espace insécable ni écriture en base ; un nom modifié manuellement et les collections
    manuelles restent inchangés ; les caches d'avis et de commentaires sont isolés par langue. Test : `PeriodsLocaleTest` (détail dans
    `docs/periodes-gestion.md`).
  - **Cheminement du site** (`GET /api/site/readiness`) : `?locale=en` traduit uniquement les étapes et libellés des trois groupes
    `app.management`, `module.support` et `ai` (`translations/readiness.en.yaml`) ; sans paramètre, avec `?locale=fr` ou valeur inconnue,
    la réponse reste strictement en français quelle que soit la langue du site (détail dans `docs/console-pilotage.md`). Test :
    `ManagementReadinessTest`.
- **Lot STOCK-VERROU (13/11/2026) : verrouillage des mouvements de stock dans EasyAdmin** (`InventoryMovementsCrudController`) :
  - **Mouvements en lecture seule** (administrateur et super administrateur) : tout mouvement dont `movement_type_id` vaut `1` (Entrant),
    `2` (Sortant), `3` (Return) ou `5` (Réception fournisseur), ou dont `commande_line_id` est non nul. Deux collisions assumées en
    l'absence de colonne d'origine dédiée : le type `1` couvre les retours/annulations web comme le stock initial d'une variante, et le
    type `2` couvre les ventes (caisse/web) comme les baisses faites depuis l'éditeur.
  - **Restent modifiables et supprimables** : les ajustements libres (type `4` avec `commande_line_id` nul) et les lignes héritées sans
    type (`movement_type_id` nul), sans pouvoir basculer vers un type verrouillé. `BATCH_DELETE` désactivé ; `DETAIL` ajouté ;
    `commandeLine` affiché en lecture seule sur liste et détail ; actions `EDIT` / `DELETE` masquées lorsque verrouillé ; `edit()`,
    `delete()`, `updateEntity()` et `deleteEntity()` relisent `movement_type_id` et `commande_line_id` par SQL direct et renvoient 403.
  - **Création d'un ajustement et intégrité de `stockBeforeMovement` / `stockAfterMovement`** : seul le type `4` (Ajustement) est
    proposé à la création (`persistEntity()` refuse tout autre type en 403). Sur le formulaire de création, `stockBeforeMovement` et
    `stockAfterMovement` sont masqués et calculés côté serveur à l'enregistrement (`stock_quantity` de `product_variant` par SQL direct,
    `stockAfter = stockBefore + quantity`, y compris négatif ; `null` si variante absente ou sans stock), sans jamais modifier
    `product_variant.stock_quantity` (le stock réel se corrige sur la fiche de la variante). En modification d'un ajustement libre, ces
    deux champs sont affichés désactivés et réimposés depuis la base dans `updateEntity()`.
  - Test : `tests/Functional/Admin/InventoryMovementsLockTest.php`.

## 8. Jeu de données de démonstration (demande du propriétaire, 09/11/2026)

Après G1 (montants en cents) : remplir le site `demo` (seul site où l'écriture est permise) pour voir la gestion fonctionner
dans l'application avec les **produits actuels** de demo : collections (ouvertes et fermée), commandes fournisseurs avec
fournisseurs et types, produits rattachés aux commandes (`POST /api/commandes/{id}/products/rattacher` : coût d'achat
`commande_cost_cents`, coefficient), frais de port avec transporteurs, notes de frais, puis **des ventes** (boutique web ou
caisse sur demo) pour que les tableaux de bord et les statistiques de la gestion montrent des achats et des ventes réels.
Moyen (livré le 09/11/2026) : commande `app:gestion:seed-demo --tenant=demo` (`Gestion/Command/SeedGestionDemoCommand`,
`Gestion/UseCase/SeedGestionDemoUseCase` qui refuse tout site autre que `demo`, `Gestion/Services/GestionDemoSeeder`) :
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

## 9. Lot MOD : l'application de gestion en module payant activable (11/11/2026)

Contrat validé par l'application (« new easymakemoney ») le 11/11/2026, Q1 à Q6 tranchées.

- **Clé** : `app.management` (droit gouverné existant : offre « management », 3 900 cents CAD, 30 jours d'essai ; colonne
  `entreprise.is_management_app_active` projetée par les droits ; demande d'essai `POST /api/site/features/app.management/request`).
- **Garde** (`ModuleAccessService::MANAGEMENT`, `ModuleAccessSubscriber`) : mêmes préfixes que la règle `ROLE_ADMIN` de la gestion dans
  `security.yaml` (`collections`, `createcollections`, `commandes`, `fournisseurs`, `transporteurs`, `notes-de-frais`, `type-fournisseurs`,
  `type-note-de-frais`, `gestion`, `periodes`, `dashboard`, `statistiques`, `stock-evolution`, `stock-value`, `frais`, `payments/statistics`,
  `taxes/monthly`, `carrier/statistics`), **sauf la caisse** : `/api/caisse/*`, `/api/statistiques/(chiffre-affaires|nombre-commandes|panier-moyen)/pos`,
  `/api/payments/statistics/pos`. Vérifiée route par route sur tout le routeur (`ManagementModuleApiTest`), la règle de `security.yaml` étant
  relue par le test. Comme les autres modules : **seulement en mode plateforme `live`** (en `demo`, rien n'est gardé), avant le pare-feu.
- **Réponse** : 403 `{ error: "MODULE_INACTIVE", code, module: "management", message, feature: "app.management", requestable, request:
  { status } | null, trial: { allowed, days } }` (`ModuleRequestHint`, ajout aussi pour les autres modules : `feature`, `requestable`…) ;
  `requestable` faux si une demande est déjà en attente (l'écran ne propose pas « Demander » deux fois) ; un seul essai par site.
- **Connexion** (`platform=admin`) et **activation de la clé** de l'application : ouvertes ; l'application affiche « Application de gestion non
  activée » avec « Demander l'essai ».
- **Notifications quotidiennes** (`app:gestion:notifications:envoyer`) : sautées pour un site dont le module est éteint, en mode live ;
  l'instantané quotidien du stock reste pris (historique).
- **Transition** : `app:gestion:module:transition` (simulation par défaut, `--apply`, `--tenant=`) accorde `app.management` (manuel, sans fin,
  note « transition MOD ») à tout site qui s'en sert déjà (périodes, commandes fournisseurs, notes de frais, conseils, documents, ou clé de
  l'application valide) ; rejouable sans effet. À lancer avant le passage de la plateforme en live (au 11/11/2026 : demo seulement concerné).
  Retour arrière : annuler les droits notés « transition MOD », ou rester en mode demo.
- **Unités IA** : la gestion consomme les crédits mensuels du site (même réserve que l'assistant et les fiches produit), 1 crédit par appel
  (conseils, avis du guide, lecture de documents) ; l'IA reste un palier à part (`ai.help` / `ai.full`). `GET /api/gestion/ia` (`ROLE_ADMIN`,
  gardée par le module) → `{ tier: none | help | full, credits: { monthly, used, remaining, resetAt }, costs: { advice, comment, document },
  limits: { commentsPerDay: 10, adviceRefreshPerDay: 3 }, today: { comments, adviceRefresh } }`. Épuisé : conseils et avis rendent
  `credits_exhausted` (les règles restent servies), documents 402 `AI_CREDITS_EXHAUSTED`. Plafonds vérifiés avant les crédits ; depuis ce lot,
  un avis refusé faute de crédits ou de palier (`credits_exhausted`, `not_included`) **n'est ni gardé en cache ni compté** dans le plafond du jour.
- Code : `Services/FeatureAccess/ModuleAccessService`, `ModuleRequestHint`, `EventSubscriber/ModuleAccessSubscriber`,
  `Gestion/Services/GestionAiUsageService`, `GestionModuleTransitionService`, `Gestion/Command/GestionModuleTransitionCommand`,
  `Gestion/Controller/GestionAiController`. Test : `tests/Functional/Gestion/ManagementModuleApiTest.php` (+ `SimulationApiTest` : refus non compté).

## 10. Lot CHE : cheminements de l'application de gestion (12/11/2026)

Demande du propriétaire, contrat validé par l'application : un cheminement ciblé (page `/admin/gestion`) et le groupe « Application de gestion »
du cheminement du site (`GET /api/site/readiness`, accueil EasyAdmin, Mon site), seulement si le module est accordé ; sinon « Obtenir ». Détail
des étapes, bloquantes ou d'amélioration, et des champs additifs `appScreen` / `figures` : `docs/console-pilotage.md`, « Cheminement ciblé de
l'application de gestion ». Les autres groupes du cheminement sont inchangés (test). La vignette « premiers pas » `cash_first_balance` recoupe
l'étape `management.cash` : l'application la retirera de son côté ; rien n'est changé côté serveur.

Correctif du 12/11/2026 : en ajoutant l'écran `gestion` à la table des écrans (`AdminStepUrlResolver::SCREENS`), le lot CHE avait laissé
l'entrée `short_code` derrière un commentaire de fin de ligne. Les étapes `management.short_code` et `mobile_shop.short_code` n'avaient
plus de lien vers leur écran dans EasyAdmin, sans erreur visible. Entrée rétablie ; garde-fou
`tests/Functional/Platform/AdminStepScreensTest.php` : tout écran cité par une étape doit exister dans la table.

Même correctif, crédits IA : les étapes `management.ai` et `ai.credits` comptent désormais les crédits du mois par une seule fonction
(`SiteReadinessService::aiCredits`), avec la règle du quota qui les applique (`LandingAiQuotaService::credits`) : forfait réglé tel quel,
0 compris ; forfait par défaut de la plateforme seulement sans réglage ; mois de facturation de la plateforme (`TIMEZONE`, le même pour
tous les sites) ; demandes réussies et réservations en cours. Avant, `management.ai` remplaçait un forfait réglé à 0 par le forfait par
défaut et annonçait des crédits que le quota refusait. Le mois reste celui du quota, pas celui du fuseau du site : changer cette borne
serait un changement du quota lui-même. Test : `ManagementReadinessTest::testBothAiStepsCountTheCreditsLikeTheQuotaDoes`.

