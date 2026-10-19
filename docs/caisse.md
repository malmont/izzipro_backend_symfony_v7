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
| Types `TransactionType` | `transaction_type` | 1 vente, 2 remboursement, 3 dépôt, 4 ouverture, 5 fermeture, 6 retrait, 7 retrait du fond de caisse, 8 ajout au fond de caisse (insérés par la migration dans toutes les bases). |

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
| `POST /api/caisse/close` | `cashRegisterId?` | 200 `{message, caisse_id, cashRegisterId}` ; `closedAt` posé ; pas de session ouverte → 400 `CASH_REGISTER_CLOSED` |
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
  (articles au prix du serveur + livraison + taxes, tolérance 1 cent), sinon 400 et rien n'est créé. Seule la part en
  espèces (`type` 2) entre en caisse.
- Total à encaisser : `POST /api/cart/quote` (public) avec les mêmes `items` **et le `cashRegisterId`** : sans
  `shippingAddress`, un devis qui porte `cashRegisterId` est taxé à l'adresse de la fiche entreprise, comme la vente
  (19/10/2026) ; `total` en cents. Sans `cashRegisterId` (boutique web), un devis sans adresse reste hors taxes
  (`taxStatus: address_required`). Une `shippingAddress` fournie l'emporte toujours.
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
  taxNumber}` (fiche entreprise du site ; `taxNumber` = numéro de TVA, sinon SIRET). E-mails de confirmation envoyés
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
(espèces) : le montant sort de la caisse (solde insuffisant → 400 `INSUFFICIENT_FUNDS`). L'annulation d'une commande
web ne change pas.

## Mécanique interne

`HandleCaisseTransactionUseCase` reçoit la session visée, **verrouille sa ligne** (`SELECT … FOR UPDATE`) pendant la
mise à jour de `amountTotal`, puis écrit la `TransactionCaisse` ; il s'exécute dans la transaction SQL de l'appelant
(vente, remboursement) ou dans la sienne. Les refus sont des `CashRegisterException` (`errorCode`, `status`,
`errors`), rendues `{error, message, errors?}` par les contrôleurs.

## Connexion du POS

Au premier lancement, le POS s'active avec une clé générée par l'administrateur du site (`POST /api/app/activate`,
voir `docs/architecture.md`, « Clé d'activation des applications mobiles ») ou, à défaut, par
`GET /api/tenant/check-mobile` ; il garde l'hôte complet reçu (`tenant.host`) et l'envoie dans `X-Tenant-Host` sur
chaque requête. Une clé POS peut être **rattachée à un poste de caisse** (`cashRegisterId` à la création ou par
`PUT /api/app-keys/{id}`, écran « Clés des applications ») : l'activation rend alors `cashRegister: {id, name}` (copie
faite sur la clé, le site n'étant pas accessible à ce moment) et le POS présélectionne ce poste, le caissier pouvant en
changer ; `null` sinon, et le POS demande le poste (`GET /api/cash-registers`). Puis le caissier se connecte : `POST /api/login {username, password, platform: "pos"}` →
`{token, refresh_token}` (voir `docs/architecture.md`, « Authentification »).
