# Caisse (POS) : postes, sessions, ventes et remboursements

Fiche du module de caisse du backend, contrat sur lequel le POS Flutter (dépôt `posflutter`) et l'application de
gestion sont codés. Routes et rôles exacts : `docs/endpoints.md`. Règles communes (tenant, connexion, erreurs) :
`docs/architecture.md`. Mise en place le 15/10/2026 (plusieurs postes par site) ; avant cette date, aucune base de site
n'avait de caisse ni de type de transaction : la caisse n'avait jamais servi sur ce backend.

## Modèle

| Objet | Table | Rôle |
|---|---|---|
| Poste de caisse `CashRegister` | `cash_register` | un terminal physique ou logique du site : `id`, `name` (unique, 100 caractères), `isActive`, `createdAt`. Jamais supprimé (historique) : désactivé. Chaque site a au moins « Caisse principale » (créé par la migration). |
| Session `Caisse` | `caisse` | ouverture → fermeture d'un poste : `cashRegister`, `amountTotal` (cents, espèces en caisse), `fonDeCaisse` (cents, part du solde qui sert de fond de caisse ; 0 et non plus null), `isOpen`, `createdAt` (date **et heure** d'ouverture depuis le 19/10/2026), `closedAt`. **Une seule session ouverte par poste** (index unique partiel `uniq_caisse_open_register`). À l'ouverture, `amountTotal` **et `fonDeCaisse`** = ceux de la dernière session fermée **du même poste** (0 sinon) : l'argent reste dans le tiroir. |
| Mouvement `TransactionCaisse` | `transaction_caisse` | ligne d'une session : type, montant, utilisateur, commande (vente, remboursement), détail des espèces (`CashDetails`). |
| Types `TransactionType` | `transaction_type` | 1 vente, 2 remboursement, 3 dépôt, 4 ouverture, 5 fermeture, 6 retrait, 7 retrait du fond de caisse, 8 ajout au fond de caisse (insérés par la migration dans toutes les bases). Depuis le 19/10/2026, chaque transaction porte aussi `cashAmount` (effet sur les espèces du tiroir, cents, signé) et `cashRounding` (arrondi des espèces qu'elle contient). |
| Coupures `TypeCash` | `type_cash` | billets et pièces par devise (19/10/2026) : `valueCents`, `kind` (`bill`/`coin`), `active`, `currency`, `position`. CAD : ids 1 = 100 $, 2 = 50 $, 3 = 10 $, 4 = 5 $, 5 = 2 $, 6 = 1 $, 7 = 25 ¢, 8 = 10 ¢, 9 = 5 ¢, 10 = 1 ¢ (inactive), 11 = 20 $. Gérées dans EasyAdmin (« Type Cash »). |
| Journal `PosAuditLog` | `pos_audit_log` | journal d'audit de la caisse (C2, 19/10/2026) : une entrée par événement, séquence continue par site, chaînée par empreinte ; **inaltérable** (un déclencheur PostgreSQL refuse modification, suppression et vidage). EasyAdmin « Journal de caisse » en lecture seule. |
| Numéros `PosReceipt` | `pos_receipt` | numéros de **tickets** (ventes) et d'**avoirs** (remboursements, retours) : deux suites continues sans trou par poste (C2). |
| Rapport Z `CashRegisterReport` | `cash_register_report` | clôture d'une session (19/10/2026) : `number` continu **par poste**, instantané figé (`data`) et chiffres clés en colonnes. Un par session ; jamais modifié ni supprimé. EasyAdmin « Rapports Z » en lecture seule. |

Code : `Entity/CashRegister`, `Entity/Caisse`, `Repository/CashRegisterRepository`, `Repository/CaisseRepository`,
`Services/CaisseService/*` (`CashRegisterResolver`, `CashRegisterService`, `CaisseService`, `CashRegisterException`),
`UseCase/CaisseUseCase/*` (`CaisseOperationsUseCase`, `HandleCaisseTransactionUseCase`, `GestCaisseUseCase`, postes),
`Controller/CaisseController/CaisseController`, `Controller/CashRegisterController/CashRegisterController`, écrans
EasyAdmin `Controller/Admin/CashRegisterCrudController` et `CaisseCrudController`. Schéma :
`migrations/Version20261015120000.php` et `scripts/migrate_all_v2_cash_registers.sh`.

## Choix du poste (`CashRegisterResolver`)

Toutes les routes de caisse et la vente acceptent un `cashRegisterId` **facultatif** (corps JSON des POST, paramètre de
requête des GET) :

- fourni → le poste doit exister et être actif, sinon `404 CASH_REGISTER_NOT_FOUND` ;
- absent → si le site a **exactement un poste actif**, il est pris (comportement d'un site à une seule caisse : aucun
  appel existant n'a besoin de `cashRegisterId`) ; sinon `400 CASH_REGISTER_REQUIRED`.

Exception : `GET /api/caisse` sans `cashRegisterId` liste les sessions de **tous** les postes (chaque session nomme le
sien) ; avec `cashRegisterId`, celles de ce poste.

## Droits

| Rôle | Peut |
|---|---|
| `ROLE_USER_POS` (caissier, sans `ROLE_ADMIN`) | connexion `platform: "pos"` ; toutes les routes `/api/caisse/*` ; `GET /api/cash-registers` (postes actifs) ; vente `POST /api/order/create-multi-payment` ; `GET /api/orders?orderSource=2`, `GET /api/payments?orderSource=2` ; statistiques de la source `pos` (`/api/statistiques/{chiffre-affaires,nombre-commandes,panier-moyen}/pos`, `/api/payments/statistics/pos`). Autre source → 403. |
| `ROLE_POS_MANAGER` (responsable de caisse, 16/10/2026 ; porte aussi ROLE_USER_POS en pratique) | tout ce que fait le caissier, plus : créer, renommer et désactiver les postes (`POST`/`PUT /api/cash-registers`, `?all=1`), rembourser une vente POS faite par un autre caissier (`POST /api/order/cancel/{id}`). Rien d'autre : pas de back-office EasyAdmin, pas de clés d'activation, pas d'autre source que la caisse, connexion `platform: "pos"` seulement. Attribuable par l'administrateur du site dans l'écran des utilisateurs. |
| `ROLE_ADMIN` | tout ce qui précède pour toutes les sources, plus les clés d'activation, les écrans EasyAdmin. |
| Clients (`ROLE_USER_INTERNET`) | rien de la caisse (403). |

## Routes de session (`/api/caisse`)

Corps des POST : `{ "cashRegisterId"?: int, "amount"?: number, "cashDetails"?: [{ "typeCash": int, "nombreItems": int }] }`.
Codes de succès inchangés depuis l'origine : `close` répond **200**, les autres **201**.

| Méthode, route | Corps / paramètres | Réponse |
|---|---|---|
| `POST /api/caisse/open` | `cashRegisterId?`, `cashFund?` (cents, ≥ 0 : fond de caisse ajouté à l'ouverture, enregistré comme « Ajout fond de caisse ») | 201 `{message, caisse_id, cashRegisterId, amountTotal, fonDeCaisse, createdAt}` ; session déjà ouverte sur le poste → 400 `CAISSE_ALREADY_OPEN` ; `cashFund` invalide → 400 `INVALID_AMOUNT` (rien n'est ouvert) |
| `POST /api/caisse/close` | `cashRegisterId?`, `countedCash?`, `cashDetails?`, `discrepancyReason?`, `keptFund?` (voir « Clôture ») | 200 `{message, caisse_id, cashRegisterId, report}` (`report` = rapport Z créé) ; pas de session ouverte → 400 `CASH_REGISTER_CLOSED` (+ `lastReport {id, number}`) |
| `GET /api/caisse/denominations` | — | 200 `[{id, label, valueCents, kind, active}]` pour la devise du site |
| `GET /api/caisse/report/x?cashRegisterId=&locale=&purpose=` | poste (règle du poste) ; `purpose=closing` quand le dialogue de fermeture le charge | 200 rapport X (voir « Clôture ») ; aucune session ouverte → 409 `CASH_REGISTER_CLOSED`. Journalisé : `X_REPORT_VIEWED`, ou `CLOSING_PREVIEW` avec `purpose=closing` |
| `POST /api/caisse/drawer-open` | `{cashRegisterId?, reason}` | 201 `{auditId, sequence, occurredAt, cashRegisterId}` : ouverture du tiroir hors encaissement, journalisée (`DRAWER_OPENED`) ; motif absent → 400 `REASON_REQUIRED` ; pas de session ouverte → 400 `CASH_REGISTER_CLOSED` |
| `GET /api/caisse/report/z?cashRegisterId=&from=&to=` | `from`/`to` : `AAAA-MM-JJ` (journées entières) ou ISO 8601 | 200 `[{id, number, cashRegister, sessionId, openedAt, closedAt, closedBy, salesTotal, expectedCash, countedCash, discrepancy}]`, plus récent d'abord ; date invalide → 400 `INVALID_DATE` |
| `GET /api/caisse/report/z/{id}` | identifiant unique du rapport (le numéro n'est unique que par poste) | 200 rapport Z complet ; inconnu → 404 `REPORT_NOT_FOUND` |
| `POST /api/caisse/deposit` (3), `withdraw` (6), `cashfunddeposit` (8), `cashfundwithdraw` (7) | `amount` > 0, `cashDetails?`, `cashRegisterId?` | 201 `{message, new_total, cashRegisterId}` ; `amount` invalide → 400 `INVALID_AMOUNT` ; retrait supérieur au solde → 400 `INSUFFICIENT_FUNDS` |
| `GET /api/caisse?days=&cashRegisterId=` | `days` : sessions créées depuis N jours | 200 liste de sessions : `{id, amountTotal, fonDeCaisse, createdAt, isOpen, transactionCaisses[], cashRegister{id, name}, closedAt}` |
| `GET /api/caisse/transactions?cashRegisterId=` | poste (règle ci-dessus) | 200 `[{id, amount, transactionDate, transactionType, cashRegisterId}]` de la session ouverte ; aucune → 404 (comme avant) |

Les montants (`amountTotal`, `new_total`, `amount` des dépôts et retraits) sont en **cents**, comme la part en espèces
d'une vente qui entre en caisse. Les listes sont en cache (clés par poste, étiquette `caisses_tag`), invalidé à chaque
écriture.

## Postes (`/api/cash-registers`)

| Méthode, route | Rôle | Corps | Réponse |
|---|---|---|---|
| `GET /api/cash-registers` | caisse, admin | `?all=1` (admin : inclut les inactifs) | 200 `[{id, name, isActive, createdAt, openSession: null \| {id, amountTotal, fonDeCaisse, createdAt}}]` |
| `POST /api/cash-registers` | admin | `{name}` | 201 le poste ; nom vide, trop long ou déjà pris → 422 `VALIDATION_FAILED` + `errors[{path, message}]` |
| `PUT /api/cash-registers/{id}` | admin | `{name?, isActive?}` | 200 le poste ; inconnu → 404 `CASH_REGISTER_NOT_FOUND` ; désactiver un poste dont une session est ouverte → 409 `CASH_REGISTER_OPEN` |

Pas de `DELETE` : un poste se désactive.

## Vente en caisse : `POST /api/order/create-multi-payment`

Route distincte du tunnel web (`/api/order/create`, `create-guest`, webhook Stripe), réservée à `ROLE_USER_POS` et
`ROLE_ADMIN`. Corps :

```json
{
  "items": [{ "productVariantId": 12, "quantity": 1, "customizationId": null }],
  "paymentMethods": [{ "type": 2, "amount": 20.00 }, { "type": 1, "amount": 13.35 }],
  "cashRegisterId": 1,
  "orderSource": 2,
  "typeOrder": 1,
  "addressId": null,
  "carrierId": null,
  "priceShipping": null,
  "payment": { "stripePaymentId": null },
  "licenseNumber": null,
  "licenseExpirationDate": null
}
```

- `items` et `paymentMethods` requis ; `type` = `payment_method.id` (1 carte, 2 espèces, 3 PayPal), `amount` en
  **unités monétaires** (dollars), positif. **La somme des `paymentMethods` doit égaler le total calculé par le serveur**
  (articles au prix du serveur + livraison + taxes, tolérance 1 cent), **ou le total plus l'arrondi des espèces** (voir
  ci-dessous) ; sinon 400 `PAYMENT_TOTAL_MISMATCH` `{error, message, expectedTotal, cashTotal}` (cents) et rien n'est
  créé. Seule la part en espèces (`type` 2) entre en caisse.
- **Arrondi des espèces** (19/10/2026) : la part en espèces est envoyée **déjà arrondie** au pas du site (5 ¢ en CAD :
  ,01 et ,02 vers le bas, ,03 à ,07 vers ,05, ,08 et ,09 vers le haut ; aucun arrondi pour les autres devises ;
  réglable dans la fiche entreprise, « Arrondi des paiements en espèces »). Le serveur accepte `espèces = arrondi(total −
  parts non espèces)` et enregistre l'écart (−2 à +2 ¢) dans `order.cash_rounding` (0 pour toute autre commande) et sur
  la transaction de caisse. Le total fiscal (`totalAmount`, taxes) ne change pas ; la somme des paiements vaut total +
  arrondi. La part espèces exacte (sans arrondi) reste acceptée.
- Total à encaisser : `POST /api/cart/quote` (public) avec les mêmes `items` **et le `cashRegisterId`** : sans
  `shippingAddress`, un devis qui porte `cashRegisterId` est taxé à l'adresse de la fiche entreprise, comme la vente
  (19/10/2026) ; `total` en cents. Sans `cashRegisterId` (boutique web), un devis sans adresse reste hors taxes
  (`taxStatus: address_required`). Une `shippingAddress` fournie l'emporte toujours. Le devis de caisse renvoie en plus
  `cashTotal` : total à payer si tout est réglé en espèces, déjà arrondi (cents) ; absent sans `cashRegisterId`.
- `orderSource` 2 (caisse) et `typeOrder` 1 (vente) par défaut ; `typeOrder` 2 = retour (montants négatifs).
- `addressId` et `carrierId` **facultatifs** : une vente au comptoir n'a ni adresse de livraison ni transporteur, ni
  frais de port ; les **taxes sont alors calculées sur l'adresse de la fiche entreprise** du site (lieu de la vente,
  `Services/OrderService/OrderTaxAddressResolver`). Fournis, ils doivent exister (404 `ADDRESS_NOT_FOUND`,
  `CARRIER_NOT_FOUND`) et le comportement est celui d'une livraison.
- `cashRegisterId` facultatif (règle du poste) ; la vente est enregistrée dans la **session ouverte du poste** ; poste
  sans session ouverte → **409 `CASH_REGISTER_CLOSED`**, aucune commande ni paiement créés (une seule transaction SQL).
- `payment.stripePaymentId` : seulement si un paiement Stripe a réellement eu lieu (terminal, lien) ; il est alors
  vérifié, à usage unique (409 s'il est déjà lié à une commande) et capturé après la commande. Sans lui, la caisse
  encaisse elle-même : aucun appel Stripe.
- Réponse 201 : champs historiques `{success: true, orderId, message}` **plus le bloc « ticket de caisse »**
  (16/10/2026, `Dto/PosReceiptDto`), montants en dollars : `reference`, `createdAt` (ISO 8601), `subTotal`,
  `totalTax`, `totalAmount`, `taxes: [{name, rate, amount}]`, `payments: [{type, label, amount}]` (`type` =
  `payment_method.id`, `label` dans la langue `?locale=`), `cashRegister: {id, name}`, `store: {name, address, phone,
  taxNumber}` (fiche entreprise du site ; `taxNumber` = numéro de TVA, sinon SIRET), `cashRounding` et `amountDue`
  (= `totalAmount` + `cashRounding`, montant réellement payé, 19/10/2026). E-mails de confirmation envoyés
  comme pour le web. `GET /api/orders/{id}` (client ou jeton invité) ne donne ni les taxes, ni les paiements, ni le poste.

Autres refus : 400 `INVALID_BODY`, `ITEMS_REQUIRED`, `PAYMENT_METHODS_REQUIRED`, `PAYMENT_METHODS_INVALID` ;
400 `CASH_REGISTER_REQUIRED` ; 404 `CASH_REGISTER_NOT_FOUND`.

## Remboursement : `POST /api/order/cancel/{id}`

Route partagée avec le web (`{paymentMethod?, cashRegisterId?}`), réservée à l'auteur de la commande ; une **vente
POS** peut aussi être remboursée par un responsable de caisse ou un administrateur (la transaction de caisse porte
alors celui qui rembourse). Seule
l'annulation d'une **vente POS** (`orderSource` 2) touche une caisse : le remboursement va au **poste de la vente
d'origine** si sa session est ouverte, sinon au poste `cashRegisterId` du corps s'il a une session ouverte, sinon au
poste unique du site (règle du poste) ; aucune session ouverte → 409 `CASH_REGISTER_CLOSED`. `paymentMethod` 2
(espèces) : le montant sort de la caisse (solde insuffisant → 400 `INSUFFICIENT_FUNDS`) ; pour une vente au comptoir,
une vente payée entièrement en espèces rend **exactement les espèces encaissées** (arrondies ou non) ; sinon le montant
rendu est **arrondi comme à l'encaissement** (5 ¢ en CAD). Enregistré comme remboursement en espèces.
L'annulation d'une commande web ne change pas.

## Clôture : rapport X, comptage, rapport Z (19/10/2026)

**Rapport X** (`GET /api/caisse/report/x`, session ouverte, lecture seule) et contenu du **rapport Z** (même calcul,
figé à la fermeture), montants en cents :
`{cashRegister{id,name}, sessionId, openedAt, openedBy{id,email,name}, openingAmount (solde reporté),
openingFund (fond reporté), currentFund, sales{count, total, cash, byPaymentMethod[{type,label,count,total}]},
taxes[{label, rate, amount}], refunds{count, total, cash, byPaymentMethod[…]}, discounts{count:0,total:0},
movements{deposits, withdrawals, fundDeposits, fundWithdrawals}, cashRounding{count, total}, expectedCash,
discrepancyThreshold, store{name, address, phone, taxNumber}}`.
- `expectedCash` = `openingAmount` + ventes espèces − remboursements espèces + dépôts − retraits + ajouts de fond −
  retraits de fond (arrondis compris) : c'est le solde tenu par la caisse.
- `sales.total` = total fiscal des ventes ; `byPaymentMethod` = montants réellement reçus par moyen (espèces arrondies).
- `taxes` : taxes des ventes de la session, nettes des annulations faites pendant la session (une annulation remet
  aujourd'hui les taxes de la commande à 0 ; une contre-écriture viendra avec le chantier C2).
- `fundDeposits` comprend le `cashFund` donné à l'ouverture.

**Fermeture** `POST /api/caisse/close {cashRegisterId?, countedCash?, cashDetails?: [{typeCash, nombreItems}],
discrepancyReason?, keptFund?}` :
- `cashDetails` (coupures de `GET /api/caisse/denominations`) : le serveur calcule `countedCash` ; si `countedCash` est
  aussi envoyé et diffère → 400 `COUNT_MISMATCH` (+ `countedFromDetails`). Coupure inconnue → 400 `INVALID_CASH_DETAILS`.
- Écart = `countedCash` − `expectedCash`. Au-delà du seuil du site (fiche entreprise, « Écart de caisse toléré sans
  motif », défaut 0), sans `discrepancyReason` → 400 `DISCREPANCY_REASON_REQUIRED` (+ `expectedCash`, `countedCash`,
  `discrepancy`).
- `keptFund` : fond laissé dans le tiroir, entre 0 et `countedCash` (défaut `countedCash`), sinon 400 `INVALID_AMOUNT` ;
  sans comptage, `keptFund` → 400 `INVALID_AMOUNT`. **C'est lui qui est reporté** (solde et fond) à l'ouverture
  suivante ; `bankDeposit` = `countedCash` − `keptFund`.
- Sans comptage : fermeture comme avant (solde reporté en entier) et **rapport Z quand même**, champs de comptage à `null`.
- Le rapport Z reçoit le numéro suivant du poste, sous verrou de la ligne du poste et dans la même transaction SQL que
  la fermeture : suite sans trou, un seul Z par session ; un second envoi répond 400 `CASH_REGISTER_CLOSED` avec
  `lastReport`. Le détail des coupures est aussi gardé sur la transaction « Fermeture ».

**Rapport Z** (réponse de la fermeture, `GET /api/caisse/report/z/{id}`) : `{id, number}` + le contenu du rapport X au
moment de la fermeture + `{closedAt, closedBy, countedCash, cashDetails[{typeCash, label, valueCents, count,
totalCents}], discrepancy, discrepancyReason, keptFund, bankDeposit}`.

Droits : toutes ces routes sont sous `/api/caisse` (caissier, responsable de caisse, administrateur).

## Journal d'audit, numérotation, terminal (chantier C2, 19/10/2026)

Ne concerne que l'activité de caisse ; la boutique en ligne n'écrit rien au journal et n'a pas de numéro de ticket.

**Terminal.** L'activation d'une clé `pos` (`POST /api/app/activate`) rend en plus `terminal: {id, token}`. `id` est
l'identifiant de la clé ; `token` est signé par le serveur (impossible à forger). **Réactiver la même clé rend le même
terminal**, sans en créer un autre : c'est ainsi qu'un terminal activé avant C2 obtient son jeton (le POS rappelle
`POST /api/app/activate` avec sa clé ; seul effet : le compteur d'utilisation de la clé). Le POS envoie
`X-Pos-Terminal: <token>` sur chaque requête (en-tête autorisé par CORS). Facultatif pour l'instant : absent ou invalide,
le terminal est inconnu au journal, sans refus ; il deviendra obligatoire avec le chantier S1. Une clé révoquée rend le
terminal inconnu. Clés `mobile`, `admin`, `livingmemory` : réponse d'activation inchangée.

**Journal** (`pos_audit_log`). Chaque entrée : `{id, sequence, type, occurredAt (heure du serveur), user {id, email,
roles} | null, cashRegisterId, sessionId, terminal {id, label} | null, subject {type, id} | null, data, prevHash, hash}` ;
`hash` = SHA-256(`prevHash` + contenu canonique). Écrite dans la même transaction SQL que l'événement : un événement
refusé ou annulé ne laisse pas de trace, et la séquence n'a pas de trou. Types :

| Type | Quand | `data` |
|---|---|---|
| `LOGIN` / `LOGIN_FAILED` | connexion `platform: "pos"` réussie / refusée (`/api/login`, `/api/otp-verify`) | `email` ; refus : `reason` (`invalid_credentials`, `not_verified`, `platform_not_allowed`, `invalid_otp`), jamais le mot de passe, `user` null |
| `LOGOUT` | `POST /api/logout` d'un terminal identifié (`X-Pos-Terminal` valide) | `email` |
| `SESSION_OPENED` / `SESSION_CLOSED` | ouverture (API ou EasyAdmin) / fermeture | montants d'ouverture ; comptage, écart, motif, fond gardé, rapport Z |
| `CASH_DEPOSIT`, `CASH_WITHDRAWAL`, `FUND_DEPOSIT`, `FUND_WITHDRAWAL` | mouvements de caisse | `amount`, solde et fond après, `cashDetails` |
| `SALE` | vente au comptoir (y compris créée à la main dans EasyAdmin) | numéro de ticket, totaux, arrondi, paiements, articles |
| `REFUND` | remboursement d'une vente au comptoir (`kind: cancellation`) ou retour (`kind: return`) | numéro d'avoir ; annulation : `before` (montants d'origine, taxes) et `after` |
| `Z_REPORT_CREATED` | fermeture | rapport, numéro, ventes, tickets et avoirs |
| `X_REPORT_VIEWED` / `CLOSING_PREVIEW` | rapport X consulté / chargé par le dialogue de fermeture (`purpose=closing`) | espèces attendues, ventes |
| `DRAWER_OPENED` | `POST /api/caisse/drawer-open` | `reason` |
| `CASH_REGISTER_CREATED` / `CASH_REGISTER_UPDATED` | postes (API ou EasyAdmin) | nom, actif, avant / après |
| `DISCOUNT_APPLIED`, `PRICE_CHANGED` | réservés (remises, changements de prix) | — |

Routes (responsable de caisse et administrateur ; caissier → 403) :
- `GET /api/pos/audit?cashRegisterId=&from=&to=&type=SALE,REFUND&page=1&pageSize=50` → `{meta {total, page, pageSize},
  data [entrées]}`, plus récent d'abord, `pageSize` 200 au plus ; type inconnu → 400 `INVALID_TYPE` ; date invalide →
  400 `INVALID_DATE`.
- `GET /api/pos/audit/verify` → `{status: "intact" | "broken", checked, firstBroken: null | {sequence, id, reason}}` :
  séquence continue depuis 1, empreintes chaînées, contenu cohérent.

**Numérotation** (`pos_receipt`). Deux suites continues sans trou **par poste** : tickets (ventes) et avoirs
(remboursements et retours), attribuées sous verrou de la ligne du poste dans la transaction de l'opération.
- Vente `POST /api/order/create-multi-payment` (201) : `receiptNumber` et `receiptKind` (`ticket`, ou `credit_note`
  pour un retour `typeOrder` 2).
- Remboursement `POST /api/order/cancel/{id}` d'une vente au comptoir : `creditNoteNumber` et `cashRegister {id, name}`
  en plus de `message` (annulation d'une commande web : réponse inchangée).
- `GET /api/orders?orderSource=2` et `GET /api/orders/{id}` : chaque vente au comptoir porte `receiptNumber`,
  `creditNoteNumber` et `cashRegister` (les commandes des autres sources n'ont pas ces clés).
- Rapports X et Z : `receipts {first, last, count}` et `creditNotes {first, last, count}` de la session.
- Les ventes antérieures au 19/10/2026 n'ont pas de numéro (aucune reprise de données).

**Contre-écriture.** Aucune route ne supprime une vente au comptoir : une annulation enregistre un paiement négatif, un
mouvement de caisse, un avoir et une entrée `REFUND`. L'annulation remet à 0 les totaux et taxes de la commande
(comportement partagé, conservé) ; les montants d'origine restent dans l'entrée `REFUND` (`before`), l'avoir, la
transaction de vente, les paiements et le rapport Z.

**EasyAdmin** (accord du propriétaire, 19/10/2026) : ni modification ni suppression des ventes au comptoir et de leurs
articles, taxes et paiements, ni des sessions, transactions et détails de caisse (boutons masqués, refus 403 côté
serveur, `Controller/Admin/CashRecordLockTrait`). Consultation et création restent possibles ; les commandes web ne
changent pas. Une vente au comptoir créée à la main (« Create order », source Pos) exige un poste de caisse, puis elle
est numérotée et journalisée avec l'administrateur pour auteur. Conséquence : une vente au comptoir ne s'annule plus
depuis EasyAdmin (passer par le POS).

**Conservation.** Aucune tâche ne purge commandes, paiements, sessions, transactions ou rapports (vérifié le
19/10/2026 : les commandes de nettoyage ne touchent que l'historique des migrations et les modèles de Mémoires Vivantes ;
aucune clé étrangère ne supprime ces données en cascade ; supprimer un caissier est bloqué par ses transactions). Les
données de caisse se gardent au moins 6 ans (Canada, France).


## PIN, déverrouillage, connexion par PIN, validation d'un responsable (chantier C3, 20/10/2026)

Une tablette de caisse est partagée et reste allumée : le POS verrouille son écran après un délai d'inactivité, et le
serveur vérifie tout ce qui touche au PIN et aux droits. Le PIN n'est jamais stocké sur l'appareil. Erreurs :
`{error, message, …contexte}`.

**PIN** (table `pos_user_pin`, la table `user` n'est pas touchée) : 4 à 6 chiffres, **envoyé en chaîne** (`"0123"`),
pour les caissiers, responsables et administrateurs. Empreinte = hacheur des mots de passe appliqué à HMAC-SHA256(PIN,
secret du serveur). Anti-force brute **par compte**, toutes routes confondues : 5 essais faux → PIN bloqué 15 minutes
(**423 `PIN_LOCKED` `{retryAfter}`** en secondes, et en-tête `Retry-After`) ; un PIN juste remet le compteur à 0 ; un
PIN faux renvoie `attemptsLeft`.
- `POST /api/pos/pin {password, newPin}` (connecté) → 204. Mot de passe faux → 400 `PASSWORD_INVALID` ; PIN mal formé →
  422 `VALIDATION_FAILED`.
- EasyAdmin, fiche ou liste d'un utilisateur de caisse : action « Réinitialiser le PIN de caisse » (efface le PIN et le
  blocage ; l'utilisateur en recrée un avec son mot de passe).

**Déverrouillage** : `POST /api/pos/unlock {pin}` (PIN de l'utilisateur du jeton) → 200 `{unlockedAt}` ; 400
`PIN_INVALID` ; 423 `PIN_LOCKED` ; 409 `PIN_NOT_SET` (le POS propose d'en créer un). Pas de nouveau jeton.

**Changement rapide de caissier** (routes de connexion : hors pare-feu JWT, **`X-Pos-Terminal` valide exigé**, sinon
403 `TERMINAL_REQUIRED` ; 30 essais de connexion par PIN par terminal et par 15 minutes, en plus du blocage par compte) :
- `GET /api/pos/users` → `[{id, name, initials, roles}]` : comptes vérifiés `ROLE_USER_POS` ou `ROLE_POS_MANAGER` qui
  ont un PIN, **sans** les administrateurs ni les comptes soumis au code e-mail.
- `POST /api/pos/pin-login {userId, pin}` → mêmes jetons que `/api/login` en `pos` (`{token, refresh_token}`) ; 401
  `PIN_INVALID` ; 423 `PIN_LOCKED` ; 409 `PIN_NOT_SET` ; **403 `PIN_LOGIN_NOT_ALLOWED`** pour `ROLE_ADMIN`,
  `ROLE_SUPER_ADMIN`, tout compte dont le code e-mail est exigé (le PIN ne contourne jamais l'OTP) et tout compte sans
  rôle de caisse.

**Réglages** (fiche entreprise, panneau EasyAdmin « Caisse (POS) ») : `GET /api/pos/settings` →
`{lockAfterMinutes, approvals: {refund: {required, threshold}, withdrawal: {…}, fundWithdrawal: {…}, drawerOpen:
{required}, closeWithoutCount: {required}, discount: {required: false, threshold: null}}, pin: {minLength: 4,
maxLength: 6, maxAttempts: 5, lockMinutes: 15}}`. **`lockAfterMinutes` : 0 = jamais de verrouillage** (défaut 5).
Seuils en cents : 0 = validation toujours (défaut), N = au-delà de N cents, vide = jamais. Tiroir sans vente et
fermeture sans comptage : validation exigée par défaut.

**Validation d'un responsable** (droits vérifiés côté serveur), pour un utilisateur qui n'est ni `ROLE_POS_MANAGER` ni
`ROLE_ADMIN` (eux n'en ont jamais besoin) :

| Action (`action`) | Route protégée | Contexte qui doit correspondre |
|---|---|---|
| `refund` | `POST /api/order/cancel/{id}` d'une **vente au comptoir** (commande web : inchangé) | `orderId` (seuil comparé au montant remboursé) |
| `withdrawal` | `POST /api/caisse/withdraw` | `amount`, `cashRegisterId` |
| `fund_withdrawal` | `POST /api/caisse/cashfundwithdraw` | `amount`, `cashRegisterId` |
| `drawer_open` | `POST /api/caisse/drawer-open` | `cashRegisterId` |
| `close_without_count` | `POST /api/caisse/close` sans `countedCash` ni `cashDetails` | `cashRegisterId` |
| `discount` | réservé (chantier F1) | — |

1. Sans validation : **403 `APPROVAL_REQUIRED` `{action, context}`** (le POS sait quoi demander).
2. Le responsable saisit son PIN sur la tablette du caissier : `POST /api/pos/approvals {action, managerId, pin, context
   {orderId?, amount?, cashRegisterId?}}` (connecté en tant que caissier, terminal exigé) → **201 `{approvalToken,
   expiresAt}`**. Refus : 403 `APPROVER_NOT_ALLOWED` (ni responsable ni administrateur), **409 `PIN_NOT_SET`
   `{managerId}`**, 400 `PIN_INVALID` (compte dans le blocage du responsable), 423 `PIN_LOCKED`, 422 `VALIDATION_FAILED`
   (action inconnue, contexte incomplet), 403 `TERMINAL_REQUIRED`.
3. Le POS rejoue la requête protégée avec **`X-Pos-Approval: <approvalToken>`** (en-tête autorisé par CORS). Jeton :
   2 minutes, usage unique, lié à l'action, au contexte, au terminal et au caissier demandeur ; sinon **403
   `APPROVAL_INVALID`**.
4. Le jeton est consommé **dans la transaction SQL de l'action** : si l'action échoue (fonds insuffisants, etc.), la
   consommation est annulée et le jeton reste utilisable jusqu'à son expiration.

Journal : `PIN_SET`, `PIN_FAILED` (jamais le PIN), `PIN_LOCKED`, `PIN_RESET`, `UNLOCK`, `LOGIN` / `LOGIN_FAILED` avec
`data.method = "pin"`, `APPROVAL_GRANTED`, `APPROVAL_FAILED` ; l'entrée de l'action validée porte `data.approvedBy {id,
name}`. Code : `Services/PosAuditService/PosPinService`, `PosApprovalService`, `PosSettingsService`,
`UseCase/PosAuditUseCase/PosPinUseCase`, `Controller/PosController/PosPinController` ; schéma
`migrations/Version20261020100000.php`, `scripts/migrate_all_v2_pos_pin.sh`.

## Mécanique interne

`HandleCaisseTransactionUseCase` reçoit la session visée, **verrouille sa ligne** (`SELECT … FOR UPDATE`) pendant la
mise à jour de `amountTotal`, puis écrit la `TransactionCaisse` ; il s'exécute dans la transaction SQL de l'appelant
(vente, remboursement) ou dans la sienne. Les refus sont des `CashRegisterException` (`errorCode`, `status`,
`errors`, `context`), rendues `{error, message, errors?, …context}` par les contrôleurs. Rapports :
`Services/CaisseService/CashSessionReportBuilder` (calcul), `UseCase/CaisseUseCase/CashReportsUseCase` (X, liste et
détail des Z), `CaisseOperationsUseCase::close` (comptage, Z) ; coupures : `DenominationService` ; arrondi :
`CashRoundingService`. Schéma : `migrations/Version20261019130000.php`, `scripts/migrate_all_v2_caisse_closing.sh`.

## Connexion du POS

Au premier lancement, le POS s'active avec une clé générée par l'administrateur du site (`POST /api/app/activate`,
voir `docs/architecture.md`, « Clé d'activation des applications mobiles ») ou, à défaut, par
`GET /api/tenant/check-mobile` ; il garde l'hôte complet reçu (`tenant.host`) et l'envoie dans `X-Tenant-Host` sur
chaque requête. Une clé POS peut être **rattachée à un poste de caisse** (`cashRegisterId` à la création ou par
`PUT /api/app-keys/{id}`, écran « Clés des applications ») : l'activation rend alors `cashRegister: {id, name}` (copie
faite sur la clé, le site n'étant pas accessible à ce moment) et le POS présélectionne ce poste, le caissier pouvant en
changer ; `null` sinon, et le POS demande le poste (`GET /api/cash-registers`). Puis le caissier se connecte : `POST /api/login {username, password, platform: "pos"}` →
`{token, refresh_token}` (voir `docs/architecture.md`, « Authentification »).
