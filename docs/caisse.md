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

## Idempotence des écritures : en-tête `Idempotency-Key` (chantier C5 partie 1, 22/10/2026)

Une coupure réseau entre l'envoi d'une écriture et la réception de sa réponse ne doit jamais produire de doublon
(deux ventes, deux tickets, deux dépôts). Le POS envoie sur chaque écriture un en-tête **`Idempotency-Key: <UUID v4>`**
(un nouvel UUID par opération, le même sur tous les réessais de cette opération). Sans en-tête, le comportement est
exactement celui d'avant (web, MAUI, EasyAdmin inchangés).

Routes couvertes (toutes `POST`) : `/api/order/create-multi-payment`, `/api/order/cancel/{id}`, `/api/caisse/open`,
`/api/caisse/close`, `/api/caisse/deposit`, `/api/caisse/withdraw`, `/api/caisse/cashfunddeposit`,
`/api/caisse/cashfundwithdraw`, `/api/caisse/drawer-open`. Sur les autres routes l'en-tête est ignoré.

| Cas | Réponse |
|---|---|
| Première requête | exécutée normalement, réponse habituelle (201 ou 200) |
| Réessai, même clé, même corps, même utilisateur | **même statut et même corps** que la première réponse, en-tête **`Idempotent-Replayed: true`**, aucune écriture (ni commande, ni ticket, ni mouvement, ni entrée de journal) |
| Même clé, corps différent, autre route ou autre utilisateur | **422 `IDEMPOTENCY_KEY_REUSED`** |
| Clé qui n'est pas un UUID | **422 `IDEMPOTENCY_KEY_INVALID`** |
| Première requête encore en cours (moins de 60 s) | **409 `IDEMPOTENCY_IN_PROGRESS`**, en-tête **`Retry-After: 2`** : réessayer avec la même clé |
| Première requête refusée (4xx : 403 `APPROVAL_REQUIRED`, 400 fonds insuffisants, etc.) ou tombée en erreur | la clé est libérée : le réessai avec la même clé (par exemple avec `X-Pos-Approval`) s'exécute |
| Écriture validée mais réponse perdue (coupure juste après la transaction) | réessai → réponse **reconstituée** depuis l'objet créé (ticket, avoir, session, rapport Z, mouvement, ouverture de tiroir), `Idempotent-Replayed: true` |

Règles : la clé est **liée à l'utilisateur connecté** ; conservation **7 jours** (purge automatique) ; la clé est
marquée comme réussie **dans la même transaction SQL que l'écriture** (une écriture validée ne peut donc jamais être
rejouée, et une réservation périmée sans écriture peut être reprise sans risque) ; CORS : `Idempotency-Key` accepté,
`Idempotent-Replayed` et `Retry-After` exposés. Une annulation web (`/api/order/cancel/{id}` depuis le site, sans
en-tête) n'est pas concernée.

Côté POS : générer l'UUID **avant** l'envoi et le conserver avec l'opération en attente ; en cas de délai dépassé ou de
coupure, renvoyer la même requête avec la même clé jusqu'à obtenir une réponse ; traiter une réponse `Idempotent-Replayed`
comme la réponse normale ; sur 409, attendre `Retry-After` secondes et réessayer ; sur 422 `IDEMPOTENCY_KEY_REUSED`,
c'est un bogue du POS (clé réutilisée pour une autre opération) : générer une nouvelle clé.

Code : `Services/PosAuditService/PosIdempotencyService` (réservation, rejeu, purge), `PosWriteResponseBuilder`
(réponses des écritures et reconstitution), `EventSubscriber/PosIdempotencySubscriber` (requête, réponse, exception),
`attach()` appelé dans les cas d'usage (`CreateOrderUseCase`, `CancelOrderUseCase`, `CaisseOperationsUseCase`) ; table
`pos_idempotency_key` ; schéma `migrations/Version20261022100000.php`, `scripts/migrate_all_v2_pos_idempotency.sh` ;
tests `tests/Functional/Boutique/PosIdempotencyApiTest.php`.

## Terminal de paiement Stripe (chantier C4, 23/10/2026)

Intégration **pilotée par le serveur** (WisePOS E, Stripe Reader S700) : la caisse demande le paiement au backend, qui le
transmet au lecteur par Internet et relit son état chez Stripe à chaque interrogation. La tablette ne parle jamais au
lecteur et ne voit aucun numéro de carte (PCI DSS). Le mode « carte déclarée » (part `type: 1` sans
`terminalPaymentId`) reste disponible et inchangé pour les boutiques sans terminal.

**Compte Stripe.** Location, lecteurs et PaymentIntent sont sur le **compte connecté du site** (charge directe, comme la
boutique en ligne). PaymentIntent `card_present` + `interac_present` (si Interac accepté) avec
`payment_method_options.card_present.capture_method = manual_preferred` : une **carte de crédit est autorisée** sur le
lecteur puis **capturée dans la transaction SQL de `create-multi-payment`** ; **Interac** (réseau à message unique) est
**débité aussitôt**, jamais capturé par le serveur. `metadata.pos_terminal = "1"` : le webhook ignore ces intentions
(branche placée avant celle de la boutique) et les événements `terminal.*`. Commission plateforme : créée au taux Interac,
remplacée par le taux carte de crédit à la capture (réglages ci-dessous).

**Clés d'idempotence Stripe.** Création : la clé POS `Idempotency-Key` (C5) de la requête, sinon un UUID gardé avec le
paiement ; envoi au lecteur `pos-process-<pi>`, capture `pos-capture-<pi>`, annulation `pos-cancel-<pi>`, remboursement
`pos-refund-<pi>-<montant>`. Un réessai après une erreur de base retrouve la même capture et ne débite ni ne rembourse
deux fois.

### Réglages par site (EasyAdmin, fiche entreprise, panneau « Terminal de paiement Stripe (caisse) »)

| Réglage | Défaut | Effet |
|---|---|---|
| Lecteur par poste (menu Postes de caisse : code d'appairage, lecteur simulé en clé de test, détacher) et « Terminal activé » | désactivé | `available` de `GET /api/pos/terminal` |
| Commission en magasin, carte de crédit (centièmes de %) | 100 (1 %) | `application_fee_amount` à la capture |
| Commission en magasin, Interac (centièmes de %) | 100 (1 %) | `application_fee_amount` à la création |
| Interac accepté | selon le pays du site (oui pour CA) | types du PaymentIntent |
| Attente de la carte sur le terminal (s) | 120 | au-delà : action du lecteur et intention annulées, `failureCode: timeout` |
| Annulation d'une autorisation carte sans vente (min) | 30 | réconciliation ; Stripe libère de toute façon sous 2 jours |
| Paiement considéré sans vente (min) | 5 | liste des paiements sans vente |
| Annulation par le client sur l'écran du lecteur | oui | `process_config.enable_customer_cancellation` |
| Afficher le panier sur le lecteur | non | `set_reader_display` avant le paiement |

Les paiements en ligne gardent leur commission actuelle (1 %, lot R1). `GET /api/pos/settings` expose en plus
`terminal {interac, cardWaitSeconds, customerCancel, orphanMinutes, authCancelMinutes, currency}` ; `GET /api/cash-registers`
expose `terminal: null | {readerId, label, simulated}`.

### Routes (`/api/pos/terminal`, caisse ; paiements sans vente et carte simulée : responsable ou administrateur ; remboursement : validation C3)

| Méthode, route | Corps | Réponse |
|---|---|---|
| `GET /api/pos/terminal?cashRegisterId=` | — | 200 `{available, cashRegisterId, reader: null \| {id, label, simulated, status: online \| offline, error}, paymentMethods: ["card_present", "interac_present"?], currentPayment, simulationAllowed, settings {cardWaitSeconds, customerCancel, interac}}` ; `available: false` sans terminal (garder la carte déclarée) |
| `POST /api/pos/terminal/payments` | `{cashRegisterId?, amount (cents, part carte), items (comme la vente), otherPayments?: [{type (2 espèces…), amount (cents)}]}` | 201 paiement (ci-dessous), `status: waiting_for_card`. Refus : 409 `TERMINAL_UNAVAILABLE`, 409 `CASH_REGISTER_CLOSED`, 422 `AMOUNT_MISMATCH {quoteTotal, otherPayments, expectedCard, amount}` (part carte ≠ devis du serveur, arrondi des espèces admis), 400 `AMOUNT_TOO_SMALL`, 409 `READER_BUSY {paymentId}`, 409 `READER_OFFLINE`, 409 `READER_NOT_FOUND`, 504 `READER_TIMEOUT` (réessayer), 503 `STRIPE_UNAVAILABLE`, 502 `STRIPE_ERROR` |
| `GET /api/pos/terminal/payments/{id}` | — | 200 paiement relu chez Stripe ; le POS interroge toutes les 1 à 2 s |
| `POST /api/pos/terminal/payments/{id}/cancel` | — | 200 paiement `canceled` (`failureCode: canceled_by_cashier`) ; 409 `READER_BUSY` pendant une autorisation (attendre puis réessayer) ; 409 `PAYMENT_ALREADY_CAPTURED` (passer par le remboursement) ; rejeu sans effet |
| `POST /api/pos/terminal/payments/{id}/refund` | `{amount?}` cents (partiel possible) | Caissier : **403 `APPROVAL_REQUIRED`** (C3, action `refund`, contexte `{orderId}` du paiement rattaché, sinon `{orderId: 0, terminalPaymentId}`), puis rejeu avec `X-Pos-Approval` ; responsable : direct. 200 paiement avec `refund` ; carte de crédit : remboursement en ligne aussitôt ; Interac : `refund.status: waiting_for_card`, le client présente sa carte, suivi par `GET` ; autorisation non capturée : libérée (`canceled`, `voided_by_manager`) ; 409 `PAYMENT_NOT_CAPTURED`, 400 `REFUND_AMOUNT_INVALID`, 502 `TERMINAL_REFUND_FAILED` |
| `GET /api/pos/terminal/payments?orphans=1&cashRegisterId=` | — | 200 `{payments: [...]}` paiements réussis **sans vente** depuis plus du délai réglé (débits et autorisations) : rembourser (`/refund`) ou rattacher en réessayant la vente avec `terminalPaymentId` |
| `POST /api/pos/terminal/payments/{id}/simulate` | `{type: card_present \| interac_present, cardNumber?}` | 200 paiement après carte simulée (lecteur simulé et clé Stripe de test seulement ; 403 `SIMULATION_FORBIDDEN` sinon, même appelée en production) ; journalisée `CARD_SIMULATED`. Cartes de test : 4242 4242 4242 4242 acceptée, 4000 0000 0000 0002 refusée, 4000 0000 0000 9995 fonds insuffisants, 4506 4450 0693 1933 Interac |

Paiement : `{paymentId, stripePaymentIntentId, status: waiting_for_card | processing | succeeded | failed | canceled,
captured, amount, currency, paymentMethodType: card_present | interac_present | null, cardBrand, last4, failureCode,
failureMessage, cashRegisterId, orderId, refund: null | {id, status: waiting_for_card | succeeded | failed, amount,
failureMessage}, createdAt, updatedAt}`. `succeeded` + `captured: false` : carte de crédit autorisée (débit à la vente) ;
`succeeded` + `captured: true` : Interac débité (ou carte capturée). `failureCode` : `insufficient_funds`,
`generic_decline`, `expired_card`… (refus), `timeout`, `canceled_by_cashier`, `authorization_expired`, `reader_offline`.
`Idempotency-Key` (C5) acceptée sur la création, l'annulation et le remboursement.

### Vente et annulation

- `POST /api/order/create-multi-payment` : une part `{type: 1, amount, terminalPaymentId}` (ou `stripePaymentId` =
  PaymentIntent) est vérifiée **puis capturée dans la transaction** : paiement réussi, même montant, même poste, jamais
  rattaché. Refus (rien n'est créé, rien n'est capturé) : 404 `TERMINAL_PAYMENT_NOT_FOUND`, 402
  `TERMINAL_PAYMENT_NOT_SUCCEEDED {status, failureCode}`, 400 `TERMINAL_PAYMENT_AMOUNT_MISMATCH {paid, expected}`, 409
  `TERMINAL_PAYMENT_WRONG_REGISTER`, 409 `TERMINAL_PAYMENT_ALREADY_USED {orderId}`, 402/502 `TERMINAL_CAPTURE_FAILED`.
  Sans `terminalPaymentId` : carte déclarée, comportement d'avant. `payments` garde `stripe_payment_id`, marque et 4
  derniers chiffres ; l'entrée `SALE` du journal porte `data.terminalPayments`.
- **Aucun argent perdu** : capture réussie chez Stripe puis erreur de base → le POS réessaie la vente avec la **même
  clé d'idempotence** : même capture (clé Stripe `pos-capture-<pi>`), vente enregistrée une fois. Sans réessai, la
  réconciliation relit Stripe : le paiement passe « débité » et apparaît dans les **paiements sans vente** ; une
  autorisation capturée n'est jamais annulée.
- `POST /api/order/cancel/{id}` d'une vente payée sur le terminal, remboursée autrement qu'en espèces
  (`paymentMethod ≠ 2`) : **carte de crédit** → remboursement Stripe en ligne dans la transaction de l'annulation
  (`refund_application_fee`, la plateforme rend sa commission) ; **Interac** → le remboursement doit d'abord être
  présenté sur le lecteur : **409 `TERMINAL_REFUND_REQUIRED {terminalPaymentId, amount, paymentMethodType, refund}`**,
  puis `POST /api/pos/terminal/payments/{id}/refund`, suivi par `GET` jusqu'à `refund.status: succeeded`, puis
  l'annulation est rejouée avec **`terminalRefundId`** (= `refund.id`). Remboursement en espèces (`paymentMethod: 2`) :
  repli autorisé, aucun appel Stripe. Validation d'un responsable (C3) inchangée.

### Journal, rapports, réconciliation

Journal (C2) : `CARD_PAYMENT_STARTED`, `CARD_PAYMENT_SUCCEEDED` (autorisation ou débit), `CARD_PAYMENT_FAILED`,
`CARD_PAYMENT_CANCELED`, `CARD_REFUND` (`data.onReader`), `CARD_SIMULATED`, `TERMINAL_CONFIGURED` ; données : montant,
lecteur, poste, PaymentIntent, type, marque et 4 derniers chiffres seulement. Rapports X et Z : ventilation par moyen
inchangée, bloc ajouté **`cardTerminal {terminal, interac, declared, count, withoutSale}`** (cents ; `declared` = parts
carte sans terminal ; `withoutSale` = paiements sans vente du poste).

Réconciliation (`PosTerminalPaymentService::reconcile`) : relit chaque paiement en attente, annule les cartes jamais
présentées (délai d'attente), libère les autorisations sans vente (délai réglé), suit les remboursements Interac. Lancée
à l'ouverture et à la fermeture de session (sans effet sur la réponse), à chaque `GET /api/pos/terminal`, et par la
commande **`app:pos:terminal-reconcile`** : seuls les sites ayant un terminal activé sont parcourus, sortie immédiate
sans paiement en attente (une requête indexée, aucun appel Stripe), verrou fichier + verrou consultatif PostgreSQL par
base, options `--from=HH:MM --to=HH:MM` (plage horaire) et `--tenant=<code>`. **Installation du cron** (hôte, toutes les
15 minutes ; fréquence et plage à ajuster) :

```
*/15 * * * * docker exec -w /var/www symfony_app_v2 php bin/console app:pos:terminal-reconcile --from=07:00 --to=23:59 >> /var/log/pos-terminal-reconcile.log 2>&1
```

Code : `Services/PosTerminalService/` (`PosTerminalGatewayInterface` + `StripeTerminalGateway`, `PosTerminalPaymentService`,
`PosTerminalSettingsService`), `UseCase/PosTerminalUseCase/PosTerminalUseCase`, `Controller/PosController/PosTerminalController`,
`Command/PosTerminalReconcileCommand`, `Entity/PosTerminalPayment` (table `pos_terminal_payment`), colonnes ajoutées sur
`cash_register` (lecteur), `stripe_config` (`terminal_location_id`), `entreprise` (réglages) ; schéma
`migrations/Version20261023100000.php`, `scripts/migrate_all_v2_pos_terminal.sh` ; tests
`tests/Functional/Boutique/PosTerminalApiTest.php` (passerelle simulée `tests/Fake/FakeTerminalGateway`).

**Reste pour la production** (par site réel) : compte Stripe connecté actif et adresse complète de l'entreprise (Location
créée au premier appairage) ; lecteur réel commandé chez Stripe, appairé par son code dans EasyAdmin, terminal activé sur
le poste ; clés `sk_live` (la route de simulation refuse alors toute carte simulée) ; cron de réconciliation installé ;
vérifier dans le tableau de bord Stripe que Terminal est disponible pour la plateforme et le pays du site (CA : CAD,
Interac) ; carte de test physique Stripe pour la recette en magasin.

## Réglages de caisse par site (lot R1, 23/10/2026)

Règle : les réglages importants se modifient **par site dans EasyAdmin** (fiche Entreprise, panneau « Caisse (POS) »,
sauf mention), jamais en constantes ; valeurs par défaut = comportement d'avant. Le POS les lit dans
`GET /api/pos/settings` (blocs ajoutés, blocs existants inchangés) :

```json
{
  "lockAfterMinutes": 5,
  "approvals": { "refund": {"required": true, "threshold": 0}, "withdrawal": {}, "fundWithdrawal": {}, "drawerOpen": {"required": true}, "closeWithoutCount": {"required": true}, "discount": {} },
  "pin": { "minLength": 4, "maxLength": 6, "maxAttempts": 5, "lockMinutes": 15 },
  "terminal": { "interac": true, "cardWaitSeconds": 120, "customerCancel": true, "orphanMinutes": 5, "authCancelMinutes": 30, "currency": "cad" },
  "cashFund": { "openingSuggested": null, "closingKeptSuggested": null },
  "paymentMethods": { "cash": true, "declaredCard": true, "paypal": true, "multiPayment": true, "declaredCardBlockedWithTerminal": false },
  "receipt": { "header": null, "footer": null, "showTaxNumbers": true },
  "heldCarts": { "max": 10, "alertHours": 24 },
  "cashAlertThreshold": null,
  "terminalRequired": false
}
```

| Réglage (EasyAdmin) | Défaut | Effet |
|---|---|---|
| PIN : longueur min (4 à 8), max (jusqu'à 12), essais avant blocage (3 à 10), durée du blocage (min) | 4, 6, 5, 15 | `settings.pin` ; `POST /api/pos/pin` refuse hors longueur (422 `VALIDATION_FAILED`), blocage `PIN_LOCKED` selon essais et durée |
| Fond suggéré à l'ouverture, fond laissé suggéré à la fermeture (cents) | vides | `settings.cashFund` : suggestions pour les écrans du POS ; `open` et `close` inchangés |
| Moyens du POS : espèces, carte déclarée, PayPal, plusieurs parts ; refuser la carte déclarée sur un poste à terminal actif | tous actifs ; refus désactivé | `settings.paymentMethods` ; `create-multi-payment` (vente de caisse seulement) : 422 `PAYMENT_METHOD_DISABLED {type}`, 422 `MULTI_PAYMENT_DISABLED`, 422 `DECLARED_CARD_BLOCKED {cashRegisterId}` (part carte sans `terminalPaymentId` sur un poste dont le terminal est actif). Retours, annulations, web, EasyAdmin : non concernés |
| Ticket : texte d'en-tête, texte de pied (texte simple, balises retirées, retours à la ligne conservés, 500 caractères), afficher les numéros de taxes | vides ; affichés | bloc `store` de la vente et des rapports : ajouts `receiptHeader`, `receiptFooter`, `showTaxNumbers`, `taxNumbers {gst, qst}` ; masqués : `taxNumber` et `taxNumbers` à `null` |
| Numéros de TPS et de TVQ (fiche Entreprise, « Informations générales ») | vides | `store.taxNumbers {gst, qst}` (obligatoires sur les reçus au Québec) |
| Préfixe des numéros de ticket (fiche du poste, 10 caractères) | vide | présentation seulement : `receiptReference` (« C1-123 ») à côté de `receiptNumber` dans la vente, `creditNoteReference` dans l'annulation, `receiptPrefix` dans les rapports X et Z. La suite numérique par poste et par genre (C2) et les numéros déjà attribués ne changent pas |
| Paniers en attente : nombre maximal, délai d'alerte (heures) | 10, 24 | `settings.heldCarts` (POS seulement) |
| Alerte « trop d'espèces » : seuil en cents | vide | `settings.cashAlertThreshold` ; rapports X et Z : `cashAlert` (`true` si `expectedCash` dépasse le seuil, `null` sans seuil) |
| Exiger un terminal identifié (X-Pos-Terminal) sur les routes de caisse | désactivé | voir « Terminaux » ci-dessous |
| Commission plateforme en ligne (page Stripe, centièmes de %) | 100 (1 %) | `application_fee_amount` des paiements de la **boutique en ligne** (`create-intent`, mise à jour du montant, tunnel) : même troncature qu'avant (`(montant × bp) / 10000` tronqué) ; 0 pour un site interne |

### Terminaux (prolongement S1)

La page EasyAdmin `/admin/app-keys` liste les clés par site (libellé, poste, créée, expire, **dernier usage**, état) et
révoque. Depuis R1 : chaque requête portant un `X-Pos-Terminal` valide met à jour le dernier usage (au plus toutes les
5 minutes) ; une requête portant l'en-tête d'une clé **révoquée** reçoit **403 `TERMINAL_REVOKED {terminalId}`** sur
toute route (le POS revient à l'écran d'activation) ; `POST /api/app/activate` avec une clé **`pos`** révoquée répond **403
`APP_KEY_REVOKED`** (une tablette gardant la clé en mémoire ne retrouve pas de jeton) ; clé inconnue ou expirée, et clés des
autres applications (LivingMemory, e-commerce, gestion) : 404 `APP_KEY_INVALID`, réponse et message inchangés. Avec le réglage « exiger un terminal », les routes `/api/caisse`, `/api/pos`,
`/api/cash-registers` et `create-multi-payment` sans terminal valide reçoivent **403 `TERMINAL_REQUIRED`** ; à activer
site par site quand toutes les tablettes envoient l'en-tête. Seules les clés `pos` portent cet en-tête : LivingMemory, la
boutique et l'application de gestion ne sont jamais concernés. Code : `EventSubscriber/PosTerminalGuardSubscriber`,
`PosTerminalService::state()`.

Constantes conservées dans le code (paramètres de sécurité) : jeton de validation 120 s (`PosApprovalService`),
idempotence 60 s et 7 jours (`PosIdempotencyService`), limites d'appels (`config/packages/rate_limiter.yaml`). Code :
`Services/PosAuditService/PosSettingsService` (`pinRules`, `paymentMethods`, `checkPaymentMethods`, `terminalRequired`),
`StripeService::onlineFee`, `Dto/StoreInfoDto` ; schéma `migrations/Version20261023120000.php`,
`scripts/migrate_all_v2_pos_settings.sh` ; tests `tests/Functional/Boutique/PosSettingsApiTest.php`.

## Retours partiels et échanges (lot F2, 24/10/2026)

Un client rapporte un ou plusieurs articles d'une vente, ou les échange. L'annulation complète
(`POST /api/order/cancel/{id}`) est inchangée ; le retour libre `typeOrder: 2` de `create-multi-payment` aussi (il sera
soumis au réglage « retour sans ticket » dans un lot ultérieur).

**Code-barres des tickets.** Chaque ticket et chaque avoir porte `barcode` (Code 128 à imprimer) : `IZ-T-<posteId>-<numéro>`
pour un ticket, `IZ-A-<posteId>-<numéro>` pour un avoir (ex. `IZ-T-1-123`) ; renvoyé par la vente, l'annulation et le
retour. La référence imprimée (`receiptReference`, ex. « C1-123 ») reste utilisable pour retrouver la vente.

**Modèle.** Un retour est une commande « RetourClient » liée à la vente d'origine (`order.returned_from_id`) dont chaque
ligne pointe la ligne vendue (`order_items.returned_from_item_id`), aux **prix payés** ; la table `pos_return` garde
motif, remise en stock, moyen et montants du remboursement, paiement terminal et vente d'échange. Taxes : part de chaque
taxe de la vente revenant à la ligne au prorata de son total, répartie en **cumul** sur les unités retournées (la somme
des retours d'une ligne égale exactement sa part, sans dérive d'arrondi). Le montant d'un retour peut donc différer d'un
cent du prix unitaire taxes comprises d'une vente à l'unité.

### Routes (`/api/pos/returns`, caisse ; validation d'un responsable selon le seuil du site)

| Méthode, route | Corps | Réponse |
|---|---|---|
| `GET /api/pos/returns/lookup?orderId=` ou `?barcode=IZ-T-1-123` ou `?receipt=C1-123[&cashRegisterId=]` | — | 200 `{orderId, reference, soldAt, receiptNumber, receiptReference, barcode, cashRegister, totalAmount, cashRounding, payments {cash, card, other, terminal: null \| {paymentId, paymentMethodType, cardBrand, last4, refundable}}, refunded {cash, card, total}, lines: [{orderItemId, productVariantId, name, saleUnit, quantity, returnedQuantity, returnableQuantity, unitPrice, unitTaxes, unitRefundable, taxes}], eligible {allowed, reason: null \| ORDER_NOT_RETURNABLE \| ORDER_CANCELED \| RETURN_DEADLINE_EXCEEDED \| NOTHING_LEFT_TO_RETURN}, returnDeadline, reasons, restockDefault}` ; 404 `ORDER_NOT_FOUND` / `RECEIPT_NOT_FOUND`, 422 `BARCODE_INVALID`, 409 `RECEIPT_AMBIGUOUS {cashRegisterIds}` (même numéro sur plusieurs postes sans préfixe : préciser le poste ou scanner) |
| `POST /api/pos/returns` | `{orderId, lines: [{orderItemId, quantity}], reasonCode? (settings.returns.reasons), reasonText?, restock? (défaut du site), refund?: {method: original \| cash \| card \| declared_card, terminalRefundId?}, cashRegisterId?}` ; sans ticket (réglage) : `{items: [{productVariantId, quantity}], …}` aux prix actuels | 201 retour (ci-dessous). Refus : 404 `ORDER_NOT_FOUND`, 409 `ORDER_NOT_RETURNABLE`, 409 `ORDER_CANCELED`, 409 `RETURN_DEADLINE_EXCEEDED {days}`, 409 `RETURN_WITHOUT_RECEIPT_FORBIDDEN`, 422 `RETURN_LINE_UNKNOWN`, 422 `RETURN_QUANTITY_EXCEEDED {orderItemId, requested, returnable}`, 422 `RETURN_REASON_REQUIRED`, 422 `RETURN_REASON_INVALID {reasons}`, 422 `REFUND_METHOD_INVALID {cardAvailable}`, 409 `CASH_REFUND_FORBIDDEN {cardPaid}`, 409 `TERMINAL_REFUND_REQUIRED {terminalPaymentId, amount}` (Interac), 409 `TERMINAL_REFUND_ALREADY_USED`, 409 `CASH_REGISTER_CLOSED`, 403 `APPROVAL_REQUIRED` |
| `POST /api/pos/exchanges` | `{return: {…comme ci-dessus…}, sale: {items, paymentMethods?, cashRegisterId?}}` | 201 `{return, sale}` : `sale` = réponse de `create-multi-payment` (ticket) ; mêmes refus, plus ceux de la vente |

Retour : `{returnId, returnOrderId, orderId, exchangeOrderId, creditNoteNumber, creditNoteReference, barcode, cashRegister,
amount, subTotal, totalTax, creditApplied, refund {method, amount, cash, card, cashRounding, terminal: null | {paymentId,
refundId, paymentMethodType, cardBrand, last4}}, reason {code, text}, restock, lines, taxes, createdAt, store}`. Montants
en cents. `Idempotency-Key` (C5) acceptée sur les deux `POST` (rejeu : même réponse, aucun second retour ni remboursement).

### Remboursement

- **Moyen d'origine** (défaut) : la **carte d'abord**, jusqu'au montant payé par carte non encore remboursé, puis les
  espèces pour le reste (les espèces restent dans le tiroir). Espèces arrondies au pas du site (5 ¢ en CAD, C1) ;
  `refund.cashRounding` = écart. `cash` : tout en espèces, refusé si la vente a une part carte sauf réglage
  « remboursement en espèces d'une vente carte » (désactivé par défaut). `card` / `declared_card` : tout sur la carte si
  la part carte remboursable suffit.
- **Carte de crédit payée sur le terminal** : remboursement partiel Stripe en ligne, dans la transaction du retour, clé
  Stripe `pos-refund-<pi>-<montant>-r<clé POS>` (un réessai ne rembourse pas deux fois). **Interac** : flux en deux
  temps comme C4 : 409 `TERMINAL_REFUND_REQUIRED {terminalPaymentId, amount}`, `POST /api/pos/terminal/payments/{id}/refund
  {amount}` (le client présente sa carte, suivi par `GET`), puis rejeu du retour avec `refund.terminalRefundId` (= `refund.id`,
  même montant, utilisable une seule fois). **Carte déclarée** : remboursement déclaré. Un paiement par terminal garde le
  cumul remboursé (`payments.terminal.refundable` dans la recherche).
- Écritures : commande de retour, paiements négatifs, transaction de caisse REFUND (rapports X et Z : `refunds`, plus un
  bloc **`returns {count, total, restocked}`**), **avoir** dans la suite du poste (C2), stock remis si `restock`, journal
  `REFUND` `kind: return` (`lines`, `reason`, `refundMethod`, montants, `terminal`, `creditApplied`).

### Échange (opération unique)

Le retour est créé, puis la nouvelle vente, dans **une seule transaction** : un avoir et un ticket. Crédit appliqué =
min(retour, vente) : la vente porte `creditApplied` et `amountDue` = total + arrondi − crédit ; `sale.paymentMethods`
couvre exactement cette différence (aucun paiement si le crédit couvre tout) ; si le retour dépasse la vente, le reste est
remboursé selon `return.refund.method`. Journal : `REFUND kind return` puis `SALE` avec `creditApplied` ; `pos_return`
lie les deux (`exchangeOrderId`).

### Validation (C3) et réglages

Action **`return`**, contexte `{orderId, amount}` (`orderId: 0` pour un retour sans ticket) : 403 `APPROVAL_REQUIRED` pour
un caissier au-delà du seuil, `POST /api/pos/approvals` puis rejeu avec `X-Pos-Approval` ; `settings.approvals.return
{required, threshold}`. Réglages (fiche Entreprise, panneau « Retours et échanges (caisse) ») et `settings.returns` :

| Réglage | Défaut | Clé |
|---|---|---|
| Délai maximal de retour (jours) | aucun | `maxDays` |
| Retour sans ticket autorisé | non | `withoutReceipt` |
| Remise en stock par défaut | oui | `restockDefault` |
| Remboursement en espèces d'une vente payée par carte | non | `cashRefundForCardSale` |
| Motifs (un par ligne) | Taille ou modèle, Défectueux, Ne convient pas, Erreur de caisse, Autre | `reasons: [{code, label}]` |
| Validation d'un retour : même seuil que le remboursement, ou seuil propre (0 toujours, vide jamais) | même seuil que le remboursement | `approvals.return` |

**Annulation après remboursement Interac validé (C4, 24/10/2026)** : `POST /api/order/cancel/{id}` rejouée avec un
`terminalRefundId` dont le remboursement a été validé par un responsable (ou fait par lui) n'exige pas une seconde
validation ; le journal porte `approvedBy` repris de cette validation. Toute autre annulation garde la règle C3.

Code : `Services/PosReturnService/PosReturnService`, `UseCase/PosReturnUseCase/PosReturnUseCase`,
`Controller/PosController/PosReturnController`, `Entity/PosReturn` ; schéma `migrations/Version20261024100000.php`,
`scripts/migrate_all_v2_pos_returns.sh` ; tests `tests/Functional/Boutique/PosReturnApiTest.php`.

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
