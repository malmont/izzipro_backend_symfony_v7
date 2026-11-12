# Messages d'erreur rendus au client (lots ERREURS, 12/11/2026)

Contrat validé par l'application (« new easymakemoney ») le 12/11/2026, sur décision du propriétaire. Constat de départ (lot ROUTEID) : deux
routes publiques de la boutique renvoyaient un message SQL brut. Inventaire : 179 blocs « catch » rendaient le message d'une exception dans
une réponse ou un message d'écran, dont 59 attrapaient largement (`Exception`, `Throwable`, `RuntimeException`).

## Règle

Une exception **imprévue** ne montre jamais son texte au client : la réponse garde **son statut HTTP et ses clés**, le texte devient neutre
(« Une erreur interne est survenue. Veuillez réessayer dans quelques instants. », celui du gestionnaire commun `ApiExceptionListener`), et
`code: "INTERNAL_ERROR"` est ajouté quand la réponse n'avait pas de code (un code existant ne change pas). Le détail (classe, message,
fichier, ligne, endroit) part au journal applicatif, niveau erreur, sous « [erreur imprévue] ». Un **refus métier** voulu garde son texte,
son code et son statut. Aucun code ni statut existant n'a changé ; les fronts qui affichent `error` ou `message` affichent le texte neutre
au lieu d'un texte technique, et peuvent traduire par le code `INTERNAL_ERROR`.

## Comment on distingue : par l'origine (`Services/Http/ClientSafeError`)

Un même « catch » large attrape nos refus métier, souvent levés sous une forme générique (`throw new \Exception('Insufficient stock for
product variant')`), et les pannes. `ClientSafeError::isIntended()` tranche :

- **texte gardé** : exception de l'application (`App\…`), où qu'elle soit créée ; exception HTTP ou générique de classe exacte `Exception`,
  `RuntimeException`, `InvalidArgumentException`, `DomainException`, `LogicException` levée **depuis notre code** (`src/`) ; et une
  `CardException` de Stripe (carte refusée : texte destiné au payeur) ;
- **texte neutre** : tout le reste. Ce qui est levé depuis une bibliothèque (Doctrine, PDO, Stripe hors carte, Symfony, transporteurs) ; les
  erreurs de PHP (`TypeError`…) ; une exception née dans une fonction ou une classe de PHP appelée par notre code
  (`new \SimpleXMLElement('…')`) ; et une exception de notre code qui **reprend dans son texte celui d'une exception imprévue qu'elle
  enveloppe** (`new \Exception('Erreur : ' . $e->getMessage(), 0, $e)`).

Limite connue, fermée par le garde-fou : `new \Exception($e->getMessage())` **sans** garder la cause en « previous » ne se reconnaît pas à
l'exécution. Il faut donc toujours passer la cause (`, 0, $e`) ou écrire un texte métier.

Usage : `$this->safeError->body($e, 'GET /api/…')` pour un corps `{ error }`, `->message($e, '…')` pour un texte, `->stripe($e, '…')` pour
une erreur de l'API Stripe.

**Stripe.** Une carte refusée (`CardException`) garde le texte de Stripe, avec son code de refus (`declineCode`, ajouté aux réponses qui la
portent). Toute autre erreur de l'API (authentification, requête invalide, limite, connexion) rend « Le service de paiement n'a pas pu
traiter la demande. Veuillez réessayer dans quelques instants. » : le message d'une erreur d'authentification peut citer un fragment de
clé, il ne sort plus.

## Garde-fou (`tests/Functional/Security/ClientErrorGuardTest.php`)

Sur le modèle de `AnonymousWriteAccessTest`, il parcourt `src/` et échoue si :

1. un « catch » large (`Exception`, `Throwable`, `RuntimeException`, `LogicException`, `Error`, `ApiErrorException`) rend `getMessage()` vers
   une sortie (réponse JSON, message d'écran, tableau, valeur de retour) sans passer par `ClientSafeError` ;
2. une exception est construite avec le message d'une autre sans la garder en « previous ».

Ses deux listes disent ce qui est admis et pourquoi (journal ou console ; reprise d'un refus rédigé ; lot à venir). Un nouvel endroit fait
échouer le test ; un endroit corrigé doit être retiré de la liste.

## Lot ERREURS-1 : gestion, caisse, boutique (12/11/2026)

Tests : `ClientSafeErrorTest` (la règle, dont « exception de src qui enveloppe une exception PDO »), `ClientErrorMessagesTest` (par route, le
service appelé est remplacé par un double qui lève une panne portant un texte témoin), `StripeGatewayErrorsTest`, et
`PosReceiptApiTest::testAnUnexpectedFailureDuringASaleOrACancellationIsNotShownToTheCashier`.

| Endroit | Avant | Maintenant |
|---|---|---|
| `GET /api/productsid/{id}`, `GET /api/products/by-slug/{slug}` (publiques) | 404 avec le message de toute exception, SQL compris | 404 ; « Product not found… » gardé ; panne : texte neutre |
| `POST /api/commandes/{id}/products`, `DELETE /api/products/{id}` | 500 avec le message | 500, texte neutre |
| `POST /api/shipping/buy` | 500 « DEBUG ERROR: » suivi du message | 500, texte neutre |
| `GET /api/booking/check/{productId}` (publique) | message rendu, **statut pris sur le code de l'exception** | refus métier inchangé ; panne : 500, texte neutre |
| `GET /api/booking/calendar/{productId}` (publique) | 400 avec le message, dont celui de PHP sur une date mal formée | 400 ; date mal formée : « Format de date invalide (attendu : YYYY-MM-DD) » ; panne : texte neutre |
| `POST` et `PUT /api/adresses` | 400 `ADDRESS_UNDELIVERABLE` avec le message du service d'adresses | « Adresse invalide : … » gardé (refus nommé `AddressUndeliverableException`) ; panne du service : texte neutre |
| Vente (`POST /api/order/create-multi-payment`) et annulation (`POST /api/order/cancel/{id}`) | 400 avec le message | refus métier inchangés ; panne : texte neutre, rien d'écrit |
| Devis et achat d'étiquette de l'administration (`AdminLabelPurchaseService`) | message du transporteur dans la réponse et dans le relevé du solde | détail au journal ; « L'achat a échoué chez le transporteur. Rien n'a été débité. Réessayez dans quelques instants. » |
| Emballage (`PackagingService`) | texte de la bibliothèque recopié dans « L'article est trop grand… » | texte seul, cause gardée en « previous » |
| Lot IA de fiches produit (`ProductAiBulkService`) | note d'un article en échec = message de l'exception | refus rédigé gardé ; panne : texte neutre |
| Écrans EasyAdmin : image et IA d'un article, connexion Stripe (2) | message de l'exception dans le bandeau | texte neutre pour une panne ; jamais le message d'une erreur Stripe |
| Passerelles Stripe : compte de la caisse, abonnements, terminal (code non reconnu), paiement web | « Stripe : » suivi du message brut | carte refusée : texte de Stripe et `declineCode` ; sinon texte neutre |

Hors lot, car le message part dans un journal ou une console et pas au client : webhook d'abonnement, suppression de compte (2),
changement de courriel, réconciliation des terminaux, notification push de la gestion (colonne `error` non rendue par l'API), transition
du module de gestion (commande de console), file d'invalidation du cache.

Non testés de bout en bout, couverts par le garde-fou et la relecture : les quatre écrans EasyAdmin, le devis et l'achat d'étiquette de
l'administration, le paiement web (`StripePaymentService`), le lot IA, l'emballage.

### Effet de bord accepté

Le devis et l'achat d'étiquette de l'administration ne citent plus le motif donné par le transporteur (« solde insuffisant chez
EasyPost »…) : il est au journal. Si l'administrateur en a besoin à l'écran, il faudra traduire les codes du transporteur en textes
rédigés (lot à part).

## Statuts trompeurs, gardés dans ce lot (décision à part)

Le statut n'a pas été touché, comme convenu. Ces réponses disent autre chose qu'une panne :

- `GET /api/productsid/{id}`, `GET /api/products/by-slug/{slug}` : **404** sur toute exception, donc aussi sur une panne de base (l'article
  semble ne pas exister ; un cache peut garder ce 404) ;
- vente et annulation en caisse : **400** sur une panne de base, comme pour un corps refusé ;
- `GET /api/booking/calendar/{productId}` : **400** sur une panne ;
- `POST` et `PUT /api/adresses` : **400 `ADDRESS_UNDELIVERABLE`** quand le service d'adresses est en panne (l'adresse semble refusée) ;
- achat d'étiquette de l'administration : **409 `PURCHASE_FAILED`** sur une panne du transporteur.

Seul changement de statut du lot : `GET /api/booking/check/{productId}` prenait pour statut le code de l'exception, quel qu'il soit (un
code SQL en chaîne faisait échouer la réponse elle-même) ; une panne répond maintenant 500.

## Pour la suite

- **ERREURS-2** (site, landing, plateforme, EasyAdmin, divers) : les entrées « ERREURS-2 » des listes du garde-fou, et les « catch » de
  `InvalidArgumentException` / `DomainException` de ces dossiers (21 blocs : plateforme, « Mon site », assistant, réservations), à relire
  pour celles qu'une bibliothèque peut lever. Dans les dossiers d'ERREURS-1, aucun bloc n'attrape ces deux classes seules.
- **ERREURS-3** (Boussole ESG, Mémoires Vivantes) : dossiers exclus du garde-fou, en attente d'attribution.
- **LEX** : refus métier écrits en anglais dans les dossiers d'ERREURS-1, laissés tels quels : « Order not found », « Order cannot be
  canceled after it has been shipped or delivered », « Cancel status not found in database », « Insufficient stock for product variant »,
  « Tax calculation failed », « Payment method not found », « Payment type not found », « Status payment not found », « Transaction type
  not found », « Unknown transaction type », « Product not found for ID: … », « Product not found for slug: … », « User not found ».
