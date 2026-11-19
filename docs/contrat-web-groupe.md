# Contrat web : le multi-boutiques (G-1 à G-4)

Pour la session « appli de gestion web » (application de gestion web, Next.js), puis le sélecteur de sites du mobile (MAUI). Écrit le
19/11/2026 par « appli degestion backend », d'après le code en production (étape 114, `ad04df28`) et les essais
`tests/Functional/Group/*`. Détail et décisions : `docs/ecrans-et-contextes.md` § 12 à § 15.

Règles communes :
- Toutes les routes : jeton du compte (`Authorization: Bearer …`) et site par l'hôte, comme le reste de l'API.
- Erreur : `{ error, code, message, … }`. On affiche selon le `code`, jamais selon le `message`.
- Dates en ISO 8601, montants en centimes, devise ISO 4217.

## 0. Qui est qui

| Rôle | Définition | Ce qu'il voit |
|---|---|---|
| **Directeur** | administrateur du site SIÈGE, connecté avec SON compte, module `module.multi_shop` actif au siège | le groupe, « Toutes les boutiques », la copie du catalogue, le sélecteur des boutiques |
| **Administrateur d'une boutique membre** | administrateur d'un site du groupe qui n'est pas le siège | son site ; le groupe en lecture (`role: "shop"`) ; quitter le groupe |
| **Directeur entré** | le directeur entré dans une boutique par le sélecteur (`GroupOperator`) | la boutique, sauf Stripe, remboursements, modules, compte (§ 6) ; jamais les outils du groupe |
| Compte de la plateforme, agence | — | jamais le groupe |

Le module est accordé par le super administrateur (ou par l'agence à ses sites créés). Sans le module, aucun écran de groupe n'apparaît.

## 1. Passer en multi-boutiques (siège)

`GET /api/group` → `{ group: null | { id, code, name, head, role: "head" | "shop", maxShops }, shops: [{ code, name, origin, joinedAt }], canCreate }`

- `canCreate: true` : afficher « Passer en multi-boutiques ». Le site n'est dans aucun groupe et a le module.
- `origin` : `head`, `created` ou `associated`. `shops` met le siège en premier.

`POST /api/group` (sans corps) → 201, la même forme que `GET /api/group`. Refus :
- 403 `MODULE_INACTIVE` (le module n'est pas actif) ;
- 403 `GROUP_FORBIDDEN` (pas un administrateur du site, entrée par jeton, compte de la plateforme) ;
- 409 `GROUP_ALREADY_MEMBER`.

## 2. Créer une boutique du groupe : PAS ENCORE

**Aucune route de création d'une boutique au groupe n'existe** à ce jour : pas de `POST /api/group/shops`. Le plan la prévoit (création
par la file d'AG-2, rattachement automatique, copie du catalogue cochée), mais elle n'a pas été codée en G-1. Ne pas afficher ce bouton ;
une nouvelle boutique entre par l'invitation (§ 3) une fois créée par la plateforme. Un lot sera proposé au chef.

## 3. Inviter une boutique existante

Siège, directeur : `POST /api/group/invitations { site: "<code du site>" }` → 202 `{ asked: true, expiresInDays: 14 }`.

La réponse est TOUJOURS la même, que le site existe ou non, qu'il soit le siège, déjà dans un groupe ou déjà invité. Le tri se fait en
tâche de fond, et la raison d'un refus reste au journal du groupe. Afficher « Invitation envoyée si cette boutique peut la recevoir ».
Refus :
- 429 `GROUP_INVITE_LIMIT` (10 par jour et par groupe, `perDay`) ;
- 403 `GROUP_HEAD_ONLY`, `MODULE_INACTIVE`, `GROUP_FORBIDDEN`.

Boutique invitée, son administrateur :
- `GET /api/site/group-invitations` → `{ invitations: [{ id, group, head, expiresAt, consent, consentVersion }] }`.
  - `consent` est le texte à afficher tel quel (fr ou en selon la langue du site) : « Le directeur du groupe X pourra entrer dans votre
    boutique et y agir ; chaque geste sera tracé ; vous pouvez quitter le groupe à tout moment. »
  - `consentVersion` vaut aujourd'hui `"g1-v1"`.
- `POST /api/site/group-invitations/{id}/accept { consent: "<consentVersion reçue>" }` → 200, la vue du groupe. Ne l'envoyer qu'après une
  case cochée par l'administrateur. Refus :
  - 422 `GROUP_CONSENT_REQUIRED` (version absente ou différente) ;
  - 404 `GROUP_INVITATION_NOT_FOUND` (inconnue, d'un autre site, échue, déjà traitée, groupe dissous) ;
  - 409 `GROUP_ALREADY_MEMBER`, `GROUP_SHOP_LIMIT`.
- `POST /api/site/group-invitations/{id}/refuse` → 204.

L'administrateur invité reçoit aussi un courriel, sans lien d'acceptation : la réponse se donne dans l'écran.

## 4. Quitter le groupe, retirer une boutique, dissoudre

`POST /api/group/leave { shop?: "<code>" }`, la vue du groupe après le geste :
- **une boutique membre** : sans `shop`, elle quitte le groupe ;
- **le siège** : avec `shop`, il retire cette boutique ; sans `shop`, il DISSOUT le groupe, seulement s'il n'y reste aucune autre boutique,
  sinon 409 `GROUP_NOT_EMPTY`.

Quitter ne supprime aucune donnée. Les entrées du directeur dans la boutique qui part se ferment aussitôt : sa requête suivante reçoit
401 `ENTRY_ENDED`. Demander une confirmation avant ces gestes.

## 5. « Toutes les boutiques » (directeur, au siège)

`GET /api/me/contexts` → `mode: "group"`. Dans `group` :
- `{ id, name, role: "head", allShops: [...] }` ;
- `allShops` liste les chemins qui savent réunir toutes les boutiques :
  - `/api/gestion/statistiques`, `/api/gestion/journee`, `/api/gestion/pertes` ;
  - `/api/commandes/lignes/a-recevoir`, `/api/gestion/tresorerie/soldes`, `/api/gestion/tresorerie/mouvements` ;
  - `/api/gestion/statistiques/annee`, `/api/gestion/groupe/resultat`.

Afficher l'interrupteur « Toutes les boutiques » seulement sur ces écrans. Mode : ajouter l'en-tête `X-Group-Scope: all` à la requête du
même écran, sur le site du siège.

Forme commune de la réponse :
```
{ scope: "group", group: { id, name }, generatedAt, truncated,
  totals: [ { currency, shops: [codes], … } ],               // un total PAR DEVISE ; jamais deux devises additionnées
  shops: [ { site, name, head, status, currency, timezone, data? } ] }
```

Statuts d'une boutique (`status`) : les afficher, ne jamais les cacher.

| Statut | Sens |
|---|---|
| `ok` | lue ; dans les totaux |
| `module_off` | n'a pas le module de l'écran |
| `error` | illisible (`code: "SHOP_UNAVAILABLE"`) |
| `timeout` | plus de 3 s |
| `skipped` | budget de 15 s épuisé |
| `duplicate` | même base qu'une autre boutique (`sameAs`) |

Seules les boutiques `ok` sont dans les totaux.

Par écran :
- **Statistiques** : `range`, `from`, `to`, `source` comme d'habitude. `sections` et `periodId` sont refusés. `totals[].indicators` a la
  même forme que la route seule ; les taux sont recalculés. `chart.points` est réuni par clé, avec `lastYear`. Remarque possible
  `PARTIAL_COMPARISON { shops }`.
- **Journée** : `totals[]` reprend la forme de la journée. `sales.vsSameWeekdayAverage.shops` liste les boutiques comparées ; `cash` vaut
  `{ balance, shops, state }`.
- **Pertes** : comptes par poste et par règle, jamais par personne. `totals[]` ne réunit pas les postes de caisse.
- **À recevoir** : `page × limit` ne dépasse pas 500, sinon 400 `GROUP_SCOPE_PAGE_TOO_DEEP` (« filtrez ou choisissez une boutique »).
  `rows` porte `site`, `currency` (celle de la commande) et `siteCurrency`. `X-Total-Count` est la somme.
- **Trésorerie** : `totals[]` donne `{ balance, balanceShops, oldestBalanceDate, rows }` pour les soldes, `{ rows }` pour les mouvements.
  Les salaires sont toujours regroupés, et aucune note n'est rendue.
- **Année** : `year` et `pricePercent` sont refusés. `months[]` est indexé par mois civil (`key` AAAA-MM, `shops` des boutiques
  couvertes) ; `cumulative` et `totals` suivent, `profitability.tauxMarque` aussi. Remarque possible `FISCAL_YEARS_DIFFER`.
- **Résultat sur des dates** : `GET /api/gestion/groupe/resultat?from=&to=`, route du groupe seulement. Sans l'en-tête : 400
  `GROUP_SCOPE_REQUIRED`. Dates invalides : 422. Plus de 366 jours : 422.

Refus du mode :
- 403 `GROUP_SCOPE_READ_ONLY` pour toute écriture : désactiver les boutons d'écriture en mode groupe ;
- 400 `GROUP_SCOPE_UNSUPPORTED` pour une route ou un paramètre qui ne se réunit pas ;
- 403 `GROUP_SCOPE_FORBIDDEN` pour un compte qui n'est pas le directeur, ou un module éteint ;
- 400 `GROUP_SCOPE_INVALID` pour une valeur autre que `all`.

## 6. Le sélecteur du directeur : entrer dans une boutique

`GET /api/me/contexts` au siège → `mode: "group"`. `sites` donne d'abord le siège (`home: true`), puis les boutiques (`home: false`),
chacune avec ses pastilles, son état et ses écrans.

Entrer : `POST /api/me/contexts/enter { site: "<code>" }` → 200 `{ token, expiresAt, mode: "group", site, entry, operator }`.
- `operator` vaut « Groupe X, prénom ».
- Le jeton vaut 15 minutes. Il faut une session vivante du directeur.
- Utiliser `token` pour les requêtes vers la boutique.
- Bandeau à afficher : « Vous êtes dans la boutique X pour le groupe Y ».

Refus : 403 `ENTRY_FORBIDDEN` (pas le directeur, boutique hors du groupe, le siège lui-même).

Dans la boutique, le directeur entré a tout, sauf ce qui suit : refus 403 `MODE_FORBIDDEN` avec `reason`.

| `reason` | Ce qui est fermé |
|---|---|
| `payment` | Stripe, terminal de paiement |
| `refund` | remboursements, annuler, capturer, retours |
| `modules` | gestes de module |
| `account` | le compte |
| `rights` | droits, groupe, taxes, outils du groupe (`/api/gestion/groupe/...`) |
| `ai` | IA |
| `customers` | import des clients |
| `security` | domaine, clés |
| `assistant` | assistant |

Chaque écriture est tracée « Groupe X, prénom » dans « Accès extérieurs » de la boutique.

Fin d'une entrée : 401 `ENTRY_ENDED`, le lien est fermé (boutique partie, rôle perdu, module éteint). Revenir au siège.

## 7. Copie du catalogue (G-4, directeur au siège, sans l'en-tête)

| Route | Effet |
|---|---|
| `POST /api/gestion/groupe/copies { source?, target, articles?: [ids] }` → 201 | aperçu, rien d'écrit dans le catalogue : `{ id, source, target, articles, withCost, createdAt, confirmedAt, cancelledAt, import: { id, status: "analysed", report, result, error } }` |
| `GET /api/gestion/groupe/copies` → `{ copies: [...] }` ; `GET /api/gestion/groupe/copies/{id}` | les copies du groupe |
| `POST /api/gestion/groupe/copies/{id}/confirm { visibility?: "hidden" \| "as_source", pos?: bool, withCost?: bool }` → 202 | la copie part en tâche de fond |
| `POST /api/gestion/groupe/copies/{id}/cancel` | annulation possible 7 jours |

- Par défaut, les articles copiés sont masqués : ni en ligne, ni en caisse.
- Le coût d'achat n'est copié que si la source est le siège ; depuis une autre boutique, seulement avec `withCost: true`.
- Le stock n'est jamais copié. Les photos sont recopiées.
- La boutique cible reçoit un avis (notification et courriel `group_catalog_copied`). Elle voit la copie dans ses imports et peut
  l'annuler elle-même ; elle ne peut pas la confirmer.

`report` a la forme de l'import (`docs/import-boutique.md` § 2) : `products { total, importable, refused, alreadyImported }`, `images`,
`barcodes`, `issues`.

Refus :
- 403 `GROUP_COPY_FORBIDDEN` (pas le directeur, ou entré dans une boutique) ;
- 404 `GROUP_SHOP_UNKNOWN`, `COPY_NOT_FOUND` ;
- 409 `COPY_CURRENCY_DIFFERS` (devises différentes), `COPY_NOT_READY` (déjà confirmée ou annulée), `IMPORT_ALREADY_ACTIVE` (une tâche
  d'import active dans la cible) ;
- 422 `VALIDATION_FAILED`, `COPY_TOO_MANY` (plus de 5 000 articles).

## 8. Ce qui est caché selon le rôle

| Écran ou geste | Directeur | Admin d'une boutique membre | Directeur entré | Agence, plateforme |
|---|---|---|---|---|
| Passer en multi-boutiques | si `canCreate` | non | non | non |
| Inviter, retirer, dissoudre | oui | non | non | non |
| Invitations reçues, accepter, refuser | — | oui (si invité) | non | non |
| Quitter le groupe | — | oui | non | non |
| « Toutes les boutiques » | oui | non | non | non |
| Copie du catalogue | oui | voit et annule les copies reçues (imports) | non | non |
| Sélecteur des boutiques | oui | non | « Revenir au siège » | non |

À venir, hors de ce contrat :
- l'assistant IA du directeur (lot IA-G) ;
- le transfert entre boutiques (G-5) ;
- les prix sur plusieurs boutiques (G-6) ;
- les frais du siège (G-7) ;
- le bilan PDF du groupe (G-8) ;
- la création d'une boutique par le groupe (§ 2).
