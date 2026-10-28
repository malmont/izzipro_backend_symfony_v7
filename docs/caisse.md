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
d'une vente qui entre en caisse. Les listes de sessions et de transactions sont lues en base à chaque appel (donnée vive
du caissier ; plus de cache depuis le 28/10/2026). Restent en cache 300 s, vidés par toute vente, retour ou paiement
(registre `Cache/CacheTags`, étiquettes `orders` et `payments`) : `GET /api/orders?orderSource=2` et
`GET /api/payments?orderSource=2` ; les meilleures ventes de la grille de favoris suivent la même étiquette (au plus 10 min,
recalculées à la première lecture qui suit une vente ou un retour).

## Postes (`/api/cash-registers`)

| Méthode, route | Rôle | Corps | Réponse |
|---|---|---|---|
| `GET /api/cash-registers` | caisse, admin | `?all=1` (admin : inclut les inactifs) | 200 `[{id, name, isActive, createdAt, openSession: null \| {id, amountTotal, fonDeCaisse, createdAt}}]` |
| `POST /api/cash-registers` | admin | `{name}` | 201 le poste ; nom vide, trop long ou déjà pris → 422 `VALIDATION_FAILED` + `errors[{path, message}]` |
| `PUT /api/cash-registers/{id}` | admin | `{name?, isActive?}` | 200 le poste ; inconnu → 404 `CASH_REGISTER_NOT_FOUND` ; désactiver un poste dont une session est ouverte → 409 `CASH_REGISTER_OPEN` |

Pas de `DELETE` : un poste se désactive.

## Vente en caisse : `POST /api/order/create-multi-payment`

> Ajouts P2-a (26/10/2026) : `items[].custom` (article libre) et `items[].priceOverride` (prix manuel), voir « Article libre et prix manuel » ; `typeOrder: 2` soumis au réglage « retour sans ticket ».
> Ajout F9 (27/10/2026) : `paymentMethods[] {type: 4 | 5, amount, code}` (carte-cadeau, avoir), voir « Cartes-cadeaux et avoirs ».
> Ajout F8 (28/10/2026) : `customerId` (client de caisse rattaché) et `loyalty: {redeemPoints}` (points rachetés en remise de ticket), voir « Fiche client et fidélité ».

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
| `POST /api/pos/terminal/payments` | `{cashRegisterId?, amount (cents, part carte), items (comme la vente) **ou `giftCardAmount` (cents, vente d'une carte-cadeau, complément F9 / C4 du 01/11/2026 : exactement l'un des deux)**, otherPayments?: [{type (2 espèces, 3 PayPal, **4 carte-cadeau, 5 avoir, 6 coupon de fabricant**, jamais 1), amount (cents)}]}` : les autres parts entrent dans la part carte attendue (`expectedCard` = devis − espèces − autres parts) ; la carte-cadeau ou l'avoir n'est débité qu'à la vente, avec son `code` | 201 paiement (ci-dessous), `status: waiting_for_card`. Refus : 409 `TERMINAL_UNAVAILABLE`, 409 `CASH_REGISTER_CLOSED`, 422 `AMOUNT_MISMATCH {quoteTotal, otherPayments, expectedCard, amount}` (part carte ≠ devis du serveur, arrondi des espèces admis), 400 `AMOUNT_TOO_SMALL`, 409 `READER_BUSY {paymentId}`, 409 `READER_OFFLINE`, 409 `READER_NOT_FOUND`, 504 `READER_TIMEOUT` (réessayer), 503 `STRIPE_UNAVAILABLE`, 502 `STRIPE_ERROR` |
| `GET /api/pos/terminal/payments/{id}` | — | 200 paiement relu chez Stripe ; le POS interroge toutes les 1 à 2 s |
| `POST /api/pos/terminal/payments/{id}/cancel` | — | 200 paiement `canceled` (`failureCode: canceled_by_cashier`) ; 409 `READER_BUSY` pendant une autorisation (attendre puis réessayer) ; 409 `PAYMENT_ALREADY_CAPTURED` (passer par le remboursement) ; rejeu sans effet |
| `POST /api/pos/terminal/payments/{id}/refund` | `{amount?}` cents (partiel possible) | Caissier : **403 `APPROVAL_REQUIRED`** (C3, action `refund`, contexte `{orderId}` du paiement rattaché, sinon `{orderId: 0, terminalPaymentId}`), puis rejeu avec `X-Pos-Approval` ; responsable : direct. 200 paiement avec `refund` ; carte de crédit : remboursement en ligne aussitôt ; Interac : `refund.status: waiting_for_card`, le client présente sa carte, suivi par `GET` ; autorisation non capturée : libérée (`canceled`, `voided_by_manager`) ; 409 `PAYMENT_NOT_CAPTURED`, 400 `REFUND_AMOUNT_INVALID`, 502 `TERMINAL_REFUND_FAILED` |
| `GET /api/pos/terminal/payments?orphans=1&cashRegisterId=` | — | 200 `{payments: [...]}` paiements réussis **sans vente** depuis plus du délai réglé (débits et autorisations) : rembourser (`/refund`) ou rattacher en réessayant la vente avec `terminalPaymentId` |
| `POST /api/pos/terminal/payments/{id}/simulate` | `{type: card_present \| interac_present, cardNumber?}` | 200 paiement après carte simulée (lecteur simulé et clé Stripe de test seulement ; 403 `SIMULATION_FORBIDDEN` sinon, même appelée en production) ; journalisée `CARD_SIMULATED`. Cartes de test : 4242 4242 4242 4242 acceptée, 4000 0000 0000 0002 refusée, 4000 0000 0000 9995 fonds insuffisants, 4506 4450 0693 1933 Interac |

Paiement : `{paymentId, stripePaymentIntentId, status: waiting_for_card | processing | succeeded | failed | canceled,
captured, amount, currency, purpose: sale | gift_card, paymentMethodType: card_present | interac_present | null, cardBrand, last4, failureCode,
failureMessage, cashRegisterId, orderId, refund: null | {id, status: waiting_for_card | succeeded | failed, amount,
failureMessage}, createdAt, updatedAt}`. `succeeded` + `captured: false` : carte de crédit autorisée (débit à la vente) ;
`succeeded` + `captured: true` : Interac débité (ou carte capturée). `failureCode` : `insufficient_funds`,
`generic_decline`, `expired_card`… (refus), `timeout`, `canceled_by_cashier`, `authorization_expired`, `reader_offline`.
`Idempotency-Key` (C5) acceptée sur la création, l'annulation et le remboursement.

### Vente et annulation

- **Destination du paiement** (complément F9 / C4, 01/11/2026) : `purpose: sale` (créé avec `items`) ou `gift_card` (créé avec
  `giftCardAmount`), reprise en métadonnée Stripe `pos_purpose`. `create-multi-payment` n'accepte qu'un paiement `sale`,
  `POST /api/pos/gift-cards` qu'un paiement `gift_card` : sinon **409 `TERMINAL_PAYMENT_PURPOSE_MISMATCH`** `{terminalPaymentId,
  purpose, expected}`, rien n'est capturé. Avec `giftCardAmount`, le « devis » est le montant de la carte (ni article ni taxe) : `amount`
  + `otherPayments` doit le couvrir (422 `AMOUNT_MISMATCH`), même intention, même commission, même suivi, même annulation, même
  réconciliation ; en mode kit (F13/F16), même `clientSecret`. Un paiement `gift_card` réussi sans carte émise (coupure entre le terminal et
  `POST /api/pos/gift-cards`) apparaît dans `GET /api/pos/terminal/payments?orphans=1` avec son `purpose` : rejouer `POST /api/pos/gift-cards`
  avec le même `terminalPaymentId` (et la même `Idempotency-Key`) le rattache, sinon `…/refund` le rembourse.
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
- `POST /api/order/cancel/{id}` (01/11/2026) : un **avoir** (commande de retour F2) ou une **vente déjà annulée** → **409 `ORDER_NOT_REFUNDABLE`**
  `{orderId, orderType | status}`, rien ne sort de la caisse ; une vente de carte-cadeau garde son 409 `GIFT_CARD_ORDER`. Avant, l'annulation d'un avoir
  échouait sur l'unicité de l'avoir numéroté (400 avec l'erreur SQL, transaction annulée : aucun remboursement n'est jamais sorti). Commandes web : inchangées.
- Commande **web** déjà annulée (même route, ou nouvel enregistrement dans EasyAdmin d'une commande au statut « Annulé ») : même refus
  **409 `ORDER_NOT_REFUNDABLE`** `{orderId, status: 7}`, rien n'est écrit. Avant, la seconde annulation répondait 200, ajoutait un remboursement de 0
  et **réintégrait le stock une seconde fois**. Statut lu en base, ligne verrouillée (EasyAdmin passe le statut à 7 avant l'appel : la première
  annulation passe ; deux annulations simultanées se suivent). Première annulation web inchangée. Test : `WebOrderDoubleCancelApiTest`.
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
inchangée, bloc ajouté **`cardTerminal {terminal, interac, declared, count, withoutSale, giftCards {terminal, interac, declared, count}}`**
(cents ; `declared` = parts carte sans terminal ; `withoutSale` = paiements sans vente du poste ; depuis le 01/11/2026 les totaux comptent
aussi les paiements terminal des **ventes de cartes-cadeaux** (rapprochement avec les virements Stripe), détaillés dans `giftCards`, et
jamais dans `sales` ni dans le chiffre d'affaires).

Réconciliation (`PosTerminalPaymentService::reconcile`) : relit chaque paiement en attente, annule les cartes jamais
présentées (délai d'attente), libère les autorisations sans vente (délai réglé), suit les remboursements Interac. Lancée
à l'ouverture et à la fermeture de session (sans effet sur la réponse), à chaque `GET /api/pos/terminal`, et par la
commande **`app:pos:terminal-reconcile`** : seuls les sites ayant un terminal activé sont parcourus, sortie immédiate
sans paiement en attente (une requête indexée, aucun appel Stripe), verrou fichier + verrou consultatif PostgreSQL par
base, options `--from=HH:MM --to=HH:MM` (plage horaire), `--tenant=<code>` et `--no-alerts` (M1). **Planification (installée le
26/10/2026)** : timer systemd de l'hôte `pos-terminal-reconcile.timer`, toutes les 5 minutes jour et nuit, journal
`/var/log/pos-terminal-reconcile.log` (rotation quotidienne, 30 jours) ; fichiers et commandes dans `ops/` et
`docs/mise-en-production-caisse.md`. Chaque exécution écrit son état daté dans `var/pos-terminal-reconcile.status.json` (dernière
exécution, dernière exécution complète, 50 dernières ; fichier `.status.test.json` pour les tests), lu par `GET /api/pos/health`, puis
lance la surveillance et les alertes (voir « Paiements de caisse en production »).

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

**Avoirs (01/11/2026)** : un **avoir** (commande de retour, type 2) ou une vente de carte-cadeau (type 3) donné en `orderId` à `lookup`,
`returns` ou `exchanges` → **409 `ORDER_NOT_RETURNABLE`** `{orderId, orderType, originalOrderId}` (« on ne retourne pas un retour ») ; avant,
`lookup` répondait 200 avec `eligible.allowed: false`, que le POS pouvait ignorer. Le code-barres d'un avoir de retour (`IZ-A-…`) renvoie,
lui, à la **vente d'origine**, qui reste consultable et retournable pour ses unités restantes ; celui d'un avoir d'annulation renvoie à la
vente annulée (`eligible.reason: ORDER_CANCELED`).

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

## Remises de caisse (lot F1, 24/10/2026)

État des lieux : la boutique en ligne n'a aucun système de remise ni de code promo (seul le prix spécial daté du produit,
`specialPrice`, existe) ; la remise F1 est propre à la caisse, **jamais honorée sur les chemins web** (`cart/quote`,
`create-intent`, `order/create` ignorent `discounts` et `ticketDiscount`). Coupons du fabricant (base taxable non réduite
au Québec) : hors F1, lot ultérieur.

**Calcul par le serveur, seule source de vérité.** Remises de ligne (`items[].discounts`, plusieurs, appliquées dans
l'ordre sur le net restant, plancher 0) : `{type: percent | amount, value, reasonCode?, reasonText?}` (`percent` : 0,01 à
100, deux décimales ; `amount` : cents). Remise de ticket (`ticketDiscount`, une seule, même forme) répartie au **prorata
des nets de ligne**, l'écart d'arrondi sur la dernière ligne. **Taxes calculées sur le net** de chaque ligne (remise du
commerçant = base taxable réduite). Total et `cashTotal` (arrondi des espèces, C1). Les lignes de commande gardent le prix
unitaire **brut** (`unit_price`), le **net** en `total_price`, les remises (`discounts` JSON avec `scope: line | ticket`,
`discount_amount`) ; la commande garde la remise de ticket (`discount_amount`, `discount_percent_bp`, motif).

### Routes

| Méthode, route | Corps | Réponse |
|---|---|---|
| `POST /api/pos/quote` (caisse, lecture, sans `Idempotency-Key`) | `{items: [{productVariantId, quantity, customizationId?, discounts?}], ticketDiscount?, cashRegisterId?, customerId?, loyalty?: {redeemPoints}}` (F8 : récompense fidélité, voir « Fiche client et fidélité ») | 200 comme `cart/quote` (taxes du magasin) plus `grossSubtotal`, `discounts {lines, ticket: null \| {type, value, amount, reasonCode, reasonText, scope}, total, gross}`, `cashTotal` ; lignes : `gross`, `discounts [{type, value, amount, reason…, scope}]`, `discountAmount`, `total` (net). Refus : 422 `DISCOUNT_INVALID`, 409 `DISCOUNT_NOT_ALLOWED {scope?}`, 422 `DISCOUNT_REASON_REQUIRED`, 422 `DISCOUNT_REASON_INVALID {reasons}`, 422/404 `QUOTE_INVALID` |
| `POST /api/order/create-multi-payment`, `sale` de `POST /api/pos/exchanges`, `POST /api/pos/terminal/payments` | mêmes `items[].discounts` et `ticketDiscount` (facultatifs : rien ne change sans eux) | la vente recalcule tout : paiements = net (sinon 400 `PAYMENT_TOTAL_MISMATCH`), part carte du terminal sur le net ; réponse et bloc ticket : `grossSubTotal`, `discounts {total, ticket, lines: [{productVariantId, quantity, gross, net, amount, discounts}]}` (dollars) ; mêmes refus que le devis plus 403 `APPROVAL_REQUIRED {action: "discount", context: {amount, percent, scope}}` et 403 `DISCOUNT_EXCEEDS_LIMIT {limit, amount, percent}` |

Journal `DISCOUNT_APPLIED` (une entrée par vente remisée : `gross`, `total`, `ticket`, `lines`, `approvedBy` si validation).
Rapports X et Z : `discounts {count, total}` (ventes remisées, remises de ligne et de ticket comprises). Retours F2 : le
remboursable d'une ligne est son **net payé** ; dans `GET /api/pos/returns/lookup`, **`unitPrice` est désormais le net payé
de la prochaine unité** (remises déduites), **`unitGross`** le prix unitaire brut et **`discountAmount`** la remise de la
ligne ; sans remise, `unitPrice` = `unitGross` comme avant.

### Droits, validation (C3) et réglages

| Rôle | Règle |
|---|---|
| Caissier | remise totale de la vente ≤ plafond caissier (pourcentage du brut **et** montant) ; au-delà : 403 `APPROVAL_REQUIRED {action: "discount", context: {amount (cents), percent (centièmes de %), scope: line \| ticket \| mixed}}`, `POST /api/pos/approvals` puis rejeu avec `X-Pos-Approval` ; la validation porte le plafond au plafond responsable |
| Responsable | ≤ plafond responsable, sans validation ; au-delà : 403 `DISCOUNT_EXCEEDS_LIMIT` |
| Administrateur | sans plafond |

Réglages (fiche Entreprise, panneau « Remises (caisse) ») et `settings.discounts` : remises autorisées (`enabled`, oui),
remise de ticket (`ticketEnabled`, oui), caissier `{maxPercent: 10, maxAmount: 2000}`, responsable `{maxPercent: 50,
maxAmount: 20000}`, motif obligatoire (`reasonRequired`, oui), motifs `reasons [{code, label}]` (Article abîmé, Fidélité,
Prix affiché, Geste commercial, Autre). `settings.approvals.discount` = `{required: enabled, threshold: plafond caissier
(cents), maxPercent}`. Code : `Services/PosDiscountService/` (`PosDiscountCalculator` calcul pur, `PosDiscountService`
réglages, contrôle, devis), `Controller/PosController/PosQuoteController` ; schéma `migrations/Version20261024120000.php`,
`scripts/migrate_all_v2_pos_discounts.sh` ; tests `tests/Functional/Boutique/PosDiscountApiTest.php`.

## Ticket numérique : réimpression, courriel, QR code (lot F3, 24/10/2026)

**Ticket complet côté serveur.** Le bloc ticket de la vente, de l'annulation, du retour et de l'échange porte en plus :
`kind` (`sale | exchange | return | cancellation`), `duplicate` (faux à l'émission), `reprintCount`, `issuedAt`,
`receiptUrl` (lien public du reçu, null si le QR est désactivé), `cashier {id, name}`, `currency`, `lines
[{productVariantId, name, saleUnit, quantity, unitPrice (brut), gross, discountAmount, total (net), discounts}]`, `change`
(monnaie rendue, ventes), et pour chaque paiement `cardBrand`, `last4`, `paymentMethodType` (`card_present`,
`interac_present`, `declared`). Mentions légales présentes (Québec, France) : raison sociale, adresse, téléphone, numéros de
TPS et TVQ (`store.taxNumbers`, R1) ou numéro fiscal / TVA intracom (`store.taxNumber`), date et heure, numéro et nature du
document, poste, caissier, détail des articles (quantité, prix unitaire, remises avec motif), sous-total, chaque taxe
séparément avec son taux, arrondi des espèces, total et devise, moyens de paiement détaillés, monnaie rendue, en-tête et pied.

**SMS** : aucun fournisseur n'est configuré (Notifier Symfony en courriel seulement) ; rien n'est codé. Plus tard : Twilio
ou Vonage par le Notifier, un réglage « reçu par SMS », coût par message.

### Routes

| Méthode, route | Corps | Réponse |
|---|---|---|
| `GET /api/pos/receipts?barcode=IZ-T-1-123` ou `?orderId=[&kind=ticket\|credit_note]` ou `?kind=&number=&cashRegisterId=` (caisse) | — | 200 bloc ticket complet avec **`duplicate: true`** (mention « DUPLICATA » à imprimer), `reprintCount` incrémenté ; pour un échange, `exchange {returnId, returnOrderId, saleOrderId, other}` (l'autre document) ; journal `RECEIPT_REPRINTED`. 404 `RECEIPT_NOT_FOUND`, 422 `BARCODE_INVALID` |
| `POST /api/pos/receipts/email {barcode \| orderId, email}` (caisse, `Idempotency-Key` acceptée) | — | 200 `{sent: true, barcode, to}` (`to` masqué `j***@domaine`) : courriel HTML aux couleurs du profil `pos` (nom, logo, couleur) avec le reçu complet et **le PDF joint** (rouleau 80 mm), envoyé par le SMTP du site via la file `email`. Refus : 409 `RECEIPT_EMAIL_DISABLED`, 422 `EMAIL_INVALID`, 503 `EMAIL_NOT_CONFIGURED` (site sans configuration d'envoi), 502 `EMAIL_SEND_FAILED`. Rejeu : même réponse, aucun second courriel |
| `POST /api/pos/receipts/revoke-link {barcode \| orderId}` (responsable, administrateur) | — | 200 `{revoked: true, barcode, receiptUrl}` : les liens déjà imprimés du ticket cessent de fonctionner (version du lien), un nouveau lien est émis ; journal `RECEIPT_LINK_REVOKED` |
| `GET /api/r/{jeton}` (**public**, `?format=pdf`) | — | page HTML simple du reçu (ou PDF), `X-Robots-Tag: noindex, nofollow, noarchive`, `Cache-Control: private, no-store` ; 404 jeton invalide, 410 lien expiré ou révoqué (message clair). Aucune donnée au-delà du ticket |

**Lien public et QR.** `receiptUrl` = `https://<hôte public du site>/api/r/<jeton>` : servi **sous `/api/`** parce que l'hôte public
d'un site (ex. `demo.arkanoa-media.com`) sert le site Next.js et ne route vers le backend que `/api/` ; rien ne change donc
sur le site web. Le jeton (`base64url(ticket.version.expiration)` + HMAC SHA-256 tronqué, secret de l'application, lié au
site) est non devinable et sans table de jetons ; expiration en fin de journée après la durée réglée (même QR toute la
journée). Le POS imprime `receiptUrl` en QR code sur le ticket (si `settings.receipt.qrPrintDefault`) ou l'affiche à l'écran.

**Données personnelles (Loi 25, RGPD).** L'adresse courriel saisie ne sert qu'à l'envoi : elle n'est enregistrée nulle
part (ni base, ni journal, ni empreinte), elle ne transite que dans le message de la file `email` le temps de l'expédition ;
le journal `RECEIPT_EMAILED` garde l'adresse masquée (`j***@domaine`), le ticket et le caissier. Aucun usage marketing.

### Réglages (`settings.receipt`, ajouts)

| Réglage (fiche Entreprise, panneau « Caisse (POS) ») | Défaut | Clé |
|---|---|---|
| Reçu par courriel | oui | `emailEnabled` |
| Reçu par QR code (lien public) | oui | `qrEnabled` |
| Validité du lien (jours) | 90 | `linkDays` |
| QR imprimé par défaut sur le ticket | oui | `qrPrintDefault` |

Code : `Services/PosAuditService/PosReceiptLinkService` (jeton, URL), `PosReceiptMailer` (HTML, PDF dompdf, envoi),
`UseCase/PosReceiptUseCase/PosReceiptUseCase`, `Controller/PosController/PosReceiptController`, `PosPublicReceiptController`,
gabarits `templates/pos/` et `templates/emails/pos_receipt.html.twig` ; schéma `migrations/Version20261024140000.php`,
`scripts/migrate_all_v2_pos_receipt_digital.sh` ; tests `tests/Functional/Boutique/PosReceiptApiTest.php`.

## Mode hors ligne (chantier C5 partie 2, 25/10/2026)

Hors ligne, la tablette vend **en espèces seulement**, calcule avec la règle publiée ci-dessous et le **pack** reçu du
serveur, imprime un ticket **provisoire** `P-<terminal>-<compteur>` (mention « provisoire »), puis synchronise par lots.
Carte, remboursements, retours, échanges, retraits, clôture : interdits hors ligne. Le verrouillage par inactivité reste
actif et le déverrouillage attend le réseau (décision du propriétaire). Désactivé par défaut (`settings.offline.enabled`),
et désactivé d'office quand les taxes du site viennent de Stripe Tax (`reason: TAX_PROVIDER_STRIPE`).

### Pack : `GET /api/pos/offline-pack?cashRegisterId=` (caisse)

En-tête `ETag` ; `If-None-Match` identique → **304** sans corps. À rafraîchir au démarrage et toutes les heures ; au-delà de
`validUntil`, la tablette ne vend plus hors ligne. Corps : `packVersion`, `generatedAt`, `serverTime`, `validUntil`,
`site {code, name, currency, taxProvider, cashRoundingStep, country, province}`, `cashRegisters [{id, name, receiptPrefix,
terminalEnabled}]`, `taxes [{id, label, rate}]`, `paymentMethods [{type, label, offline}]` (espèces seules `offline: true`),
`denominations`, `products [{id, code, barcode (produit, celui scanné aujourd'hui), name, saleUnit, categoryIds, price,
specialPrice, specialPriceFrom, specialPriceTo, variants [{id, label, price, barcode (variante, facultatif), stock,
customizable}]}]` (produits `isPos` vendables, sans location ; `customizable` : vendu hors ligne au prix de base), `discounts`
(réglages F1), `settings {pin, lockAfterMinutes, receipt, paymentMethods, cashFund, cashAlertThreshold, offline}`,
`calculationRule: "pos-offline-v1"`, `testCases: "/api/pos/offline-cases"`. Chaque pack émis est conservé sous sa version
(`pos_offline_pack` : prix, taxes, pas d'arrondi) pour recalculer les ventes aux conditions connues de la tablette. Scan
hors ligne : d'abord `variants[].barcode`, puis `products[].barcode`.
Grille de favoris (F10) : clé `favorites [{productId, variantId, defaultVariantId, pinned, rank}]` **seulement si les favoris sont
activés** ; sinon contenu et ETag du pack inchangés. Depuis le 28/10/2026, elle ne contient que les **épinglés** du poste (ou du site),
toujours `pinned: true`, rangs 1..n, prix dans `products` ; les plus vendus restent servis en ligne par `GET /api/pos/favorites` (ils
changeaient à chaque vente et créaient une version de pack à chaque passage). Même format d'entrée : rien à changer côté caisse.

### Règle de calcul « pos-offline-v1 » et jeu de cas partagé

Cents entiers ; `round` = entier le plus proche, demi-cent vers le haut en valeur absolue (`round()` de PHP).

1. Prix unitaire = `variant.price` sinon `product.specialPrice` si la date de vente (corrigée) est dans la plage, sinon `product.price`.
2. Brut de ligne = prix unitaire × quantité.
3. Remises de ligne dans l'ordre : `percent` → `round(netCourant × valeur / 100)`, `amount` → `min(valeur, netCourant)` ; plancher 0.
4. Remise de ticket : base = Σ nets ; montant idem ; répartie au prorata des nets (`round(montant × net_i / base)`), la **dernière ligne** porte l'écart.
5. Sous-total = Σ nets. 6. Pour chaque taxe du pack : `amount_i = round(sousTotal × rate_i)` (sur le total net). 7. Total = sousTotal + Σ amount_i.
8. `cashTotal = round(total / pas) × pas` ; `cashRounding = cashTotal − total`. 9. Monnaie = espèces reçues − cashTotal.

Le jeu de cas `config/pos/offline-cases.json` (servi par `GET /api/pos/offline-cases`) donne pour chaque cas les lignes,
remises, taxes, pas et le résultat attendu ; le serveur le rejoue dans ses tests (`PosOfflineApiTest`) et le POS doit le
rejouer à l'identique : c'est la garantie que le total hors ligne égale le total serveur. Code : `Services/PosOfflineService/PosOfflineCalculator`
(même code de remises que F1 : `PosDiscountCalculator`).

### Synchronisation : `POST /api/pos/offline-sales` (caisse, `X-Pos-Terminal` obligatoire, 1 à 200 ventes)

Corps : `{packVersion, deviceTime (ISO 8601, heure de la tablette à l'envoi), clockOffsetSeconds, sales: [{idempotencyKey (UUID),
provisionalNumber "P-<terminal>-<compteur>", cashRegisterId, sellerId (vendeur réel), soldAt (heure tablette), items [{productVariantId,
quantity, discounts?}], ticketDiscount?, payments [{type: 2, amount}], cashReceived, totals {subtotal, taxes [{label, amount}], total,
cashTotal, cashRounding}, packVersion?, chain {seq, prevHash, hash}}], approvals?: {"P-12-7": "<jeton X-Pos-Approval>"}}`.

Réponse 200 `{syncId, serverTime, clockOffsetSeconds, chainBroken, results: [...]}`, une entrée par vente dans l'ordre :

| `status` | Contenu |
|---|---|
| `accepted` | `orderId`, `receiptNumber`, `receiptReference`, `barcode`, `soldAt` (heure corrigée retenue), `priceDrift {amount, taxes [{label, expected, declared, drift}], within}`, `currentPriceDrift` (information : écart avec le prix courant), `stockShortfall [{productVariantId, shortBy, stockBefore}]`, `lateEntry`, `chainBroken`, `forced`, `totals {expected, declared, cashTotal}` |
| `duplicate` | même `idempotencyKey` déjà acceptée pour ce terminal : même contenu que la première réponse, aucune seconde commande |
| `rejected` | `error`, `message`, `forceable`, contexte ; la vente **reste en file** : `OFFLINE_DISABLED`, `OFFLINE_LIMIT_EXCEEDED`, `OFFLINE_SALE_INVALID` (structure, variante inconnue du pack, moyen non espèces, remise au-delà du plafond du vendeur, paiements ≠ cashTotal), `SELLER_INVALID`, `PACK_UNKNOWN`, `CASH_REGISTER_CLOSED`, et les trois cas **forçables** : `PRICE_DRIFT_EXCEEDED {drift, tolerance, expected, declared, taxes}`, `PACK_EXPIRED`, `OFFLINE_TOO_OLD` |

Règles : heure retenue = `soldAt` + décalage (le plus grand, en valeur absolue, du décalage déclaré et de celui mesuré entre
`deviceTime` et l'heure du serveur) ; `order_date` = heure corrigée, `synced_at` = enregistrement. Vendeur = `sellerId` (droit
de caisse exigé), le compte qui synchronise est journalisé (`data.syncedBy`). Session : celle ouverte sur le poste à la
synchronisation ; vente datée avant son ouverture → `lateEntry`. **Recalcul au pack déclaré** (prix et taxes de `packVersion`,
prix spécial selon la date de vente) : `priceDrift` = total perçu − recalcul ; au-delà de `priceTolerance` → `PRICE_DRIFT_EXCEEDED`,
sauf **acceptation forcée** : jeton de validation C3 (`POST /api/pos/approvals`, action **`offline_force`**, contexte
`{terminalId, counter, amount}` = compteur du numéro provisoire et `cashTotal` perçu), un jeton par vente dans `approvals`,
journalisé (`forced {code, approvedBy}`). La vente est enregistrée **aux montants perçus et aux taxes imprimées** ; un
changement de prix légitime pendant la coupure ne crée aucun écart (`currentPriceDrift` l'indique à titre d'information).
Stock décrémenté même sous zéro (`stockShortfall`). Remises hors ligne : plafond du vendeur du pack, sans validation possible.
Chaînage : `hash = sha256(prevHash + JSON canonique de la vente sans chain)` (clés triées, sans espaces, UTF-8 et `/` non
échappés) ; rupture (prevHash ≠ dernier hash connu du terminal, ou hash faux) → `chainBroken: true`, signalée, jamais bloquante.
Chaque vente est traitée dans sa propre transaction.
**Clé déjà utilisée par une vente en ligne** (29/10/2026) : une vente passée par `create-multi-payment` avec `Idempotency-Key` puis
perdue par la caisse (réponse non reçue) et mise en file sous la même clé est reconnue (stockage C5 partie 1, même site) : même
montant → `duplicate` avec la commande existante (`orderId`, `receiptNumber`, `receiptReference`, `barcode`, `onlineSale: true`),
aucune seconde commande ; autre montant → `rejected` **`IDEMPOTENCY_KEY_REUSED`** (non forçable). Journal `OFFLINE_SYNC` (`kind`
`online_duplicate` | `online_key_reused`).

Journal : `SALE` avec `data.offline {provisionalNumber, soldAt, deviceSoldAt, clockOffsetSeconds, packVersion, chain, chainBroken,
priceDrift, currentPriceDrift, stockShortfall, lateEntry, forced, cashReceived}` et `data.syncedBy` ; `OFFLINE_SYNC` par lot.
Ticket : `provisionalNumber` à côté du numéro officiel (vente, réimpression F3). Rapports X et Z : bloc **`offline {count,
total, priceDrift, stockShortfalls, lateEntries}`**. Historique : `GET /api/pos/offline-sales?terminalId=&limit=` (responsable).

### Réglages (`settings.offline`)

| Réglage (fiche Entreprise, panneau « Mode hors ligne (caisse) ») | Défaut | Clé |
|---|---|---|
| Mode hors ligne autorisé | **non** | `enabled` (+ `reason`: `DISABLED` \| `TAX_PROVIDER_STRIPE`) |
| Durée maximale hors ligne (h) | 24 | `maxOfflineHours` |
| Ventes en file au plus | 100 | `maxQueuedSales` |
| Montant en file au plus (cents) | 500 000 | `maxQueuedAmount` |
| Écart de prix toléré par vente (cents) | 5 | `priceTolerance` |
| Validité du pack (h) | 24 | `packValidityHours` |

Code : `Services/PosOfflineService/` (`PosOfflineCalculator`, `PosOfflinePackService`, `PosOfflineSyncService`, `PosOfflineSettings`),
`Controller/PosController/PosOfflineController`, entités `PosOfflinePack`, `PosOfflineSale`, `PosOfflineSync` ; colonnes
`product_variant.barcode`, `pos_receipt.provisional_number`, `order.synced_at` ; schéma `migrations/Version20261025100000.php`,
`scripts/migrate_all_v2_pos_offline.sh` ; tests `tests/Functional/Boutique/PosOfflineApiTest.php`.

### Simulation d'échange (complément F2, 25/10/2026)

`POST /api/pos/exchanges/quote {return: {orderId, lines}, sale: {items (remises F1), ticketDiscount?, cashRegisterId?}}` (lecture,
sans `Idempotency-Key`) → `{returnAmount, sale {subtotal, grossSubtotal, discounts, taxes, total, cashTotal}, creditApplied,
amountDue, cashAmountDue (arrondi des espèces), refundDue, cashRefundDue}` : exactement le calcul de `POST /api/pos/exchanges`,
sans écriture ; les paiements de l'échange doivent couvrir `amountDue` (`cashAmountDue` en espèces). Mêmes erreurs de validation.

## Article libre et prix manuel (lot P2-a, F7, 26/10/2026)

Chemins de caisse seulement (`POST /api/pos/quote`, vente `create-multi-payment` source 2, échange et son devis, retours F2,
paiement terminal C4 via le devis, hors ligne C5-2). Le web (`/api/cart/quote`, `create-intent`, `order/create`) ignore ces
clés : réponses inchangées. Désactivés par défaut (réglages par site). Code : `Services/PosLineService/PosLineService`.

### Article libre

Vendre un article absent du catalogue (« Divers », retouche, service) : `items[].custom {label (≤ 120), unitPrice (cents),
taxable?: true}` **sans** `productVariantId`. Modèle : **ligne de commande sans variante** (`order_items.product_variant_id`
null, `custom_label`, `taxable`) ; aucun mouvement de stock, ni à la vente ni au retour ; aucun produit « Divers » créé.
`taxable: false` (exonéré / détaxé, p. ex. produits de base au Québec) : la ligne est exclue de l'assiette (table) ou envoyée
avec le code Stripe « non taxable ». Les remises F1 s'appliquent aussi aux lignes libres.

Lecteurs des listes de commandes (admin, MAUI, `GET /api/orders`) : une ligne libre garde **exactement** la structure d'une
ligne normale : `productId: 0`, `productVariantId: null`, `productVariantName` = libellé, `productVariantColor/Size/Image:
null`, `options: []`. Statistiques : les totaux de commande comptent la ligne, les classements par produit l'ignorent.

### Prix manuel

Remplacer le prix d'un article du catalogue sur une ligne : `items[].priceOverride {unitPrice (cents), reasonCode?,
reasonText?}` avec `productVariantId`. Distinct d'une remise F1 (qui s'applique après, sur le prix manuel) : la ligne garde
`catalogUnitPrice` (prix catalogue, supplément de personnalisation compris) et le ticket montre les deux. Base :
`order_items.catalog_unit_price` (null = prix catalogue appliqué, comme avant), `price_reason_code`, `price_reason_text`.
Une **hausse** de prix est journalisée mais jamais soumise à validation.

### Devis, ticket, retours, rapports (ajouts facultatifs)

- Devis (`/api/pos/quote`, échange, `exchanges/quote`) : `lines[].custom {label, taxable}`, `lines[].catalogUnitPrice`,
  `lines[].priceOverride {unitPrice, reasonCode, reasonText, difference}` (`difference` = (catalogue − manuel) × quantité,
  positive pour une baisse) ; résumés **`customItems {count, total}`** et **`priceOverrides {count, catalogTotal, total,
  difference, reduction}`** (toujours présents sur le devis de caisse ; sur les devis partagés, seulement si la vente en contient).
- Ticket (vente, réimpression, lien public) : `lines[].name` = libellé pour un article libre, `custom`, `taxable`,
  `catalogUnitPrice` (dollars, null sinon), `priceReason {code, text}`.
- Retours F2 : `lookup.lines[].custom`, `lookup.lines[].catalogUnitPrice` ; retour **au prix payé** (prix manuel ou prix
  saisi) ; un article libre n'est jamais remis en stock (`restock` sans effet) ; une ligne libre non taxable n'a aucune part
  de taxe (les lignes taxables se partagent les taxes de la vente).
- Rapports X et Z : **`customItems {count, total}`** (lignes, total net) et **`priceOverrides {count, catalogTotal, total,
  difference}`**.
- Journal : **`PRICE_CHANGED`** par ligne {reference, receiptNumber, orderItemId, productVariantId, name, quantity, before, after,
  difference, reason {code, text}, approvedBy?} ; `SALE.data.customItems [{label, quantity, unitPrice, totalPrice, taxable}]`,
  `customItemsTotal`, `approvedBy` si validation.

### Droits, validation (C3) et réglages

| Réglage (fiche Entreprise, panneau « Article libre et prix manuel (caisse) ») | Défaut | Clé `GET /api/pos/settings` |
|---|---|---|
| Article libre autorisé | **non** | `customItems.enabled` |
| Article libre : montant maximal par vente sans validation (cents) | vide (jamais) | `customItems.maxAmount` (null = jamais, 0 = toujours) |
| Prix manuel autorisé | **non** | `priceOverride.enabled` |
| Prix manuel : baisse maximale par vente sans validation (cents) | vide (jamais) | `priceOverride.maxAmount` |
| Prix manuel : motif obligatoire | oui | `priceOverride.reasonRequired` |
| Motifs de prix manuel (un par ligne) | Article abîmé, Prix concurrent, Prix négocié, Erreur d'étiquette, Autre | `priceOverride.reasons [{code, label}]` |

`settings.approvals.customItem` et `settings.approvals.priceOverride` : `{required, threshold}`. Erreurs : 409
`CUSTOM_ITEM_NOT_ALLOWED` / `PRICE_OVERRIDE_NOT_ALLOWED` (désactivé, ou hors caisse), 422 `PRICE_OVERRIDE_REASON_REQUIRED`,
`PRICE_OVERRIDE_REASON_INVALID {reasonCode, reasons}`, `QUOTE_INVALID` (libellé, prix, `taxable` invalides).

Au-delà du montant maximal, pour un caissier : 403 `APPROVAL_REQUIRED` action **`custom_item`** contexte `{amount}` (total net
des articles libres de la vente) ou **`price_override`** contexte `{amount}` (somme des baisses). Responsable et
administrateur : jamais de validation.

**Validation groupée (P2-a).** Quand une vente cumule plusieurs actions soumises à validation (remise F1, article libre, prix
manuel), le serveur les demande **en une fois** : 403 `APPROVAL_REQUIRED {action, context, actions: [{action, context}, …]}`
(`action`/`context` = la première, compatibilité C3). `POST /api/pos/approvals {actions: [{action, context}, …], managerId,
pin}` renvoie **un** jeton couvrant toutes ces actions (le responsable saisit son PIN une seule fois) ; `{action, context,
managerId, pin}` reste accepté. Le jeton (`X-Pos-Approval`) doit couvrir chaque action demandée avec son contexte (sinon 403
`APPROVAL_INVALID {action, actions}` : « jeton d'une autre action » ou « contexte différent », jeton non consommé) ; il est
consommé une fois pour toute la vente. Base : `pos_approval.actions` (json, null = jeton d'une seule action).

### Hors ligne (C5 partie 2)

Permis hors ligne quand le réglage est activé, dans la limite du montant maximal (aucune validation possible hors ligne) ;
au-delà : vente refusée **forçable** (`CUSTOM_ITEM_LIMIT_EXCEEDED`, `PRICE_OVERRIDE_LIMIT_EXCEEDED`, contexte
`customItemsTotal`, `customItemsMax`, `priceOverrideReduction`, `priceOverrideMax`) par `offline_force` ; désactivé sur le
site : `OFFLINE_SALE_INVALID` non forçable. Le pack porte `settings.customItems` et `settings.priceOverride`. Vente
synchronisée : `items[].custom {label, unitPrice, taxable}` (sans `productVariantId`) et `items[].priceOverride {unitPrice,
reasonCode?, reasonText?}` ; recalcul au pack déclaré avec le prix saisi ou le prix manuel (le prix du pack devient le prix
catalogue), `PRICE_CHANGED` journalisé. Règle « pos-offline-v1 » étendue de façon additive : taxes sur `taxableSubtotal`
(= sous-total sans ligne libre, résultats des cas existants inchangés) ; trois cas ajoutés au jeu partagé.

## Retour libre et réglage « retour sans ticket » (lot P2-a, point C, 26/10/2026)

`POST /api/order/create-multi-payment` avec `typeOrder: 2` (retour libre, ancien parcours du POS) et `orderSource: 2` est soumis
à `settings.returns.withoutReceipt` (défaut non) : 409 **`RETURN_WITHOUT_RECEIPT_FORBIDDEN`** avant toute écriture. Les
autres sources (e-commerce, application mobile) ne changent pas. Le parcours à jour est le retour F2 sur la vente d'origine.

## Statistiques de paiement : répartition journalière (lot P2-a, point B, 26/10/2026)

`GET /api/payments/statistics/{source}` : `current_week.*.daily` cumule désormais **par jour** (lundi 00:00:00 → dimanche
23:59:59). Avant : regroupement par horodatage exact (deux paiements du même jour n'en comptaient qu'un) et semaine commençant
le lundi à l'heure courante. Changement visible (administration, application MAUI), voulu ; les autres statistiques ne changent pas.

Correctifs du 01/11/2026 (changements visibles voulus, comparés avant/après sur les 11 routes de statistiques de demo) :
- `current_week.*.total` : la semaine finissait le **dimanche 0 h** (le `+6 jours` partait du lundi 0 h) : tout paiement du dimanche
  manquait au total ; borne ramenée au dimanche 23 h 59 min 59 s.
- `current_month.*` et `current_year.*` : les bornes gardaient l'heure courante (du 1er « à l'heure de la requête » au dernier jour
  « à la même heure ») ; désormais du 1er à 0 h au dernier jour à 23 h 59 min 59 s.
- **Fuseau du magasin** (`StoreClock`, celui des exports F14) pour la semaine, sa répartition journalière, le mois et l'année : une vente
  du soir (par exemple 22 h à Montréal, 2 h UTC le lendemain) compte dans sa journée locale ; les bornes sont ramenées en UTC pour la
  requête (heure des paiements en base). Les totaux ne changent pas, seule la répartition entre jours voisins bouge.

## Cartes-cadeaux et avoirs (lot F9, 27/10/2026)

Deux moyens de paiement de magasin, propres à la caisse. Code : `Services/PosGiftCardService/PosGiftCardService`,
`UseCase/PosGiftCardUseCase`, `Controller/PosController/PosGiftCardController`. Désactivés par défaut.

### Modèle et comptabilité

- `pos_gift_card` : `kind` (`gift_card` | `store_credit`), **empreinte SHA-256 du code seule** (`code_hash`) et `last4`,
  `initial_amount`, `balance`, `status` (`active`, `depleted`, `canceled`, `replaced`), `expires_at` (null = jamais),
  vente d'émission (`issued_order_id`), retour d'émission (`issued_return_id`, avoir), poste, remplacement
  (`replaces_id`, `replaced_by_id`). `pos_gift_card_transaction` : `issue`, `redeem`, `refund` (recrédit d'un retour),
  `cash_out`, `adjust`, `cancel`, `replace_out`, `replace_in`, montant signé, solde après, commande, retour, session,
  utilisateur, terminal, `approved_by`, motif.
- Codes `GC-XXXX-XXXX-XXXX` (carte) et `AV-XXXX-XXXX-XXXX` (avoir) : alphabet `23456789ABCDEFGHJKLMNPQRSTUVWXYZ`, 60 bits
  d'aléa. Le code en clair est renvoyé **une seule fois** (émission, remplacement) ; code-barres Code 128 `IZ-G-<code>` et QR =
  le code. Le serveur accepte le code en minuscules, sans tirets, ou avec le préfixe `IZ-G-`. Carte physique pré-imprimée :
  code fourni à la vente, même format, 409 `GIFT_CARD_CODE_TAKEN` s'il existe.
- **La vente d'une carte est un passif, pas un chiffre d'affaires** : commande de **type 3 « CarteCadeau »** (source caisse,
  statut complétée), une ligne libre non taxable « Carte-cadeau •••• 1234 », total = montant, aucune taxe ; paiements
  enregistrés normalement (espèces, carte déclarée, terminal C4 ; jamais une carte-cadeau) ; transaction de caisse de **type 9
  « Vente carte-cadeau »** (espèces dans la caisse attendue, hors bloc ventes) ; ticket de genre **`gift_card`**, numérotation
  propre par poste, code-barres `IZ-C-<poste>-<n°>`. Les taxes sont perçues **à l'utilisation** (vente ordinaire).
  Statistiques : chiffre d'affaires, panier moyen et nombre de commandes ne comptent que les types 1 et 2 (résultat identique
  sur les données existantes) ; les statistiques de paiement comptent l'argent encaissé à la vente de la carte et non
  l'utilisation (aucune ligne `payments`) : pas de double compte.
- Utilisation : `paymentMethods[] {type: 4 (carte-cadeau) | 5 (avoir), amount (dollars), code}` dans
  `POST /api/order/create-multi-payment`, `POST /api/pos/exchanges` et le paiement mixte. **Aucune ligne dans la table des moyens
  de paiement ni dans `payments`** (web, EasyAdmin, MAUI inchangés) : la part est portée par `order.gift_card_applied`
  (comme `credit_applied` pour un échange) ; contrôle : paiements + crédit + carte-cadeau = total ; l'arrondi des espèces porte
  sur la part espèces seule. Débit **dans la transaction de la vente** (ligne verrouillée), journal `GIFT_CARD_REDEEMED`.
  Erreurs 409 : `GIFT_CARD_DISABLED`, `GIFT_CARD_KIND_MISMATCH` (type 4 avec un code AV, ou l'inverse), `GIFT_CARD_INACTIVE`,
  `GIFT_CARD_EXPIRED`, `GIFT_CARD_INSUFFICIENT {balance, requested}`, `GIFT_CARD_PAYMENT_INVALID` (hors caisse, ou pour acheter
  une carte) ; 400 `PAYMENT_METHODS_INVALID` sans `code` ; 404 `GIFT_CARD_NOT_FOUND` ; 422 `GIFT_CARD_CODE_INVALID`.

### Routes (`/api/pos/gift-cards`, caisse)

| Route | Corps | Réponse |
|---|---|---|
| `POST /api/pos/gift-cards` (`Idempotency-Key`) | `{amount (cents), code? (carte pré-imprimée), paymentMethods [{type 1|2|3, amount (dollars), terminalPaymentId? (paiement créé avec **`giftCardAmount`**, destination `gift_card`, sinon 409 `TERMINAL_PAYMENT_PURPOSE_MISMATCH`)}], cashRegisterId?}` | 201 `{giftCard {id, kind, code (une fois), last4, barcode, balance, initialAmount, status, usable, expiresAt, issuedAt, issuedOrderId}, orderId, …ticket (kind gift_card, receiptKind gift_card, barcode IZ-C-…, giftCard masqué)}` ; 409 `GIFT_CARD_DISABLED`, `GIFT_CARD_CODE_TAKEN`, `GIFT_CARD_PAYMENT_INVALID` ; 400 `PAYMENT_TOTAL_MISMATCH` ; 422 `GIFT_CARD_CODE_INVALID` |
| `GET /api/pos/gift-cards/{code}` | — | 200 carte (sans code) ; 404 `GIFT_CARD_NOT_FOUND` ; 429 **`GIFT_CARD_LOOKUP_LIMIT`** `{retryAfterSeconds}` (20 consultations par terminal et 60 par adresse IP par 10 minutes) ; chaque consultation, réussie ou non, est journalisée (`GIFT_CARD_LOOKUP`) |
| `POST /api/pos/gift-cards/{code}/cancel` (`Idempotency-Key`) | `{terminalRefundId?, cashRegisterId?}` | 200 `{giftCard (canceled), orderId, refunds [{type, amount}], cash, …avoir (kind gift_card_cancellation)}` ; validation C3 **`gift_card_cancel`** `{amount}` ; carte jamais utilisée seulement : 409 `GIFT_CARD_USED`, `GIFT_CARD_INACTIVE`, `GIFT_CARD_NOT_CANCELABLE` (avoir). Remboursement par le moyen d'origine (terminal : en ligne ou Interac déjà présenté sur le lecteur), transaction de caisse **type 10**, commande d'émission au statut Annulation |
| `POST /api/pos/gift-cards/{code}/adjust` (`Idempotency-Key`) | `{amount (cents, signé, non nul), reason, cashRegisterId?}` | 200 `{giftCard}` ; validation C3 **`gift_card_adjust`** `{amount}` (valeur absolue) ; 409 `GIFT_CARD_INSUFFICIENT` si le solde deviendrait négatif |
| `POST /api/pos/gift-cards/{code}/cash-out` (`Idempotency-Key`) | `{cashRegisterId?}` | 200 `{giftCard (depleted), amount, cash (arrondi), cashRounding}` : de droit jusqu'au seuil du site (Québec : 5,00 $) ; au-delà, 409 `GIFT_CARD_CASH_OUT_FORBIDDEN` sauf réglage, alors validation C3 `gift_card_adjust` ; transaction de caisse type 10 |
| `POST /api/pos/gift-cards/replace` (`Idempotency-Key`) | `{orderId | barcode (IZ-C-<poste>-<n°>) | returnId, last4, cashRegisterId?}` | 201 `{giftCard (nouveau code, une fois), replaced (ancienne carte, status replaced)}` ; validation C3 **`gift_card_replace`** `{amount}` ; carte perdue retrouvée par sa vente d'émission (ticket) et ses 4 derniers caractères |

Listes de commandes (`GET /api/orders?orderSource=2`) : la vente d'une carte apparaît comme une commande de type 3 avec sa ligne
libre et, en plus des clés habituelles, `giftCardReceiptNumber` ; aucune clé existante ne change.

Un rejeu idempotent reconstitue l'état de la carte **sans le code en clair** (empreinte seule en base) : le POS garde le code
reçu à la première réponse. `POST /api/order/cancel/{id}` sur une vente de carte : 409 `GIFT_CARD_ORDER`.

### Devis, retours, avoirs, ticket, rapports

- Devis `POST /api/pos/quote` et `POST /api/pos/exchanges/quote` : `giftCards: [{code, amount?}]` → `giftCards [{…carte, applied,
  reason}]`, `giftCardApplied`, `amountDue` (reste après cartes), `cashAmountDue` ; aucun débit. Sans `giftCards`, réponses inchangées.
- Retours F2 : `lookup.payments.giftCard` (part réglée par carte) et `giftCardRefundable` ; `refund.method` **`original`** recrédite
  **d'abord les cartes de la vente**, puis la carte bancaire, puis les espèces ; **`cash`** refusé pour la part carte-cadeau
  (409 `CASH_REFUND_FORBIDDEN {giftCardPaid}`) sauf réglage « remboursement au-delà du seuil » ; **`store_credit`** : tout le
  remboursement devient un avoir (409 `STORE_CREDIT_DISABLED` si désactivé). Réponse : `refund.giftCard` (recrédité),
  `refund.storeCredit {code (une fois), last4, balance, expiresAt, barcode}` ; base `pos_return.gift_card_refund`,
  `pos_return.store_credit_id`. Échange : la vente d'échange accepte les types 4 et 5.
- Ticket (vente, réimpression, lien public) : `giftCardApplied`, `giftCardPayments [{kind, last4, amount}]`, `amountDue` déduit de la
  part carte-cadeau, `giftCard` (carte émise, masquée) sur un ticket `gift_card`, `storeCredit` (masqué) sur un avoir ; natures
  `gift_card` et `gift_card_cancellation`.
- Rapports X et Z : **`giftCards {sold, redeemed, creditsIssued, refunded (annulations + soldes remboursés), adjusted : {count,
  amount}, outstanding}`** (`outstanding` = solde actif du site, passif) ; `sales.giftCards` (part réglée par carte ou avoir :
  espèces + cartes + giftCards = total des ventes). Les ventes de cartes ne comptent ni dans `sales` ni dans `refunds`.
- Journal : `GIFT_CARD_SOLD`, `GIFT_CARD_REDEEMED`, `GIFT_CARD_REFUNDED`, `STORE_CREDIT_ISSUED`, `GIFT_CARD_LOOKUP`, `GIFT_CARD_CANCELED`,
  `GIFT_CARD_ADJUSTED`, `GIFT_CARD_CASH_OUT`, `GIFT_CARD_REPLACED` (`approvedBy` sur les actions validées).
- Hors ligne (C5 partie 2) : **jamais** (solde inconnu sans réseau) ; `settings.giftCards.offline = false` dans le pack ; une vente
  hors ligne avec un paiement de type 4 ou 5 est rejetée `OFFLINE_SALE_INVALID` (« carte-cadeau et avoir indisponibles hors ligne »),
  la vente d'une carte hors ligne n'existe pas.

### Réglages (`settings.giftCards`, fiche Entreprise, panneau « Cartes-cadeaux et avoirs (caisse) »)

| Réglage | Défaut | Clé |
|---|---|---|
| Cartes-cadeaux activées | **non** | `enabled` |
| Montants proposés (cents) | 2500, 5000, 10000 (montant libre toujours possible) | `suggestedAmounts` |
| Expiration des cartes (mois) | **vide = jamais** (Québec : aucune date d'expiration sur une carte prépayée vendue contre de l'argent ; la règle dépend du pays) | `expiryMonths` |
| Avoirs activés | non | `storeCredits.enabled` |
| Expiration des avoirs (mois) | vide = jamais | `storeCredits.expiryMonths` |
| Remboursement en espèces d'un solde : seuil (cents) | **500** (Québec : 5,00 $ ou moins sur demande) | `cashRefundMaxBalance` |
| Remboursement en espèces au-delà du seuil (validation) | non | `cashRefundBeyond` |

`settings.approvals.giftCardCancel`, `giftCardAdjust`, `giftCardReplace` : `{required: true}` (toujours un responsable). Références :
Loi sur la protection du consommateur du Québec, art. 187.3 (expiration) et OPC (remboursement d'un solde ≤ 5 $).

## Fiche client et fidélité (lot F8, 28/10/2026)

Code : `Services/PosCustomerService/PosCustomerService` et `PosLoyaltyService`, `UseCase/PosCustomerUseCase`,
`Controller/PosController/PosCustomerController`. Fiche client activée par défaut (aucun effet sans rattachement), fidélité
désactivée par défaut.

### État des lieux et modèle

- Les clients de la boutique en ligne sont des `user` (`ROLE_USER_INTERNET`, courriel obligatoire et unique ; téléphone sur les
  adresses). `order.user_id` est le client d'une commande web mais **le caissier** d'une vente en caisse. Aucun programme de
  fidélité ni consentement n'existait ; la newsletter (`newsletter_subscriber`) reste au web.
- **Fiche de caisse `pos_customer`**, distincte du compte web : prénom, nom, téléphone **E.164** (« +15145551234 » : 514-555-1234,
  (514) 555-1234 et +1 514 555 1234 donnent la même fiche ; numéro national interprété avec le pays du site, international avec + ;
  les adresses web existantes ne sont pas touchées), courriel (minuscules),
  `user_id` du compte web rattaché (rien n'est écrit sur `user`, aucun compte ni courriel créé), `loyalty_code` (`FD-XXXX-XXXX`,
  code-barres `IZ-F-<code>`), `loyalty_points`, dates, `erased_at`, `merged_into_id`. `pos_customer_consent` : finalité
  (`loyalty`, `receipts`, `marketing`, `account_link`), accordé ou retiré, date, caissier, version du texte (historique conservé,
  état effectif = dernière ligne). `pos_loyalty_transaction` : `earn`, `redeem`, `return`, `adjust`, `expire`, `merge_in`,
  `merge_out`, points signés, solde après, valeur, commande, retour, session. Colonnes additives `order.customer_id` et
  `pos_gift_card.customer_id`.

### Routes (`/api/pos/customers`, caisse)

| Route | Corps / réponse |
|---|---|
| `GET /api/pos/customers?q=` | Recherche : code de fidélité (exact, `IZ-F-` accepté), **courriel exact**, **téléphone exact** (E.164 ou 10 derniers chiffres), sinon **nom (3 caractères au moins), fiches de caisse seulement**. Les comptes web (`source: web`) ne sortent que sur un courriel ou un téléphone exact, jamais par nom, jamais s'ils sont déjà rattachés. 20 résultats au plus. **Limite par utilisateur** : `customers.searchPerMinute` recherches par minute (défaut 30, 0 = sans limite) → 429 **`CUSTOMER_SEARCH_RATE_LIMITED`** `{limit, retryAfterSeconds}`, comptée sur le journal. Journal **`CUSTOMER_SEARCHED`** `{kind, query (courriel et téléphone masqués), posResults, webResults}`. |
| `POST /api/pos/customers` (`Idempotency-Key`) | `{firstName?, lastName?, phone?, email?, consents?: {loyalty, receipts, marketing, account_link}}` : un nom et un téléphone ou un courriel ; 409 `CUSTOMER_EXISTS {customerId, field}` ; code de fidélité attribué dès le consentement `loyalty`. 201 fiche. |
| `GET /api/pos/customers/{id}` | Fiche (journal **`CUSTOMER_VIEWED`**) ; `GET …/{id}/history` : ventes, retours et cartes de caisse (`order.customer_id`), commandes web du compte rattaché (détail pour le responsable), mouvements de points, totaux. Fiche effacée : lisible (comptabilité), 410 `CUSTOMER_ERASED` en écriture ; fusionnée : 409 `CUSTOMER_MERGED {mergedIntoId}`. |
| `PATCH /api/pos/customers/{id}` | Coordonnées et consentements ; journal `CUSTOMER_UPDATED` (clés modifiées) |
| `POST /api/pos/customers/{id}/link` | `{userId}` : rattachement à un compte web ; **consentement `account_link` exigé** (409 `CONSENT_REQUIRED`), 404 `USER_NOT_FOUND`, 409 `USER_ALREADY_LINKED`, `CUSTOMER_LINK_DISABLED` |
| `POST /api/pos/customers/{id}/merge` (`Idempotency-Key`) | `{intoId}` (responsable) : ventes, cartes, points et consentements accordés passent sur la fiche conservée |
| `POST /api/pos/customers/{id}/erase` (`Idempotency-Key`) | Responsable (action C3 `customer_erase {customerId}` pour les validations) : **anonymisation** (nom, coordonnées, code, compte rattaché effacés, consentements retirés, points annulés) ; les ventes restent rattachées |
| `GET /api/pos/customers/{id}/export` | Responsable : droit d'accès, données complètes et historique des consentements (`CUSTOMER_EXPORTED`) |
| `POST /api/pos/customers/{id}/loyalty/adjust` (`Idempotency-Key`) | `{points (signé), reason, cashRegisterId?}` : responsable, ou caissier avec validation C3 **`loyalty_adjust`** `{customerId, points}` |

**Masquage** : le caissier voit nom, `phone` « •••• 1234 », `email` « m***@domaine », `linkedAccount` (sans `userId`), points et
consentements ; le responsable voit tout. Fiche client désactivée : 409 `CUSTOMERS_DISABLED`.

### Rattachement d'une vente, fidélité, reçu

- `customerId` dans `POST /api/order/create-multi-payment`, `POST /api/pos/returns` (sinon client de la vente d'origine),
  `POST /api/pos/exchanges` (`sale.customerId`), `POST /api/pos/gift-cards` (acheteur, aucun point). Détachement : ne pas envoyer la
  clé (rien n'est modifié après l'encaissement). 404 `CUSTOMER_NOT_FOUND`, 409 `CUSTOMER_NOT_ALLOWED` hors caisse.
- **Points gagnés** : `floor(assiette / 100) × pointsPerDollar`, assiette = net des articles après remises, hors taxes, **moins la part
  payée par carte-cadeau ou avoir** ; jamais sur une vente de carte-cadeau ; seulement si le programme est actif et le consentement
  `loyalty` accordé. **Rachat** : `loyalty: {redeemPoints: N}` dans la vente (ou `sale.loyalty` d'un échange) → remise de ticket
  calculée par le serveur (`valuePer100Points`, minimum `minRedeem`, pas `redeemStep`), motif « Récompense fidélité (N points) »,
  **hors plafonds et validations caissier**, taxes sur le net ; incompatible avec une remise de ticket saisie (409
  `LOYALTY_TICKET_DISCOUNT_CONFLICT`) ; 422 `LOYALTY_POINTS_INVALID`, 409 `LOYALTY_INSUFFICIENT`, `LOYALTY_DISABLED`,
  `LOYALTY_CONSENT_REQUIRED`, `LOYALTY_CUSTOMER_REQUIRED`. Le programme s'appuie sur les remises F1 : elles doivent rester autorisées.
- **Rachat connu avant l'encaissement (26/10/2026)** : `customerId` et `loyalty: {redeemPoints}` aussi dans `POST /api/pos/quote`,
  `POST /api/pos/exchanges/quote` (`sale.customerId`, `sale.loyalty`) et `POST /api/pos/terminal/payments` (mode articles). Mêmes
  contrôles, même ordre et mêmes refus que la vente, **sans aucune écriture** (points expirés par inactivité : comptés 0, l'expiration
  est écrite par la vente comme avant). La récompense devient la remise de ticket « Récompense fidélité (N points) » : `total`,
  `cashTotal`, cartes-cadeaux (`amountDue`), coupons et part carte sont calculés **après** récompense. Clé ajoutée seulement si des points
  sont rachetés : `loyalty {customerId, redeemPoints, amount, balance, balanceAfter}` (devis), `sale.loyalty` (devis d'échange).
  Client seul (sans rachat) : contrôlé (404 `CUSTOMER_NOT_FOUND`, 409 `CUSTOMERS_DISABLED`), réponse identique. **Sans ces clés : réponses
  identiques.** Échange : client = `sale.customerId`, sinon `return.customerId`, sinon client de la vente d'origine.
  Terminal : aucun point débité (la vente débite) ; rachat sur la vente d'une carte-cadeau → 409 `TERMINAL_PAYMENT_PURPOSE_MISMATCH` ;
  si le solde change entre le paiement et la vente, la vente refuse (`LOYALTY_INSUFFICIENT`) et le paiement capturé reste dans les
  paiements sans vente (réconciliation C4). Le POS envoie ensuite à la vente exactement les mêmes `customerId` et `loyalty`.
- **Échange : points repris par le retour (28/10/2026)** : quand la vente d'origine est du même client, le retour reprend d'abord les
  points qu'elle avait fait gagner (prorata du net retourné) ; le devis `POST /api/pos/exchanges/quote` et le contrôle de
  `POST /api/pos/exchanges` vérifient le solde **après** cette reprise, comme la vente le fera : mêmes refus (`LOYALTY_INSUFFICIENT`
  avec le `balance` restant), `sale.loyalty.balance` et `balanceAfter` exacts, clé **`returnTakesBack`** (points repris) ajoutée
  seulement dans ce cas. Avant, le devis acceptait un rachat que l'échange refusait ensuite.
- **Correctif de l'échange avec rachat (26/10/2026)** : le crédit appliqué était calculé sur le total de la vente d'échange **avant**
  récompense ; quand le retour couvrait ce total, le crédit dépassait la vente et le client perdait la récompense (retour 33,34 $, vente
  27,59 $ après 500 points : 0 rendu au lieu de 5,75 $). Le crédit est désormais calculé après récompense. Sans rachat : inchangé.
  Test : `PosLoyaltyQuoteApiTest`.
- **Retour** : points de la vente repris au prorata du net remboursé, jamais au-delà des points gagnés ; **points déjà dépensés :
  solde ramené à 0, manque indiqué** dans la réponse (`loyalty {returned, shortfall, balance}`) et le journal (`LOYALTY_RETURNED
  {due, points, shortfall}`), le retour n'est jamais bloqué ; annulation d'une vente : tous les points repris, même règle. **Expiration**
  : solde remis à zéro après N mois sans gain ni rachat (réglage, **vide = jamais, désactivée par défaut**).
- Ticket : `customer {id, name (prénom et initiale), loyalty {earned, redeemed, redeemedAmount, balance, code}}` (null sans client).
  Journal `LOYALTY_EARNED`, `LOYALTY_REDEEMED`, `LOYALTY_RETURNED`, `LOYALTY_ADJUSTED`, `LOYALTY_EXPIRED` ; `SALE.data.customerId`, `loyalty`.
- Reçu par courriel F3 : `POST /api/pos/receipts/email {barcode}` **sans `email`** → adresse du client rattaché s'il a consenti aux
  reçus (`receipts`), journal `source: customer` ; sinon 422 `EMAIL_INVALID` comme avant. L'adresse n'est toujours pas conservée sur le reçu.
- Rapports X et Z : **`customers {salesWithCustomer {count, total}, loyalty {earned, redeemed {points, amount, count}, returned}}`**.
- Hors ligne (C5 partie 2) : pas de fichier client dans le pack. Une vente hors ligne porte `customerId` ou `loyaltyCode` connus de la
  tablette : rattachement et points à la synchronisation, réponse `customer {id, loyaltyEarned}` ; fiche absente ou effacée →
  vente acceptée sans client, `customerUnknown: true`. Aucune création hors ligne (`settings.customers.offline = "queued"`).

### Données personnelles (Loi 25, RGPD)

Consentement explicite enregistré par finalité avec date, caissier et version du texte (`settings.customers.consentTextVersion`) ;
`marketing` séparé, jamais présumé ; `account_link` exigé avant tout rattachement à un compte web. Données minimales (pas d'adresse
postale sur la fiche). Journal C2 de chaque recherche, consultation, export, modification. Droit d'accès : export ; droit à
l'effacement : anonymisation, pas de suppression (les ventes restent rattachées à la fiche anonymisée).

### Réglages (panneau EasyAdmin « Clients et fidélité (caisse) », `settings.customers` et `settings.loyalty`)

| Réglage | Défaut | Clé |
|---|---|---|
| Fiche client activée | **oui** | `customers.enabled` |
| Rattachement d'un compte web autorisé | oui | `customers.linkEnabled` |
| Version du texte des consentements | vide | `customers.consentTextVersion` |
| Recherches de clients par utilisateur et par minute | 30 (0 = sans limite) | `customers.searchPerMinute` |
| Programme de fidélité activé | **non** | `loyalty.enabled` |
| Points par dollar dépensé | 1 | `loyalty.pointsPerDollar` |
| Valeur de 100 points (cents) | 100 | `loyalty.valuePer100Points` |
| Minimum et pas de rachat (points) | 500 et 100 | `loyalty.minRedeem`, `loyalty.redeemStep` |
| Expiration des points (mois sans activité) | vide = jamais | `loyalty.expiryMonths` |

`settings.approvals.loyaltyAdjust` et `customerErase` : `{required: true}`.

## Statistiques par caissier et exports comptables (lot F14, 29/10/2026)

Routes nouvelles et séparées : les 11 routes de statistiques existantes (`/api/payments/statistics/*`, `/api/statistiques/*`,
`/api/carrier/statistics`) sont inchangées. Code : `Services/PosStatisticsService/PosCashierStatisticsService`,
`Services/AccountingService/` (service commun), `UseCase/PosReportingUseCase`, `Controller/PosController/PosReportingController`,
page EasyAdmin `Controller/Admin/PosAccountingExportController` (menu IIZIMANAGER → « Exports comptables (caisse) »).

### Fuseau horaire du magasin (`settings.store`)

`{timezone, source}` : réglage `pos_timezone` (fiche Entreprise, IANA), sinon déduit de la **province** quand le pays en a plusieurs
(Canada : QC et ON → America/Toronto, MB → America/Winnipeg, SK → America/Regina, AB → America/Edmonton, BC → America/Vancouver,
NS/PE → America/Halifax, NB → America/Moncton, NL → America/St_Johns…), sinon du pays (Martinique → America/Martinique, France →
Europe/Paris…), sinon UTC. Sert aux journées des statistiques par caissier et des exports. Les réservations gardent leur
America/Toronto actuel. Code : `Services/CaisseService/StoreClock`.

### Vecteur de chaînage hors ligne (complément C5 partie 2)

`GET /api/pos/offline-cases` porte une clé à part **`chainVectors`** (format `cases` inchangé) : une vente complète (remises de ligne et
de ticket, article libre à libellé accentué, prix manuel, client), `prevHash`, la chaîne JSON **canonique exacte** et le **hash
attendu**. Règle : `hash = sha256(prevHash + JSON canonique de la vente sans la clé chain)`, canonique = clés triées
récursivement, sans espaces, UTF-8 et `/` non échappés. Le POS doit reproduire les deux à l'octet près (test).

### `GET /api/pos/statistics/cashiers?from=&to=&cashRegisterId=&cashierId=` (caisse)

Période en journées du magasin (`from`, `to` au format AAAA-MM-JJ, défaut : aujourd'hui), par **date de vente**. Une ligne par
**vendeur réel** (`order.user_id` : le compte qui a encaissé ; hors ligne : `sellerId`) et une ligne `total` :
`sales {count, grossHt, netHt, ttc, items, averageTicket, itemsPerTicket}`, `discounts {count, amount}` (F1),
`priceOverrides {count, difference}` et `customItems {count, amount}` (F7), `returns {count, amount}` (F2, par celui qui a fait le
retour), `cancellations {count, amount}` (par celui qui a annulé), `drawerOpenings` (sans vente), `approvals {requested, granted}`
(C3), `discrepancies {sessions, total, absolute}` (sessions qu'il a fermées, C1), `offlineSales`, `giftCardsSold {count, amount}`
(F9), `salesWithCustomer`, `loyaltyPointsEarned` (F8). Un **caissier** ne voit que ses propres chiffres (`cashierId` forcé, `scope:
self`, courriel masqué) ; responsable et administrateur voient tous les vendeurs. Chaque consultation est journalisée
**`CASHIER_STATS_VIEWED`** (qui, période, portée) : données d'employés, finalité de gestion. Test : la somme des vendeurs égale le
rapport X/Z de la même période.

### Export comptable (responsable ; page EasyAdmin pour l'administrateur)

À partir des **sessions clôturées** (rapports Z). **Journée comptable : un rapport Z entier est rattaché à la date d'OUVERTURE de sa
session, dans le fuseau du magasin, jamais coupé sur deux jours** (une session peut passer minuit). Les montants d'une session
viennent de ses commandes, paiements, retours et mouvements (figés à la clôture) ; le Z donne la pièce (`Z-<poste>-<n°>`), les
espèces comptées, l'écart et le dépôt en banque. Les rapports clôturés avant F9 et F8 (blocs cartes-cadeaux et clients absents,
comptés à 0) sont signalés dans l'aperçu (`legacyReports`, `legacyNote`). Journal **`EXPORT_GENERATED`** (qui, période, format,
nombre de rapports).

| Route | Contenu |
|---|---|
| `GET /api/pos/exports/accounting?from=&to=&cashRegisterId=` | Aperçu JSON : `period`, `timezone`, `rule`, `days[]` (synthèse par jour), `entries[]`, `totals {debit, credit, balanced, byDay}`, `reports[]`, `legacyReports`, `accounts` |
| `GET …/accounting/summary.csv` | **Synthèse par jour** : UTF-8 avec BOM, « ; », virgule décimale : rapports Z, ventes (nombre, HT brut, remises, récompenses fidélité, HT net, arrondi), base et montant de chaque taxe (TPS, TVQ ; TVA en France), ventes TTC, encaissements par moyen (espèces, carte déclarée, terminal carte, Interac, PayPal, carte-cadeau, avoir), retours (nombre, TTC, remboursé espèces et carte, recrédits, avoirs émis), cartes-cadeaux vendues (passif) et remboursées, écart de caisse, dépôt en banque, mouvements du fond, dépôts et retraits |
| `GET …/accounting/entries.csv?format=generic` | **Écritures équilibrées** « ; » : Date;Journal;Compte;Libellé du compte;Libellé;Débit;Crédit;Pièce;Source |
| `GET …/accounting/entries.csv?format=qbo` | QuickBooks Online : « , », point décimal, sans BOM, dates JJ/MM/AAAA, colonnes `JournalNo,JournalDate,AccountName,Description,Debits,Credits` (noms de comptes ; à l'import choisir le format de date JJ/MM/AAAA). **Au-delà de 1 000 lignes** (limite d'import QuickBooks) : 413 **`EXPORT_TOO_LARGE`** `{lines, limit, from, to, suggestedTo, linesUntilSuggestedTo}` : réduire la période jusqu'à `suggestedTo` (dernier jour entier qui tient, les pièces ne sont jamais coupées) ; si une seule journée dépasse déjà la limite, le message renvoie au format `generic` |

Écritures (journal VT, une pièce par Z) : ventes HT brutes au crédit des ventes, remises et récompenses fidélité au débit de leurs
comptes, chaque taxe collectée au crédit, encaissements au débit (caisse, cartes à encaisser, Interac, PayPal) ; règlements par
carte-cadeau ou avoir au débit du passif correspondant ; retours : ventes et taxes au débit, remboursements au crédit du moyen,
avoirs émis au crédit du passif ; cartes-cadeaux vendues : débit du moyen, crédit « cartes-cadeaux en circulation » (passif, pas de
chiffre d'affaires) ; écart de caisse (+ excédent : débit caisse, crédit écarts) ; dépôt en banque (débit banque, crédit caisse) ;
arrondi des espèces ; écart résiduel d'arrondi porté au compte d'arrondi. **Contrôle : débits = crédits par jour** (`totals.byDay`,
test).

Formats d'import des logiciels courants au Québec : **QuickBooks Online** importe des écritures de journal en CSV (format `qbo`,
colonnes ci-dessus, moins de 1 000 lignes par fichier, comptes désignés par leur nom) ; **Sage 50 Canada** importe le journal
général par son format texte « .IMP » et **Acomba** par son module d'importation avec correspondance de colonnes : le format
`generic` (une ligne par imputation avec numéro de compte, pièce et date) est fait pour y être mappé.

### Service comptable commun (`Services/AccountingService`)

Indépendant de la caisse, prévu pour l'export de la boutique en ligne et le module comptable de l'application de gestion :
`ChartOfAccounts` (plan **unique par site**, réglé dans EasyAdmin une ligne par clé `cle=numero;Nom`, plan par défaut québécois :
`sales`, `sales_rate_<taux>`, `returns`, `discounts`, `loyalty_rewards`, `shipping_revenue`, `tax_gst`, `tax_qst`, `tax_hst`,
`tax_hst_<province>`, `tax_pst`, `tax_vat`, `tax_vat_<taux>`, `tax_other`, `cash`, `bank`, `card_clearing`, `interac_clearing`,
`stripe_clearing`, `paypal_clearing`, `stripe_fees`, `commission`, `stripe_payout`, `gift_card_liability`, `store_credit_liability`,
`cash_discrepancy`, `cash_rounding`, `suspense` ; les clés dynamiques retombent sur leur clé générique). **À l'enregistrement dans EasyAdmin**, une ligne mal
formée, une clé inconnue, un doublon ou un numéro de compte invalide sont **refusés avec un message précis** (ligne, cause, clés acceptées),
jamais ignorés. `JournalEntryBuilder`
(entrée : montants par catégorie et une **source** — `pos` aujourd'hui, `web` demain : ventes par taxe, taxes, livraison, remises,
encaissements par moyen, remboursements, cartes-cadeaux, avoirs, écarts, frais Stripe, commission, virements ; sortie : écritures
équilibrées). Le web s'y branchera en produisant ses montants par jour (commandes, remboursements et virements Stripe) et en
appelant `JournalEntryBuilder::build(..., 'web', ...)` avec le même plan de comptes. Exposé dans `settings.accounting.accounts`.

## Conservation des données, contrôle des rôles, Loi 96 (lot A1 / S2 / A4, 30/10/2026)

Code : `Services/RetentionService/` (politique, exception), `EventSubscriber/RetentionGuardSubscriber` (garde Doctrine),
`Services/ArchiveService/PosArchiveService` (archive annuelle), `Controller/PosController/PosArchiveController`,
`Controller/Admin/PosArchiveAdminController` ; schéma `migrations/Version20261030100000.php`,
`scripts/migrate_all_v2_retention.sh`. Tests : `tests/Functional/Boutique/PosRetentionArchiveApiTest.php`,
`tests/Functional/Security/PosRoleMatrixTest.php`.

### Durées de conservation (`settings.retention`)

| Pays du site (adresse de l'entreprise) | Plancher | Base légale (à confirmer par le comptable du site) |
|---|---|---|
| Canada (`CA`, dont Québec) | **6 ans après la fin de l'année** à laquelle les registres se rapportent | Loi de l'impôt sur le revenu, art. 230(4) et Règlement de l'impôt sur le revenu, art. 5800 (ARC, « Conservation des registres ») ; Loi sur la taxe d'accise, art. 286 (TPS/TVH) ; Loi sur l'administration fiscale du Québec (RLRQ, c. A-6.002), art. 35.1 (Revenu Québec, « Conservation des registres et pièces justificatives ») |
| France et DROM (`FR`, `MQ`, `GP`, `GF`, `RE`, `YT`) | **10 ans** | Code de commerce, art. L123-22 (documents comptables et pièces justificatives : 10 ans) ; Livre des procédures fiscales, art. L102 B (6 ans, couvert par les 10) |
| Belgique (`BE`), Suisse (`CH`), Luxembourg (`LU`), Monaco (`MC`) | 10 ans | BE : CIR 92 art. 315 et Code TVA art. 60 (10 ans depuis la loi du 20/11/2022) ; CH : CO art. 958f ; LU : Code de commerce art. 16 |
| États-Unis (`US`) | 7 ans | choix prudentiel (IRS : 3 à 7 ans selon le cas) |
| autre pays | 10 ans | valeur prudente par défaut |

- `RetentionPolicy::legalYears(pays)` donne le plancher ; la fiche Entreprise (EasyAdmin, panneau « Conservation des données
  de vente (caisse) », champ « Durée de conservation (années) ») permet d'allonger la durée **par site**, jamais de la
  raccourcir (validateur : « La durée de conservation ne peut pas être inférieure au plancher légal du pays »). Vide = défaut
  du pays. `GET /api/pos/settings` rend `retention: {years, legalYears, country, source: "country" | "site", writeMode, purge: "none", fiscalYear: "calendar"}`.
- Échéance d'une donnée = **31 décembre, 23:59:59 (fuseau du magasin), de l'année de la vente + N** (`RetentionPolicy::endOfRetention`).
  **L'exercice est supposé égal à l'année civile** (`fiscalYear: "calendar"`) : si l'exercice du site se termine à une autre
  date, l'échéance légale peut être postérieure (« 6 ans après la fin de l'année d'imposition ») ; à confirmer par le comptable.
- **AUCUNE purge automatique n'a lieu à l'échéance** (`purge: "none"`) : les données restent en base indéfiniment ; une
  éventuelle suppression ou anonymisation à l'échéance sera une décision à part, prise plus tard, hors de ce lot.

### Garde des suppressions et des modifications (`RetentionGuardSubscriber`, écouteur Doctrine `onFlush`)

**Suppression : refusée tout de suite**, quel que soit le mode, avec un message clair (409 `RETENTION_LOCKED` par l'API,
« accès refusé » avec le message dans EasyAdmin) à la place d'une erreur SQL :

- données de vente : commandes de la caisse (`orderSource` 2) et commandes de type carte-cadeau (type 3), **commandes web
  payées** (paiement enregistré ou statut complété/livré ; une commande web **non payée**, panier abandonné, reste
  supprimable comme avant), leurs lignes, taxes et paiements ; sessions de caisse, transactions et comptages, rapports Z,
  tickets, retours, cartes-cadeaux et leurs mouvements, journal d'audit, paiements terminal, ventes hors ligne et
  synchronisations, validations C3 ;
- **parents** référencés par une vente : utilisateur (vendeur ou client d'une vente), variante et produit vendus, poste de
  caisse ayant une session : refusés avec le message « … référencé par N vente(s) » (désactiver le poste, archiver le
  produit, anonymiser l'utilisateur à la place).

**Modification : d'abord en mode observation** (réglage EasyAdmin « Modifications des données de caisse », défaut
`observe`) : rien n'est bloqué ; toute modification d'une donnée de vente **hors exceptions** est journalisée
`RETENTION_WRITE_OBSERVED {entity, id, fields, origin (route ou commande), user}` dans `pos_audit_log`. Le passage au mode
`block` (modification refusée, 409 `RETENTION_LOCKED`, « Corriger par une contre-écriture ») se décidera **après la recette,
au vu du journal** ; il n'y a pas de préproduction. Exceptions (jamais journalisées, jamais bloquées) :

| Entité | Champs libres (écritures normales du cycle de vie) |
|---|---|
| `Order` | `status`, `statusUpdatedAt`, `totalAmount`, `subTotal`, `total_tax`, `syncedAt`, `customerId`, `taxTransactionId`, `stripeInvoiceId` |
| `OrderTax` | `amount` (annulation : taxes ramenées à 0, contre-écriture C1) |
| `Payments` | `stripePaymentId`, `stripeReceiptUrl`, `stripeStatus`, `stripeCardBrand`, `stripeLast4`, `stripeRiskLevel`, `statutPayment` |
| `Caisse` (session) | `amountTotal`, `fonDeCaisse`, `isOpen`, `closedAt` |
| `PosReceipt` | `reprintCount`, `linkVersion`, `linkRevokedAt`, `provisionalNumber` |
| `PosReturn` | `exchangeOrder`, `storeCreditId`, `giftCardRefund` |
| `PosGiftCard` | `balance`, `status`, `canceledAt`, `replacedById`, `customerId`, `issuedReturnId` |
| `PosGiftCardTransaction` | `returnId`, `approvedBy`, `reason` |
| `PosApproval` | `usedAt` |
| `PosTerminalPayment`, `PosOfflineSale`, `PosOfflineSync` | toutes (états techniques) |
| toute entité créée dans la même requête | libre (construction d'une vente en plusieurs flushs) |

Le garde est enregistré pour la connexion partagée (`doctrine.event_subscriber`, `config/services.yaml`) : il s'applique
à l'API, à EasyAdmin et aux commandes console (le `CashRecordLockTrait` de C2 reste la première barrière dans EasyAdmin).
Un traitement interne légitime peut appeler `RetentionGuardSubscriber::suspend()` dans sa transaction ; aucun ne le fait aujourd'hui.

### Instantanés des lignes de vente (`order_items.product_name`, `variant_label`, `product_code`, `barcode`)

Remplis **par le serveur** à la vente, en caisse comme sur le web (`OrderItems::snapshotFrom`, dans `OrderItemService`) ;
les lectures (ticket, DTO `productVariantName`, exports, archive) partent de l'instantané et retombent sur le catalogue
s'il est vide. **Réponses JSON de même forme** : aucune clé ajoutée ni retirée. Rétro-remplissage à la migration depuis le
catalogue **actuel** (nom, code, code-barres de la variante ou du produit, libellé couleur + taille) : l'historique déjà
perdu (produit renommé ou supprimé avant le 30/10/2026) ne revient pas. Ligne libre (P2-a) : `product_name` vide,
`displayName()` rend `customLabel`.

### Archive annuelle (`GET /api/pos/archive/{year}`, responsable ; EasyAdmin « Archive annuelle de caisse », `/admin/pos/archive`)

ZIP `archive-caisse-<site>-<année>.zip` : `archive.json` (site, année, fuseau, rapports Z de l'année (date d'ouverture, fuseau du
magasin), journal d'audit de l'année **avec vérification ligne à ligne du chaînage** (`hash = sha256(prev_hash . payload)`) et
synthèse `chain {entries, valid, firstBroken}`, ventes de la caisse par lots de 200 avec lignes, taxes, paiements, tickets,
retour et mouvements de carte, cartes-cadeaux émises dans l'année), `archive.pdf` (**synthèse seule** : compteurs, totaux
par Z, état du chaînage), `SHA256SUMS`, `README.txt` (vérification : `sha256sum -c SHA256SUMS`). Produite **en flux** dans
`var/archives` (jamais l'année entière en mémoire ; testée avec 300 ventes copiées dans le test, le PHP reste à mémoire
constante), puis envoyée et supprimée (`X-Archive-Sha256`, `X-Archive-Counts`, `X-Archive-Chain-Valid`) ; journal
`ARCHIVE_EXPORTED {year, sha256, size, counts, chainValid}`. 403 `FORBIDDEN` pour un caissier, 400 `YEAR_INVALID`. L'archive est
un **format ouvert à conserver hors du serveur** (le contribuable reste responsable de sa conservation) ; elle ne retire
rien de la base.

Sauvegardes du serveur (pour mémoire, aucun changement d'infrastructure dans ce lot) : `systemd` `backup-daily.timer`
(04:00 UTC) vide toutes les bases dans `/home/arkanoa/backups/data`, **rotation 14 jours**. Elles couvrent la **restauration**
après incident, pas la conservation légale : celle-ci repose sur la base vivante (aucune purge) et sur les archives
annuelles téléchargées et conservées par le site.

### Rattachement d'un terminal à un poste (S2.6)

Une clé POS portant un `cashRegisterId` (écran « Clés des applications », `PUT /api/app-keys/{id}`) rend `cashRegisterId`
dans `GET /api/pos/terminal`. Pour un **caissier** (sans `ROLE_POS_MANAGER`), ce terminal n'opère **en caisse** que sur ce
poste : vendre (`create-multi-payment`), ouvrir, clôturer, dépôt, retrait, fond, tiroir, paiement terminal, cartes-cadeaux
et commandes annulées → `cashRegisterId` différent = 403 `CASH_REGISTER_FORBIDDEN {cashRegisterId, terminalCashRegisterId}`,
absent = le poste du terminal (`CashRegisterResolver::resolve`). **Retours, échanges, consultation et réimpression de
tickets restent possibles pour toute vente du site** (le retour s'enregistre sur le poste du caissier). Responsable et
administrateur : tous les postes. Clé sans poste : comportement inchangé.

### Cartes-cadeaux : réponse uniforme et limite de codes refusés (S2.8)

- Carte **inconnue, annulée ou remplacée** : même réponse 404 `GIFT_CARD_NOT_FOUND` « Carte-cadeau ou avoir inconnu ou
  inutilisable » (avant : `GIFT_CARD_CANCELED` / `GIFT_CARD_REPLACED` révélaient l'existence du code). Format invalide : 422
  `GIFT_CARD_CODE_INVALID` inchangé (non compté).
- Chaque code refusé est journalisé (`GIFT_CARD_LOOKUP`, `result: GIFT_CARD_NOT_FOUND`, empreinte du code) ; au-delà de
  **10 codes refusés en 10 minutes, par utilisateur OU par terminal** (réglage EasyAdmin « Codes de cartes-cadeaux refusés :
  essais par 10 minutes », `settings.giftCards.codeAttemptsPer10Min`, 0 = sans limite), consultation **et** paiement par
  carte (même valide) répondent 429 `GIFT_CARD_LOOKUP_LIMIT {retryAfterSeconds: 600, limit}` ; journal `code_attempts_limited`.
  Les limiteurs IP/terminal de F9 (`pos_gift_card_lookup_*`) restent en place.
- Validations C3 : au plus **30 par terminal et 15 minutes** (`limiter.pos_approval_terminal`) → 429 `APPROVAL_RATE_LIMITED`.

### Loi 96 (A4) : documents en français

Ticket (impression, réimpression), page publique `GET /api/r/{jeton}` (`lang="fr"`, « Télécharger en PDF »), PDF et courriel du
reçu, bons de retour et avoirs sont **en français** ; natures : « Ticket de vente », « Ticket d'échange », « Avoir (retour) »,
« Avoir (annulation) », « Ticket de carte-cadeau », « Avoir (carte-cadeau annulée) » (les deux dernières manquaient et
rendaient « Ticket »). Test : rendu des six natures sans mot anglais (`Receipt`, `Change`, `Subtotal`, …). Les textes libres
du site (en-tête, pied de ticket, noms de produits) restent de la responsabilité du site.

### Audit des rôles (S2.7)

Règle : `ROLE_USER_POS` (caissier) = caisse de son poste, lecture des postes, ventes et retours ; `ROLE_POS_MANAGER` = en plus
postes, exports, archive, statistiques de tous, révocation de liens, actions C3 sans validation ; `ROLE_ADMIN` = tout.
Garde-fou : `tests/Functional/Security/PosRoleMatrixTest.php` (un caissier tente chaque action réservée : refus attendu)
et `AnonymousWriteAccessTest`. Écarts corrigés dans ce lot : révocation de lien de reçu (le service exigeait seulement la
route ; désormais aussi dans `PosReceiptUseCase::revokeLink`) ; réponse non uniforme des cartes annulées/remplacées.

Table d'audit (générée depuis `docs/endpoints.md` le 30/10/2026 ; **153 routes authentifiées** atteignables par un
caissier, plus 147 routes publiques). Colonnes : accès `security.yaml` (« caisse » = `ROLE_USER_POS ou ROLE_POS_MANAGER ou
ROLE_ADMIN`, « web + caisse » = aussi `ROLE_USER_INTERNET`) ; contrôle dans le code (rôle, action C3, propriété) ; test qui le
prouve (`tests/Functional/...`, « — » = aucun test dédié).

| Méthode | Route | Accès | Contrôle dans le code (rôle / C3 / propriété) | Test |
|---|---|---|---|---|
| GET | `/api/newsletter` | ROLE_ADMIN depuis le 27/10/2026 (web + caisse avant : liste des courriels inscrits lisible par tout compte) | refus 403 dans le code + access_control | ReservationConfirmAccessTest |
| GET, POST | `/booking-setup/` | aucune règle | authentification propre au contrôleur (jeton), pages HTML | AnonymousWriteAccessTest (liste) |
| ANY | `/booking-setup/configure/{id}` | aucune règle | authentification propre au contrôleur (jeton), pages HTML | AnonymousWriteAccessTest (liste) |
| ANY | `/booking-setup/list` | aucune règle | authentification propre au contrôleur (jeton), pages HTML | AnonymousWriteAccessTest (liste) |
| ANY | `/booking-setup/logout` | aucune règle | authentification propre au contrôleur (jeton), pages HTML | AnonymousWriteAccessTest (liste) |
| ANY | `/` | aucune règle | page publique du site legacy / connexion | — |
| ANY | `/` | aucune règle | page publique du site legacy / connexion | — |
| ANY | `/account/order/{id}` | web + caisse | sa commande (`o.userId`) | — |
| ANY | `/account/otp` | web + caisse | session de l'utilisateur | AuthCookieFlowTest |
| ANY | `/account` | web + caisse | redirige vers /admin (ROLE_ADMIN) | — |
| GET | `/adress/` | web + caisse | ses adresses (**corrigé S2.7** : avant, toutes) | — |
| GET, POST | `/adress/new` | web + caisse | rattache à l'utilisateur connecté | — |
| GET, POST | `/adress/{id}/edit` | web + caisse | propriétaire (**corrigé S2.7**) | — |
| POST | `/adress/{id}` | web + caisse | propriétaire + CSRF (**corrigé S2.7**) | — |
| GET | `/api/adresses/` | web + caisse | ses adresses (`getUserAdress() === user`) | CustomerAccountApiTest |
| PUT | `/api/adresses/{id}/set-primary` | web + caisse | ses adresses (`getUserAdress() === user`) | CustomerAccountApiTest |
| DELETE | `/api/adresses/{id}` | web + caisse | ses adresses (`getUserAdress() === user`) | CustomerAccountApiTest |
| PUT | `/api/adresses/{id}` | web + caisse | ses adresses (`getUserAdress() === user`) | CustomerAccountApiTest |
| GET | `/api/adresses` | web + caisse | ses adresses (`getUserAdress() === user`) | CustomerAccountApiTest |
| POST | `/api/adresses` | web + caisse | ses adresses (`getUserAdress() === user`) | CustomerAccountApiTest |
| POST | `/api/caisse/cashfunddeposit` | caisse | session ouverte du poste ; poste du terminal si rattaché (`CashRegisterResolver`) | CashRegisterApiTest, PosRoleMatrixTest |
| POST | `/api/caisse/cashfundwithdraw` | caisse | idem ; fond négatif refusé | CashRegisterApiTest |
| POST | `/api/caisse/close` | caisse | session du poste ; C3 `close_without_count` si comptage absent et réglage actif ; poste du terminal | CashClosingApiTest, PosRoleMatrixTest |
| GET | `/api/caisse/denominations` | caisse | lecture, coupures du site | CashClosingApiTest |
| POST | `/api/caisse/deposit` | caisse | session ouverte du poste ; poste du terminal | CashRegisterApiTest, PosRoleMatrixTest |
| POST | `/api/caisse/drawer-open` | caisse | C3 `drawer_open` hors vente si réglage actif ; poste du terminal | PosPinApiTest, PosRoleMatrixTest |
| POST | `/api/caisse/open` | caisse | poste actif sans session ouverte ; poste du terminal | CashRegisterApiTest |
| GET | `/api/caisse/report/x` | caisse | lecture, session du poste | CashClosingApiTest |
| GET | `/api/caisse/report/z/{id}` | caisse | lecture, rapport du site | CashClosingApiTest |
| GET | `/api/caisse/report/z` | caisse | lecture, rapports du site | CashClosingApiTest |
| GET | `/api/caisse/transactions` | caisse | lecture, session ouverte du poste | CashRegisterApiTest |
| POST | `/api/caisse/withdraw` | caisse | session ouverte du poste ; poste du terminal | CashRegisterApiTest |
| GET | `/api/caisse` | caisse | lecture, session du poste | CashRegisterApiTest |
| GET | `/api/cash-registers` | caisse | lecture ; postes inactifs : responsable seulement | CashRegisterApiTest, PosManagerRoleTest |
| GET | `/api/colors` | web + caisse | lecture de référentiels | — |
| DELETE | `/api/notes-de-frais/{id}` | web + caisse | **ROLE_ADMIN ou propriétaire de la collection de la note** (403 `FORBIDDEN`, **corrigé S2.7** : avant, tout utilisateur connecté, toute note) | PosRoleMatrixTest |
| PUT | `/api/notes-de-frais/{id}` | web + caisse | **ROLE_ADMIN ou propriétaire de la collection** (**corrigé S2.7**) | PosRoleMatrixTest |
| POST | `/api/order/cancel/{id}` | web + caisse | auteur de la commande ; vente de caisse d'autrui : responsable ; C3 `refund` selon seuil | PosOrderApiTest, PosManagerRoleTest, PosRoleMatrixTest |
| POST | `/api/order/create-multi-payment` | caisse | `orderSource` 2 : session ouverte du poste (ou du terminal) ; C3 `discount`, `price_override`, `custom_item` ; retour libre selon réglage ; carte-cadeau : limite de codes refusés | PosOrderApiTest, PosDiscountApiTest, PosCustomItemApiTest, PosGiftCardApiTest, PosRoleMatrixTest |
| POST | `/api/order/create` | web + caisse | route web : caissier/responsable sans ROLE_ADMIN → vente web seulement (403 `POS_ROUTE_REQUIRED` pour source 2 ou type ≠ vente, **corrigé S2.7**) ; adresse de l'utilisateur | CheckoutApiTest, PosRoleMatrixTest |
| GET | `/api/orders` | web + caisse | caissier/responsable : `orderSource=2` obligatoire (403 sinon) ; admin : tout | PosRoleAccessTest |
| GET | `/api/ordersuser` | web + caisse | ses commandes | CustomerAccountApiTest |
| POST | `/api/payment` | web + caisse | paiement de l'utilisateur, invité avec `guestToken` ou administrateur, sinon 404 `PAYMENT_NOT_FOUND` (**corrigé S7, 31/10/2026**) | CustomerAccountApiTest |
| GET | `/api/payments` | web + caisse | caissier/responsable : `orderSource=2` obligatoire ; admin : tout | PosRoleAccessTest |
| POST | `/api/pos/approvals` | caisse | PIN d'un responsable, terminal ; 30 / terminal / 15 min (429 `APPROVAL_RATE_LIMITED`) | PosPinApiTest |
| GET | `/api/pos/archive/{year}` | caisse | **responsable** (403 `FORBIDDEN`) | PosRetentionArchiveApiTest, PosRoleMatrixTest |
| POST | `/api/pos/customers/{id}/erase` | caisse | **responsable** (puis C3 `customer_erase` formelle) | PosCustomerApiTest, PosRoleMatrixTest |
| GET | `/api/pos/customers/{id}/export` | caisse | **responsable** | PosCustomerApiTest, PosRoleMatrixTest |
| GET | `/api/pos/customers/{id}/history` | caisse | fiche du site ; champs masqués pour le caissier ; journal | PosCustomerApiTest |
| POST | `/api/pos/customers/{id}/link` | caisse | consentement `account_link` du client exigé, réglage `linkEnabled` | PosCustomerApiTest |
| POST | `/api/pos/customers/{id}/loyalty/adjust` | caisse | C3 `loyalty_adjust` (toujours pour un caissier) | PosCustomerApiTest, PosRoleMatrixTest |
| POST | `/api/pos/customers/{id}/merge` | caisse | **responsable** | PosCustomerApiTest, PosRoleMatrixTest |
| GET | `/api/pos/customers/{id}` | caisse | fiche du site ; masquage caissier ; journal `CUSTOMER_VIEWED` | PosCustomerApiTest |
| PATCH | `/api/pos/customers/{id}` | caisse | fiche du site ; consentements | PosCustomerApiTest |
| GET | `/api/pos/customers` | caisse | recherche : courriel/téléphone exacts, nom ≥ 3 ; 30/min/utilisateur (429) ; journal masqué | PosCustomerApiTest |
| POST | `/api/pos/customers` | caisse | création ; téléphone E.164 | PosCustomerApiTest |
| POST | `/api/pos/exchanges/quote` | caisse | lecture (simulation) ; toute vente du site | PosReturnApiTest |
| POST | `/api/pos/exchanges` | caisse | toute vente du site ; session du poste du caissier ; C3 `return` selon seuil | PosReturnApiTest, PosRoleMatrixTest |
| GET | `/api/pos/exports/accounting/entries.csv` | caisse | **responsable** | PosReportingApiTest, PosRoleMatrixTest |
| GET | `/api/pos/exports/accounting/summary.csv` | caisse | **responsable** | PosReportingApiTest, PosRoleMatrixTest |
| GET | `/api/pos/exports/accounting` | caisse | **responsable** | PosReportingApiTest, PosRoleMatrixTest |
| POST | `/api/pos/gift-cards/replace` | caisse | C3 `gift_card_replace` ; vente d'origine + 4 derniers caractères | PosGiftCardApiTest, PosRoleMatrixTest |
| POST | `/api/pos/gift-cards/{code}/adjust` | caisse | C3 `gift_card_adjust` | PosGiftCardApiTest, PosRoleMatrixTest |
| POST | `/api/pos/gift-cards/{code}/cancel` | caisse | C3 `gift_card_cancel` | PosGiftCardApiTest, PosRoleMatrixTest |
| POST | `/api/pos/gift-cards/{code}/cash-out` | caisse | réglage `cashOutEnabled` ; session du poste ; C3 selon seuil | PosGiftCardApiTest |
| GET | `/api/pos/gift-cards/{code}` | caisse | 404 uniforme ; 10 codes refusés / 10 min / utilisateur et terminal (429) ; limiteurs IP/terminal F9 | PosGiftCardApiTest, PosRoleMatrixTest |
| POST | `/api/pos/gift-cards` | caisse | session ouverte du poste ; poste du terminal ; réglage `enabled` | PosGiftCardApiTest |
| GET | `/api/pos/offline-cases` | caisse | lecture publique du jeu de cas (connecté) | PosOfflineApiTest |
| GET | `/api/pos/offline-pack` | caisse | lecture ; poste du terminal | PosOfflineApiTest |
| GET | `/api/pos/offline-sales` | caisse | **responsable** (historique des synchronisations) | PosOfflineApiTest |
| POST | `/api/pos/offline-sales` | caisse | `X-Pos-Terminal` obligatoire ; vendeur déclaré doit avoir un rôle de caisse ; C3 `offline_force` ; plafonds de remise | PosOfflineApiTest |
| POST | `/api/pos/pin` | caisse | son propre PIN, mot de passe exigé | PosPinApiTest |
| POST | `/api/pos/quote` | caisse | lecture (devis) ; remises plafonnées par rôle (information) | PosDiscountApiTest |
| POST | `/api/pos/receipts/email` | caisse | toute vente du site (par conception) ; adresse non conservée ; limite d'envois | PosReceiptApiTest |
| POST | `/api/pos/receipts/revoke-link` | caisse | **responsable** (route et service, **corrigé S2.7**) | PosReceiptApiTest, PosRoleMatrixTest |
| GET | `/api/pos/receipts` | caisse | toute vente du site (réimpression, par conception) ; journal `RECEIPT_REPRINTED` | PosReceiptApiTest, PosRoleMatrixTest |
| GET | `/api/pos/returns/lookup` | caisse | toute vente du site (lecture) | PosReturnApiTest, PosRoleMatrixTest |
| POST | `/api/pos/returns` | caisse | toute vente du site ; session du poste du caissier ; C3 `return` selon seuil ; sans ticket selon réglage | PosReturnApiTest, PosRoleMatrixTest |
| GET | `/api/pos/settings` | caisse | lecture des réglages du site (sans secret) | PosSettingsApiTest |
| GET | `/api/pos/statistics/cashiers` | caisse | caissier : ramené à lui-même (`scope: self`) ; responsable : tous | PosReportingApiTest, PosRoleMatrixTest |
| POST | `/api/pos/terminal/payments/{id}/cancel` | caisse | paiement du site ; terminal | PosTerminalApiTest |
| POST | `/api/pos/terminal/payments/{id}/refund` | caisse | C3 `refund` selon seuil | PosTerminalApiTest |
| POST | `/api/pos/terminal/payments/{id}/simulate` | caisse | **responsable** (mode test) | PosTerminalApiTest, PosRoleMatrixTest |
| GET | `/api/pos/terminal/payments/{id}` | caisse | lecture, paiement du site | PosTerminalApiTest |
| GET | `/api/pos/terminal/payments` | caisse | lecture, paiements du site | PosTerminalApiTest |
| POST | `/api/pos/terminal/payments` | caisse | session ouverte du poste ; poste du terminal | PosTerminalApiTest |
| GET | `/api/pos/terminal` | caisse | poste du terminal si rattaché (403 `CASH_REGISTER_FORBIDDEN`) ; rend `cashRegisterId` et `mode` | PosTerminalApiTest, PosRoleMatrixTest |
| POST | `/api/pos/coupons/parse` | caisse | lecture d'un code-barres, aucune écriture (F17) | PosManufacturerCouponApiTest |
| GET | `/api/pos/coupons/claims` et `.csv` | caisse | **responsable** (403 `FORBIDDEN`) (F17) | PosManufacturerCouponApiTest |
| POST | `/api/pos/coupons/claims/mark` | caisse | **responsable** (F17) | PosManufacturerCouponApiTest |
| GET | `/api/pos/health` | **administrateur** (M1) | surveillance des paiements de caisse, lecture | PosStripeLiveApiTest |
| GET | `/api/pos/favorites` | caisse | lecture ; poste facultatif (F10) | PosFavoritesApiTest |
| GET | `/api/pos/favorites/pins` | caisse | **responsable** (403 `FORBIDDEN`) (F10) | PosFavoritesApiTest |
| PUT | `/api/pos/favorites/pins` | caisse | **responsable** (403 `FORBIDDEN`) ; journal `FAVORITES_UPDATED` (F10) | PosFavoritesApiTest |
| POST | `/api/pos/terminal/connection-token` | caisse | poste du terminal (rattachement), mode kit autorisé par le site, `X-Pos-Terminal` obligatoire, 60 / terminal / 10 min (F13/F16) | PosTerminalSdkApiTest |
| POST | `/api/pos/terminal/payments/{id}/refund/confirm` | caisse | paiement du site, remboursement Stripe vérifié (intention, montant), rejeu idempotent (F13/F16) | PosTerminalSdkApiTest |
| POST | `/api/pos/unlock` | caisse | son propre PIN ; blocage après N essais | PosPinApiTest |
| GET | `/api/products/{id}/reviews/eligibility` | web + caisse | utilisateur connecté (achat) | ReviewApiTest |
| POST | `/api/products/{id}/reviews` | web + caisse | éligibilité (achat), limite de débit | ReviewApiTest |
| GET | `/api/profile` | web + caisse | soi-même | CustomerAccountApiTest |
| PATCH, PUT | `/api/profile` | web + caisse | soi-même ; rôles non modifiables ; changement réel de courriel confirmé par mot de passe ou code OTP (**corrigé S7**) | CustomerAccountApiTest |
| GET | `/api/reviews/mine` | web + caisse | ses avis | ReviewApiTest |
| DELETE | `/api/reviews/{id}` | web + caisse | auteur (404 sinon) | ReviewApiTest |
| PUT, PATCH | `/api/reviews/{id}` | web + caisse | auteur (404 sinon) | ReviewApiTest |
| POST | `/api/shipping/buy` | web + caisse | commande de l'utilisateur ou ROLE_ADMIN (403 `FORBIDDEN`, 404 `ORDER_NOT_FOUND`), trace retirée de la réponse (**corrigé S2.7**) | PosRoleMatrixTest |
| POST | `/api/shipping/parcels` | web + caisse | calcul sans écriture | — |
| GET | `/api/sizes` | web + caisse | lecture de référentiels | — |
| GET | `/api/social-networks` | web + caisse | lecture de référentiels | — |
| GET | `/api/styles` | web + caisse | lecture de référentiels | — |
| POST | `/api/subscriptions/portal-session` | web + caisse + `#[IsGranted(ROLE_USER_INTERNET)]` | `#[IsGranted(ROLE_USER_INTERNET)]` : refusé au caissier ; ses abonnements | SubscriptionApiTest |
| POST | `/api/subscriptions/{id}/cancel` | web + caisse + `#[IsGranted(ROLE_USER_INTERNET)]` | `#[IsGranted(ROLE_USER_INTERNET)]` : refusé au caissier ; ses abonnements | SubscriptionApiTest |
| POST | `/api/subscriptions/{id}/change-plan` | web + caisse + `#[IsGranted(ROLE_USER_INTERNET)]` | `#[IsGranted(ROLE_USER_INTERNET)]` : refusé au caissier ; ses abonnements | SubscriptionApiTest |
| POST | `/api/subscriptions/{id}/pause` | web + caisse + `#[IsGranted(ROLE_USER_INTERNET)]` | `#[IsGranted(ROLE_USER_INTERNET)]` : refusé au caissier ; ses abonnements | SubscriptionApiTest |
| POST | `/api/subscriptions/{id}/resume` | web + caisse + `#[IsGranted(ROLE_USER_INTERNET)]` | `#[IsGranted(ROLE_USER_INTERNET)]` : refusé au caissier ; ses abonnements | SubscriptionApiTest |
| GET | `/api/subscriptions/{id}` | web + caisse + `#[IsGranted(ROLE_USER_INTERNET)]` | `#[IsGranted(ROLE_USER_INTERNET)]` : refusé au caissier ; ses abonnements | SubscriptionApiTest |
| GET | `/api/subscriptions` | web + caisse + `#[IsGranted(ROLE_USER_INTERNET)]` | `#[IsGranted(ROLE_USER_INTERNET)]` : refusé au caissier ; ses abonnements | SubscriptionApiTest |
| POST | `/api/subscriptions` | web + caisse + `#[IsGranted(ROLE_USER_INTERNET)]` | `#[IsGranted(ROLE_USER_INTERNET)]` : refusé au caissier ; ses abonnements | SubscriptionApiTest |
| GET | `/api/type-fournisseurs` | web + caisse | lecture de référentiels | — |
| GET | `/api/type-note-de-frais` | web + caisse | lecture de référentiels | — |
| ANY | `/cart/add/{id}` | aucune règle | panier de session (pages HTML du site legacy) | — |
| ANY | `/cart/delete-all/{id}` | aucune règle | panier de session (pages HTML du site legacy) | — |
| ANY | `/cart/delete/{id}` | aucune règle | panier de session (pages HTML du site legacy) | — |
| ANY | `/cart` | aucune règle | panier de session (pages HTML du site legacy) | — |
| ANY | `/cgu/conditions-generales-utilisation` | aucune règle | page publique du site legacy / connexion | — |
| ANY | `/checkout/confirm` | web + caisse | panier de session, ses adresses | — |
| ANY | `/checkout/edit` | web + caisse | idem | — |
| ANY | `/checkout` | web + caisse | idem | — |
| GET, POST | `/contact/` | aucune règle | page publique du site legacy / connexion | — |
| ANY | `/create-checkout-session/{reference}` | aucune règle | tunnel Stripe legacy (référence de commande) | — |
| ANY | `/logout` | aucune règle | page publique du site legacy / connexion | — |
| POST | `/password-reset/confirm` | aucune règle | page publique du site legacy / connexion | — |
| GET | `/password-reset/form` | aucune règle | page publique du site legacy / connexion | — |
| ANY | `/product/{slug}` | aucune règle | page publique du site legacy / connexion | — |
| ANY | `/register` | aucune règle | page publique du site legacy / connexion | — |
| ANY | `/shop` | aucune règle | page publique du site legacy / connexion | — |
| ANY | `/stripe-payment-cancel/{StripeCheckoutSessionId}` | aucune règle | tunnel Stripe legacy (référence de commande) | — |
| ANY | `/stripe-payment-succes/{StripeCheckoutSessionId}` | aucune règle | tunnel Stripe legacy (référence de commande) | — |
| GET, POST | `/stripe/connect` | aucune règle + `#[IsGranted(ROLE_ADMIN)]` | `#[IsGranted(ROLE_ADMIN)]` | — |
| GET, POST | `/stripe/disconnect` | aucune règle + `#[IsGranted(ROLE_ADMIN)]` | `#[IsGranted(ROLE_ADMIN)]` | — |
| GET | `/stripe/success` | aucune règle + `#[IsGranted(ROLE_ADMIN)]` | `#[IsGranted(ROLE_ADMIN)]` | — |
| GET | `/videos/{id}` | aucune règle | page publique du site legacy / connexion | — |
| GET | `/videos` | aucune règle | page publique du site legacy / connexion | — |
| GET | `/api/validate-token` | web + caisse | soi-même | AuthCookieFlowTest |
| GET | `/verify/email` | aucune règle | page publique du site legacy / connexion | — |
| ANY | `/setup/new-store` | aucune règle | jeton de création de boutique (base maître) | AnonymousWriteAccessTest (liste) |
| ANY | `/setup/status/{tenantCode}/{syncJobId}/{finalUrl}` | aucune règle | jeton de création de boutique (base maître) | AnonymousWriteAccessTest (liste) |
| GET, HEAD | `/assets/uploads/{path}` | aucune règle | fichiers publics ou clé d'accès signée | SharedMediaDeliveryHardeningTest |
| GET, HEAD | `/bucket-simulator/{path}` | aucune règle | fichiers publics ou clé d'accès signée | SharedMediaDeliveryHardeningTest |
| GET, HEAD | `/media/secure/{accessKey}` | aucune règle | fichiers publics ou clé d'accès signée | SharedMediaDeliveryHardeningTest |
| GET | `/shared-media/{id}` | aucune règle | médias partagés publics (UUID) | SharedMediaSecurityTest |
| GET | `/shared-media` | aucune règle | médias partagés publics (UUID) | SharedMediaSecurityTest |
| GET, HEAD | `/uploads/{path}` | aucune règle | fichiers publics ou clé d'accès signée | SharedMediaDeliveryHardeningTest |
| GET | `/api/categories.{_format}` | web + caisse | lecture | — |
| POST | `/api/categories.{_format}` | web + caisse | `security: is_granted(ROLE_ADMIN)` sur l'opération (API Platform ; `endpoints.md` ne le voit pas) | — |
| DELETE | `/api/categories/{id}.{_format}` | web + caisse | `security: is_granted(ROLE_ADMIN)` sur l'opération (API Platform ; `endpoints.md` ne le voit pas) | — |
| GET | `/api/categories/{id}.{_format}` | web + caisse | lecture | — |
| PATCH | `/api/categories/{id}.{_format}` | web + caisse | `security: is_granted(ROLE_ADMIN)` sur l'opération (API Platform ; `endpoints.md` ne le voit pas) | — |
| PUT | `/api/categories/{id}.{_format}` | web + caisse | `security: is_granted(ROLE_ADMIN)` sur l'opération (API Platform ; `endpoints.md` ne le voit pas) | — |
| GET, HEAD | `/api/errors/{status}` | web + caisse | pages d'erreur API Platform | — |
| GET | `/api/validation_errors/{id}` | web + caisse | pages d'erreur API Platform | — |
| GET | `/api/validation_errors/{id}` | web + caisse | pages d'erreur API Platform | — |
| GET | `/api/validation_errors/{id}` | web + caisse | pages d'erreur API Platform | — |

Routes **publiques** (`PUBLIC_ACCESS`, un caissier n'y gagne rien par rapport à un visiteur) : `/api/Carrier` (1), `/api/admin-settings` (2), `/api/adresses` (2), `/api/app` (1), `/api/app-profile` (1), `/api/baniere-statiques` (2), `/api/bannieres` (2), `/api/booking` (2), `/api/boussole` (3), `/api/boutique-settings` (1), `/api/candidatures` (1), `/api/cart` (1), `/api/categories-marque` (2), `/api/category` (1), `/api/components-config` (1), `/api/contact` (1), `/api/contacts` (2), `/api/currencies` (1), `/api/customization` (1), `/api/embeds` (2), `/api/emplois` (1), `/api/entreprise` (1), `/api/explore-cards` (1), `/api/features` (1), `/api/financement` (1), `/api/health` (1), `/api/homeslider` (1), `/api/landingpage` (1), `/api/landingpage-config` (3), `/api/landingpage-settings` (1), `/api/login` (1), `/api/logout` (1), `/api/marques` (1), `/api/memoires` (40), `/api/multiliens` (2), `/api/newsletter` (1), `/api/order` (1), `/api/order-statuses` (1), `/api/orders` (1), `/api/otp-verify` (1), `/api/password-reset` (2), `/api/pos` (2), `/api/presentation-groups` (2), `/api/presentations` (2), `/api/products` (8), `/api/products.{_format}` (1), `/api/productsid` (1), `/api/r` (1), `/api/recherches` (2), `/api/register` (1), `/api/resend-verification` (1), `/api/reservations` (5), `/api/service-offers` (2), `/api/shared-media` (2), `/api/shipping` (2), `/api/stripe` (2), `/api/stripe-config` (1), `/api/subscription-plans` (1), `/api/team` (2), `/api/tenant` (3), `/api/token` (1), `/api/transporteurs` (1), `/api/vehicle_products` (1), `/api/vehicle_products.{_format}` (1), `/api/vehicles` (2), `/api/vehicles.{_format}` (1), `/api/videos` (2), `/api/webhooks` (2), `/stripe` (1).

Constats hors lot : `POST /api/payment` et `PATCH /api/profile`, corrigés par le lot S7 du 31/10/2026 (`docs/boutique.md`) ; les ressources API Platform `Commande`, `Product`, `Vehicle`, `VehicleProduct` n'ont pas de clause
`security` (couvertes par `access_control` seul) ; `docs/endpoints.md` ne voit pas les clauses `security` d'API Platform
(`/api/categories` : écritures bien réservées à ROLE_ADMIN).


## Kit Stripe Terminal dans l'application : Tap to Pay et lecteur Bluetooth (lots F13 / F16, 31/10/2026)

En plus du terminal **piloté par le serveur** (C4 : WisePOS E, S700, la tablette ne parle jamais au lecteur), deux façons d'encaisser
une carte **depuis l'application** avec le kit natif Stripe Terminal (iOS et Android, pas de web) : **Tap to Pay** (le téléphone
ou la tablette lit la carte sans contact) et le **lecteur Bluetooth BBPOS WisePad 3**. Dans les deux cas l'application parle au
lecteur ; le serveur fournit les jetons, crée et vérifie le paiement ; aucune clé Stripe ni aucun numéro de carte dans
l'application. Code : `Services/PosTerminalService/PosTerminalPaymentService` (canal `sdk`), `PosTerminalSettingsService`,
`Controller/PosController/PosTerminalController` ; schéma `migrations/Version20261031100000.php`, `scripts/migrate_all_v2_pos_terminal_sdk.sh` ;
tests `tests/Functional/Boutique/PosTerminalSdkApiTest.php` (kit simulé : `FakeTerminalGateway::sdkConfirm`, `sdkRefund`).

### Disponibilité au Canada (documentation Stripe, 31/10/2026)

| | Disponibilité | Conditions |
|---|---|---|
| Tap to Pay sur iPhone | Canada, Interac pris en charge, PIN depuis iOS 16.4 | iPhone XS ou plus récent, iOS d'au plus un an ; **iPad non pris en charge** ; droit Apple « Tap to Pay on iPhone » demandé par l'éditeur de l'application (développement puis distribution), clé `com.apple.developer.proximity-reader.payment.acceptance`, revue Apple, écran « Comment payer » obligatoire |
| Tap to Pay sur Android | Canada, Interac et PIN pris en charge | Android 13 ou plus, services Google et Play Store, correctif de sécurité de moins de 12 mois, non enraciné, options développeur désactivées, **jamais un terminal certifié PCI PTS** ; appareils cités par Stripe : Sunmi **T3**, **D3 MINI**, V3 MIX, L3, V3, CPad, Flex 3, Galaxy Tab Active5 (le D3 « tout court » n'est pas listé) |
| BBPOS WisePad 3 | Canada | Bluetooth basse énergie (iOS, Android), clavier PIN et puce, pack de langue français avec un emplacement canadien, logiciel `4.01.07.00_Prod_NA_off_v30` ou plus récent |
| Interac (`interac_present`) | les trois | capture automatique seulement (`manual_preferred` pour mélanger avec la carte de crédit) ; **remboursement en personne obligatoire**, par le kit (`processRefund`), jamais par l'API |

**Point d'attention** : beaucoup de cartes canadiennes sont « PIN hors ligne seulement » : au-dessus des plafonds sans contact (Interac
Flash 250 $, insertion exigée au-delà de 100 $ ou à la quatrième transaction consécutive), la carte doit être insérée, ce que Tap to Pay
ne permet pas. Stripe refuse alors la transaction (`offline_pin_required`, `online_or_offline_pin_required`) : le POS propose un autre
moyen (lecteur, espèces). Le WisePad 3 n'a pas cette limite. Stripe ne publie pas de kit Flutter : l'application passe par un greffon
ou des canaux de plateforme ; le contrat serveur ci-dessous n'en dépend pas.

### Modes et réglages

- **Mode du terminal par poste** (menu Postes de caisse, « Mode du terminal ») : `manual` (carte déclarée), `server` (piloté par le
  serveur), `tap_to_pay`, `bluetooth`. Non réglé = **déduit de l'existant** : `server` si un lecteur est rattaché et activé, `manual`
  sinon. Exposé dans `GET /api/pos/terminal` (`mode`) et `GET /api/cash-registers` (`terminalMode`).
- **Autorisations par site** (fiche Entreprise, panneau « Terminal de paiement Stripe (caisse) ») : « Tap to Pay autorisé » et
  « Lecteur Bluetooth autorisé », **défaut non** ; « Remboursement Interac en attente sur le lecteur : expiration (minutes) », défaut
  15. `GET /api/pos/settings` : `terminal.tapToPay`, `terminal.bluetoothReader`, `terminal.refundPendingMinutes`.
- Poste en mode kit non autorisé par le site : `GET /api/pos/terminal` → `available: false, error: TERMINAL_MODE_NOT_ALLOWED` ; jeton et
  paiement → 409 `TERMINAL_MODE_NOT_ALLOWED`. Poste en mode manuel ou serveur : jeton → 409 `TERMINAL_MODE_INVALID`.
- La **carte déclarée** reste soumise au réglage R1 (`declaredCardBlockedWithTerminal` : bloquée aussi quand le poste est en mode kit autorisé).
- **Hors ligne** : cartes toujours refusées hors ligne (décision C5) ; ne pas activer le mode hors ligne du kit Stripe.

### Jeton de connexion : `POST /api/pos/terminal/connection-token {cashRegisterId?}` (caissier, `X-Pos-Terminal` obligatoire)

→ 200 `{secret, locationId, mode, cashRegisterId}` : ConnectionToken créé sur le **compte connecté du site** pour l'**emplacement du
site** (créé au besoin depuis l'adresse de l'entreprise, comme C4). Le poste est celui du terminal rattaché (A1-S2 : autre poste → 403
`CASH_REGISTER_FORBIDDEN` pour un caissier ; sans `cashRegisterId`, le poste du terminal). Le secret n'est **jamais mis en cache ni
journalisé** (l'application le redemande à chaque reconnexion du kit) ; **60 jetons par terminal et 10 minutes** → 429
`CONNECTION_TOKEN_RATE_LIMITED {retryAfterSeconds}` ; journal `CONNECTION_TOKEN_ISSUED {cashRegisterId, mode, locationId, terminalId}`.
Sans en-tête : 403 `TERMINAL_REQUIRED`.

### Paiement : routes C4 réutilisées, canal porté par le poste (`channel: server | sdk`)

| Étape | Kit (poste `tap_to_pay` ou `bluetooth`) | Serveur (C4), inchangé |
|---|---|---|
| `POST /api/pos/terminal/payments {cashRegisterId?, amount, items, otherPayments?, reader?: {id, label, type}}` | même PaymentIntent (`card_present` + `interac_present`, `manual_preferred`, commission, `metadata.pos_terminal`, `pos_channel: sdk`), ligne `channel: sdk`, **`clientSecret` rendu** ; `X-Pos-Terminal` obligatoire (403 `TERMINAL_REQUIRED`) ; un paiement à la fois **par terminal** (409 `READER_BUSY`) ; `reader` journalisé | envoyé au lecteur ; **`clientSecret: null`, toujours** |
| L'application | `collectPaymentMethod` puis `confirmPaymentIntent` avec le secret | — |
| `GET /api/pos/terminal/payments/{id}` | relit Stripe : `succeeded` + `captured: false` (carte autorisée) ou `true` (Interac débité) ; **refus d'une carte** (dont « PIN hors ligne ») : `status` reste `waiting_for_card`, `failureCode` = code Stripe tel quel (`offline_pin_required`…), `failureMessage`, `clientSecret` toujours présent pour une autre carte ; au-delà de `cardWaitSeconds` : `canceled`, `failureCode: timeout` | relit Stripe et le lecteur |
| `POST /api/order/create-multi-payment` avec `terminalPaymentId` | identique : vérifié puis **capturé dans la transaction** | identique |
| `POST …/cancel` | intention annulée chez Stripe (pas d'action lecteur) ; le kit peut aussi annuler localement, le serveur fait foi | action du lecteur et intention annulées |
| Paiements sans vente, réconciliation (cron `app:pos:terminal-reconcile`, qui parcourt désormais aussi les sites n'ayant que des postes en mode kit), idempotence (`Idempotency-Key`), journal, rapports X et Z (`cardTerminal`) | identiques (même table, mêmes états) | identiques |

Réponse d'un paiement : clés ajoutées **`channel`**, **`clientSecret`** (secret du canal `sdk` tant que la carte est attendue, sinon
`null`), **`refundInstruction`** (ci-dessous) ; rien de retiré.

### Remboursements

- **Carte de crédit** (`card_present`) : inchangé, par le serveur en ligne (annulation de vente, retour, `POST …/refund`).
- **Interac par le kit** : `POST /api/pos/terminal/payments/{id}/refund {amount?}` (validation C3 `refund` inchangée) → `refund.status:
  waiting_for_card` et **`refundInstruction {paymentIntentId, chargeId, amount, currency, requestedAt, expiresInMinutes}`** ; journal
  `REFUND_PENDING`. L'application appelle `processRefund` sur le lecteur (le client représente la carte d'origine), puis
  **`POST /api/pos/terminal/payments/{id}/refund/confirm {stripeRefundId}`** : le serveur relit le remboursement chez Stripe (404
  `REFUND_NOT_FOUND`, 409 `REFUND_MISMATCH` s'il porte sur un autre paiement, 409 `REFUND_AMOUNT_MISMATCH`), l'enregistre
  (`refund.status: succeeded`, `refund.id` à passer en **`terminalRefundId`** à l'annulation ou au retour, comme C4), journal
  `REFUND_CONFIRMED` + `CARD_REFUND` ; **même `stripeRefundId` rejoué → même réponse** ; autre identifiant après confirmation → 409
  `REFUND_ALREADY_CONFIRMED` ; sans demande en attente → 409 `REFUND_NOT_PENDING`.
- **Expiration** : un remboursement en attente non confirmé après `refundPendingMinutes` (défaut 15) est repris par la réconciliation
  C4 (et par `GET`) : Stripe est relu ; un remboursement du bon montant postérieur à la demande est enregistré (`REFUND_CONFIRMED`,
  `via: reconcile`) ; sinon la demande expire (`refund.status: failed`, message « demande expirée », journal `REFUND_EXPIRED`) et le
  paiement redevient remboursable.

### Journal

`CONNECTION_TOKEN_ISSUED`, `REFUND_PENDING`, `REFUND_CONFIRMED`, `REFUND_EXPIRED` ; les entrées `CARD_*` portent `channel`, `readerLabel`
et, au démarrage, `reader` déclaré par l'application. Aucun secret, aucun numéro de carte.

## Coupons de fabricant (lot F17, 01/11/2026)

Bons de réduction émis par une marque et **remboursés au commerçant** par le fabricant ou une chambre de compensation. Code :
`Services/PosCouponService/` (`PosCouponService`, `CouponBarcodeParser`), `Controller/PosController/PosCouponController`,
`Controller/Admin/PosCouponAdminController` ; entité `PosManufacturerCoupon` (`pos_manufacturer_coupon`), colonne
`order.manufacturer_coupon_applied` ; schéma `migrations/Version20261101100000.php`, `scripts/migrate_all_v2_pos_coupons.sh` ;
tests `tests/Functional/Boutique/PosManufacturerCouponApiTest.php`.

### Règle fiscale et modèle

- **Loi sur la taxe d'accise, art. 181(2)** (bon remboursable) : la taxe percevable par l'inscrit est réputée égale à celle qui serait
  percevable **sans le bon** ; le bon vaut paiement partiel taxes comprises, l'inscrit est réputé avoir perçu la fraction de taxe de la
  valeur du bon (crédit de taxe sur les intrants ; le remboursement par le fabricant n'est pas une contrepartie, art. 181(5)).
  **Revenu Québec** (bons de réduction remboursables) applique la même règle à la TVQ : le bon « réduit le total de la facture comme
  un paiement en argent », TPS et TVQ calculées sur le prix de l'article sans tenir compte du bon. Exemple officiel : article 25 $,
  bon 10 $ : TPS 1,25 $, TVQ 2,49 $, le client paie 18,74 $. Le bon **non remboursable** (celui du magasin) peut, lui, réduire la
  base de taxe : c'est la remise F1, qui n'a pas changé.
- **Modèle retenu : le coupon est un MOYEN DE PAIEMENT** (part `type: 6`, modèle des cartes-cadeaux F9), jamais une remise. Le devis,
  les lignes, les taxes et le total de la vente sont ceux du prix plein ; seul le **dû** diminue. Aucune ligne dans la table des
  paiements : la part est cumulée dans `order.manufacturer_coupon_applied`, chaque coupon a sa ligne dans `pos_manufacturer_coupon`.
  Plusieurs coupons par vente. Vente au comptoir seulement (`orderSource` 2, vente de type 1) : 409 `COUPON_PAYMENT_INVALID` sinon.
  Les remises F1, les prix promotionnels et le tunnel web sont inchangés.

### Réglages (fiche Entreprise, panneau « Coupons de fabricant (caisse) » ; `settings.coupons`, `settings.approvals.manufacturerCoupon`)

| Réglage | Défaut | Effet |
|---|---|---|
| Coupons de fabricant acceptés | **non** | 409 `COUPON_DISABLED` sur tout coupon (devis et vente) |
| Montant maximal de coupons par vente sans validation (cents) | 2000 (20 $) | au-delà du **total** des coupons de la vente : validation C3 action **`manufacturer_coupon`**, contexte `{amount}` ; 0 = toujours ; intégrée aux validations groupées P2-a (un seul jeton avec les remises, articles libres et prix manuels) ; jamais pour un responsable |

`GET /api/pos/settings` : `coupons {enabled, maxWithoutApproval, barcodeFormats: ["gs1_databar_8110", "upc_a_5"], taxOnFullPrice: true, offline: false}`,
`approvals.manufacturerCoupon {required, threshold}`. **Hors ligne** : part de type 6 refusée à la synchronisation (`OFFLINE_SALE_INVALID`,
« coupons de fabricant indisponibles hors ligne »), comme les cartes-cadeaux.

### Lecture d'un code-barres : `POST /api/pos/coupons/parse {barcode}` (caisse)

Formats : **GS1 DataBar Expanded, identificateur d'application 8110** (norme nord-américaine, GS1 US / GS1 Canada ; préfixe `(8110)` ou
`]e0` accepté) et **ancien UPC-A à système numérique 5**. Réponse : `{format, companyPrefix, offerCode, saveValue (cents), saveValueCode,
valueCode (fixed_amount | free_item | buy_x_get_y | percent_off | …), amount, amountReliable, expiresAt, startsAt, serial, familyCode,
purchaseRequirement {value, code}, retailerId, complete, expired, alreadyUsed}`. **`amountReliable: true`** seulement pour un montant fixe
(code de valeur 0 ou absent) sur un code entièrement lu : sinon `amount: null` et le POS **demande toujours le montant au caissier**.
Fiables : préfixe du fabricant, code d'offre, montant fixe, date d'expiration et numéro de série s'ils sont présents. Non fiables sans
les données du fabricant : exigences d'achat, codes famille, « article gratuit », « deux pour un », pourcentage. UPC-A système 5 : seul le
fabricant se lit, le code de valeur à deux chiffres suppose une table que l'on ne reproduit pas. 422 `COUPON_BARCODE_INVALID` sinon.
La structure DataBar vient de la norme NACAG et reste **à valider sur de vrais coupons** (recette prévue) ; `alreadyUsed` n'est renseigné
qu'avec un numéro de série (`null` sinon).

Exemples pour les tests du POS :

| Code-barres | Réponse attendue |
|---|---|
| `81100012345123456320011000032712315000012390000` (DataBar 8110, montant fixe) | `format: gs1_databar_8110, companyPrefix: "012345", offerCode: "123456", saveValue: 200, amount: 200, amountReliable: true, expiresAt: "2027-12-31", serial: "000123", valueCode: fixed_amount, complete: true` |
| `811000123456543211011000091000` (DataBar 8110, code de valeur 1 « article gratuit ») | `companyPrefix: "012345", offerCode: "654321", saveValue: 0, saveValueCode: "1", valueCode: free_item, amount: null, amountReliable: false, serial: null, alreadyUsed: null` → le caissier saisit le montant |
| `512345123257` (UPC-A système 5) | `format: upc_a_5, companyPrefix: "12345", familyCode: "123", valueCode: "25", amount: null, amountReliable: false` → le caissier saisit le montant ; `512345123250` → 422 (chiffre de contrôle) |

### Devis et vente

- **Devis** `POST /api/pos/quote` : `coupons: [{amount (cents, requis si le code-barres ne le donne pas), manufacturer (requis sans
  code-barres lisible), barcode?, offerCode?, serial?, productVariantId?, expiresAt? (AAAA-MM-JJ)}]` → ajoute `manufacturerCoupons
  {count, total, coupons: [{amount, manufacturer, companyPrefix, offerCode, serial, format, source: scan | manual, productVariantId,
  expiresAt, amountReliable}]}`, et **`amountDue` / `cashAmountDue`** diminués du total des coupons (après les cartes-cadeaux si
  `giftCards` est aussi envoyé). `total`, `taxes`, `lines` inchangés. Sans code-barres, `manufacturer` libre ; avec un code-barres sans
  fabricant saisi : `GS1 <préfixe>`.
- Refus (devis et vente) : 409 `COUPON_DISABLED` ; 422 `COUPON_AMOUNT_INVALID` (montant absent ou ≤ 0) ; 422 **`COUPON_EXCEEDS_ITEM`**
  (montant > net de la ligne concernée, toutes ses unités ; sans ligne : > net des articles) ; 422 `COUPON_EXCEEDS_DUE` (total des coupons
  > dû) ; 422 `COUPON_ITEM_NOT_IN_SALE` ; **422 `COUPON_EXPIRED`** quand la date d'expiration est lue ou saisie (le caissier retire le
  coupon, jamais de forçage ; sans date lisible, aucun contrôle) ; **409 `COUPON_ALREADY_USED`** quand le même coupon (fabricant + offre +
  numéro de série) a déjà été accepté sur le site et n'a pas été annulé par un retour avant réclamation (sans série, aucun contrôle).
  Chaque refus est journalisé `MANUFACTURER_COUPON_REFUSED {reason: already_used | expired, …}`, y compris en vente (réécrit après le
  retour arrière de la transaction, `inSale: true`).
- **Vente** `POST /api/order/create-multi-payment` : une part par coupon `{type: 6, amount (dollars, comme les autres parts), coupon:
  {manufacturer?, barcode?, offerCode?, serial?, productVariantId?, expiresAt?}}` (`manufacturer` ou `barcode` requis : 400
  `PAYMENT_METHODS_INVALID`). Les parts couvrent total + arrondi − crédit, coupons compris ; contrôles ci-dessus **avant** toute
  validation C3 ; enregistrement dans la transaction (ligne verrouillée), `MANUFACTURER_COUPON_REDEEMED` par coupon. Réponse : clés
  ajoutées **`manufacturerCouponApplied`** (dollars) et une entrée `payments[] {type: 6, label: "Coupon fabricant – <fabricant> (offre
  …)", amount, coupon {manufacturer, offerCode, status}}` ; `amountDue` = total + arrondi − crédit − cartes-cadeaux − coupons ; taxes et
  total inchangés. Échange F2 (`sale` de `POST /api/pos/exchanges`) : même corps. Terminal C4 : `otherPayments: [{type: 6, amount}]`
  accepté, part carte = total − espèces − coupons.
- **Ticket** (impression, réimpression, courriel, page publique) : une ligne « Coupon fabricant – <marque> » dans les paiements, taxes
  calculées sur le prix plein.

### Retours (F2) et annulations

- `GET /api/pos/returns/lookup` : `payments.manufacturerCoupons {total, items: [{id, orderItemId, manufacturer, amount, status}]}`.
- `POST /api/pos/returns` : le remboursement est le **montant réellement payé par le client** ; la part coupon des unités retournées
  n'est jamais rendue (coupon lié à une ligne : au prorata des unités retournées ; coupon sans ligne : au prorata du net retourné).
  Réponse : `manufacturerCoupons {notRefunded, voided, returnedAfterClaim, coupons}`. Un coupon dont la ligne est entièrement retournée
  (cumul des retours), ou tout coupon quand toute la vente est retournée : **`voided`** s'il n'était pas encore réclamé (il sort du
  rapport et libère son numéro de série), **`returned_after_claim`** s'il l'était déjà (le fabricant a déjà remboursé une vente défaite :
  à régulariser avec lui, section à part du rapport). Journal `MANUFACTURER_COUPON_VOIDED`.
- `POST /api/order/cancel/{id}` d'une vente avec coupons : montant rendu = total − coupons ; coupons annulés ou « retournés après
  réclamation » de la même façon ; journal `REFUND.data.manufacturerCoupons`.

### Réclamation (responsable ; page EasyAdmin « Coupons de fabricant (caisse) », `/admin/pos/coupons`)

| Méthode, route | Réponse |
|---|---|
| `GET /api/pos/coupons/claims?from&to&manufacturer&status` | `{period, status, manufacturer, count, total, taxFractions {TPS, TVQ…}, taxRule, byManufacturer: [{manufacturer, count, total, taxFractions}], coupons: [...]}` ; `status` : `to_claim` (défaut), `claimed`, `returned_after_claim`, `voided`, `all` ; période = date d'acceptation (fuseau du magasin), défaut mois en cours |
| `GET /api/pos/coupons/claims.csv?…` | CSV « ; », virgule décimale, BOM : Fabricant, Préfixe GS1, Code offre, Numéro de série, Accepté le, Vente, Montant, Statut, Réclamé le, Lot, Source, Fraction <taxe>… |
| `POST /api/pos/coupons/claims/mark {from, to, manufacturer?}` | coupons **à réclamer** de la période passés en `claimed` dans un lot `CL-<horodatage>-<aléa>` : `{batch, count, total, byManufacturer, period}` ; 409 `NOTHING_TO_CLAIM` ; journal `MANUFACTURER_COUPONS_CLAIMED` |

Caissier : 403 `FORBIDDEN`. **Fraction de taxe** d'un coupon = montant × taux / (100 + taux) par taxe (TPS 5/105, TVQ 9,975/109,975 :
25,00 $ → 1,19 $ et 2,27 $) : information pour le comptable (CTI / RTI), **aucune écriture automatique**.

### Rapports X et Z, export comptable (F14)

- X et Z : bloc **`manufacturerCoupons {count, total, voided}`** (acceptés sur la session, annulés par les retours de la session) ;
  `sales.manufacturerCoupons` (espèces + cartes + cartes-cadeaux + coupons = total des ventes).
- Plan de comptes : clé **`coupon_receivable`** (défaut 1050 « Coupons de fabricant à recevoir », actif). Écritures : encaissement par
  coupon au **débit** de ce compte (« Coupons de fabricant acceptés »), coupons annulés par les retours au **crédit** (« Coupons de
  fabricant annulés (retours) ») ; le règlement du fabricant (banque contre coupons à recevoir) se passe hors caisse. Synthèse CSV : deux
  colonnes ajoutées **en fin**, « Coupons fabricant acceptés » et « Coupons fabricant annulés ».

### Journal

`MANUFACTURER_COUPON_REDEEMED`, `MANUFACTURER_COUPON_REFUSED`, `MANUFACTURER_COUPON_VOIDED`, `MANUFACTURER_COUPONS_CLAIMED` ; les
entrées portent fabricant, préfixe, offre, série et montant, jamais de donnée personnelle.

## Paiements de caisse en production (lot M1)

### Mode Stripe par site : « Paiements de caisse : test | réel »

- Réglage du site, **test par défaut**, affiché dans la page EasyAdmin **« Paiements de caisse (Stripe) »** (menu Caisse) et dans
  `GET /api/pos/terminal` : nouvelle clé **`stripeMode: "test" | "live"`** (la clé `mode` reste le type de terminal).
- Clés : test = clés actuelles de la plateforme ; réel = **`STRIPE_LIVE_SECRET_KEY` / `STRIPE_LIVE_PUBLIC_KEY`, réservées à la caisse**.
  Compte Connect **réel distinct** (`stripe_config.live_account_id`, `live_is_active`, `live_terminal_location_id`) ; le compte actuel
  reste celui du mode test. Choix de la clé et du compte : `StripeAccountResolver` (réutilisable par la boutique le jour où elle passera
  en réel ; aujourd'hui la boutique, ses webhooks et les abonnements n'en dépendent pas).
- **Passage en réel** (page EasyAdmin seulement, jamais par le formulaire de l'entreprise) : clés réelles configurées, compte réel du
  magasin qui **peut encaisser ET recevoir les virements** (`charges_enabled`, `payouts_enabled`, relus chez Stripe à l'instant), **aucun
  paiement par terminal en cours** ni remboursement Interac en attente. Refus 409 `STRIPE_KEYS_MISSING`, `STRIPE_ACCOUNT_NOT_READY`
  (`{chargesEnabled, payoutsEnabled, currentlyDue, disabledReason}`), `TERMINAL_PAYMENTS_PENDING`. Les lecteurs appairés sont **détachés**
  (un lecteur appartient à un compte et à un mode) et s'appairent de nouveau depuis la fiche du poste. Journal **`STRIPE_MODE_CHANGED
  {from, to, detachedReaders, account}`** avec l'administrateur. Retour en test : mêmes contrôles de paiements en cours, journalisé.
- **En réel** : `simulationAllowed` toujours `false`, lecteur simulé refusé (403 `SIMULATION_FORBIDDEN`), carte simulée impossible.
- Chaque paiement par terminal garde son mode (`pos_terminal_payment.stripe_mode`). Toute opération Stripe sur un paiement de l'autre
  mode → **409 `TERMINAL_PAYMENT_OTHER_MODE {paymentId, paymentMode, siteMode}`**, rien n'est écrit : capture par la vente, annulation
  (`POST …/terminal/payments/{id}/cancel`), **remboursement (`POST …/terminal/payments/{id}/refund`, ajouté le 28/10/2026 : il manquait,
  un remboursement Interac d'un paiement de test aurait attendu pour toujours en mode réel)**, confirmation du remboursement du kit,
  remboursement d'une annulation ou d'un retour, carte simulée. Pour la caisse : afficher le message et rembourser autrement (espèces).
  Un tel paiement n'est plus relu chez Stripe.
- Passage en réel : le compte relu avec la clé réelle doit être **en mode réel chez Stripe** (`livemode`), sinon 409
  `STRIPE_ACCOUNT_NOT_LIVE` ; une clé sans préfixe `sk_live` / `rk_live` dans `STRIPE_LIVE_SECRET_KEY` est ignorée (clés réelles
  « non configurées »), et une clé réelle dans la variable de test aussi (28/10/2026).
- **« Déconnecter Stripe » (page Stripe de la boutique)** : sans compte réel de caisse, la ligne de configuration est supprimée comme
  avant ; caisse **en mode réel** → refus (message : repasser la caisse en test d'abord) ; caisse en test avec un compte réel enregistré →
  seul le compte de test de la boutique est retiré, le compte réel de la caisse est conservé. Boutique : une configuration active sans
  compte vaut « aucun compte » (jamais le compte de la plateforme avec une commission).

### Compte Connect réel d'un nouveau magasin (page « Paiements de caisse (Stripe) »)

« Inscription Stripe du compte réel » crée au besoin un compte Express réel (pays et courriel de l'entreprise) et ouvre le lien
d'inscription Stripe ; au retour, l'état est relu (actif si encaissement et virements autorisés). Le tableau affiche pour chaque mode :
compte (identifiant tronqué), actif, encaissement, virements, pièces demandées (`currently_due`, `past_due`), emplacement Terminal.
« Créer l'emplacement Terminal » crée l'emplacement du mode en cours à l'adresse de l'entreprise (aussi créé au premier appairage).
Journal `STRIPE_ACCOUNT_UPDATED` (création, lien, changement d'état) et `TERMINAL_CONFIGURED {action: location_created}`. Site interne :
compte de la plateforme, sans inscription.

### Surveillance : `GET /api/pos/health` (administrateur ; 403 sinon) et bloc de la page EasyAdmin

Toujours 200, `Cache-Control: no-store` : `{status: ok | info | warning | critical, checkedAt, site, usesTerminal, stripe {mode, internal,
keys {test, live}, account {configured, id (tronqué), active, terminalLocation, status {livemode, chargesEnabled, payoutsEnabled,
detailsSubmitted, currentlyDue, pastDue, eventuallyDue, disabledReason, currentDeadline} | {error}}, pendingPayments}, reconcile
{lastRunAt, lastOutcome, lastCompletedAt, minutesAgo, stale, staleAfterMinutes: 30, lastErrors}, checks: [{code, level, message, count,
details}], alerts {siteEnabled, siteRecipient (masqué), platformRecipient}}`.

| Contrôle (`code`) | Niveau | Quand |
|---|---|---|
| `STRIPE_KEYS_MISSING` | critical | mode réel sans clés réelles |
| `STRIPE_ACCOUNT_NOT_READY` | critical | mode réel, encaissement ou virements suspendus |
| `STRIPE_ACCOUNT_REQUIREMENTS` | warning | mode réel, pièces demandées par Stripe |
| `STRIPE_ACCOUNT_UNREADABLE` | warning | compte réel illisible chez Stripe |
| `SIMULATED_READER_IN_LIVE` | critical | lecteur simulé en mode réel |
| `STRIPE_TEST_MODE` | info | site avec terminal en mode test |
| `RECONCILE_STALE` | critical | site avec terminal, réconciliation complète il y a plus de 30 minutes (hors plage horaire réglée : non). Visible dans la route et la page EasyAdmin ; **jamais envoyé par courriel**, puisque c'est la réconciliation qui envoie les courriels : un timer arrêté est détecté par Healthchecks.io (`docs/mise-en-production-caisse.md`, section 6) |
| `ORPHAN_PAYMENTS` | warning | paiements par terminal sans vente au-delà du délai réglé (20 derniers listés) |
| `REFUNDS_EXPIRED` | warning | remboursements Interac en attente au-delà du délai, ou expirés en 24 h |
| `SESSIONS_OPEN_24H` | warning | sessions de caisse ouvertes depuis plus de 24 h |
| `CAPTURE_FAILURES` | critical | échecs de capture d'un paiement par terminal en 24 h (journal `CARD_PAYMENT_FAILED {stage: capture}`, écrit après l'annulation de la vente) |

**Alertes par courriel** (fin de chaque réconciliation, sites ayant déjà ouvert une session de caisse ; compte Stripe relu seulement en
mode réel) : contrôles `warning` et `critical`, au **contact du site** si activé (fiche Entreprise, panneau « Paiements de caisse et
surveillance (caisse) » : alertes activées, défaut non ; adresse facultative, sinon courriel de l'entreprise) et à l'**administrateur de
la plateforme** (`POS_ALERT_PLATFORM_EMAIL`, facultative). Envoi par le serveur de la plateforme (`MAILER_DSN`). Même situation : pas de
nouvel envoi avant 6 heures (une heure si elle s'aggrave) ; situation disparue : marquée résolue (table `pos_health_alert`). Journal
`HEALTH_ALERT_SENT {codes, recipients, sent, status}`. **Aucune donnée de carte.**

Liste de contrôle d'ouverture d'un magasin, clés restreintes, passage du serveur en production : `docs/mise-en-production-caisse.md`.
Test : `PosStripeLiveApiTest`.

## Grille de favoris (lot F10)

Grille de produits à toucher sur l'écran de vente (articles sans code-barres, ventes fréquentes). **Désactivée par défaut.**

### Réglages (panneau EasyAdmin « Grille de favoris (caisse) », `settings.favorites`)

`{enabled (défaut false), mode: "both" | "pinned" | "bestSellers" (défaut both), days (30, de 1 à 365), gridSize (24, de 1 à 100)}`.

### Grille : `GET /api/pos/favorites?cashRegisterId=` (caisse)

- Désactivée : **200 `{enabled: false, items: []}`**.
- Sinon `{enabled: true, mode, days, gridSize, scope: "site" | "register", cashRegisterId, items: [...]}` : épinglés d'abord, dans
  l'ordre choisi, puis les plus vendus non épinglés jusqu'à `gridSize` (mode `pinned` : épinglés seuls ; `bestSellers` : plus vendus seuls).
- **Portée** : les épinglés d'un poste **remplacent** ceux du site pour ce poste (sans fusion) ; poste sans épinglés propres ou
  `cashRegisterId` absent : ceux du site (`scope: "site"`).
- Entrée : `{rank, productId, variantId (variante épinglée, sinon null), defaultVariantId (variante à vendre sans choix : épinglée ou
  unique ; null si plusieurs), hasVariants (true : la caisse demande la variante), name, variantLabel, price (cents, règle du devis :
  prix de la variante, sinon prix effectif du produit, prix promotionnel compris ; pour un produit à plusieurs variantes, prix du produit,
  le devis fait foi), image (URL ou null), barcode, pinned}`.
- Seulement les produits vendables en caisse (`isPos`, vente activée, hors location) ; jamais les cartes-cadeaux ni les articles libres.
  Un produit épinglé devenu non vendable est écarté de la grille, son épinglage est conservé (`sellable: false` dans la liste des épinglés).
- `ETag` ; `If-None-Match` identique (même faible, `W/"…"`) → **304** sans corps, comme le pack hors ligne.

**Plus vendus** : ventes au comptoir (source caisse, type vente) **non annulées**, des `days` derniers jours **dans le fuseau du magasin**
(journée en cours comprise), quantités **nettes des retours** faits sur ces ventes, par produit, de la plus grande à la plus petite
(égalité : identifiant croissant). Une requête d'agrégat, classement gardé au plus 10 minutes et recalculé à la première lecture
qui suit une vente ou un retour (étiquette `orders` du registre `Cache/CacheTags`, 28/10/2026). Les épinglés
sont lus à chaque appel : un épinglage se voit tout de suite.

### Épinglage (responsable ; caissier : 403 `FORBIDDEN`)

- `GET /api/pos/favorites/pins?cashRegisterId=` → `{scope, cashRegisterId, inheritsSite (poste sans épinglés propres : ceux du site
  sont listés), pins: [{id, position, productId, variantId, name, variantLabel, sellable}], maxPins: 100}`.
- `PUT /api/pos/favorites/pins {cashRegisterId?: int | null, pins: [{productId, variantId?}]}` : **remplace** la liste ordonnée de la
  portée (épingler, retirer, réordonner en un appel) ; `pins: []` pour un poste : le poste reprend les épinglés du site. Réponse : comme
  le GET. Refus **422 `VALIDATION_FAILED`** sans écriture, `errors: [{path, code, message}]` avec `code` `PRODUCT_NOT_FOUND`,
  `PRODUCT_NOT_SELLABLE`, `VARIANT_MISMATCH` (variante d'un autre produit), `DUPLICATE`, `TOO_MANY` (> 100), `INVALID`.
  Journal **`FAVORITES_UPDATED {scope, cashRegisterId, before, after}`**.
- EasyAdmin, menu Caisse « Favoris de la caisse » : même table (produit, variante facultative qui fixe le produit, poste vide = site,
  position) ; journal `FAVORITES_UPDATED {source: "easyadmin", action, pin}`.
- Suppression d'un produit, d'une variante ou d'un poste : ses épinglages disparaissent (table `pos_favorite`, clés en cascade).

Index de performance sur les commandes : non créé (volume actuel faible, cache) ; à reconsidérer au-delà de quelques milliers de ventes
de caisse par mois. Test : `PosFavoritesApiTest`.

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
faite sur la clé, le site n'étant pas accessible à ce moment) et le POS présélectionne ce poste ; depuis le 30/10/2026 (S2.6) un
**caissier** n'opère en caisse que sur ce poste (403 `CASH_REGISTER_FORBIDDEN` ailleurs), un responsable peut en changer ; `null` sinon, et le POS demande le poste (`GET /api/cash-registers`). Puis le caissier se connecte : `POST /api/login {username, password, platform: "pos"}` →
`{token, refresh_token}` (voir `docs/architecture.md`, « Authentification »).
