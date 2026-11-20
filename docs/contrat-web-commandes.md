# Contrat web : commandes et rendez-vous de la boutique (lot CMD-WEB-1, 20/11/2026)

Contrat validé par le chef le 20/11/2026. Il sert l'écran « Commandes » de la gestion web (dépôt `Izzipro_next`) et l'écran de l'agent
d'Assistance. Ce lot n'a aucune migration. Les gestes (rembourser, capturer, annuler, expédier, renvoyer un courriel) sont le lot CMD-WEB.

## 1. Commandes, lecture seule

Les deux routes passent par le service de `/admin/commandes/…`, de « Ma journée » et de l'assistant du site (`OrderDeskService`). Le calcul
est donc le même partout.

### `GET /api/site/commandes/a-traiter?limit=`

`limit` va de 1 à 500 (100 par défaut). La réponse :

```json
{
  "toShip": [{ "id": 12, "reference": "REF#…", "date": "2026-11-20 10:02", "statusId": 3, "status": "Payée",
               "totalCents": 3334, "customer": "Ana Lima", "email": "ana@…", "shippedAt": null }],
  "toCapture": [], "incomplete": [], "inTransit": [],
  "counts": { "toShip": 1, "toCapture": 0, "incomplete": 0, "inTransit": 0, "total": 1 }
}
```

Elle ne contient que les commandes web (la caisse a ses propres écrans). En vue limitée, la réponse porte `readOnly: true`.

### `GET /api/site/commandes/{id}?locale=fr|en`

La fiche porte `order`, `statusId`, `statusLabel`, `currency`, `money`, `payments[]`, `labels[]`, `shipment`, `timeline[]`, `journal[]`,
`customer`, `actions{}` et `returns[]`. Les montants sont en cents. Une commande inconnue renvoie 404 `ORDER_NOT_FOUND`.

`GET /api/orders/{id}` reste la route du CLIENT.

### Ce que reçoit chaque lecteur (liste blanche, `OrderDeskView`)

| Lecteur | Ce qu'il reçoit |
|---|---|
| Administrateur du site (son compte), et le super administrateur, y compris quand il entre sur un site (c'est le même compte) | La fiche entière. |
| Agent d'Assistance, agence entrée pendant une assistance « commandes » | Vue LIMITÉE, décrite sous le tableau. |
| Directeur entré | La vue limitée, avec trois retraits de plus, décrits sous le tableau. |

Ce que retire la vue limitée :
- les champs `payments[].stripeId` et `payments[].receiptUrl` ;
- les champs `money.captured[].intentId` et `money.captured[].receiptUrl` ;
- le champ `journal[].after`.

Le reste de la vue limitée :
- la carte apparaît en « marque •••• 4 derniers chiffres » ;
- `actions` est toutes à `false` ;
- `readOnly` vaut `true`.

Ce que le directeur entré perd en plus :
- ni courriel ni adresse du client : `customer = { name, ordersCount }`, plus de `order.shippingAdress` ni de `order.userId`. Le champ
  `order.hasShippingAddress` reste ;
- ni lien d'étiquette (`labels[].url` porte l'adresse) ;
- dans « À traiter », ni `email`. `customer` vaut `null` quand le nom manque, car la vue rendait alors le courriel.

Une clé nouvelle de la fiche n'est jamais servie au lecteur limité tant qu'elle n'est pas ajoutée à la liste blanche.

## 2. Rendez-vous de la boutique

Il s'agit des prestations réservées depuis la vitrine : l'écran EasyAdmin « Réservations de Prestations ». La table `reservation` porte
aussi les entretiens de Mémoires Vivantes. Un entretien est une ligne qui a un livre, un biographe, un numéro de séance ou un chapitre :
c'est le critère du courriel, plus le chapitre. Ces routes ne servent JAMAIS ces lignes : la réponse est 404 sans dire pourquoi.

### `GET /api/site/reservations-boutique`

Paramètres : `status=pending|confirmed|cancelled`, `from=AAAA-MM-JJ`, `to=AAAA-MM-JJ`, `page=`, `limit=` (sans `page`, c'est la
première page). Les rendez-vous à venir viennent d'abord, le plus proche en tête ; viennent ensuite les rendez-vous passés, le plus
récent en tête. « À venir » se juge sur le jour du MAGASIN. La réponse est
`{ items[], total }`, avec les en-têtes `X-Total-Count`, `X-Page` et `X-Limit` (50 par défaut, 200 au plus).

Un rendez-vous a cette forme :

```json
{ "id": 7, "serviceId": "massage-60", "serviceName": "Massage 60 min", "forfaitName": null, "date": "2026-11-24", "slot": "10:00",
  "guests": 1, "clientName": "Ana Lima", "clientEmail": "ana@…", "clientPhone": "+1 …", "notes": null, "status": "pending",
  "createdAt": "2026-11-20 09:12" }
```

Le directeur entré reçoit les rendez-vous sans `clientEmail`, `clientPhone` ni `notes` : c'est la minimisation de la fiche commande.

Les erreurs sont 422 `STATUS_INVALID` et 422 `DATE_INVALID` (avec `field`).

### `GET /api/site/reservations-boutique/{id}`

Renvoie le rendez-vous. Une ligne inconnue ou un entretien MV renvoie 404 `RESERVATION_NOT_FOUND`.

### `POST /api/site/reservations-boutique/{id}/confirmer` et `POST /api/site/reservations-boutique/{id}/annuler`

La réponse est `{ reservation, changed, emailRequested }`.
- **Rejouer le même geste** : `changed: false`, sans second courriel.
- **Confirmer un rendez-vous annulé** : 409 `RESERVATION_CANCELLED`. EasyAdmin, lui, garde la reconfirmation, comme avant.
- **`emailRequested`** : le courriel au client a été DEMANDÉ. Le service d'envoi écrit ses propres échecs au journal sans les remonter : le
  serveur ne sait pas si le courriel est arrivé.
- **Paiement** : un rendez-vous de la boutique ne porte aujourd'hui aucun paiement (aucune colonne, aucune table qui le cite). Annuler
  n'a donc rien à rembourser. Si un lien de paiement apparaît, l'essai `ShopBookingApiTest::testABookingCarriesNoPayment` casse, et la
  garde 409 `RESERVATION_PAID` est alors à poser (décision du chef).
- **Un seul code** : les boutons Confirmer et Annuler d'EasyAdmin passent par le même service (`ShopBookingService::changeStatus`). La
  ligne y est verrouillée, et chaque changement est écrit au journal du site (ressource `reservation`), dans la même transaction que le
  changement. Pour le directeur entré, l'écran EasyAdmin est en lecture seule : ses boutons et son formulaire sont fermés.

## 3. `GET /api/payments`

- **Pagination** : `?page=&limit=` sont facultatifs. Sans `page`, la réponse est celle d'avant, avec en plus `X-Total-Count`. Avec `page`,
  la liste est lue en base, du plus récent au plus ancien, avec les en-têtes `X-Total-Count`, `X-Page` et `X-Limit`.
- **`orderSource=all`** (toutes les sources) : réservé à l'administrateur, et seulement avec `page`. Sinon, la réponse est 400
  `PAGE_REQUIRED`.
- **`?locale=fr|en`** : choisit la langue de `paymentMethod` et de `paymentStatus`. Sans ce paramètre, la langue de la requête s'applique,
  comme avant (l'anglais par défaut).
- **Droits inchangés** : l'administrateur voit toutes les sources ; le caissier et le gérant de caisse voient la source 2 seulement.
  L'agent d'Assistance n'y a pas accès.

## 4. Candidatures : `lienCv`

`POST /api/candidatures` accepte dans `lienCv` une adresse http ou https de 255 caractères au plus (la longueur de la colonne). Sinon, la
réponse est 422, avec la violation sur `lienCv`. Un champ vide est permis. Les anciennes lignes ne sont pas relues.

## 5. Droits

| | Admin | Agent d'Assistance | Agence entrée (assistance) | Directeur entré |
|---|---|---|---|---|
| Commandes : à traiter, fiche | tout | vue limitée | section `commandes`, vue limitée | vue sans courriel ni adresse |
| Rendez-vous : liste, fiche | oui | oui | section `clients` | oui, sans courriel, téléphone ni notes |
| Rendez-vous : confirmer, annuler | oui | oui | section `clients` en modification | non (403 `MODE_FORBIDDEN`) |
| `/api/payments` | toutes sources | non | comme avant | comme avant |

- **Agent d'Assistance** : `ROLE_SUPPORT` n'ouvre, hors du module, QUE ces six routes (`SupportScopeSubscriber::OPEN`, plus les règles
  précises de `security.yaml`). `SupportRoleMatrixTest` le vérifie sur toutes les routes de l'API. « Depuis le ticket » : le ticket porte
  `orderId`, et le web appelle `GET /api/site/commandes/{orderId}`.
- **Garde du mode** : les tables figées (`classement.json`, `entree-groupe-fermees.json`, `entree-groupe-ecritures.json`,
  `assistance-ecritures.json`) sont régénérées et relues.

## 6. Outils de l'assistant du site

- **`get_orders_todo` et `get_order`** : inchangés. La console de l'assistant est réservée à l'administrateur et fermée au directeur entré.
- **`get_shop_bookings`** (lecture) : 20 rendez-vous au plus, à venir d'abord ; jamais un entretien MV.
- **`propose_booking_status`** (`reservationId`, `decision` : `confirm` ou `cancel`) : le serveur rend un aperçu (client, prestation,
  jour, créneau, état avant et après, courriel au client). Une personne confirme ; le tout est relu sous le verrou à la confirmation. Il
  n'est jamais appliqué pour le directeur entré.
- Aucun outil sur les paiements ni sur `lienCv`.
