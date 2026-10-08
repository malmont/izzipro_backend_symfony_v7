# Index des endpoints du backend

Généré (ne pas modifier à la main) ; après tout ajout ou changement de route, régénérer depuis la racine du dépôt :
`docker exec -w /var/www symfony_app_v2 php bin/console app:docs:endpoints > docs/endpoints.md`
Contexte : `docs/architecture.md` ; détail de chaque module : sa fiche.

- **Accès** : rôle exigé par la première règle `access_control` de `config/packages/security.yaml` qui s'applique
  (`PUBLIC_ACCESS` = sans connexion ; `aucune règle` = pas de contrôle à ce niveau). Les contrôleurs ajoutent parfois
  leurs propres vérifications : `#[IsGranted]` est signalé, les autres (voters, contrôles dans le code) sont dans les fiches.
- Toutes les routes sont résolues pour le tenant de la requête (en-tête `X-Tenant-Host`, voir `docs/architecture.md`).

## Landing Page (14)

| Méthodes | Route | Accès | Contrôleur |
|---|---|---|---|
| GET | `/api/components-config` | PUBLIC_ACCESS | `Controller\LandingPagesController\LandingPagesController::getComponentsConfig` |
| POST | `/api/landingpage-ai/compose` | ROLE_ADMIN | `Controller\LandingAiController\LandingAiController::compose` |
| GET | `/api/landingpage-ai/jobs/{jobId}` | ROLE_ADMIN | `Controller\LandingAiController\LandingAiController::job` |
| GET | `/api/landingpage-ai/usage` | ROLE_ADMIN | `Controller\LandingAiController\LandingAiController::usage` |
| POST | `/api/landingpage-ai/video-prompt` | ROLE_ADMIN | `Controller\LandingAiController\LandingAiController::videoPrompt` |
| POST | `/api/landingpage-audit/{id}/restore` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\ContentAuditController\ContentAuditController::restore` |
| GET | `/api/landingpage-audit/{id}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\ContentAuditController\ContentAuditController::getOne` |
| GET | `/api/landingpage-audit` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\ContentAuditController\ContentAuditController::list` |
| POST | `/api/landingpage-config/rollback` | PUBLIC_ACCESS | `Controller\LandingConfigController\LandingConfigController::rollback` |
| GET | `/api/landingpage-config/status` | PUBLIC_ACCESS | `Controller\LandingConfigController\LandingConfigController::status` |
| POST | `/api/landingpage-config/sync` | PUBLIC_ACCESS | `Controller\LandingConfigController\LandingConfigController::sync` |
| GET | `/api/landingpage-settings` | PUBLIC_ACCESS | `Controller\LandingPageSettingsController\LandingPageSettingsController::getSettings` |
| PUT | `/api/landingpage-settings` | ROLE_ADMIN | `Controller\LandingPageSettingsController\LandingPageSettingsController::updateSettings` |
| GET | `/api/landingpage` | PUBLIC_ACCESS | `Controller\ProductController\ProductController::getLandingPageProducts` |

## Contenus des sites (67)

| Méthodes | Route | Accès | Contrôleur |
|---|---|---|---|
| GET | `/api/baniere-statiques/{id}` | PUBLIC_ACCESS | `Controller\BaniereStatiqueApiController\BaniereStatiqueApiController::getOne` |
| GET | `/api/baniere-statiques` | PUBLIC_ACCESS | `Controller\BaniereStatiqueApiController\BaniereStatiqueApiController::list` |
| DELETE | `/api/bannieres/{id}` | ROLE_ADMIN | `Controller\BanniereApiController\BanniereApiController::delete` |
| GET | `/api/bannieres/{id}` | PUBLIC_ACCESS | `Controller\BanniereApiController\BanniereApiController::getOne` |
| PUT | `/api/bannieres/{id}` | ROLE_ADMIN | `Controller\BanniereApiController\BanniereApiController::update` |
| GET | `/api/bannieres` | PUBLIC_ACCESS | `Controller\BanniereApiController\BanniereApiController::list` |
| POST | `/api/bannieres` | ROLE_ADMIN | `Controller\BanniereApiController\BanniereApiController::create` |
| GET | `/api/candidatures/{id}` | ROLE_ADMIN | `Controller\CandidatureApiController\CandidatureApiController::getOne` |
| GET | `/api/candidatures` | ROLE_ADMIN | `Controller\CandidatureApiController\CandidatureApiController::list` |
| POST | `/api/candidatures` | PUBLIC_ACCESS | `Controller\CandidatureApiController\CandidatureApiController::create` |
| GET | `/api/categories-marque/{id}/marques` | PUBLIC_ACCESS | `Controller\CategorieMarqueApiController\CategorieMarqueApiController::getMarquesForCategory` |
| DELETE | `/api/categories-marque/{id}` | ROLE_ADMIN | `Controller\CategorieMarqueApiController\CategorieMarqueApiController::delete` |
| PUT | `/api/categories-marque/{id}` | ROLE_ADMIN | `Controller\CategorieMarqueApiController\CategorieMarqueApiController::update` |
| GET | `/api/categories-marque` | PUBLIC_ACCESS | `Controller\CategorieMarqueApiController\CategorieMarqueApiController::list` |
| POST | `/api/categories-marque` | ROLE_ADMIN | `Controller\CategorieMarqueApiController\CategorieMarqueApiController::create` |
| POST | `/api/contact/submit` | PUBLIC_ACCESS | `Controller\ContactApiController\ContactSubmitController::submit` |
| POST | `/api/contacts/create` | PUBLIC_ACCESS | `Controller\ContactApiController\ContactApiController::create` |
| DELETE | `/api/contacts/{id}` | ROLE_ADMIN | `Controller\ContactApiController\ContactApiController::delete` |
| GET | `/api/contacts/{id}` | ROLE_ADMIN | `Controller\ContactApiController\ContactApiController::getOne` |
| PUT | `/api/contacts/{id}` | ROLE_ADMIN | `Controller\ContactApiController\ContactApiController::update` |
| GET | `/api/contacts` | ROLE_ADMIN | `Controller\ContactApiController\ContactApiController::list` |
| POST | `/api/contacts` | PUBLIC_ACCESS | `Controller\ContactApiController\ContactApiController::create` |
| GET | `/api/embeds/{id}` | PUBLIC_ACCESS | `Controller\EmbedApiController\EmbedApiController::getOne` |
| GET | `/api/embeds` | PUBLIC_ACCESS | `Controller\EmbedApiController\EmbedApiController::list` |
| DELETE | `/api/emplois/{id}` | ROLE_ADMIN | `Controller\EmploiApiController\EmploiApiController::delete` |
| PUT | `/api/emplois/{id}` | ROLE_ADMIN | `Controller\EmploiApiController\EmploiApiController::update` |
| GET | `/api/emplois` | PUBLIC_ACCESS | `Controller\EmploiApiController\EmploiApiController::list` |
| POST | `/api/emplois` | ROLE_ADMIN | `Controller\EmploiApiController\EmploiApiController::create` |
| POST | `/api/financement/submit` | PUBLIC_ACCESS | `Controller\FinancementApiController::submit` |
| DELETE | `/api/marques/{id}` | ROLE_ADMIN | `Controller\MarqueApiController\MarqueApiController::delete` |
| PUT | `/api/marques/{id}` | ROLE_ADMIN | `Controller\MarqueApiController\MarqueApiController::update` |
| GET | `/api/marques` | PUBLIC_ACCESS | `Controller\MarqueApiController\MarqueApiController::list` |
| POST | `/api/marques` | ROLE_ADMIN | `Controller\MarqueApiController\MarqueApiController::create` |
| DELETE | `/api/multiliens/{id}` | ROLE_ADMIN | `Controller\MultilienApiController\MultilienApiController::delete` |
| GET | `/api/multiliens/{id}` | PUBLIC_ACCESS | `Controller\MultilienApiController\MultilienApiController::getOne` |
| PUT | `/api/multiliens/{id}` | ROLE_ADMIN | `Controller\MultilienApiController\MultilienApiController::update` |
| GET | `/api/multiliens` | PUBLIC_ACCESS | `Controller\MultilienApiController\MultilienApiController::list` |
| POST | `/api/multiliens` | ROLE_ADMIN | `Controller\MultilienApiController\MultilienApiController::create` |
| POST | `/api/newsletter/subscribe` | PUBLIC_ACCESS | `Controller\NewsletterApiController\NewsletterApiController::subscribe` |
| GET | `/api/newsletter` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\NewsletterApiController\NewsletterApiController::list` |
| PUT | `/api/presentation-groups/{id}/presentations/order` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\LandingContentController\LandingContentController::reorder` |
| DELETE | `/api/presentation-groups/{id}/presentations/{presentationId}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\LandingContentController\LandingContentController::removePresentation` |
| POST | `/api/presentation-groups/{id}/presentations` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\LandingContentController\LandingContentController::addPresentation` |
| GET | `/api/presentation-groups/{id}` | PUBLIC_ACCESS | `Controller\PresentationGroupApiController\PresentationGroupApiController::getOne` |
| GET | `/api/presentation-groups` | PUBLIC_ACCESS | `Controller\PresentationGroupApiController\PresentationGroupApiController::list` |
| GET | `/api/presentations/{id}` | PUBLIC_ACCESS | `Controller\PresentationApiController\PresentationApiController::getOne` |
| GET | `/api/presentations` | PUBLIC_ACCESS | `Controller\PresentationApiController\PresentationApiController::list` |
| DELETE | `/api/recherches/{id}` | ROLE_ADMIN | `Controller\RechercheApiController\RechercheApiController::delete` |
| GET | `/api/recherches/{id}` | PUBLIC_ACCESS | `Controller\RechercheApiController\RechercheApiController::getOne` |
| PUT | `/api/recherches/{id}` | ROLE_ADMIN | `Controller\RechercheApiController\RechercheApiController::update` |
| GET | `/api/recherches` | PUBLIC_ACCESS | `Controller\RechercheApiController\RechercheApiController::list` |
| POST | `/api/recherches` | ROLE_ADMIN | `Controller\RechercheApiController\RechercheApiController::create` |
| DELETE | `/api/service-offers/{id}` | ROLE_ADMIN | `Controller\ServiceOfferApiController\ServiceOfferApiController::delete` |
| GET | `/api/service-offers/{id}` | PUBLIC_ACCESS | `Controller\ServiceOfferApiController\ServiceOfferApiController::getOne` |
| PUT | `/api/service-offers/{id}` | ROLE_ADMIN | `Controller\ServiceOfferApiController\ServiceOfferApiController::update` |
| GET | `/api/service-offers` | PUBLIC_ACCESS | `Controller\ServiceOfferApiController\ServiceOfferApiController::list` |
| POST | `/api/service-offers` | ROLE_ADMIN | `Controller\ServiceOfferApiController\ServiceOfferApiController::create` |
| DELETE | `/api/team/{id}` | ROLE_ADMIN | `Controller\TeamController\TeamController::delete` |
| GET | `/api/team/{id}` | PUBLIC_ACCESS | `Controller\TeamController\TeamController::getTeam` |
| PUT | `/api/team/{id}` | ROLE_ADMIN | `Controller\TeamController\TeamController::update` |
| GET | `/api/team` | PUBLIC_ACCESS | `Controller\TeamController\TeamController::list` |
| POST | `/api/team` | ROLE_ADMIN | `Controller\TeamController\TeamController::create` |
| DELETE | `/api/videos/{id}` | ROLE_ADMIN | `Controller\VideoApiController\VideoApiController::delete` |
| GET | `/api/videos/{id}` | PUBLIC_ACCESS | `Controller\VideoApiController\VideoApiController::getOne` |
| PUT | `/api/videos/{id}` | ROLE_ADMIN | `Controller\VideoApiController\VideoApiController::update` |
| GET | `/api/videos` | PUBLIC_ACCESS | `Controller\VideoApiController\VideoApiController::list` |
| POST | `/api/videos` | ROLE_ADMIN | `Controller\VideoApiController\VideoApiController::create` |

## Mémoires Vivantes (76)

| Méthodes | Route | Accès | Contrôleur |
|---|---|---|---|
| POST | `/api/memoires/admin/book-type-chapters/{id}/prompt/optimize` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\BookTypeAdminController::optimizeChapterPrompt` |
| PUT | `/api/memoires/admin/book-type-chapters/{id}/questions/order` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\BookTypeAdminController::reorderQuestions` |
| POST | `/api/memoires/admin/book-type-chapters/{id}/questions` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\BookTypeAdminController::addQuestion` |
| DELETE | `/api/memoires/admin/book-type-chapters/{id}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\BookTypeAdminController::deleteChapter` |
| PUT, PATCH | `/api/memoires/admin/book-type-chapters/{id}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\BookTypeAdminController::updateChapter` |
| DELETE | `/api/memoires/admin/book-type-roles/{id}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\BookTypeAdminController::deleteRole` |
| PUT, PATCH | `/api/memoires/admin/book-type-roles/{id}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\BookTypeAdminController::updateRole` |
| GET | `/api/memoires/admin/book-types/prompt-variables` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\BookTypeAdminController::promptVariables` |
| PUT | `/api/memoires/admin/book-types/{id}/chapters/order` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\BookTypeAdminController::reorderChapters` |
| POST | `/api/memoires/admin/book-types/{id}/chapters` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\BookTypeAdminController::addChapter` |
| POST | `/api/memoires/admin/book-types/{id}/prompt/optimize` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\BookTypeAdminController::optimizeTypePrompt` |
| POST | `/api/memoires/admin/book-types/{id}/roles` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\BookTypeAdminController::addRole` |
| POST | `/api/memoires/admin/book-types/{id}/test` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\BookTypeAdminController::testType` |
| DELETE | `/api/memoires/admin/book-types/{id}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\BookTypeAdminController::deleteType` |
| GET | `/api/memoires/admin/book-types/{id}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\BookTypeAdminController::getType` |
| PUT, PATCH | `/api/memoires/admin/book-types/{id}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\BookTypeAdminController::updateType` |
| GET | `/api/memoires/admin/book-types` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\BookTypeAdminController::listTypes` |
| POST | `/api/memoires/admin/book-types` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\BookTypeAdminController::createType` |
| DELETE | `/api/memoires/admin/questions/{id}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\BookTypeAdminController::deleteQuestion` |
| PUT, PATCH | `/api/memoires/admin/questions/{id}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\BookTypeAdminController::updateQuestion` |
| DELETE | `/api/memoires/admin/users/{id}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\UserManagementController::delete` |
| PUT, PATCH | `/api/memoires/admin/users/{id}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\UserManagementController::update` |
| GET | `/api/memoires/admin/users` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\UserManagementController::list` |
| POST | `/api/memoires/admin/users` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\UserManagementController::create` |
| GET | `/api/memoires/ai-models` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\ChapterController::getAiModels` |
| POST | `/api/memoires/auth/activate` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\AccountActivationController::activate` |
| GET | `/api/memoires/book-fonts` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\BookFontController::list` |
| GET | `/api/memoires/book-types/{code}` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\BookTypeController::get` |
| GET | `/api/memoires/book-types` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\BookTypeController::list` |
| POST | `/api/memoires/books/{bookId}/pdf/generate` | IS_AUTHENTICATED_FULLY | `MemoiresVivantes\Controller\BookPdfController::generatePdfs` |
| GET | `/api/memoires/books/{bookId}/pdf/preview-cover` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\BookPdfController::previewCover` |
| GET | `/api/memoires/books/{bookId}/pdf/preview-interior` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\BookPdfController::previewInterior` |
| GET | `/api/memoires/books/{id}/chapters` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\ChapterController::listByBook` |
| POST | `/api/memoires/books/{id}/chapters` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\ChapterController::create` |
| POST | `/api/memoires/books/{id}/contributors` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\ContributorController::create` |
| DELETE | `/api/memoires/books/{id}/cover` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\BookController::removeCover` |
| POST | `/api/memoires/books/{id}/cover` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\BookController::uploadCover` |
| POST | `/api/memoires/books/{id}/payment-link` | IS_AUTHENTICATED_FULLY | `MemoiresVivantes\Controller\BookPaymentApiController::generateAndSendPaymentLink` |
| GET | `/api/memoires/books/{id}/payment-status` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\BookPaymentApiController::getPaymentStatus` |
| POST | `/api/memoires/books/{id}/pdf/generate` | IS_AUTHENTICATED_FULLY | `MemoiresVivantes\Controller\BookPdfController::generatePdfs` |
| GET | `/api/memoires/books/{id}/pdf/preview-cover` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\BookPdfController::previewCover` |
| GET | `/api/memoires/books/{id}/pdf/preview-interior` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\BookPdfController::previewInterior` |
| POST | `/api/memoires/books/{id}/print/estimate` | IS_AUTHENTICATED_FULLY | `MemoiresVivantes\Controller\BookPrintApiController::estimate` |
| POST | `/api/memoires/books/{id}/print/order` | IS_AUTHENTICATED_FULLY | `MemoiresVivantes\Controller\BookPrintApiController::createOrder` |
| GET | `/api/memoires/books/{id}/print/orders` | IS_AUTHENTICATED_FULLY | `MemoiresVivantes\Controller\BookPrintApiController::listOrdersByBook` |
| POST | `/api/memoires/books/{id}/print/payment-intent` | IS_AUTHENTICATED_FULLY | `MemoiresVivantes\Controller\BookPrintApiController::createPaymentIntent` |
| GET | `/api/memoires/books/{id}/reservations` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\BookController::getReservations` |
| POST | `/api/memoires/books/{id}/send-payment-link` | IS_AUTHENTICATED_FULLY | `MemoiresVivantes\Controller\BookPaymentApiController::generateAndSendPaymentLink` |
| DELETE | `/api/memoires/books/{id}` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\BookController::delete` |
| GET | `/api/memoires/books/{id}` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\BookController::get` |
| PUT | `/api/memoires/books/{id}` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\BookController::update` |
| GET | `/api/memoires/books` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\BookController::list` |
| POST | `/api/memoires/books` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\BookController::create` |
| POST | `/api/memoires/chapters/{chapterId}/photos/layout` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\ChapterController::updatePhotosLayout` |
| POST | `/api/memoires/chapters/{id}/audio` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\ChapterController::uploadAudio` |
| GET | `/api/memoires/chapters/{id}/contributor-links` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\ChapterController::getContributorLinks` |
| POST | `/api/memoires/chapters/{id}/generate` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\ChapterController::generate` |
| POST | `/api/memoires/chapters/{id}/improve-answer` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\ChapterController::improveAnswer` |
| POST | `/api/memoires/chapters/{id}/photos/layout` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\ChapterController::updatePhotosLayout` |
| POST | `/api/memoires/chapters/{id}/photos` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\ChapterController::addPhoto` |
| POST | `/api/memoires/chapters/{id}/reset` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\ChapterController::reset` |
| GET | `/api/memoires/chapters/{id}/share-link` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\ChapterController::getShareLink` |
| GET | `/api/memoires/chapters/{id}/status` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\ChapterController::status` |
| DELETE | `/api/memoires/chapters/{id}` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\ChapterController::delete` |
| GET | `/api/memoires/chapters/{id}` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\ChapterController::get` |
| PUT | `/api/memoires/chapters/{id}` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\ChapterController::update` |
| POST | `/api/memoires/contributors/{id}/approve` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\ContributorController::approve` |
| DELETE | `/api/memoires/contributors/{id}` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\ContributorController::delete` |
| PUT | `/api/memoires/contributors/{id}` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\ContributorController::update` |
| DELETE | `/api/memoires/photos/{id}` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\ChapterPhotoController::delete` |
| PUT | `/api/memoires/photos/{id}` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\ChapterPhotoController::update` |
| POST | `/api/memoires/print/order` | IS_AUTHENTICATED_FULLY | `MemoiresVivantes\Controller\BookPrintApiController::createOrder` |
| GET | `/api/memoires/print/orders/{orderId}` | IS_AUTHENTICATED_FULLY | `MemoiresVivantes\Controller\BookPrintApiController::getOrder` |
| POST | `/api/memoires/print/payment-intent` | IS_AUTHENTICATED_FULLY | `MemoiresVivantes\Controller\BookPrintApiController::createPaymentIntent` |
| GET | `/api/memoires/questions` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\QuestionController::list` |
| POST | `/api/webhooks/lulu` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\LuluWebhookController::handleLuluWebhook` |

## Boussole ESG (20)

| Méthodes | Route | Accès | Contrôleur |
|---|---|---|---|
| POST | `/api/boussole/auth/login` | PUBLIC_ACCESS | `ESG\Controller\AuthController::login` |
| POST | `/api/boussole/auth/register` | PUBLIC_ACCESS | `ESG\Controller\AuthController::register` |
| GET | `/api/boussole/company` | ROLE_COMPANY ou ROLE_CONSULTANT ou ROLE_USER ou ROLE_ADMIN | `ESG\Controller\CompanyController::getProfile` |
| PUT | `/api/boussole/company` | ROLE_COMPANY ou ROLE_CONSULTANT ou ROLE_USER ou ROLE_ADMIN | `ESG\Controller\CompanyController::updateProfile` |
| GET | `/api/boussole/documents/download/{id}` | ROLE_COMPANY | `ESG\Controller\DocumentController::downloadById` |
| GET | `/api/boussole/documents/download` | ROLE_COMPANY | `ESG\Controller\DocumentController::downloadByPath` |
| DELETE | `/api/boussole/documents/{id}` | ROLE_COMPANY | `ESG\Controller\DocumentController::delete` |
| GET | `/api/boussole/documents` | ROLE_COMPANY | `ESG\Controller\DocumentController::list` |
| POST | `/api/boussole/documents` | ROLE_COMPANY | `ESG\Controller\DocumentController::upload` |
| GET | `/api/boussole/questions` | ROLE_COMPANY ou ROLE_CONSULTANT ou ROLE_USER ou ROLE_ADMIN | `ESG\Controller\QuestionController::listQuestions` |
| GET | `/api/boussole/referentials` | PUBLIC_ACCESS | `ESG\Controller\ReferentialController::listReferentials` |
| GET | `/api/boussole/sessions/historique` | ROLE_COMPANY ou ROLE_CONSULTANT ou ROLE_USER ou ROLE_ADMIN | `ESG\Controller\SessionController::historique` |
| PUT | `/api/boussole/sessions/{uuid}/answers` | ROLE_COMPANY ou ROLE_CONSULTANT ou ROLE_USER ou ROLE_ADMIN | `ESG\Controller\SessionController::saveAnswers` |
| GET | `/api/boussole/sessions/{uuid}/recommendations` | ROLE_COMPANY ou ROLE_CONSULTANT ou ROLE_USER ou ROLE_ADMIN | `ESG\Controller\SessionController::getRecommendations` |
| GET | `/api/boussole/sessions/{uuid}/report/download` | ROLE_COMPANY ou ROLE_CONSULTANT ou ROLE_USER ou ROLE_ADMIN | `ESG\Controller\ReportController::download` |
| GET | `/api/boussole/sessions/{uuid}/report` | ROLE_COMPANY ou ROLE_CONSULTANT ou ROLE_USER ou ROLE_ADMIN | `ESG\Controller\ReportController::getStatus` |
| POST | `/api/boussole/sessions/{uuid}/submit` | ROLE_COMPANY ou ROLE_CONSULTANT ou ROLE_USER ou ROLE_ADMIN | `ESG\Controller\SessionController::submit` |
| GET | `/api/boussole/sessions/{uuid}` | ROLE_COMPANY ou ROLE_CONSULTANT ou ROLE_USER ou ROLE_ADMIN | `ESG\Controller\SessionController::getSession` |
| GET | `/api/boussole/sessions` | ROLE_COMPANY ou ROLE_CONSULTANT ou ROLE_USER ou ROLE_ADMIN | `ESG\Controller\SessionController::list` |
| POST | `/api/boussole/sessions` | ROLE_COMPANY ou ROLE_CONSULTANT ou ROLE_USER ou ROLE_ADMIN | `ESG\Controller\SessionController::create` |

## Réservations (11)

| Méthodes | Route | Accès | Contrôleur |
|---|---|---|---|
| GET | `/api/booking/calendar/{productId}` | PUBLIC_ACCESS | `Controller\BookingController\BookingController::calendar` |
| GET | `/api/booking/check/{productId}` | PUBLIC_ACCESS | `Controller\BookingController\BookingController::check` |
| GET | `/api/reservations/booked-slots` | PUBLIC_ACCESS | `Controller\ReservationApiController::getBookedSlots` |
| GET | `/api/reservations/services` | PUBLIC_ACCESS | `Controller\ReservationApiController::getServices` |
| POST | `/api/reservations/{id}/confirm` | PUBLIC_ACCESS | `Controller\ReservationApiController::confirm` |
| GET | `/api/reservations` | PUBLIC_ACCESS | `Controller\ReservationApiController::list` |
| POST | `/api/reservations` | PUBLIC_ACCESS | `Controller\ReservationApiController::create` |
| GET, POST | `/booking-setup/` | aucune règle | `Controller\BookingController\BookingSetupController::auth` |
| ANY | `/booking-setup/configure/{id}` | aucune règle | `Controller\BookingController\BookingSetupController::configure` |
| ANY | `/booking-setup/list` | aucune règle | `Controller\BookingController\BookingSetupController::list` |
| ANY | `/booking-setup/logout` | aucune règle | `Controller\BookingController\BookingSetupController::logout` |

## Boutique et commun (147)

| Méthodes | Route | Accès | Contrôleur |
|---|---|---|---|
| ANY | `/` | aucune règle | `Controller\Account\SecurityController::login` |
| ANY | `/` | aucune règle | `Controller\HomeController::index` |
| ANY | `/account/order/{id}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\Account\AccountController::show` |
| ANY | `/account/otp` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\Account\OtpController::verifyOtp` |
| ANY | `/account` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\Account\AccountController::index` |
| GET | `/adress/` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\Account\AdressController::index` |
| GET, POST | `/adress/new` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\Account\AdressController::new` |
| GET, POST | `/adress/{id}/edit` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\Account\AdressController::edit` |
| POST | `/adress/{id}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\Account\AdressController::delete` |
| GET | `/api/Carrier` | PUBLIC_ACCESS | `Controller\CarrierControleur\CarrierControleur::getCarrier` |
| DELETE | `/api/admin-settings/presets/{id}` | ROLE_ADMIN | `Controller\AdminSettingsController\AdminSettingsController::deletePreset` |
| GET | `/api/admin-settings/presets` | PUBLIC_ACCESS | `Controller\AdminSettingsController\AdminSettingsController::getPresets` |
| POST | `/api/admin-settings/presets` | ROLE_ADMIN | `Controller\AdminSettingsController\AdminSettingsController::savePreset` |
| GET | `/api/admin-settings` | PUBLIC_ACCESS | `Controller\AdminSettingsController\AdminSettingsController::getAdminSettings` |
| PUT | `/api/admin-settings` | ROLE_ADMIN | `Controller\AdminSettingsController\AdminSettingsController::updateAdminSettings` |
| GET | `/api/adresses/` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\AdressController\AdressApiController::getUserAdresses` |
| GET | `/api/adresses/autocomplete` | PUBLIC_ACCESS | `Controller\AdressController\AddressAutocompleteController::suggest` |
| GET | `/api/adresses/details` | PUBLIC_ACCESS | `Controller\AdressController\AddressAutocompleteController::details` |
| PUT | `/api/adresses/{id}/set-primary` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\AdressController\AdressApiController::setPrimaryAddress` |
| DELETE | `/api/adresses/{id}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\AdressController\AdressApiController::deleteAdress` |
| PUT | `/api/adresses/{id}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\AdressController\AdressApiController::editAdress` |
| GET | `/api/adresses` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\AdressController\AdressApiController::getUserAdresses` |
| POST | `/api/adresses` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\AdressController\AdressApiController::createAdress` |
| GET | `/api/boutique-settings` | PUBLIC_ACCESS | `Controller\BoutiqueSettingsController\BoutiqueSettingsController::getSettings` |
| PUT | `/api/boutique-settings` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\BoutiqueSettingsController\BoutiqueSettingsController::updateSettings` |
| POST | `/api/caisse/cashfunddeposit` | ROLE_ADMIN | `Controller\CaisseController\CaisseController::cashFundDeposit` |
| POST | `/api/caisse/cashfundwithdraw` | ROLE_ADMIN | `Controller\CaisseController\CaisseController::cashFundWithdraw` |
| POST | `/api/caisse/close` | ROLE_ADMIN | `Controller\CaisseController\CaisseController::closeCaisse` |
| POST | `/api/caisse/deposit` | ROLE_ADMIN | `Controller\CaisseController\CaisseController::deposit` |
| POST | `/api/caisse/open` | ROLE_ADMIN | `Controller\CaisseController\CaisseController::openCaisse` |
| GET | `/api/caisse/transactions` | ROLE_ADMIN | `Controller\CaisseController\CaisseController::getOpenCaisseTransactions` |
| POST | `/api/caisse/withdraw` | ROLE_ADMIN | `Controller\CaisseController\CaisseController::withdraw` |
| GET | `/api/caisse` | ROLE_ADMIN | `Controller\CaisseController\CaisseController::getCaisse` |
| GET | `/api/carrier/statistics` | ROLE_ADMIN | `Controller\StatistiqueDashboard\CarrierStatisticsController::getCarrierStatistics` |
| POST | `/api/cart/quote` | PUBLIC_ACCESS | `Controller\CartController\CartQuoteController::quote` |
| GET | `/api/category` | PUBLIC_ACCESS | `Controller\CategoriesControlleur\CategoryController::getCategories` |
| GET | `/api/collections/{id}/commandes` | ROLE_ADMIN | `Controller\CommandeController\CommandeController::getCommandesByCollection` |
| POST | `/api/collections/{id}/commandes` | ROLE_ADMIN | `Controller\CommandeController\CommandeController::createCommande` |
| GET | `/api/collections/{id}/notes-de-frais` | ROLE_ADMIN | `Controller\NoteDeFraisController\NoteDeFraisController::getNotesDeFraisByCollection` |
| POST | `/api/collections/{id}/notes-de-frais` | ROLE_ADMIN | `Controller\NoteDeFraisController\NoteDeFraisController::createNoteDeFrais` |
| DELETE | `/api/collections/{id}` | ROLE_ADMIN | `Controller\CollectionsController\CollectionController::deleteCollection` |
| GET | `/api/collections` | ROLE_ADMIN | `Controller\CollectionsController\CollectionController::getCollections` |
| GET | `/api/colors` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\ColorsController\ColorController::getColors` |
| DELETE | `/api/commandes/{id}/frais-de-port` | ROLE_ADMIN | `Controller\FraisDePortController\FraisDePortController::deleteFraisDePort` |
| GET | `/api/commandes/{id}/frais-de-port` | ROLE_ADMIN | `Controller\FraisDePortController\FraisDePortController::getFraisDePort` |
| POST | `/api/commandes/{id}/frais-de-port` | ROLE_ADMIN | `Controller\FraisDePortController\FraisDePortController::createFraisDePort` |
| GET | `/api/commandes/{id}/products` | ROLE_ADMIN | `Controller\ProductController\ProductController::getProductsByCommande` |
| POST | `/api/commandes/{id}/products` | ROLE_ADMIN | `Controller\ProductController\ProductController::createProductByCommande` |
| POST | `/api/createcollections` | ROLE_ADMIN | `Controller\CollectionsController\CollectionController::createCollection` |
| GET | `/api/currencies` | PUBLIC_ACCESS | `App\Controller\CurrencyController\CurrencyController` |
| GET | `/api/customization/config/{variantId}` | PUBLIC_ACCESS | `App\Controller\ProductVariantController\CustomizationConfigController` |
| POST | `/api/dashboard/collection/{id}/close` | ROLE_ADMIN | `Controller\DashboardListCollectionController\DashboardListCollectionController::closeCollection` |
| GET | `/api/dashboard/collection/{id}` | ROLE_ADMIN | `Controller\DashboardListCollectionController\DashboardListCollectionController::dashboardCollection` |
| GET | `/api/dashboard/collections-combined` | ROLE_ADMIN | `Controller\DashboardListCollectionController\DashboardListCollectionController::getCombinedCollectionsData` |
| POST | `/api/dashboard/commande/{id}/close` | ROLE_ADMIN | `Controller\DashboardCommandeController\DashboardCommandeController::closeCommand` |
| GET | `/api/dashboard/commande/{id}` | ROLE_ADMIN | `Controller\DashboardCommandeController\DashboardCommandeController::getCommandeMetrics` |
| GET | `/api/entreprise/{id}` | PUBLIC_ACCESS | `Controller\EntrepriseController\EntrepriseController::getEntreprise` |
| PUT | `/api/entreprise/{id}` | ROLE_ADMIN | `Controller\EntrepriseController\EntrepriseController::updateEntreprise` |
| POST | `/api/entreprise` | ROLE_ADMIN | `Controller\EntrepriseController\EntrepriseController::create` |
| GET | `/api/explore-cards` | PUBLIC_ACCESS | `App\Controller\ExploreCardController\ExploreCardController` |
| GET | `/api/features` | PUBLIC_ACCESS | `App\Controller\FeatureController\FeatureController` |
| DELETE | `/api/fournisseurs/{id}` | ROLE_ADMIN | `Controller\FournisseurController\FournisseurController::deleteFournisseur` |
| GET | `/api/fournisseurs` | ROLE_ADMIN | `Controller\FournisseurController\FournisseurController::getAllFournisseurs` |
| POST | `/api/fournisseurs` | ROLE_ADMIN | `Controller\FournisseurController\FournisseurController::createFournisseur` |
| GET | `/api/frais/total` | ROLE_ADMIN | `Controller\StatistiqueDashboard\FraisController::getTotalFrais` |
| GET | `/api/homeslider` | PUBLIC_ACCESS | `Controller\HomeSliderController\HomeSliderController::getHomeSlider` |
| DELETE | `/api/media/{id}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\MediaApiController\MediaApiController::delete` |
| PATCH | `/api/media/{id}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\MediaApiController\MediaApiController::rename` |
| GET | `/api/media` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\MediaApiController\MediaApiController::list` |
| POST | `/api/media` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\MediaApiController\MediaApiController::upload` |
| DELETE | `/api/notes-de-frais/{id}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\NoteDeFraisController\NoteDeFraisController::deleteNoteDeFrais` |
| PUT | `/api/notes-de-frais/{id}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\NoteDeFraisController\NoteDeFraisController::updateNoteDeFrais` |
| POST | `/api/order/cancel/{id}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\OrderController\OrderController::cancelOrder` |
| POST | `/api/order/create-guest` | PUBLIC_ACCESS | `Controller\OrderController\OrderController::createGuestOrder` |
| POST | `/api/order/create-multi-payment` | ROLE_USER_POS ou ROLE_ADMIN | `Controller\OrderController\OrderController::createOrderWithMultiplePayments` |
| POST | `/api/order/create` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\OrderController\OrderController::createOrder` |
| GET | `/api/orders` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\OrderController\OrderController::getOrders` |
| GET | `/api/ordersuser` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\OrderController\OrderController::getUserOrders` |
| POST | `/api/payment` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\paymentController\PaymentsController::processPayment` |
| GET | `/api/payments/statistics/{source}` | ROLE_ADMIN | `Controller\StatistiqueDashboard\PaymentsController::getPaymentStatistics` |
| GET | `/api/payments` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\paymentController\PaymentsController::getPayments` |
| DELETE | `/api/product-variants/{id}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\ProductVariantController\ProductVariantController::deleteProductVariant` |
| GET | `/api/products/by-category` | PUBLIC_ACCESS | `Controller\CategoriesControlleur\CategoryController::getProductsByCategory` |
| GET | `/api/products/by-slug/{slug}` | PUBLIC_ACCESS | `Controller\ProductController\ProductController::getProductBySlug` |
| GET | `/api/products/{id}/variants` | PUBLIC_ACCESS | `Controller\ProductVariantController\ProductVariantController::getProductVariants` |
| POST | `/api/products/{id}/variants` | ROLE_ADMIN | `Controller\ProductVariantController\ProductVariantController::createProductVariant` |
| DELETE | `/api/products/{id}` | ROLE_ADMIN | `Controller\ProductController\ProductController::deleteProduct` |
| GET | `/api/products/{offer}` | PUBLIC_ACCESS | `Controller\ProductController\ProductController::getProductsByOffer` |
| GET | `/api/products` | PUBLIC_ACCESS | `Controller\ProductController\ProductController::getAllProducts` |
| GET | `/api/productsid/{id}` | PUBLIC_ACCESS | `Controller\ProductController\ProductController::getProductById` |
| GET | `/api/profile` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\Account\ProfileApiController::getProfile` |
| PATCH, PUT | `/api/profile` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\Account\ProfileApiController::updateProfile` |
| POST | `/api/shipping/buy` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\ShippingController\ShippingController::buy` |
| POST | `/api/shipping/parcels` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\ShippingController\ShippingController::parcels` |
| POST | `/api/shipping/rates` | PUBLIC_ACCESS | `Controller\ShippingController\ShippingController::rates` |
| POST | `/api/shipping/summary` | PUBLIC_ACCESS | `Controller\ShippingController\ShippingController::summary` |
| GET | `/api/sizes` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\SizesController\SizeController::getSizes` |
| GET | `/api/social-networks` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\SocialNetworkController\SocialNetworkController::list` |
| GET | `/api/statistiques/chiffre-affaires/{source}` | ROLE_ADMIN | `Controller\StatistiqueDashboard\StatistiqueGeneraleController::getChiffreAffaires` |
| GET | `/api/statistiques/nombre-commandes/{source}` | ROLE_ADMIN | `Controller\StatistiqueDashboard\StatistiqueGeneraleController::getOrderStatistics` |
| GET | `/api/statistiques/panier-moyen/{source}` | ROLE_ADMIN | `Controller\StatistiqueDashboard\StatistiqueGeneraleController::getAverageOrderValueStatistics` |
| GET | `/api/stock-evolution` | ROLE_ADMIN | `Controller\StockStatisticsController\StockStatisticsController::getStockEvolution` |
| GET | `/api/stock-value` | ROLE_ADMIN | `Controller\StockStatisticsController\StockValueController::getStockValues` |
| GET | `/api/stripe-config` | PUBLIC_ACCESS | `Controller\paymentController\PaymentsController::getStripeConfig` |
| POST | `/api/stripe/create-intent` | PUBLIC_ACCESS | `Controller\paymentController\PaymentsController::createStripePaymentIntent` |
| POST | `/api/stripe/webhook` | PUBLIC_ACCESS | `Controller\StripeController\StripeWebhookController::handleWebhook` |
| GET | `/api/styles` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\StyleController\StyleController::getStyles` |
| GET | `/api/taxes/monthly` | ROLE_ADMIN | `Controller\StatistiqueDashboard\TaxController::getMonthlyTaxes` |
| DELETE | `/api/transporteurs/{id}` | ROLE_ADMIN | `Controller\TransporteurController\TransporteurController::deleteTransporteur` |
| GET | `/api/transporteurs` | PUBLIC_ACCESS | `Controller\TransporteurController\TransporteurController::getTransporteurs` |
| POST | `/api/transporteurs` | ROLE_ADMIN | `Controller\TransporteurController\TransporteurController::createTransporteur` |
| GET | `/api/type-fournisseurs` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\TypeFournisseurController\TypeFournisseurController::list` |
| GET | `/api/type-note-de-frais` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\TypeNoteDeFraisController\TypeNoteDeFraisController::list` |
| GET | `/api/vehicles/carousel` | PUBLIC_ACCESS | `Controller\Api\VehicleApiController::getCarouselVehicles` |
| DELETE | `/api/{app}-site-models/{id}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\LandingSiteModelController\LandingSiteModelController::delete` |
| GET | `/api/{app}-site-models/{id}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\LandingSiteModelController\LandingSiteModelController::getOne` |
| PUT | `/api/{app}-site-models/{id}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\LandingSiteModelController\LandingSiteModelController::update` |
| GET | `/api/{app}-site-models` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\LandingSiteModelController\LandingSiteModelController::list` |
| POST | `/api/{app}-site-models` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\LandingSiteModelController\LandingSiteModelController::create` |
| PATCH | `/api/{resource}/{id}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\LandingContentController\LandingContentController::patch` |
| ANY | `/cart/add/{id}` | aucune règle | `Controller\Cart\CartController::addToCart` |
| ANY | `/cart/delete-all/{id}` | aucune règle | `Controller\Cart\CartController::deleteAllCart` |
| ANY | `/cart/delete/{id}` | aucune règle | `Controller\Cart\CartController::deleteFromCart` |
| ANY | `/cart` | aucune règle | `Controller\Cart\CartController::index` |
| ANY | `/cgu/conditions-generales-utilisation` | aucune règle | `Controller\CGU\CGUController::index` |
| ANY | `/checkout/confirm` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\Cart\CheckOutController::confirm` |
| ANY | `/checkout/edit` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\Cart\CheckOutController::checkoutEdit` |
| ANY | `/checkout` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\Cart\CheckOutController::index` |
| GET, POST | `/contact/` | aucune règle | `Controller\ContactController::new` |
| ANY | `/create-checkout-session/{reference}` | aucune règle | `Controller\Stripe\StripeStripeCheckoutSessionController::index` |
| ANY | `/logout` | aucune règle | `Controller\Account\SecurityController::logout` |
| POST | `/password-reset/confirm` | aucune règle | `Controller\Account\ResetPasswordController::confirmPasswordReset` |
| GET | `/password-reset/form` | aucune règle | `Controller\Account\ResetPasswordController::resetPasswordForm` |
| ANY | `/product/{slug}` | aucune règle | `Controller\HomeController::show` |
| ANY | `/register` | aucune règle | `Controller\Account\RegistrationController::register` |
| ANY | `/shop` | aucune règle | `Controller\HomeController::shop` |
| ANY | `/stripe-payment-cancel/{StripeCheckoutSessionId}` | aucune règle | `Controller\Stripe\StripeStripeCancelPaymentController::index` |
| ANY | `/stripe-payment-succes/{StripeCheckoutSessionId}` | aucune règle | `Controller\Stripe\StripeStripeSuccesPaymentController::index` |
| GET, POST | `/stripe/connect` | aucune règle + `#[IsGranted(ROLE_ADMIN)]` | `Controller\StripeController\StripeController::connect` |
| GET, POST | `/stripe/disconnect` | aucune règle + `#[IsGranted(ROLE_ADMIN)]` | `Controller\StripeController\StripeController::disconnect` |
| GET | `/stripe/success` | aucune règle + `#[IsGranted(ROLE_ADMIN)]` | `Controller\StripeController\StripeController::success` |
| POST | `/stripe/webhook` | PUBLIC_ACCESS | `Controller\StripeController\StripeWebhookController::handleWebhook` |
| DELETE | `/videos/{id}` | ROLE_ADMIN | `Controller\VideoApiController\VideoApiController::delete` |
| GET | `/videos/{id}` | aucune règle | `Controller\VideoApiController\VideoApiController::getOne` |
| PUT | `/videos/{id}` | ROLE_ADMIN | `Controller\VideoApiController\VideoApiController::update` |
| GET | `/videos` | aucune règle | `Controller\VideoApiController\VideoApiController::list` |
| POST | `/videos` | ROLE_ADMIN | `Controller\VideoApiController\VideoApiController::create` |

## Authentification et comptes (9)

| Méthodes | Route | Accès | Contrôleur |
|---|---|---|---|
| POST | `/api/login` | PUBLIC_ACCESS | `Controller\Account\SecurityController::loginApi` |
| POST | `/api/logout` | PUBLIC_ACCESS | `Controller\Account\SecurityController::logoutWeb` |
| POST | `/api/otp-verify` | PUBLIC_ACCESS | `Controller\Account\OtpApiController::otpVerifyApi` |
| POST | `/api/password-reset/request` | PUBLIC_ACCESS | `Controller\Account\ResetPasswordController::requestPasswordReset` |
| POST | `/api/register` | PUBLIC_ACCESS | `Controller\Account\RegistrationController::registerApi` |
| POST | `/api/resend-verification` | PUBLIC_ACCESS | `Controller\Account\RegistrationController::resendVerificationEmail` |
| POST | `/api/token/refresh` | PUBLIC_ACCESS | `Controller\Account\SecurityController::refreshToken` |
| GET | `/api/validate-token` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\Account\SecurityController::validateToken` |
| GET | `/verify/email` | aucune règle | `Controller\Account\RegistrationController::verifyEmail` |

## Tenants (4)

| Méthodes | Route | Accès | Contrôleur |
|---|---|---|---|
| GET | `/api/health` | PUBLIC_ACCESS | `Controller\HealthController::index` |
| GET, POST | `/api/tenant/check` | PUBLIC_ACCESS | `Controller\TenantSetupController\TenantController::check` |
| ANY | `/setup/new-store` | aucune règle | `Controller\TenantSetupController\TenantSetupController::setup` |
| ANY | `/setup/status/{tenantCode}/{syncJobId}/{finalUrl}` | aucune règle | `App\Controller\TenantSetupController\SyncStatusController` |

## Fichiers et médias (8)

| Méthodes | Route | Accès | Contrôleur |
|---|---|---|---|
| GET | `/api/shared-media/{id}` | PUBLIC_ACCESS | `Controller\SharedMediaApiController\SharedMediaApiController::getOne` |
| GET | `/api/shared-media` | PUBLIC_ACCESS | `Controller\SharedMediaApiController\SharedMediaApiController::list` |
| GET, HEAD | `/assets/uploads/{path}` | aucune règle | `Controller\Storage\BucketSimulatorController::serve` |
| GET, HEAD | `/bucket-simulator/{path}` | aucune règle | `Controller\Storage\BucketSimulatorController::serve` |
| GET, HEAD | `/media/secure/{accessKey}` | aucune règle | `Controller\Storage\SecureMediaDeliveryController::deliver` |
| GET | `/shared-media/{id}` | aucune règle | `Controller\SharedMediaApiController\SharedMediaApiController::getOne` |
| GET | `/shared-media` | aucune règle | `Controller\SharedMediaApiController\SharedMediaApiController::list` |
| GET, HEAD | `/uploads/{path}` | aucune règle | `Controller\Storage\BucketSimulatorController::serve` |

## Administration (EasyAdmin) (16)

| Méthodes | Route | Accès | Contrôleur |
|---|---|---|---|
| GET | `/admin/barcode-image/{id}` | ROLE_ADMIN | `Controller\Admin\BarcodeManagementController::barcodeImage` |
| GET | `/admin/barcode-management/search` | ROLE_ADMIN | `Controller\Admin\BarcodeManagementController::search` |
| ANY | `/admin/barcode-management` | ROLE_ADMIN | `Controller\Admin\BarcodeManagementController::index` |
| ANY | `/admin/pdf/print-barcode/{id}/{copies}` | ROLE_ADMIN | `Controller\Admin\BarcodeManagementController::pdfPrintBarcode` |
| ANY | `/admin/print-barcode/{id}/{copies}` | ROLE_ADMIN | `Controller\Admin\BarcodeManagementController::printBarcode` |
| ANY | `/admin/product-variant/{id}/edit-custom` | ROLE_ADMIN | `Controller\Admin\CustomProductVariantController::edit` |
| ANY | `/admin/product/{id}/specifications` | ROLE_ADMIN | `Controller\Admin\ProductSpecificationController::edit` |
| GET, POST | `/admin/redis/flush` | ROLE_ADMIN | `Controller\Admin\RedisAdminController::flushRedis` |
| GET, POST | `/admin/stripe/connect` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\AdminStripeController::connect` |
| GET, POST | `/admin/stripe/disconnect` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\AdminStripeController::disconnect` |
| GET | `/admin/stripe/success` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\AdminStripeController::success` |
| POST | `/admin/workers/chapters/{id}/fail` | ROLE_SUPER_ADMIN | `Controller\Admin\WorkerAdminController::failChapter` |
| POST | `/admin/workers/chapters/{id}/relaunch` | ROLE_SUPER_ADMIN | `Controller\Admin\WorkerAdminController::relaunchChapter` |
| POST | `/admin/workers/restart` | ROLE_SUPER_ADMIN | `Controller\Admin\WorkerAdminController::restart` |
| GET | `/admin/workers` | ROLE_SUPER_ADMIN | `Controller\Admin\WorkerAdminController::index` |
| ANY | `/admin` | ROLE_ADMIN | `Controller\Admin\DashboardController::index` |

## API Platform (34)

| Méthodes | Route | Accès | Contrôleur |
|---|---|---|---|
| GET | `/api/categories.{_format}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | API Platform (Categories) |
| POST | `/api/categories.{_format}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | API Platform (Categories) |
| DELETE | `/api/categories/{id}.{_format}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | API Platform (Categories) |
| GET | `/api/categories/{id}.{_format}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | API Platform (Categories) |
| PATCH | `/api/categories/{id}.{_format}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | API Platform (Categories) |
| PUT | `/api/categories/{id}.{_format}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | API Platform (Categories) |
| GET | `/api/commandes.{_format}` | ROLE_ADMIN | API Platform (Commande) |
| POST | `/api/commandes.{_format}` | ROLE_ADMIN | API Platform (Commande) |
| DELETE | `/api/commandes/{id}.{_format}` | ROLE_ADMIN | API Platform (Commande) |
| GET | `/api/commandes/{id}.{_format}` | ROLE_ADMIN | API Platform (Commande) |
| PATCH | `/api/commandes/{id}.{_format}` | ROLE_ADMIN | API Platform (Commande) |
| PUT | `/api/commandes/{id}.{_format}` | ROLE_ADMIN | API Platform (Commande) |
| GET, HEAD | `/api/errors/{status}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | API Platform (?) |
| GET | `/api/products.{_format}` | PUBLIC_ACCESS | API Platform (Product) |
| POST | `/api/products.{_format}` | ROLE_ADMIN | API Platform (Product) |
| DELETE | `/api/products/{id}.{_format}` | ROLE_ADMIN | API Platform (Product) |
| GET | `/api/products/{id}.{_format}` | PUBLIC_ACCESS | API Platform (Product) |
| PATCH | `/api/products/{id}.{_format}` | ROLE_ADMIN | API Platform (Product) |
| PUT | `/api/products/{id}.{_format}` | ROLE_ADMIN | API Platform (Product) |
| GET | `/api/validation_errors/{id}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | API Platform (ValidationException) |
| GET | `/api/validation_errors/{id}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | API Platform (ValidationException) |
| GET | `/api/validation_errors/{id}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | API Platform (ValidationException) |
| GET | `/api/vehicle_products.{_format}` | PUBLIC_ACCESS | API Platform (VehicleProduct) |
| POST | `/api/vehicle_products.{_format}` | ROLE_ADMIN | API Platform (VehicleProduct) |
| DELETE | `/api/vehicle_products/{id}.{_format}` | ROLE_ADMIN | API Platform (VehicleProduct) |
| GET | `/api/vehicle_products/{id}.{_format}` | PUBLIC_ACCESS | API Platform (VehicleProduct) |
| PATCH | `/api/vehicle_products/{id}.{_format}` | ROLE_ADMIN | API Platform (VehicleProduct) |
| PUT | `/api/vehicle_products/{id}.{_format}` | ROLE_ADMIN | API Platform (VehicleProduct) |
| GET | `/api/vehicles.{_format}` | PUBLIC_ACCESS | API Platform (Vehicle) |
| POST | `/api/vehicles.{_format}` | ROLE_ADMIN | API Platform (Vehicle) |
| DELETE | `/api/vehicles/{id}.{_format}` | ROLE_ADMIN | API Platform (Vehicle) |
| GET | `/api/vehicles/{id}.{_format}` | PUBLIC_ACCESS | API Platform (Vehicle) |
| PATCH | `/api/vehicles/{id}.{_format}` | ROLE_ADMIN | API Platform (Vehicle) |
| PUT | `/api/vehicles/{id}.{_format}` | ROLE_ADMIN | API Platform (Vehicle) |
