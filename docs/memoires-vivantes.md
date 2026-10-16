# Module Mémoires Vivantes

Livres de souvenirs écrits à partir des réponses d'un narrateur (ou de contributeurs), rédigés chapitre par chapitre
par l'IA, puis mis en page (PDF), payés et imprimés (Lulu). Contexte : `docs/architecture.md`.
Code : `src/MemoiresVivantes/` (module autonome). Côté frontend : `src/components/MemoiresVivantes/README.md`
(dépôt `Izzipro_next`).

## Endpoints

Tous sous `/api/memoires/` : liste complète et accès dans `docs/endpoints.md`, section « Mémoires Vivantes ».

- `access_control` laisse `/api/memoires/` **public**, pour que les liens de partage fonctionnent sans connexion, sauf :
  `/api/memoires/admin/` (`ROLE_ADMIN`) ; impression (`…/print/…`), `pdf/generate`, `payment-link`,
  `send-payment-link` et `/api/memoires/mobile/` (connexion obligatoire, `IS_AUTHENTICATED_FULLY`).
- **Le contrôle est dans chaque action** :
  - livre : `Security/BookAccessGuard.php`, avec `canView` (lien de chapitre signé, clé `APP_SECRET`, ou voter
    `BOOK_VIEW`) et `canManage` (connecté, propriétaire ou admin : `BOOK_EDIT`) ;
  - chapitre : `ChapterVoter` et la vérification de signature de `ChapterController` ;
  - voters : `Security/BookVoter.php`, `Security/ChapterVoter.php`.
- Toute nouvelle action doit en utiliser un : rien ne la protège sinon. Lecture : `canView` ; tout ce qui coûte,
  engage ou modifie : `canManage`.
- **Invités (lien signé)** : ils modifient `answers` et `contributorAnswers` ; le texte final (`contentFinal`) aussi,
  mais seulement par le lien de partage du chapitre (relecture, « Partager l'édition » du frontend), jamais par le
  lien personnel d'un contributeur ; titre, thème et position restent au propriétaire ; ils ne voient ni l'e-mail du propriétaire ni le lien de paiement (`BookOutputDto`,
  `$withPrivateDetails`). Un contributeur retiré du livre perd son lien (`BookAccessGuard::contributorBelongsTo`).
- **Témoignages** : un enregistrement ne peut pas effacer le témoignage d'un contributeur du livre absent de l'envoi
  (`UpdateChapterUseCase::keepMissingContributors`) ; pour retirer un témoignage, retirer le contributeur.
- **Réponses des contributeurs filtrées par rôle** : `contributorAnswers` ne contient, pour chaque contributeur, que
  les réponses aux questions du rôle demandé (`?role=`, `?contributorId=`, sinon rôle par défaut du type). Un `PUT`
  ne supprime donc pas les réponses qu'il ne mentionne pas (`UpdateChapterUseCase::keepUnmentionedAnswers`) ; pour
  effacer une réponse, l'envoyer avec un texte vide.
- Le livre renvoie `typeInfo` : son type, même désactivé, dans la forme d'un élément de `GET /book-types`, plus
  `isActive`. Rôle d'un contributeur : vérifié pour les types configurables (422, `allowedRoles`), libre pour les
  quatre types d'origine.
- Identifiant mal formé : 404 (`EventListener/InvalidIdentifierListener`) ; champs obligatoires absents : 422.

| Groupe | Routes | Contrôleur |
|---|---|---|
| Livres | `GET, POST /books`, `GET, PUT, DELETE /books/{id}`, couverture | `BookController` |
| Application mobile Living Memory | `POST /mobile/books`, `GET /mobile/quotas`, `GET /mobile/pricing`, `POST /mobile/chapters/{id}/unlock`, `GET /mobile/purchases/unassigned` (connexion obligatoire ; voir « Application mobile Living Memory », « Quotas des appels IA », « Rédaction payante et livre imprimé ») | `MobileBookController`, `MobileQuotaController`, `MobilePurchaseController` |
| Webhook RevenueCat | `POST /api/webhooks/revenuecat` (public, en-tête Authorization = `REVENUECAT_WEBHOOK_SECRET`) | `RevenueCatWebhookController` |
| Chapitres | `/books/{id}/chapters`, `/chapters/{id}` (+ `status`, `generate`, `reset`, `audio`, `improve-answer`, `share-link`, `contributor-links`) | `ChapterController` |
| Photos | `/chapters/{id}/photos`, `/photos/{id}`, mise en page | `ChapterController`, `ChapterPhotoController` |
| Contributeurs | `/books/{id}/contributors`, `/contributors/{id}` (+ `approve`) | `ContributorController` |
| PDF | `/books/{id}/pdf/preview-interior`, `preview-cover`, `generate` | `BookPdfController` |
| Paiement du livre | `/books/{id}/payment-link`, `send-payment-link`, `payment-status` | `BookPaymentApiController` (Stripe Checkout du compte connecté du tenant) |
| Impression | `/books/{id}/print/estimate`, `print/payment-intent`, `print/order`, `print/orders` ; `POST /api/webhooks/lulu` | `BookPrintApiController`, `LuluWebhookController` |
| Types de livre (lecture) | `GET /book-types`, `/book-types/{code}`, `GET /questions`, `GET /ai-models` | `BookTypeController`, `QuestionController` |
| Polices des livres | `GET /book-fonts` | `BookFontController` |
| Administration | `/admin/book-types…`, `/admin/book-type-chapters…`, `/admin/book-type-roles…`, `/admin/questions…`, `/admin/users…`, `/admin/ai-quotas` (+ `/usage`), `/admin/mobile-users` (+ `/{userId}`), `/admin/pricing`, `/admin/payments`, `/admin/revenue` | `Controller/Admin/` (`#[IsGranted('ROLE_ADMIN')]`) |
| Activation de compte | `POST /auth/activate` | `AccountActivationController` ; l'invitation (`POST /admin/users` sans mot de passe) envoie un e-mail avec le lien (`emails/memoires_account_invitation.html.twig`) |

Liens envoyés aux utilisateurs (activation, partage de chapitre, retour de paiement) : `Services/FrontendUrlResolver`
(origine de la requête, sinon domaine du tenant, sinon `<code>.<FRONTEND_BASE_DOMAIN>`). `MEMOIRES_FRONTEND_URL` n'est
plus qu'un dernier repli : chaque site a son frontend.

## Données

| Table | Entité | Contenu |
|---|---|---|
| `mv_book` | `Book` | livre (propriétaire, type, origine `channel` : `web` par défaut, `mobile` pour un livre créé par l'application Living Memory, fixée à la création ; statut de paiement, nombre de séances d'entretien `session_count` / `sessionCount` de 1 à 12, adresse du client `client_address` : itinéraire Google Maps pour le biographe, `clientAddressMapsUrl` dans l'API, jamais montrée aux invités) ; identifiant UUID |
| `mv_chapter` | `Chapter` | réponses, texte généré, `generation_status` |
| `mv_chapter_photo` | `ChapterPhoto` | photos d'un chapitre |
| `mv_contributor` | `Contributor` | contributeurs (livres collectifs) |
| `mv_book_type`, `mv_book_type_chapter`, `mv_book_type_role` | `BookType…` | types de livre configurables |
| `mv_question` | `MemoireQuestion` | questions des chapitres |
| `mv_book_print_order` | `BookPrintOrder` | commandes d'impression Lulu ; commandes de l'application : montant facturé (`charged_amount`, `charged_currency`), `payment_status` (`pending` / `paid`), `stripe_payment_intent_id`, statut `pending_payment` avant paiement |
| `mv_ai_quota_setting` | `AiQuotaSetting` | quotas IA du site (une ligne ; sans ligne : valeurs par défaut), voir « Quotas des appels IA » ; `regenerations_per_day` depuis le lot « rédaction payante » |
| `mv_chapter_payment` | `ChapterPayment` | registre des achats de rédaction de chapitre (RevenueCat : store, transaction, montant, devise, statut `paid` / `refunded`, `environment` sandbox / production, chapitre affecté) ; le chapitre porte en copie le paiement qui le débloque (`mv_chapter.paid_*`, `hidden_after_refund`) |
| `mv_pricing_setting` | `PricingSetting` | tarifs du site : grille du livre imprimé par devise (JSON), produit « rédaction d'un chapitre » des stores |
| `mv_ai_usage` | `AiUsage` | appels IA aboutis par utilisateur : `transcription`, `improvement` (soumis aux quotas) et `generation` (rédaction aboutie d'un chapitre d'un livre soumis aux quotas, enregistrée par `GenerateChapterHandler` : cumul seulement, pas de quota) |
| `user` (table commune) | `App\Entity\User` | depuis le 16/10/2026 : `signup_channel` (`web_invite` invitation par un biographe, `web_register` inscription web, `mobile` application Living Memory ; null avant), `created_at` (null avant), `last_login_at` (`/api/login`, `/api/otp-verify`, `/api/token/refresh`) ; `scripts/migrate_all_v2_user_signup.sh` |

Fichiers : `var/storage/public_bucket/uploads/memoires/` (photos, PDF), `…/uploads/audio/` (enregistrements).

## Types de livre

- Données en base (2 familles : `direct`, 1 ou 2 narrateurs ; `collectif`, contributeurs avec rôles). Code :
  `BookType/` (`BookTypeAdminService`, `BookTypeResolver`, `DatabasePromptEngine`, `LegacyBookTypeCatalog`).
- Types historiques (`individuel`, `couple`, `famille`, `hommage`) : prompts dans `Services/AnthropicService.php`
  (`promptSource = code`) ; nouveaux types : prompts en base (`promptSource = database`).
- **Chapitre ajouté dans la console à un type historique** : le code ne le connaît pas, il est donc rédigé avec les
  consignes en base (consigne du type + consigne du chapitre), comme pour un type configurable
  (`BookTypeResolver::findPromptTypeForChapter`). Les chapitres d'origine gardent les consignes du code. Le bouton
  « Tester » de la console utilise toujours les consignes en base : pour un chapitre d'origine d'un type historique,
  il ne reflète pas la rédaction réelle.
- Les réponses sont rattachées aux questions **par leur texte** : ne jamais renommer ni supprimer une question hors de
  `BookTypeAdminService` / `EventListener/MemoireQuestionAnswerSyncListener`.
- Nouveaux tenants : les types et questions par défaut viennent de la base modèle `gmasuite`, publiés depuis
  `db_memoiresvivantes` par `app:memoires:publish-template` (refuse une cible qui contient des livres).

## Application mobile Living Memory

Application Flutter grand public (site `memoiresvivantes`, activation par clé `livingmemory`, connexion `platform`
`mobile`, `ROLE_USER_INTERNET` : `docs/architecture.md`, « Authentification »). Elle lit et écrit par les routes
existantes du module ; une seule route lui est propre (16/10/2026) :

- `POST /api/memoires/mobile/books` (`MobileBookController`, `UseCase/CreateMobileBookUseCase`) : même corps et même
  réponse (201, livre complet) que `POST /books`, mais le livre est créé avec **`channel = mobile`** et un type demandé
  mais inconnu ou désactivé répond **422 `{"error": "TYPE_INVALID", "message", "requestedType"}`** (`message` est
  destiné aux utilisateurs, sans route technique ; le web crée alors un livre
  individuel ; sans type, la route mobile crée aussi un livre individuel).
- **`channel`** (`mv_book.channel`, champ `channel` de `BookOutputDto`) : `web` pour tous les livres existants et pour
  `POST /books`, `mobile` pour un livre de l'application. Fixé à la création, jamais modifiable (ignoré par `PUT`,
  `Book::setChannel` n'est appelé que par `CreateBookUseCase`). Il distingue les livres dont la rédaction d'un chapitre
  sera payante (achat intégré, un chapitre à la fois, à venir : 402 `CHAPTER_NOT_PAID`) ; la rédaction des livres web
  reste libre. **Aujourd'hui rien n'est bloqué** pour les livres mobile.
- Les livres mobile ne sont traités à part nulle part ailleurs : le mode administration du web les liste
  (`GET /books?scope=all`, `?userId=`), les ouvre, les modifie, les supprime, en rédige les chapitres et les PDF comme
  les autres (`BookVoter` accorde tout à `ROLE_ADMIN`). Le champ `channel` permet au web d'afficher l'origine.
- PDF : l'auteur d'un livre mobile n'est jamais le nom par défaut du site (voir « Mise en page du livre »).
- Transcriptions et améliorations : quotas par utilisateur et par jour, `GET /mobile/quotas` (voir « Quotas des appels IA »).
  Chaque livre rendu porte **`aiQuotaLimited`** (16/10/2026) : vrai quand les appels IA de ce livre sont soumis aux
  quotas pour l'utilisateur courant (livre mobile, ou livre web si le site applique les quotas au web, et
  utilisateur non administrateur) ; le frontend affiche alors le reste du jour près des boutons.
- Tests : `MobileBookChannelTest`, `AiQuotaTest`.

## Quotas des appels IA

`POST /chapters/{id}/audio` (Whisper) et `POST /chapters/{id}/improve-answer` (Anthropic) coûtent à chaque appel.
Depuis le 16/10/2026, chaque site a des quotas, réglables par l'administrateur sans nouvelle version du code
(`Services/AiQuotaService`, `Entity/AiQuotaSetting`, `Entity/AiUsage`, `UseCase/Quota/`).

- **Réglages** (une ligne `mv_ai_quota_setting` par site, valeurs par défaut sans ligne) : transcriptions par
  utilisateur et par jour (40), améliorations par utilisateur et par jour (60), reprises de rédaction par chapitre et
  par jour (3, voir « Rédaction payante »), durée maximale d'un enregistrement
  en secondes (300, entre 30 et 1 800), application aux livres web (non), fuseau horaire du site (`America/Martinique` :
  aucun site ne porte de fuseau ailleurs). **0 = illimité.** Le jour va de minuit à minuit dans ce fuseau.
- **Qui est limité** (`AiQuotaService::isLimited`) : le propriétaire connecté d'un livre `channel = mobile` ; d'un
  livre `web` seulement si « application aux livres web » est activé. Jamais un administrateur (même sur le livre d'un
  autre), jamais un proche invité venu par un lien (sa règle reste : une amélioration par question).
- **Contrôles, avant l'appel IA** :
  - enregistrement trop long : 400 `{"error": "AUDIO_TOO_LONG", "message", "maxSeconds"}`. La durée est lue dans
    l'en-tête du fichier (`Services/AudioDurationProbe` : WAV, MP4/M4A, OGG, MP3, WebM quand il porte une durée) et
    comparée au champ facultatif `durationSeconds` du formulaire (la plus grande des deux compte) : un WebM enregistré
    en direct par un navigateur n'a souvent pas de durée dans son en-tête, les clients envoient donc `durationSeconds` ;
  - quota du jour atteint : 429 `{"error": "QUOTA_EXCEEDED", "message", "quota": {"kind": "transcription" |
    "improvement", "limit", "used", "resetsAt"}}` + en-tête `Retry-After` (secondes jusqu'à la remise à zéro).
- **Seuls les appels aboutis comptent** (`AiQuotaService::record`) : un 422 `noSpeech`, un 503 de l'IA ou une
  erreur ne sont pas décomptés.
- **Lecture par l'application** : `GET /api/memoires/mobile/quotas` (connexion obligatoire) →
  `{"transcription": {"limit", "used", "remaining", "resetsAt"}, "improvement": {…}, "maxAudioSeconds"}`
  (`limit` null = illimité). Le web peut l'appeler aussi.
- **Réglage par l'administrateur** : écran EasyAdmin « Quotas IA Mémoires Vivantes »
  (`Controller/Admin/Memoires/AiQuotaPageController`, réglages + consommation d'un jour par utilisateur) ; API
  `ROLE_ADMIN` (`Controller/Admin/AiQuotaAdminController`) : `GET`/`PUT /api/memoires/admin/ai-quotas`
  (`{transcriptionsPerDay, improvementsPerDay, maxAudioSeconds, applyToWebBooks, timezone}`, champs facultatifs au
  `PUT`, 422 `VALIDATION_FAILED` avec `errors[{path, message}]`), `GET /api/memoires/admin/ai-quotas/usage?date=AAAA-MM-JJ`
  → `{date, timezone, from, to, users: [{userId, email, name, transcriptions, improvements}], limits}`.
- Schéma : `migrations/Version20261016120000.php`, `scripts/migrate_all_v2_mv_ai_quotas.sh`. Tests : `AiQuotaTest`
  (Whisper simulé : `tests/Fake/FakeOpenAiService.php`).

## Rédaction payante et livre imprimé (application mobile)

Lot du 16/10/2026, livres `channel = mobile` seulement : les livres web ne sont jamais verrouillés ni facturés, et un
administrateur n'est jamais soumis au paiement ni aux reprises. Schéma : `migrations/Version20261016160000.php`,
`scripts/migrate_all_v2_mv_paid_chapters.sh`. Tests : `PaidChapterTest` (RevenueCat simulé :
`tests/Fake/FakeRevenueCatClient.php` ; paiement Stripe simulé : `FakeCheckoutPaymentGateway` ; Lulu sans clé =
simulation).

**Rédaction d'un chapitre** (`Services/ChapterPaymentService`, `Entity/ChapterPayment`, `UseCase/Payment/`).
- Un achat consommable dans les stores (produit `lm_chapter_writing`, prix fixé par pays dans les stores, 4,99 € en
  France) débloque **un chapitre, définitivement**, reprises comprises. Identifiant RevenueCat de l'utilisateur :
  `"<code du site>:<userId>"`. Pas de chapitre offert.
- `POST /chapters/{id}/generate` : chapitre d'un livre mobile non payé → **402 `{"error": "CHAPTER_NOT_PAID",
  "message", "product"}`** pour le propriétaire. Un chapitre créé ou modifié avec des réponses n'est **jamais rédigé
  automatiquement** tant qu'il n'est pas payé (`ChapterGenerationService::isWritingLocked`). Chapitre déjà rédigé :
  les reprises sont bornées par chapitre et par jour (`regenerationsPerDay`, défaut 3, 0 = illimité) pour les livres
  soumis aux quotas → 429 `QUOTA_EXCEEDED` avec `quota.kind = "regeneration"` ; comptées sur les lignes `generation`
  de `mv_ai_usage` (la première rédaction n'est pas une reprise). L'achat n'est consommé que si la rédaction aboutit.
- `POST /api/memoires/mobile/chapters/{id}/unlock {"transactionId", "store": "app_store" | "play_store"}` → 200 le
  chapitre complet (`paid` renseigné) ; idempotent (même transaction, même chapitre) ; 404 `PURCHASE_NOT_FOUND`
  (inconnue de RevenueCat, réessayer), 409 `PURCHASE_ALREADY_USED` (autre chapitre), 409 `CHAPTER_ALREADY_PAID`
  (la transaction reste disponible), 409 `PURCHASE_REFUNDED`, 422 `WRONG_PRODUCT`, 422 `BOOK_NOT_MOBILE`. Le backend
  vérifie la transaction auprès de RevenueCat (clé secrète `REVENUECAT_SECRET_KEY`) si elle n'est pas déjà connue.
- `GET /api/memoires/mobile/purchases/unassigned` → `{purchases: [{transactionId, store, product, amount, currency,
  purchasedAt}]}` : achats réglés pas encore affectés (après synchronisation RevenueCat ; sert au rétablissement des achats).
- **Webhook** `POST /api/webhooks/revenuecat` (en-tête Authorization = `REVENUECAT_WEBHOOK_SECRET`, 503 tant qu'il
  n'est pas configuré, 401 sinon) : `INITIAL_PURCHASE` / `NON_RENEWING_PURCHASE` → achat enregistré, non affecté ;
  `CANCELLATION` (remboursement d'un consommable, toujours total) → ligne `refunded` (`refundedAt`, `refundedAmount`),
  chapitre repassé **non payé, texte masqué** (`hiddenAfterRefund`, texte conservé en base, `contentFinal` rendu
  null, exclu du PDF), rétabli par un nouvel achat. Rejouable sans effet. Jamais de remboursement déclenché par nous.
- Chaque chapitre rendu (`ChapterOutputDto`) porte `paid` (`{amount, currency, paidAt, paymentRef}` ou null),
  `writingLocked` (vrai = 402 attendu ; toujours faux pour un administrateur, contexte posé par
  `EventListener/ChapterViewerContextSubscriber`) et `hiddenAfterRefund` ; livre web : `null`, `false`, `false`.
- PDF : un livre mobile n'inclut que ses chapitres payés et non remboursés (`BookPdfGeneratorService::isPrintable`) ;
  sans aucun chapitre payé et rédigé, `pdf/preview-interior` répond 422 `BOOK_EMPTY`.

**Livre imprimé** (`Services/PricingService`, `Services/PrintPaymentService`, `Entity/PricingSetting`).
- Grille **par devise, réglable par site** : premier exemplaire d'un livre, exemplaires suivants du même livre,
  livraison comprise. Défaut : EUR 200 / 100, CAD 300 / 150. Devises autorisées (liste fermée, Stripe) : `eur`,
  `cad`, `usd`, `chf`, `gbp`. La devise suit le pays du store de l'utilisateur ; un livre garde la devise de son
  premier exemplaire facturé. Ne s'applique qu'aux commandes passées **depuis l'application par le propriétaire d'un
  livre mobile** (`PricingService::paymentRequired`) ; un biographe ou un administrateur commande comme avant (coût
  Lulu, pas de paiement).
- `GET /api/memoires/mobile/pricing` → `{currencies, defaultCurrency, print: {<devise>: {firstCopy, nextCopies}},
  chapterProduct}`. `GET`/`PUT /api/memoires/admin/pricing` (ROLE_ADMIN) → `{print, chapterProduct, defaultCurrency,
  allowedCurrencies, isDefault, updatedAt}` ; `PUT {"print": {…}}` remplace toute la grille (une devise absente est
  retirée, au moins une devise, montants décimaux ≥ 0 à deux décimales), `chapterProduct` ignoré ; 422
  `VALIDATION_FAILED` avec `errors[].path` = `print`, `print.<devise>`, `print.<devise>.<champ>`.
- `GET /books/{id}/print/estimate?quantity=n&currency=eur` (nouveau, sans adresse) → `{quantity, currency, lines:
  [{copy, label, quantity, amount}], total, alreadyPrinted, paymentRequired}` ; 422 `CURRENCY_NOT_SUPPORTED`. Le
  `POST …/print/estimate` du web (avec adresse) garde sa forme et reçoit en plus `billing`, `lines`, `total`,
  `alreadyPrinted`, `paymentRequired` (`currency` reste celle de l'estimation Lulu).
- Paiement (propriétaire d'un livre mobile) : `POST …/print/payment-intent {quantity, currency, recipient}` crée une
  commande `pending_payment` (montant de la grille, `payment_status = pending`) et une intention Stripe (passerelle du
  panier, capture manuelle) → `{clientSecret, amount, currency, paymentIntentId, order_id, paymentRequired: true}` ;
  `POST …/print/order {paymentIntentId, recipient…}` exige l'intention autorisée ou réglée (402 `PRINT_NOT_PAID`
  sinon, 409 `PRINT_ALREADY_ORDERED` si déjà convertie), complète la commande (PDF, Lulu) puis capture le paiement
  (`payment_status = paid`). **Plus de commande Lulu avant paiement pour l'application** ; le flux web est inchangé.
- Console d'administration : `payments[]` kind `chapter` (statut `paid` / `refunded`, `refundedAt`, `refundedAmount`,
  `paymentRef`, `store`, `environment`) et `print` (montant facturé, `pending` / `paid` / `refunded`, `paymentRef` =
  intention Stripe), `payments.totals` par devise (`totalAmount` = repli, devise par défaut du site),
  `books.chaptersPaid`, `chapters[].paid` et `hiddenAfterRefund`.
- **Remboursement d'une impression fait dans Stripe** : le webhook Stripe existant (`POST /api/stripe/webhook`,
  événement `charge.refunded`, métadonnées `memoires_print` et `tenant_code` posées sur l'intention) passe la
  commande en `payment_status = refunded` avec `refunded_at` et `refunded_amount`. Le livre reste imprimé : rien
  d'autre n'est fait. L'événement `charge.refunded` doit être activé sur le point de terminaison Stripe du site.

**Registre des paiements** (`Controller/Admin/PaymentsAdminController`, `Services/PaymentLedgerService`,
`Repository/PaymentLedgerRepository` : une lecture SQL réunissant les trois sources ; lecture seule, aucune action).
- `GET /api/memoires/admin/payments?from=AAAA-MM-JJ&to=AAAA-MM-JJ&kind=chapter,print,book&status=paid,pending,refunded
  &store=app_store,play_store,stripe&channel=web,mobile&search=&page=1&perPage=50` (ROLE_ADMIN). Défaut : 30 derniers
  jours (dates du site, minuit à minuit dans son fuseau), tous statuts, les trois kinds (les livres payés par lien
  Stripe font une ligne par livre, datée de `paid_at` : `mv_book` ne garde que l'état courant du paiement, pas
  d'historique de liens). `channel` filtre sur l'origine du livre ; `google_play` est accepté comme alias de
  `play_store`. Tri : `paidAt` décroissant. → `{from, to, timezone, page, perPage, total, totals: [{currency, paid, refunded, pending, net}]
  (par devise, jamais additionnées ; `paid` inclut les montants ensuite remboursés, `net = paid − refunded`),
  counts: {paid, pending, refunded}, payments: [{id, kind, status, amount, currency, paidAt, refundedAt,
  refundedAmount, store, paymentRef, environment, user: {userId, email, name}, book: {id, title, channel} | null,
  chapter: {id, title} | null, print: {quantity, orderId, luluStatus} | null}]}`. 422 `VALIDATION_FAILED` sur un
  paramètre invalide. Les totaux portent sur toute la fenêtre filtrée, pas seulement la page.
- Export : `?format=csv` ou `Accept: text/csv` → toutes les lignes de la fenêtre (500 au plus), séparateur `;`,
  UTF-8 avec BOM, montants en unités avec un point, `Content-Disposition: attachment`.
- Données complètes à partir du 16/10/2026 (création des tables). Tests : `AdminPaymentsTest`.

**Vue d'ensemble par livre** (`Controller/Admin/RevenueAdminController`, `Services/RevenueOverviewService`), lecture seule.
- `GET /api/memoires/admin/revenue?from=&to=&channel=web,mobile&search=&page=1&perPage=50` (ROLE_ADMIN) : **tous
  les livres**, web et mobile, même sans paiement. La période (dates du site) s'applique aux dates de paiement
  (livre : `paid_at` ; chapitre : affectation, sinon achat ; impression : commande) ; sans période : tout. →
  `{from, to, timezone, summary: {books: {total, web, mobile}, byChannel: [{channel, totals: [{currency, book,
  chapters, prints, refunded, net}], counts: {booksPaid, chaptersPaid, printsPaid, refunds}}], totals: [...]}, page,
  perPage, total, books: [{id, title, type, channel, createdAt, owner: {userId, email, name}, biographer: null,
  chapters: {total, written, paid}, bookPayment: {status, amount, currency, paidAt} | null, chaptersRevenue:
  [{currency, paid, refunded}], prints: {orders, copies, revenue: [{currency, paid, pending, refunded}]}, net:
  [{currency, amount}]}]}`. Montants bruts encaissés dans `book`, `chapters`, `prints` (remboursés compris),
  `refunded` à part, `net = book + chapters + prints − refunded`, par devise. Une commande d'impression web sans
  paiement compte dans `orders` et `copies`, pas dans `revenue`. `biographer` est toujours null : aucun lien
  biographe-livre n'existe en base (le propriétaire est le client). Tri : net décroissant (devise par défaut du
  site) puis création décroissante. 422 `VALIDATION_FAILED` sur un paramètre invalide.

## Console d'administration : populations et utilisateurs mobile

Rien en base ne rattache un compte à une population : elle est **calculée** (`Services/UserPopulationResolver`),
avec une priorité : `team` (ROLE_ADMIN : biographes) ; `mobile` (inscrit depuis l'application, `signup_channel =
mobile`, **ou** propriétaire d'au moins un livre `channel = mobile`) ; `web_client` (tous les autres : clients dont
un biographe fait le livre, collaborateurs invités, comptes de la boutique : mêmes rôles, indiscernables). Un client
web qui crée un livre dans l'application devient donc « mobile ». L'application Living Memory envoie
`{"app": "livingmemory"}` dans le corps de `POST /api/register` (en plus de `platform: "mobile"`) : c'est ce qui
marque l'origine mobile, car `platform` vaut déjà `mobile` par défaut quand il est absent et ne distingue rien.
Lecture métier : un compte web est en général un biographe de l'équipe ou un client suivi par lui ; un utilisateur
mobile fait son propre livre, avec des quotas et, à venir, des chapitres payants (le web affiche `web_client` comme « Web »).

- `GET /admin/users` : champs **ajoutés** à chaque utilisateur : `population`, `signupChannel`, `createdAt`,
  `lastActivityAt` (dernier des : dernière connexion, modification d'un livre ou d'un chapitre, appel IA),
  `mobileBooksCount`. Cette liste contient tous les comptes du site, clients de la boutique compris.
- `GET /admin/mobile-users?search=&page=1&perPage=20` (`Controller/Admin/MobileUserAdminController`,
  `Services/MobileUserStatsService`, `Repository/UserActivityStatsRepository` : SQL agrégé, une requête par famille) :
  population mobile seulement, administrateurs exclus → `{date, timezone, limits, page, perPage, total, users:
  [{userId, email, name, signupChannel, createdAt, lastActivityAt, books: {count, mobileCount, chaptersTotal,
  chaptersWritten, chaptersPaid}, payments: {count, totalAmount, currency, lastPaidAt}, credits: null, ai:
  {transcriptionsToday, improvementsToday, transcriptionsTotal, improvementsTotal, generationsTotal, lastCallAt,
  remaining: {transcriptions, improvements}}}]}`. Montants en unités avec la devise, dates ISO 8601 dans le fuseau
  du site. `perPage` ≤ 100.
- `GET /admin/mobile-users/{userId}` → `{user: {…, population, roles}, books: [{id, title, type, channel, createdAt,
  paymentStatus, chapters: [{id, title, position, generationStatus, hasText, updatedAt, paid: null}]}], payments:
  [{id, amount, currency, status, paidAt, object: {kind: "book" | "print", id, title}}], credits: null, ai: {…,
  history: [{date, transcriptions, improvements, generations}] (30 jours)}}` ; 404 `MOBILE_USER_NOT_FOUND` hors
  population mobile.
- Ce qui est mesuré aujourd'hui : paiements = livre payé par lien Stripe (`mv_book`) et commandes d'impression
  (`mv_book_print_order`) ; **aucun paiement par chapitre ni crédit par utilisateur n'existe** (`chaptersPaid` = 0,
  `paid` = null, `credits` = null, jusqu'au lot « achat intégré » : table `mv_chapter_payment` à venir, kind
  `chapter`) ; cumuls IA à partir du 16/10/2026 et seulement pour les appels soumis aux quotas (plus `generation`
  pour les livres mobile). `createdAt` est null pour les comptes antérieurs au 16/10/2026.
- Tests : `MobileUserAdminTest`.

## Génération d'un chapitre (tâche de fond)

`POST /chapters/{id}/generate` → `Services/ChapterGenerationService` → message `GenerateChapterMessage` (transport
`async`, worker `symfony_messenger_worker_v2`) → `Message/GenerateChapterHandler.php` (2 parties, Claude via
`AnthropicService`).

- `generation_status` : `pending` → `generating_part1` → `part1_done` → `generating_part2` → `completed` | `failed`.
  Un chapitre créé sans réponse est `completed` avec un texte vide : `pending` signifie « rédaction en file ».
- Le chapitre d'un livre renvoie `speaker` (interlocuteur défini par son type : `person1`, `person2`, `both`,
  `contributors`, `synthesis`) et `isSynthesis` (`BookTypeResolver::speakersByTheme`) : un chapitre de synthèse n'a pas
  besoin de question, le frontend l'affiche sans questionnaire.
- **Rien n'est envoyé à l'IA sans matériau** (`cannotGenerateReason`) : 422 à la demande, aucune rédaction à la création
  d'un chapitre vide (le frontend les crée vides), dernier contrôle dans le handler. Chapitres de synthèse : au moins
  un témoignage dans le livre.
- **Une seule rédaction à la fois** par chapitre : une seconde demande répond 200 `alreadyInProgress` sans rien lancer.
- **Rédaction perdue** (`failIfLost`, appelé par `GET /chapters/{id}`, `/status`, `/generate` et l'écran Workers) :
  échec au bout de 2 min si la file est vide et que rien n'est en cours de traitement ; sinon 30 min en
  `generating_*`, 60 min en `pending` ou `part1_done`.
- Seconde partie (consignes en base) : la première partie est redonnée **en entier** à l'IA quand elle est courte
  (8 000 caractères), sinon ses trois derniers paragraphes ; si tout est traité et que les consignes interdisent toute
  conclusion, l'IA répond `[RIEN À AJOUTER]` et le chapitre se termine sur la première partie
  (`DatabasePromptEngine::isNothingToAdd`).
- Le titre du chapitre répété par l'IA en première ligne est retiré à la rédaction, et à la mise en page pour les
  chapitres déjà rédigés (`ChapterTextFormatter::withoutRepeatedTitle`).
- Appels à l'IA : 3 essais sur surcharge ou erreur passagère (429, 529, 5xx), bornés dans le temps (10 min).
- Texte envoyé à l'IA pour une réponse : `ChapterQuestionProvider::answerText` (version améliorée si elle existe et
  n'a pas été écartée, sinon réponse saisie). Livres « couple » : le frontend envoie les deux voix dans un seul texte
  (« Elle: … ⏎ Lui: … », saisie et amélioration) avec un choix par voix (`useImproved1`, `useImproved2`) ; le texte
  est recomposé voix par voix, et des étiquettes sans texte ne comptent pas comme une réponse. Prénom du narrateur : celui du livre, jamais un compte générique
  (`AnthropicService::narratorFirstName`).
- Transcription audio : OpenAI Whisper (`Services/OpenAiService.php`), WebM/Ogg (Chrome, Firefox) et MP4/AAC (Safari) ;
  un enregistrement sans parole répond 422 `noSpeech` (`TranscribeAudioUseCase`). Amélioration d'une réponse : 503 si
  l'IA ne répond pas, sans consommer l'essai de l'invité.
- Suivi et relance : écran EasyAdmin « Workers » (`docs/architecture.md`, « Tâches de fond »).

## Mise en page du livre (PDF)

`Services/BookPdfGeneratorService.php`, gabarits `templates/pdf/memoires/` (Dompdf).

- Texte des chapitres : `Services/ChapterTextFormatter.php` le découpe en sous-titres (`===Titre===`, `## Titre`,
  `**Titre**` seul sur sa ligne) et paragraphes, retire le balisage Markdown et les emojis (absents des polices), sans
  produire de HTML. Un chapitre sans texte ni photo n'apparaît ni au sommaire ni dans le livre.
- Couverture : **Dompdf ignore `box-sizing`**. Chaque bloc est positionné en absolu avec une largeur explicite ; les
  textes sont centrés sur la zone visible (hors rembordage et fond perdu, `edge_pt`). Le corps du titre est ajusté au
  mot le plus long, **mesuré avec la police** (`fitFontSize`) : un titre ne passe à la ligne qu'entre deux mots, jamais
  au milieu d'un mot (ni `word-wrap: break-word`, ni règle propre à un titre) ; même garantie pour la tranche
  (`fitLine`) et les pages de titre de l'intérieur. La couleur de fond est validée (`normalizeColor`).
- Géométrie de la couverture dépliée (points PDF) : `coverGeometry()`, renvoyée dans l'en-tête `X-Cover-Geometry` de
  `pdf/preview-cover` et dans `cover_geometry` de `pdf/generate`. Un seul gabarit quel que soit `format` : plat de
  658,28 pt (210 mm + rembordage 19,05 mm + fond perdu 3,175 mm), hauteur 967,89 pt, tranche
  `max(6,5 mm ; pages × 0,057 mm + 1,5 mm)` ; zone visible d'un plat : 595,28 × 841,89 pt.
- **Polices** : catalogue court dans `Services/BookFontCatalog.php` (`GET /book-fonts`), fichiers et licences dans
  `resources/fonts/memoires/`. Le livre porte un code (`mv_book.font`, champ `font` de l'API ; code inconnu ignoré) ;
  les aperçus et `pdf/generate` acceptent un paramètre facultatif `font` pour essayer une police sans l'enregistrer.
  Le catalogue enregistre les polices auprès de Dompdf (dossier inscriptible `var/dompdf-fonts`), fixe le corps du
  texte par police (`bodyPt`) et le corps des titres se mesure avec la police choisie. Polices incorporées au PDF.
  Catégorie `script` (écriture manuscrite) : la police ne s'applique qu'aux titres (`titleFamily` : couverture, pages de
  titre, titres de chapitre, sous-titres), sans capitales ni interlettrage ; le texte courant reste en police de lecture.
- Auteur : texte ou objet `author` du livre (`normalizeAuthorName`) ; à l'impression, jamais le destinataire du colis.
  À défaut (`resolveAuthorName`) : prénom(s) du narrateur, sinon prénom et nom du propriétaire, sinon le nom par défaut
  codé en dur pour un livre `web` ; pour un livre `mobile`, jamais ce nom par défaut (propriétaire, sinon vide).
- Vérifier un rendu : générer le PDF et le regarder (les tests ne contrôlent que le HTML produit).

## Tester

- `tests/Functional/MemoiresVivantes/` (`BookTypeApiTestCase` imite le frontend : `?locale=fr`, `X-XSRF-TOKEN`, JWT) :
  contrat des types de livre, accès aux livres, rédaction (`ChapterGenerationTest`), invités et contributeurs
  (`ChapterGuestAccessTest`), PDF (`BookPdfTest`). Base modèle des tests : `db_mv_test_booktypes` (ne jamais la supprimer).
- Whisper simulé : `tests/Fake/FakeOpenAiService.php` (`transcribe` ; chaîne vide = 422 `noSpeech`).
- Client Anthropic simulé : `tests/Fake/FakeAnthropicService.php` (`complete` et `improveAnswer` ; les consignes
  historiques du code appellent l'API réelle : ne pas exécuter le handler d'un type historique dans un test).
- Essai réel de bout en bout : sur le site `demo` uniquement (types et questions publiés le 30/09/2026).

## Pièges connus et points à vérifier

- Corrigé le 29/09/2026 (tests : `tests/Functional/MemoiresVivantes/BookAccessTest.php`) :
  - impression, PDF et paiement ne vérifiaient que l'existence du livre (l'UUID servait de clé) ;
  - la commande d'impression acceptait une requête anonyme ;
  - `payment-link` acceptait un montant fourni par l'appelant (réservé désormais aux admins) ;
  - les URL de PDF fournies à la commande d'impression sont limitées aux PDF générés pour ce livre.
- Corrigé le 30/09/2026 (voir les sections ci-dessus) : chapitres vides envoyés à l'IA, « Admin » comme prénom du
  narrateur, réponses saisies ignorées par les synthèses quand la clé `improvedAnswer` était vide, PDF (balisage brut,
  couverture décalée), erreur 500 de `pdf/generate` avec l'objet auteur, livre bloqué « pending » sur un lien de
  paiement expiré (un lien Stripe Checkout vit 24 h : `BookPaymentService::resetExpiredPayment`).
- Auteur par défaut « Danielle Almont » en dur (`resolveAuthorName`, livres `web` seulement : un livre `mobile` n'y a
  jamais recours) : à rendre configurable par site avant un second client.
- `Book` porte un `#[ApiResource]` sans effet : API Platform n'explore que `src/Entity`, donc aucune opération
  (`/api/books`, `/api/book_print_orders`) n'existe, avec ou sans jeton (vérifié le 16/10/2026 : 404 partout) ; seules
  les routes `/api/memoires/` et leurs voters donnent accès aux livres.
- **À faire avant de passer Lulu en production** (aujourd'hui : bac à sable) : le paiement de l'impression n'existe
  pas (`print/payment-intent` renvoie un `client_secret` vide) et `print/order` transmet le travail à Lulu dès sa
  création. Le propriétaire connecté peut donc commander une impression sans payer. Il faut vérifier le paiement
  Stripe avant `LuluPrintService::createPrintJob`.
- Après une modification d'un handler : `messenger:stop-workers` (voir `docs/architecture.md`), ou attendre le
  redémarrage automatique (15 min).

## Tenir cette fiche à jour

À chaque nouvel endpoint, table, type de message ou changement des types de livre : mettre à jour cette fiche, puis
régénérer `docs/endpoints.md` (commande en tête du fichier).
