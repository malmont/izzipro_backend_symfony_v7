# Aides de la gestion (lot FIS-2)

Codé le 21/11/2026 sur le contrat validé par le chef de projet. Le plan d'ensemble est dans `docs/plan-impots.md` (§ 3.4 et lot FIS-2 du § 4), sur la branche `docs-plan-impots`.

Code :
- `src/Gestion/Aid/` : `AidCalculator` (calcul pur), `GestionAidService` et `AidReminderService` ;
- `Gestion/Controller/GestionAidController` et `Gestion/UseCase/GestionAidUseCase`.

Tables :
- `gestion_aid` : la fiche ;
- `gestion_aid_reminder` : les rappels déjà faits ;
- `gestion_loan.aid_id` : le prêt lié à une avance (colonne posée par FIS-1, index posé par ce lot).

Migration `Version20270202500000`, script `scripts/migrate_all_v2_gestion_aids.sh`. Essai : `tests/Functional/Gestion/AidApiTest.php`.

Aucun écran EasyAdmin. L'écran est celui de l'application de gestion (web et mobile), code d'écran `aides`.

## 1. Le principe

- La **fiche** de l'aide est stockée. Le **produit** (résultat) et les **flux** (trésorerie) sont **calculés** à chaque lecture, jamais stockés.
- Il y a **trois genres** d'aide :
  - `operating` (aide d'exploitation) : un **produit** au résultat, au prorata de la période couverte, sinon à la date de chaque versement. Ses versements entrent aussi en trésorerie ;
  - `investment` (aide à l'investissement) : un **encaissement seul**, en trésorerie, jamais au résultat (décision Q6) ;
  - `refundable_advance` (avance remboursable) : elle crée un **prêt à 0 %** (lot FIS-1), lié à l'aide et visible dans « Prêts ». C'est ce prêt qui porte le déblocage et les remboursements : l'aide elle-même n'a **aucun flux**, pour ne jamais compter l'argent deux fois.
- **États** :
  - `requested` : demandée ;
  - `granted` : accordée ;
  - `received` : reçue ;
  - `to_repay` : à rembourser ;
  - `closed` : close.

  Une aide compte comme **acquise** dès `granted` (décision D1).
- Une aide dans une **autre devise** que le site reste dans la liste et sa fiche, avec `inCalculations: false`. Elle n'entre ni dans la trésorerie ni dans le résultat (aucune conversion, comme un prêt).

## 2. Les règles de calcul (décisions du chef, 21/11/2026)

- **Produit d'une aide d'exploitation (D1)** : le montant **accordé**, dès `granted` (donc aussi `received`, `to_repay` et `closed`), au **prorata des jours** de la période couverte.
  - Le calcul arrondit le **cumul** couru : les mois d'une année font exactement le montant, au cent près.
  - Sans période couverte, le produit tombe à la date de chaque versement (reçu ou prévu).
  - Une aide `requested` n'a aucun produit.
- **Deux parts** :
  - `received` : la part du produit déjà reçue ;
  - `pending` : accordé, pas encore reçu.

  Avec une période couverte, la part reçue suit la proportion de ce qui est déjà reçu de l'aide entière. Sans période couverte, chaque versement est rangé selon son propre état. `estimated: true` tant qu'une part reste à recevoir : c'est une estimation, et l'écran le dit.
- **Trésorerie** :
  - un versement **reçu** entre toujours ;
  - un versement **prévu** n'entre que si l'aide est `granted`, `received` ou `to_repay`. Une aide demandée n'est pas une rentrée sûre ; une aide close n'attend plus rien.
- **À rembourser** (précision 1 du chef : une condition non tenue, sur une aide qui n'est pas une avance) :
  - le remboursement `{ amount, dueOn, decidedOn }` est exigé ;
  - il **sort** de la trésorerie à `dueOn` ;
  - le produit déjà compté **baisse** d'autant sur la période qui contient `decidedOn`. `decidedOn` vaut par défaut le jour du magasin où l'état passe à `to_repay` ;
  - une période figée garde son instantané ;
  - une aide `closed` garde son remboursement.
- **Double comptage (D3)** : quand un mouvement prévu d'**entrée** saisi à la main a le même montant qu'un versement compté, le même mois, la remarque `AID_MAY_OVERLAP_MOVEMENTS` le signale, dans la trésorerie et dans `charges.caveats` du résultat. Rien n'est supprimé : le gérant choisit lequel garder.

## 3. L'avance remboursable (D2, précision 2)

- Champs propres à l'avance :
  - `advance: { durationMonths, frequencyMonths (1 | 3), firstDueOn, deferralMonths? }` ;
  - au moins un versement : le **premier** versement est le **déblocage** du prêt.
- Le prêt est créé, avec `aid_id` qui le lie à l'aide, quand l'avance **devient acquise** :
  - soit dès la création si elle est déjà `granted` (ou plus) ;
  - soit au passage de `requested` à un état acquis.

  Le prêt reprend le libellé de l'aide, l'organisme comme prêteur, le montant accordé et la devise, un taux de 0 %, les termes de l'avance et des notes « Avance remboursable (aide n° X) ». Il n'est jamais recréé.
- Le prêt applique ses propres règles (FIS-1), par exemple une première échéance au plus deux rythmes après le déblocage. Une attente plus longue se saisit en différé (`deferralMonths`).
- Si le prêt refuse ces termes, la création est **entièrement annulée**, sans aide ni prêt. La réponse est 422 `advance: AID_ADVANCE_INVALID`, avec les champs du prêt sous `advance.<champ>`.
- Tant que le prêt existe :
  - les **termes** de l'aide ne changent pas : genre, montant, devise, termes de l'avance, date du premier versement ;
  - l'aide **ne se supprime pas**.

  Dans les deux cas : **409 `AID_LINKED_LOAN`** `{ aidId, loanId }`. On modifie le prêt, ou on ferme l'aide. Il n'y a jamais de suppression en cascade. Le libellé, l'état, les conditions, les justificatifs et les notes se changent librement.
- Le prêt supprimé dans « Prêts », l'aide garde `loanId: null` et se supprime.

## 4. Routes (rôle de la gestion, comme les prêts)

| Route | Rend |
|---|---|
| `GET /api/gestion/aides` | `{ aids: [...] }` : les aides, de la plus récente à la plus ancienne, chacune avec son résumé du jour |
| `POST /api/gestion/aides` (201) | la fiche créée, avec son résumé |
| `GET /api/gestion/aides/{id}` | la fiche et son résumé |
| `PATCH /api/gestion/aides/{id}` | la fiche modifiée (champs absents gardés) |
| `DELETE /api/gestion/aides/{id}` (204) | — ; 409 `AID_LINKED_LOAN` si un prêt lié existe |
| `PATCH /api/gestion/aides/{id}/conditions/{index}` | `{ state: todo \| kept \| broken }` : l'état d'une condition par son rang (ordre des échéances) ; 404 `AID_CONDITION_NOT_FOUND` |
| `POST /api/gestion/aides/{id}/justificatifs` (201) | `{ documentId }` : rattache un document de la gestion déjà déposé (`GestionDocument`), sans doublon ; 422 `documentId: AID_DOCUMENT_NOT_FOUND` |
| `DELETE /api/gestion/aides/{id}/justificatifs/{documentId}` | retire le justificatif (le document reste dans la gestion) ; 404 `AID_DOCUMENT_NOT_FOUND` |

Une aide inconnue donne 404 `AID_NOT_FOUND`. Création, modification et suppression sont écrites au journal des modifications (ressource `gestion-aides`).

Pour le directeur d'un groupe entré dans une boutique, les aides sont en **lecture seule**. Les routes d'écriture sont fermées par sa garde (raison `payment`), comme les prêts : une avance crée un prêt.

**Fiche** : chaque champ, avec son code de refus (422 `VALIDATION_FAILED`, `fields.<champ>`).

| Champ | Contenu | Refus |
|---|---|---|
| `label` | 1 à 120 caractères | `AID_LABEL_INVALID` |
| `organism`, `notes` | facultatifs (120 et 1000 caractères) | — |
| `kind` | `operating`, `investment` ou `refundable_advance` | `AID_KIND_INVALID` |
| `amount` | montant accordé, en centimes | `AID_AMOUNT_INVALID` |
| `currency` | code ISO ; celle du site par défaut | `AID_CURRENCY_INVALID` |
| `status` | `requested` par défaut | `AID_STATUS_INVALID` |
| `coveredFrom`, `coveredTo` | les deux bornes ou aucune, `AAAA-MM-JJ` | `AID_DATE_INVALID` |
| période couverte ou versements | une aide d'exploitation a l'un ou l'autre | `AID_PERIOD_REQUIRED` (sur `coveredFrom`) |
| `payments` | `[{ date, amount, received: bool }]`, 24 au plus, jamais plus que le montant accordé | `AID_PAYMENT_INVALID` |
| `conditions` | `[{ label, dueOn, state }]`, 24 au plus ; `state` vaut `todo` par défaut | `AID_CONDITION_INVALID` |
| `documents` | identifiants de documents de la gestion, 20 au plus ; seuls les nouveaux sont relus | `AID_DOCUMENT_NOT_FOUND` |
| `repayment` | `{ amount, dueOn, decidedOn? }`, exigé pour `to_repay` (sauf une avance) | `AID_REPAYMENT_INVALID` |
| `advance` | exigé pour une avance, voir § 3 | `AID_ADVANCE_INVALID` |

**Résumé**, dans la liste et la fiche : les champs de la fiche, plus :
- `loanId` : le prêt lié, ou `null` ;
- `receivedTotal` : la somme des versements reçus ;
- `toReceive` : accordé moins reçu, pour une aide acquise et pas close ;
- `conditionsDue` : les conditions `todo` dont l'échéance est passée ;
- `documentsMissing` : reçue, sans justificatif ;
- `inCalculations` : dans la devise du site.

**Exemple de réponse** (`GET /api/gestion/aides/7`, données fictives) :

```json
{
  "id": 7, "label": "Aide au démarrage", "organism": "Organisme Témoin", "kind": "operating", "amount": 120000, "currency": "CAD",
  "status": "granted", "coveredFrom": "2049-01-01", "coveredTo": "2049-12-31",
  "payments": [{ "date": "2049-02-10", "amount": 60000, "received": true }, { "date": "2049-09-10", "amount": 60000, "received": false }],
  "conditions": [{ "label": "Garder deux emplois", "dueOn": "2049-12-31", "state": "todo" }],
  "documents": [42], "repayment": null, "advance": null, "notes": null,
  "loanId": null, "receivedTotal": 60000, "toReceive": 60000, "conditionsDue": 0, "documentsMissing": false, "inCalculations": true
}
```

## 5. Ce qui change dans les autres écrans

- **Trésorerie** (`GET /api/gestion/tresorerie`) :
  - les versements et les remboursements décidés entrent dans la projection et dans le solde à une date ;
  - ils sont rangés dans la catégorie `aid` de `byCategory`, avec le libellé « Aide ». Ce n'est pas une catégorie des mouvements saisis ;
  - la remarque `AID_MAY_OVERLAP_MOVEMENTS` peut apparaître (§ 2) ;
  - le **rapprochement bancaire** compte les mêmes flux dans l'attendu.
- **Résultat d'une période** (`GET /api/periodes/{id}/resultat`) :
  - `charges.operatingAids: { received, pending, repaid, total, aids, estimated }` ;
  - **`charges.total` garde son sens** (décision du chef, 21/11/2026 : la gestion web le lit) : les charges seules, comme avant FIS-2 ;
  - **`charges.net`** = `total` − `operatingAids.total` : les charges moins les aides d'exploitation. Il peut être négatif ; sans aide, il vaut `total` ;
  - `result.netAfterCharges` = `netAfterFixed` − `charges.net`, inchangé sans aide ;
  - une période figée après les prêts mais avant les aides garde son instantané, avec `charges.operatingAids: null` et `charges.net` = `total` (l'instantané a compté `netAfterCharges` sans aide) ;
  - une période figée avant les prêts reste `charges: null` (`CLOSED_BEFORE_LOANS`).
- **Statistiques** (`sections=charges`) :
  - `charges.operatingAids: { current, previous, lastYear }`, chacun de la forme `{ received, pending, repaid, total, aids }` ;
  - `charges.net: { current, previous, lastYear }` : charges moins aides, sur chaque plage ;
  - `financial`, `previous` et `lastYear` restent les charges financières seules.
- **Année** (`GET /api/gestion/statistiques/annee`) : par période, `financialCharges` inchangé, `operatingAids` (le total) et `netCharges` (charges moins aides), `null` pour une période figée avant le lot.
- **Groupe** (« Toutes les boutiques ») :
  - le résultat réunit `netAfterCharges` et `charges.operatingAids` par devise ;
  - `estimated`, un drapeau, n'est pas réuni ;
  - `gestion_aid` entre dans l'empreinte du cache de la journée, de l'année et du résultat du groupe.

## 6. Rappels et guide

- **Rappel par courriel** :
  - type `aid_reminder`, courriel de **suivi**, sujet « gestion » (`NotificationCatalog`) ;
  - il part aux administrateurs du site (pas au compte de la plateforme) ;
  - il suit leur **niveau** et le **plafond de 3 par jour** (précision 3, `NotificationGate`), jamais avant 8 h du magasin ;
  - il se coupe dans les préférences de notification de la gestion (`aid_reminder`, canal `email`).
- **Ce qu'il annonce** : une condition encore `todo` arrivée à échéance, ou une aide reçue sans justificatif. **Chaque élément une seule fois** :
  - l'élément est réservé dans `gestion_aid_reminder` avant l'envoi. Une condition a pour clé son libellé et son échéance : la changer en fait une autre ;
  - l'élément est rendu si rien n'est parti ni n'a été retenu.
- **Quand il passe** : à chaque passage horaire de `app:gestion:notifications:envoyer`, pour un site dont le module de gestion est activé.
- **Le courriel** :
  - au plus 10 lignes, puis « et N autres » ;
  - un seul lien, vers l'écran `aides`, ou la fiche d'une aide quand il n'en concerne qu'une (`docs/liens-espace-administration.md`).
- **Guide** : la règle `AID_CONDITIONS_DUE` (`docs/guide-ia-gestion.md`) se déclenche quand des conditions sont échues à tenir.
  - Effet : le montant accordé des aides concernées (`aid_at_stake`). Confiance élevée.
  - Action : l'écran `aides`.
  - Elle s'éteint dès que les conditions sont marquées.

## 7. Outils de l'IA

- **Analyse nommée** `aids.overview` (écran `aides`, route `GET /api/gestion/aides`) :
  - un tableau : libellé, genre, état, accordé, reçu, à recevoir, conditions échues ;
  - des chiffres : `aidsCount`, `aidsReceived`, `aidsToReceive`, `aidsConditionsDue`, `aidsDocumentsMissing`, `operatingAidsYear` (le produit de l'année civile en cours, une estimation) ;
  - **ni l'organisme, ni les notes, ni le texte des conditions** ne sont rendus au modèle.
- **Lexique** : deux notions, `aide-exploitation` (avec le chiffre `operatingAidsYear`) et `avance-remboursable`.
- **Guide de l'écran** `aides`.
- Aucune action d'écriture par le fil dans ce lot.

## 8. Simplifications, dites à l'écran

- L'aide à l'investissement n'est pas étalée sur la durée du bien.
- Une avance versée en plusieurs fois est débloquée en une fois, à la date du premier versement, pour le montant accordé.
- La part reçue d'une aide à période couverte est une proportion, pas une date de versement.
