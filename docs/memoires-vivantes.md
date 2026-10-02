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
| Chapitres | `/books/{id}/chapters`, `/chapters/{id}` (+ `status`, `generate`, `reset`, `audio`, `improve-answer`, `share-link`, `contributor-links`) | `ChapterController` |
| Photos | `/chapters/{id}/photos`, `/photos/{id}`, mise en page | `ChapterController`, `ChapterPhotoController` |
| Contributeurs | `/books/{id}/contributors`, `/contributors/{id}` (+ `approve`) | `ContributorController` |
| PDF | `/books/{id}/pdf/preview-interior`, `preview-cover`, `generate` | `BookPdfController` |
| Paiement du livre | `/books/{id}/payment-link`, `send-payment-link`, `payment-status` | `BookPaymentApiController` (Stripe Checkout du compte connecté du tenant) |
| Impression | `/books/{id}/print/estimate`, `print/payment-intent`, `print/order`, `print/orders` ; `POST /api/webhooks/lulu` | `BookPrintApiController`, `LuluWebhookController` |
| Types de livre (lecture) | `GET /book-types`, `/book-types/{code}`, `GET /questions`, `GET /ai-models` | `BookTypeController`, `QuestionController` |
| Polices des livres | `GET /book-fonts` | `BookFontController` |
| Administration | `/admin/book-types…`, `/admin/book-type-chapters…`, `/admin/book-type-roles…`, `/admin/questions…`, `/admin/users…` | `Controller/Admin/` (`#[IsGranted('ROLE_ADMIN')]`) |
| Activation de compte | `POST /auth/activate` | `AccountActivationController` ; l'invitation (`POST /admin/users` sans mot de passe) envoie un e-mail avec le lien (`emails/memoires_account_invitation.html.twig`) |

Liens envoyés aux utilisateurs (activation, partage de chapitre, retour de paiement) : `Services/FrontendUrlResolver`
(origine de la requête, sinon domaine du tenant, sinon `<code>.<FRONTEND_BASE_DOMAIN>`). `MEMOIRES_FRONTEND_URL` n'est
plus qu'un dernier repli : chaque site a son frontend.

## Données

| Table | Entité | Contenu |
|---|---|---|
| `mv_book` | `Book` | livre (propriétaire, type, statut de paiement, adresse du client `client_address` : itinéraire Google Maps pour le biographe, `clientAddressMapsUrl` dans l'API, jamais montrée aux invités) ; identifiant UUID |
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
- **Chapitre ajouté dans la console à un type historique** : le code ne le connaît pas, il est donc rédigé avec les
  consignes en base (consigne du type + consigne du chapitre), comme pour un type configurable
  (`BookTypeResolver::findPromptTypeForChapter`). Les chapitres d'origine gardent les consignes du code. Le bouton
  « Tester » de la console utilise toujours les consignes en base : pour un chapitre d'origine d'un type historique,
  il ne reflète pas la rédaction réelle.
- Les réponses sont rattachées aux questions **par leur texte** : ne jamais renommer ni supprimer une question hors de
  `BookTypeAdminService` / `EventListener/MemoireQuestionAnswerSyncListener`.
- Nouveaux tenants : les types et questions par défaut viennent de la base modèle `gmasuite`, publiés depuis
  `db_memoiresvivantes` par `app:memoires:publish-template` (refuse une cible qui contient des livres).

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
- Vérifier un rendu : générer le PDF et le regarder (les tests ne contrôlent que le HTML produit).

## Tester

- `tests/Functional/MemoiresVivantes/` (`BookTypeApiTestCase` imite le frontend : `?locale=fr`, `X-XSRF-TOKEN`, JWT) :
  contrat des types de livre, accès aux livres, rédaction (`ChapterGenerationTest`), invités et contributeurs
  (`ChapterGuestAccessTest`), PDF (`BookPdfTest`). Base modèle des tests : `db_mv_test_booktypes` (ne jamais la supprimer).
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
- Auteur par défaut « Danielle Almont » en dur (`resolveAuthorName`) : à rendre configurable par site avant un
  second client.
- **À faire avant de passer Lulu en production** (aujourd'hui : bac à sable) : le paiement de l'impression n'existe
  pas (`print/payment-intent` renvoie un `client_secret` vide) et `print/order` transmet le travail à Lulu dès sa
  création. Le propriétaire connecté peut donc commander une impression sans payer. Il faut vérifier le paiement
  Stripe avant `LuluPrintService::createPrintJob`.
- Après une modification d'un handler : `messenger:stop-workers` (voir `docs/architecture.md`), ou attendre le
  redémarrage automatique (15 min).

## Tenir cette fiche à jour

À chaque nouvel endpoint, table, type de message ou changement des types de livre : mettre à jour cette fiche, puis
régénérer `docs/endpoints.md` (commande en tête du fichier).
