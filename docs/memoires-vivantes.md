# Module Mémoires Vivantes

Livres de souvenirs écrits à partir des réponses d'un narrateur (ou de contributeurs), rédigés chapitre par chapitre
par l'IA, puis mis en page (PDF), payés et imprimés (Lulu). Contexte : `docs/architecture.md`.
Code : `src/MemoiresVivantes/` (module autonome). Côté frontend : `src/components/MemoiresVivantes/README.md`
(dépôt `Izzipro_next`).

## Endpoints

Tous sous `/api/memoires/` : liste complète et accès dans `docs/endpoints.md`, section « Mémoires Vivantes ».

- `access_control` laisse `/api/memoires/` **public**, pour que les liens de partage fonctionnent sans connexion, sauf :
  `/api/memoires/admin/` (`ROLE_ADMIN`) ; impression (`…/print/…`), `pdf/generate`, `payment-link`,
  `send-payment-link` (connexion obligatoire, `IS_AUTHENTICATED_FULLY`).
- **Le contrôle est dans chaque action** :
  - livre : `Security/BookAccessGuard.php`, avec `canView` (lien de chapitre signé, clé `APP_SECRET`, ou voter
    `BOOK_VIEW`) et `canManage` (connecté, propriétaire ou admin : `BOOK_EDIT`) ;
  - chapitre : `ChapterVoter` et la vérification de signature de `ChapterController` ;
  - voters : `Security/BookVoter.php`, `Security/ChapterVoter.php`.
- Toute nouvelle action doit en utiliser un : rien ne la protège sinon. Lecture : `canView` ; tout ce qui coûte,
  engage ou modifie : `canManage`.

| Groupe | Routes | Contrôleur |
|---|---|---|
| Livres | `GET, POST /books`, `GET, PUT, DELETE /books/{id}`, couverture | `BookController` |
| Chapitres | `/books/{id}/chapters`, `/chapters/{id}` (+ `status`, `generate`, `reset`, `audio`, `improve-answer`, `share-link`, `contributor-links`) | `ChapterController` |
| Photos | `/chapters/{id}/photos`, `/photos/{id}`, mise en page | `ChapterController`, `ChapterPhotoController` |
| Contributeurs | `/books/{id}/contributors`, `/contributors/{id}` (+ `approve`) | `ContributorController` |
| PDF | `/books/{id}/pdf/preview-interior`, `preview-cover`, `generate` | `BookPdfController` |
| Paiement du livre | `/books/{id}/payment-link`, `send-payment-link`, `payment-status` | `BookPaymentApiController` (Stripe Checkout du compte connecté du tenant) |
| Impression | `/books/{id}/print/estimate`, `print/payment-intent`, `print/order`, `print/orders` ; `POST /api/webhooks/lulu` | `BookPrintApiController`, `LuluWebhookController` |
| Types de livre (lecture) | `GET /book-types`, `/book-types/{code}`, `GET /questions`, `GET /ai-models` | `BookTypeController`, `QuestionController` |
| Administration | `/admin/book-types…`, `/admin/book-type-chapters…`, `/admin/book-type-roles…`, `/admin/questions…`, `/admin/users…` | `Controller/Admin/` (`#[IsGranted('ROLE_ADMIN')]`) |
| Activation de compte | `POST /auth/activate` | `AccountActivationController` |

## Données

| Table | Entité | Contenu |
|---|---|---|
| `mv_book` | `Book` | livre (propriétaire, type, statut de paiement…) ; identifiant UUID |
| `mv_chapter` | `Chapter` | réponses, texte généré, `generation_status` |
| `mv_chapter_photo` | `ChapterPhoto` | photos d'un chapitre |
| `mv_contributor` | `Contributor` | contributeurs (livres collectifs) |
| `mv_book_type`, `mv_book_type_chapter`, `mv_book_type_role` | `BookType…` | types de livre configurables |
| `mv_question` | `MemoireQuestion` | questions des chapitres |
| `mv_book_print_order` | `BookPrintOrder` | commandes d'impression Lulu |

Fichiers : `var/storage/public_bucket/uploads/memoires/` (photos, PDF), `…/uploads/audio/` (enregistrements).

## Types de livre

- Données en base (2 familles : `direct`, 1 ou 2 narrateurs ; `collectif`, contributeurs avec rôles). Code :
  `BookType/` (`BookTypeAdminService`, `BookTypeResolver`, `DatabasePromptEngine`, `LegacyBookTypeCatalog`).
- Types historiques (`individuel`, `couple`, `famille`, `hommage`) : prompts dans `Services/AnthropicService.php`
  (`promptSource = code`) ; nouveaux types : prompts en base (`promptSource = database`).
- Les réponses sont rattachées aux questions **par leur texte** : ne jamais renommer ni supprimer une question hors de
  `BookTypeAdminService` / `EventListener/MemoireQuestionAnswerSyncListener`.
- Nouveaux tenants : les types et questions par défaut viennent de la base modèle `gmasuite`, publiés depuis
  `db_memoiresvivantes` par `app:memoires:publish-template` (refuse une cible qui contient des livres).

## Génération d'un chapitre (tâche de fond)

`POST /chapters/{id}/generate` → message `GenerateChapterMessage` (transport `async`, worker
`symfony_messenger_worker_v2`) → `Message/GenerateChapterHandler.php` (2 parties, Claude via `AnthropicService`).

- `generation_status` : `pending` → `generating_part1` → `part1_done` → `generating_part2` → `completed` | `failed`.
- `GET /chapters/{id}/status` fait échouer une génération bloquée : 30 min en `generating_*`, 60 min en `pending` ou
  `part1_done` (`ChapterController::STALE_GENERATION_MINUTES`).
- Transcription audio : OpenAI Whisper (`Services/OpenAiService.php`). Appels à l'IA bornés dans le temps (10 min, 5 min).

## Tester

- `tests/Functional/MemoiresVivantes/` (contrat de l'API des types de livre ; `BookTypeApiTestCase` imite le frontend :
  `?locale=fr`, `X-XSRF-TOKEN`, JWT). Base modèle des tests : `db_mv_test_booktypes` (ne jamais la supprimer).
- Client Anthropic simulé : `tests/Fake/FakeAnthropicService.php`.

## Pièges connus et points à vérifier

- Corrigé le 29/09/2026 (tests : `tests/Functional/MemoiresVivantes/BookAccessTest.php`) :
  - impression, PDF et paiement ne vérifiaient que l'existence du livre (l'UUID servait de clé) ;
  - la commande d'impression acceptait une requête anonyme ;
  - `payment-link` acceptait un montant fourni par l'appelant (réservé désormais aux admins) ;
  - les URL de PDF fournies à la commande d'impression sont limitées aux PDF générés pour ce livre.
- **À faire avant de passer Lulu en production** (aujourd'hui : bac à sable) : le paiement de l'impression n'existe
  pas (`print/payment-intent` renvoie un `client_secret` vide) et `print/order` transmet le travail à Lulu dès sa
  création. Le propriétaire connecté peut donc commander une impression sans payer. Il faut vérifier le paiement
  Stripe avant `LuluPrintService::createPrintJob`.
- Après une modification d'un handler : `messenger:stop-workers` (voir `docs/architecture.md`), ou attendre le
  redémarrage automatique (15 min).

## Tenir cette fiche à jour

À chaque nouvel endpoint, table, type de message ou changement des types de livre : mettre à jour cette fiche, puis
régénérer `docs/endpoints.md` (commande en tête du fichier).
