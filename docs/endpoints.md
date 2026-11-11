# Index des endpoints du backend

Généré (ne pas modifier à la main) ; après tout ajout ou changement de route, régénérer depuis la racine du dépôt :
`docker exec -w /var/www symfony_app_v2 php bin/console app:docs:endpoints > docs/endpoints.md`
Contexte : `docs/architecture.md` ; détail de chaque module : sa fiche.

- **Accès** : rôle exigé par la première règle `access_control` de `config/packages/security.yaml` qui s'applique
  (`PUBLIC_ACCESS` = sans connexion ; `aucune règle` = pas de contrôle à ce niveau). Les contrôleurs ajoutent parfois
  leurs propres vérifications : `#[IsGranted]` est signalé, les autres (voters, contrôles dans le code) sont dans les fiches.
- Toutes les routes sont résolues pour le tenant de la requête (en-tête `X-Tenant-Host`, voir `docs/architecture.md`).

## Landing Page (20)

| Méthodes | Route | Accès | Contrôleur |
|---|---|---|---|
| GET | `/api/components-config` | PUBLIC_ACCESS | `Controller\LandingPagesController\LandingPagesController::getComponentsConfig` |
| POST | `/api/landingpage-ai/compose` | ROLE_ADMIN | `Controller\LandingAiController\LandingAiController::compose` |
| GET | `/api/landingpage-ai/design-md` | ROLE_ADMIN | `Controller\LandingAiController\LandingAiController::designMd` |
| GET | `/api/landingpage-ai/instructions` | ROLE_ADMIN | `Controller\LandingAiController\LandingAiController::instructions` |
| PUT | `/api/landingpage-ai/instructions` | ROLE_ADMIN | `Controller\LandingAiController\LandingAiController::updateInstructions` |
| GET | `/api/landingpage-ai/jobs/{jobId}` | ROLE_ADMIN | `Controller\LandingAiController\LandingAiController::job` |
| DELETE | `/api/landingpage-ai/memory` | ROLE_ADMIN | `Controller\LandingAiController\LandingAiController::forgetMemory` |
| GET | `/api/landingpage-ai/memory` | ROLE_ADMIN | `Controller\LandingAiController\LandingAiController::memory` |
| POST | `/api/landingpage-ai/usage/{id}/outcome` | ROLE_ADMIN | `Controller\LandingAiController\LandingAiController::outcome` |
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
| GET | `/api/newsletter` | ROLE_ADMIN | `Controller\NewsletterApiController\NewsletterApiController::list` |
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

## Mémoires Vivantes (101)

| Méthodes | Route | Accès | Contrôleur |
|---|---|---|---|
| GET | `/api/memoires/admin/ai-quotas/usage` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\AiQuotaAdminController::usage` |
| GET | `/api/memoires/admin/ai-quotas` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\AiQuotaAdminController::show` |
| PUT | `/api/memoires/admin/ai-quotas` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\AiQuotaAdminController::update` |
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
| GET | `/api/memoires/admin/mobile-users/{userId}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\MobileUserAdminController::show` |
| GET | `/api/memoires/admin/mobile-users` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\MobileUserAdminController::list` |
| GET | `/api/memoires/admin/payments` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\PaymentsAdminController::list` |
| GET | `/api/memoires/admin/pricing` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\PricingAdminController::show` |
| PUT | `/api/memoires/admin/pricing` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\PricingAdminController::update` |
| DELETE | `/api/memoires/admin/questions/{id}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\BookTypeAdminController::deleteQuestion` |
| PUT, PATCH | `/api/memoires/admin/questions/{id}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\BookTypeAdminController::updateQuestion` |
| GET | `/api/memoires/admin/revenue` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `MemoiresVivantes\Controller\Admin\RevenueAdminController::overview` |
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
| GET | `/api/memoires/books/{bookId}/pdf/status` | IS_AUTHENTICATED_FULLY | `MemoiresVivantes\Controller\BookPdfController::pdfStatus` |
| GET | `/api/memoires/books/{id}/chapters` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\ChapterController::listByBook` |
| POST | `/api/memoires/books/{id}/chapters` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\ChapterController::create` |
| POST | `/api/memoires/books/{id}/contributors` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\ContributorController::create` |
| DELETE | `/api/memoires/books/{id}/cover` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\BookController::removeCover` |
| POST | `/api/memoires/books/{id}/cover` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\BookController::uploadCover` |
| GET | `/api/memoires/books/{id}/invitations` | IS_AUTHENTICATED_FULLY | `MemoiresVivantes\Controller\ChapterInvitationController::listForBook` |
| POST | `/api/memoires/books/{id}/payment-link` | IS_AUTHENTICATED_FULLY | `MemoiresVivantes\Controller\BookPaymentApiController::generateAndSendPaymentLink` |
| GET | `/api/memoires/books/{id}/payment-status` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\BookPaymentApiController::getPaymentStatus` |
| POST | `/api/memoires/books/{id}/pdf/generate` | IS_AUTHENTICATED_FULLY | `MemoiresVivantes\Controller\BookPdfController::generatePdfs` |
| GET | `/api/memoires/books/{id}/pdf/preview-cover` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\BookPdfController::previewCover` |
| GET | `/api/memoires/books/{id}/pdf/preview-interior` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\BookPdfController::previewInterior` |
| GET | `/api/memoires/books/{id}/pdf/status` | IS_AUTHENTICATED_FULLY | `MemoiresVivantes\Controller\BookPdfController::pdfStatus` |
| GET | `/api/memoires/books/{id}/print/estimate` | IS_AUTHENTICATED_FULLY | `MemoiresVivantes\Controller\BookPrintApiController::billingEstimate` |
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
| POST | `/api/memoires/chapters/{id}/invitation/complete` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\ChapterInvitationController::complete` |
| POST | `/api/memoires/chapters/{id}/invitations` | IS_AUTHENTICATED_FULLY | `MemoiresVivantes\Controller\ChapterInvitationController::create` |
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
| GET | `/api/memoires/invitations/unsubscribe/{code}/{token}` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\ChapterInvitationController::unsubscribe` |
| POST | `/api/memoires/invitations/{id}/resend` | IS_AUTHENTICATED_FULLY | `MemoiresVivantes\Controller\ChapterInvitationController::resend` |
| DELETE | `/api/memoires/invitations/{id}` | IS_AUTHENTICATED_FULLY | `MemoiresVivantes\Controller\ChapterInvitationController::cancel` |
| DELETE | `/api/memoires/mobile/account` | IS_AUTHENTICATED_FULLY | `MemoiresVivantes\Controller\MobileAccountController::delete` |
| POST | `/api/memoires/mobile/books` | IS_AUTHENTICATED_FULLY | `MemoiresVivantes\Controller\MobileBookController::create` |
| POST | `/api/memoires/mobile/chapters/{id}/unlock` | IS_AUTHENTICATED_FULLY | `MemoiresVivantes\Controller\MobilePurchaseController::unlock` |
| GET | `/api/memoires/mobile/pricing` | IS_AUTHENTICATED_FULLY | `MemoiresVivantes\Controller\MobilePurchaseController::pricing` |
| GET | `/api/memoires/mobile/purchases/unassigned` | IS_AUTHENTICATED_FULLY | `MemoiresVivantes\Controller\MobilePurchaseController::unassignedPurchases` |
| GET | `/api/memoires/mobile/quotas` | IS_AUTHENTICATED_FULLY | `MemoiresVivantes\Controller\MobileQuotaController::show` |
| DELETE | `/api/memoires/photos/{id}` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\ChapterPhotoController::delete` |
| PUT | `/api/memoires/photos/{id}` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\ChapterPhotoController::update` |
| POST | `/api/memoires/print/order` | IS_AUTHENTICATED_FULLY | `MemoiresVivantes\Controller\BookPrintApiController::createOrder` |
| GET | `/api/memoires/print/orders/{orderId}` | IS_AUTHENTICATED_FULLY | `MemoiresVivantes\Controller\BookPrintApiController::getOrder` |
| POST | `/api/memoires/print/payment-intent` | IS_AUTHENTICATED_FULLY | `MemoiresVivantes\Controller\BookPrintApiController::createPaymentIntent` |
| GET | `/api/memoires/questions` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\QuestionController::list` |
| POST | `/api/webhooks/lulu` | PUBLIC_ACCESS | `MemoiresVivantes\Controller\LuluWebhookController::handleLuluWebhook` |
| POST | `/api/webhooks/revenuecat` | PUBLIC_ACCESS | `App\MemoiresVivantes\Controller\RevenueCatWebhookController` |

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
| POST | `/api/reservations/{id}/confirm` | IS_AUTHENTICATED_FULLY | `Controller\ReservationApiController::confirm` |
| GET | `/api/reservations` | PUBLIC_ACCESS | `Controller\ReservationApiController::list` |
| POST | `/api/reservations` | PUBLIC_ACCESS | `Controller\ReservationApiController::create` |
| GET, POST | `/booking-setup/` | aucune règle | `Controller\BookingController\BookingSetupController::auth` |
| ANY | `/booking-setup/configure/{id}` | aucune règle | `Controller\BookingController\BookingSetupController::configure` |
| ANY | `/booking-setup/list` | aucune règle | `Controller\BookingController\BookingSetupController::list` |
| ANY | `/booking-setup/logout` | aucune règle | `Controller\BookingController\BookingSetupController::logout` |

## Boutique et commun (431)

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
| DELETE | `/api/account` | ROLE_USER_INTERNET | `Controller\Account\AccountApiController::delete` |
| DELETE | `/api/admin-settings/presets/{id}` | ROLE_ADMIN | `Controller\AdminSettingsController\AdminSettingsController::deletePreset` |
| GET | `/api/admin-settings/presets` | PUBLIC_ACCESS | `Controller\AdminSettingsController\AdminSettingsController::getPresets` |
| POST | `/api/admin-settings/presets` | ROLE_ADMIN | `Controller\AdminSettingsController\AdminSettingsController::savePreset` |
| GET | `/api/admin-settings` | PUBLIC_ACCESS | `Controller\AdminSettingsController\AdminSettingsController::getAdminSettings` |
| PUT | `/api/admin-settings` | ROLE_ADMIN | `Controller\AdminSettingsController\AdminSettingsController::updateAdminSettings` |
| GET | `/api/adresses/` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\AdressController\AdressApiController::getUserAdresses` |
| GET | `/api/adresses/autocomplete` | PUBLIC_ACCESS | `Controller\AdressController\AddressAutocompleteController::suggest` |
| GET | `/api/adresses/details` | PUBLIC_ACCESS | `Controller\AdressController\AddressAutocompleteController::details` |
| PUT | `/api/adresses/{id}/set-primary` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\AdressController\AdressApiController::setPrimaryAddress` |
| DELETE | `/api/adresses/{id}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\AdressController\AdressApiController::deleteAdress` |
| PUT | `/api/adresses/{id}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\AdressController\AdressApiController::editAdress` |
| GET | `/api/adresses` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\AdressController\AdressApiController::getUserAdresses` |
| POST | `/api/adresses` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\AdressController\AdressApiController::createAdress` |
| DELETE | `/api/app-keys/{id}` | ROLE_ADMIN | `Controller\AppKeyController\AppKeyController::revoke` |
| PUT | `/api/app-keys/{id}` | ROLE_ADMIN | `Controller\AppKeyController\AppKeyController::update` |
| GET | `/api/app-keys` | ROLE_ADMIN | `Controller\AppKeyController\AppKeyController::list` |
| POST | `/api/app-keys` | ROLE_ADMIN | `Controller\AppKeyController\AppKeyController::create` |
| GET | `/api/app-profile/{app}` | PUBLIC_ACCESS | `Controller\AppProfileController\AppProfileController::show` |
| POST | `/api/app/activate` | PUBLIC_ACCESS | `Controller\AppKeyController\AppActivationController::activate` |
| GET | `/api/boutique-settings` | PUBLIC_ACCESS | `Controller\BoutiqueSettingsController\BoutiqueSettingsController::getSettings` |
| PUT | `/api/boutique-settings` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\BoutiqueSettingsController\BoutiqueSettingsController::updateSettings` |
| POST | `/api/caisse/cashfunddeposit` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\CaisseController\CaisseController::cashFundDeposit` |
| POST | `/api/caisse/cashfundwithdraw` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\CaisseController\CaisseController::cashFundWithdraw` |
| POST | `/api/caisse/close` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\CaisseController\CaisseController::closeCaisse` |
| GET | `/api/caisse/denominations` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\CaisseController\CaisseController::denominations` |
| POST | `/api/caisse/deposit` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\CaisseController\CaisseController::deposit` |
| POST | `/api/caisse/drawer-open` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\CaisseController\CaisseController::drawerOpen` |
| POST | `/api/caisse/open` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\CaisseController\CaisseController::openCaisse` |
| GET | `/api/caisse/report/x` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\CaisseController\CaisseController::reportX` |
| GET | `/api/caisse/report/z/{id}` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\CaisseController\CaisseController::reportZ` |
| GET | `/api/caisse/report/z` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\CaisseController\CaisseController::reportZList` |
| GET | `/api/caisse/transactions` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\CaisseController\CaisseController::getOpenCaisseTransactions` |
| POST | `/api/caisse/withdraw` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\CaisseController\CaisseController::withdraw` |
| GET | `/api/caisse` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\CaisseController\CaisseController::getCaisse` |
| GET | `/api/carrier/statistics` | ROLE_ADMIN | `Controller\StatistiqueDashboard\CarrierStatisticsController::getCarrierStatistics` |
| POST | `/api/cart/quote` | PUBLIC_ACCESS | `Controller\CartController\CartQuoteController::quote` |
| PUT | `/api/cash-registers/{id}` | ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\CashRegisterController\CashRegisterController::update` |
| GET | `/api/cash-registers` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\CashRegisterController\CashRegisterController::list` |
| POST | `/api/cash-registers` | ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\CashRegisterController\CashRegisterController::create` |
| GET | `/api/category` | PUBLIC_ACCESS | `Controller\CategoriesControlleur\CategoryController::getCategories` |
| GET | `/api/collections/{id}/commandes` | ROLE_ADMIN | `Controller\CommandeController\CommandeController::getCommandesByCollection` |
| POST | `/api/collections/{id}/commandes` | ROLE_ADMIN | `Controller\CommandeController\CommandeController::createCommande` |
| GET | `/api/collections/{id}/notes-de-frais` | ROLE_ADMIN | `Controller\NoteDeFraisController\NoteDeFraisController::getNotesDeFraisByCollection` |
| POST | `/api/collections/{id}/notes-de-frais` | ROLE_ADMIN | `Controller\NoteDeFraisController\NoteDeFraisController::createNoteDeFrais` |
| GET | `/api/collections/{id}/resultat` | ROLE_ADMIN | `Gestion\Controller\PeriodeController::result` |
| DELETE | `/api/collections/{id}` | ROLE_ADMIN | `Controller\CollectionsController\CollectionController::deleteCollection` |
| PATCH | `/api/collections/{id}` | ROLE_ADMIN | `Controller\CollectionsController\CollectionController::patchCollection` |
| GET | `/api/collections` | ROLE_ADMIN | `Controller\CollectionsController\CollectionController::getCollections` |
| GET | `/api/colors` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\ColorsController\ColorController::getColors` |
| POST | `/api/colors` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\LandingContentController\BoutiqueCatalogController::createColor` |
| GET | `/api/commandes/lignes/a-recevoir` | ROLE_ADMIN | `Controller\CommandeController\CommandeLineController::toReceive` |
| GET | `/api/commandes/products/disponibles` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\CommandeController\CommandeProductAttachmentController::available` |
| DELETE | `/api/commandes/{id}/frais-de-port` | ROLE_ADMIN | `Controller\FraisDePortController\FraisDePortController::deleteFraisDePort` |
| GET | `/api/commandes/{id}/frais-de-port` | ROLE_ADMIN | `Controller\FraisDePortController\FraisDePortController::getFraisDePort` |
| POST | `/api/commandes/{id}/frais-de-port` | ROLE_ADMIN | `Controller\FraisDePortController\FraisDePortController::createFraisDePort` |
| POST | `/api/commandes/{id}/lignes/{lineId}/reception/annuler` | ROLE_ADMIN | `Controller\CommandeController\CommandeLineController::cancelReception` |
| POST | `/api/commandes/{id}/lignes/{lineId}/reception` | ROLE_ADMIN | `Controller\CommandeController\CommandeLineController::receive` |
| DELETE | `/api/commandes/{id}/lignes/{lineId}` | ROLE_ADMIN | `Controller\CommandeController\CommandeLineController::delete` |
| PATCH | `/api/commandes/{id}/lignes/{lineId}` | ROLE_ADMIN | `Controller\CommandeController\CommandeLineController::update` |
| GET | `/api/commandes/{id}/lignes` | ROLE_ADMIN | `Controller\CommandeController\CommandeLineController::list` |
| POST | `/api/commandes/{id}/products/rattacher` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\CommandeController\CommandeProductAttachmentController::attach` |
| DELETE | `/api/commandes/{id}/products/{productId}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\CommandeController\CommandeProductAttachmentController::detach` |
| GET | `/api/commandes/{id}/products` | ROLE_ADMIN | `Controller\ProductController\ProductController::getProductsByCommande` |
| POST | `/api/commandes/{id}/products` | ROLE_ADMIN | `Controller\ProductController\ProductController::createProductByCommande` |
| POST | `/api/commandes/{id}/reception` | ROLE_ADMIN | `Controller\CommandeController\CommandeLineController::receiveAll` |
| DELETE | `/api/commandes/{id}` | ROLE_ADMIN | `Controller\CommandeController\CommandeUpdateController::delete` |
| PATCH | `/api/commandes/{id}` | ROLE_ADMIN | `Controller\CommandeController\CommandeUpdateController::patch` |
| POST | `/api/commandes` | ROLE_ADMIN | `Controller\CommandeController\CommandeController::createCommandeByDate` |
| POST | `/api/createcollections` | ROLE_ADMIN | `Controller\CollectionsController\CollectionController::createCollection` |
| GET | `/api/currencies` | PUBLIC_ACCESS | `App\Controller\CurrencyController\CurrencyController` |
| DELETE | `/api/customization-combinations/{id}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\LandingContentController\BoutiqueCatalogController::deleteCombination` |
| POST | `/api/customization-options/{id}/values` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\LandingContentController\BoutiqueCatalogController::addValue` |
| DELETE | `/api/customization-values/{id}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\LandingContentController\BoutiqueCatalogController::deleteValue` |
| GET | `/api/customization/config/{variantId}` | PUBLIC_ACCESS | `App\Controller\ProductVariantController\CustomizationConfigController` |
| POST | `/api/dashboard/collection/{id}/close` | ROLE_ADMIN | `Controller\DashboardListCollectionController\DashboardListCollectionController::closeCollection` |
| POST | `/api/dashboard/collection/{id}/reopen` | ROLE_ADMIN | `Controller\DashboardListCollectionController\DashboardListCollectionController::reopenCollection` |
| GET | `/api/dashboard/collection/{id}` | ROLE_ADMIN | `Controller\DashboardListCollectionController\DashboardListCollectionController::dashboardCollection` |
| GET | `/api/dashboard/collections-combined` | ROLE_ADMIN | `Controller\DashboardListCollectionController\DashboardListCollectionController::getCombinedCollectionsData` |
| POST | `/api/dashboard/commande/{id}/close` | ROLE_ADMIN | `Controller\DashboardCommandeController\DashboardCommandeController::closeCommand` |
| GET | `/api/dashboard/commande/{id}` | ROLE_ADMIN | `Controller\DashboardCommandeController\DashboardCommandeController::getCommandeMetrics` |
| GET | `/api/entreprise/{id}` | PUBLIC_ACCESS | `Controller\EntrepriseController\EntrepriseController::getEntreprise` |
| PUT | `/api/entreprise/{id}` | ROLE_ADMIN | `Controller\EntrepriseController\EntrepriseController::updateEntreprise` |
| POST | `/api/entreprise` | ROLE_ADMIN | `Controller\EntrepriseController\EntrepriseController::create` |
| GET | `/api/explore-cards` | PUBLIC_ACCESS | `App\Controller\ExploreCardController\ExploreCardController` |
| PUT | `/api/features/order` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\LandingContentController\BoutiqueCatalogController::reorderFeatures` |
| DELETE | `/api/features/{id}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\LandingContentController\BoutiqueCatalogController::deleteFeature` |
| GET | `/api/features` | PUBLIC_ACCESS | `App\Controller\FeatureController\FeatureController` |
| POST | `/api/features` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\LandingContentController\BoutiqueCatalogController::createFeature` |
| GET | `/api/form-submissions/export` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\FormSubmissionController\FormSubmissionAdminController::export` |
| GET | `/api/form-submissions/forms` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\FormSubmissionController\FormSubmissionAdminController::forms` |
| DELETE | `/api/form-submissions/{id}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\FormSubmissionController\FormSubmissionAdminController::delete` |
| GET | `/api/form-submissions/{id}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\FormSubmissionController\FormSubmissionAdminController::show` |
| PATCH | `/api/form-submissions/{id}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\FormSubmissionController\FormSubmissionAdminController::status` |
| GET | `/api/form-submissions` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\FormSubmissionController\FormSubmissionAdminController::index` |
| POST | `/api/form-submissions` | PUBLIC_ACCESS | `Controller\FormSubmissionController\FormSubmissionPublicController::submit` |
| DELETE | `/api/fournisseurs/{id}` | ROLE_ADMIN | `Controller\FournisseurController\FournisseurController::deleteFournisseur` |
| GET | `/api/fournisseurs` | ROLE_ADMIN | `Controller\FournisseurController\FournisseurController::getAllFournisseurs` |
| POST | `/api/fournisseurs` | ROLE_ADMIN | `Controller\FournisseurController\FournisseurController::createFournisseur` |
| GET | `/api/frais/total` | ROLE_ADMIN | `Controller\StatistiqueDashboard\FraisController::getTotalFrais` |
| POST | `/api/gestion/accueil/vignettes/{id}/ecarter` | ROLE_ADMIN | `Gestion\Controller\HomeTilesController::dismiss` |
| GET | `/api/gestion/accueil/vignettes` | ROLE_ADMIN | `Gestion\Controller\HomeTilesController::list` |
| GET | `/api/gestion/campagnes/{id}/rentabilite` | ROLE_ADMIN | `Gestion\Controller\MarketingCampaignController::profitability` |
| DELETE | `/api/gestion/campagnes/{id}` | ROLE_ADMIN | `Gestion\Controller\MarketingCampaignController::delete` |
| GET | `/api/gestion/campagnes/{id}` | ROLE_ADMIN | `Gestion\Controller\MarketingCampaignController::get` |
| PATCH | `/api/gestion/campagnes/{id}` | ROLE_ADMIN | `Gestion\Controller\MarketingCampaignController::update` |
| GET | `/api/gestion/campagnes` | ROLE_ADMIN | `Gestion\Controller\MarketingCampaignController::list` |
| POST | `/api/gestion/campagnes` | ROLE_ADMIN | `Gestion\Controller\MarketingCampaignController::create` |
| POST | `/api/gestion/conseils/actualiser` | ROLE_ADMIN | `Gestion\Controller\GestionAdviceController::refresh` |
| POST | `/api/gestion/conseils/{id}/ecarter` | ROLE_ADMIN | `Gestion\Controller\GestionAdviceController::dismiss` |
| POST | `/api/gestion/conseils/{id}/fait` | ROLE_ADMIN | `Gestion\Controller\GestionAdviceController::done` |
| GET | `/api/gestion/conseils/{id}/mesure` | ROLE_ADMIN | `Gestion\Controller\GestionAdviceController::measure` |
| GET | `/api/gestion/conseils` | ROLE_ADMIN | `Gestion\Controller\GestionAdviceController::list` |
| PATCH | `/api/gestion/documents/{id}/brouillon` | ROLE_ADMIN | `Gestion\Controller\GestionDocumentController::draft` |
| POST | `/api/gestion/documents/{id}/confirmer` | ROLE_ADMIN | `Gestion\Controller\GestionDocumentController::confirm` |
| GET | `/api/gestion/documents/{id}/fichier/{n}` | ROLE_ADMIN | `Gestion\Controller\GestionDocumentController::page` |
| DELETE | `/api/gestion/documents/{id}/lignes/{n}/photo` | ROLE_ADMIN | `Gestion\Controller\GestionDocumentController::linePhotoDelete` |
| GET | `/api/gestion/documents/{id}/lignes/{n}/photo` | ROLE_ADMIN | `Gestion\Controller\GestionDocumentController::linePhotoGet` |
| POST | `/api/gestion/documents/{id}/lignes/{n}/photo` | ROLE_ADMIN | `Gestion\Controller\GestionDocumentController::linePhoto` |
| POST | `/api/gestion/documents/{id}/relire` | ROLE_ADMIN | `Gestion\Controller\GestionDocumentController::reread` |
| DELETE | `/api/gestion/documents/{id}` | ROLE_ADMIN | `Gestion\Controller\GestionDocumentController::delete` |
| GET | `/api/gestion/documents/{id}` | ROLE_ADMIN | `Gestion\Controller\GestionDocumentController::get` |
| GET | `/api/gestion/documents` | ROLE_ADMIN | `Gestion\Controller\GestionDocumentController::list` |
| POST | `/api/gestion/documents` | ROLE_ADMIN | `Gestion\Controller\GestionDocumentController::create` |
| GET | `/api/gestion/exports/achats.csv` | ROLE_ADMIN | `Gestion\Controller\GestionExportController::purchases` |
| GET | `/api/gestion/frais-fixes` | ROLE_ADMIN | `Gestion\Controller\GestionFixedCostController::list` |
| POST | `/api/gestion/locations/grilles/annuler` | ROLE_ADMIN | `Gestion\Controller\GestionRentalController::gridUndo` |
| POST | `/api/gestion/locations/grilles/apercu` | ROLE_ADMIN | `Gestion\Controller\GestionRentalController::gridPreview` |
| POST | `/api/gestion/locations/grilles/appliquer` | ROLE_ADMIN | `Gestion\Controller\GestionRentalController::gridApply` |
| GET | `/api/gestion/locations/grilles/modifications` | ROLE_ADMIN | `Gestion\Controller\GestionRentalController::gridChanges` |
| GET | `/api/gestion/locations/grilles` | ROLE_ADMIN | `Gestion\Controller\GestionRentalController::grids` |
| POST | `/api/gestion/locations/promotions/apercu` | ROLE_ADMIN | `Gestion\Controller\GestionRentalController::promotionPreview` |
| POST | `/api/gestion/locations/promotions/appliquer` | ROLE_ADMIN | `Gestion\Controller\GestionRentalController::promotionApply` |
| GET | `/api/gestion/locations/promotions/lots` | ROLE_ADMIN | `Gestion\Controller\GestionRentalController::promotionBatches` |
| POST | `/api/gestion/locations/promotions/terminer` | ROLE_ADMIN | `Gestion\Controller\GestionRentalController::promotionEnd` |
| GET | `/api/gestion/locations/promotions` | ROLE_ADMIN | `Gestion\Controller\GestionRentalController::promotions` |
| GET | `/api/gestion/locations/statistiques` | ROLE_ADMIN | `Gestion\Controller\GestionRentalController::statistics` |
| GET | `/api/gestion/notifications/journal` | ROLE_ADMIN | `Gestion\Controller\GestionNotificationController::journal` |
| GET | `/api/gestion/notifications/preferences` | ROLE_ADMIN | `Gestion\Controller\GestionNotificationController::preferences` |
| PATCH | `/api/gestion/notifications/preferences` | ROLE_ADMIN | `Gestion\Controller\GestionNotificationController::updatePreferences` |
| POST | `/api/gestion/preparation/avis` | ROLE_ADMIN | `Gestion\Controller\GestionPreparationController::advise` |
| GET | `/api/gestion/preparation/estimations` | ROLE_ADMIN | `Gestion\Controller\GestionPreparationController::estimations` |
| POST | `/api/gestion/preparation/simulation` | ROLE_ADMIN | `Gestion\Controller\GestionPreparationController::simulate` |
| POST | `/api/gestion/preparation/valider` | ROLE_ADMIN | `Gestion\Controller\GestionPreparationController::validate` |
| POST | `/api/gestion/prix/annuler` | ROLE_ADMIN | `Gestion\Controller\GestionPriceController::undo` |
| POST | `/api/gestion/prix/apercu` | ROLE_ADMIN | `Gestion\Controller\GestionPriceController::preview` |
| POST | `/api/gestion/prix/appliquer` | ROLE_ADMIN | `Gestion\Controller\GestionPriceController::apply` |
| GET | `/api/gestion/prix/lots/{id}` | ROLE_ADMIN | `Gestion\Controller\GestionPriceController::batch` |
| GET | `/api/gestion/prix/lots` | ROLE_ADMIN | `Gestion\Controller\GestionPriceController::batches` |
| POST | `/api/gestion/promotions/apercu` | ROLE_ADMIN | `Gestion\Controller\GestionPromotionController::preview` |
| POST | `/api/gestion/promotions/appliquer` | ROLE_ADMIN | `Gestion\Controller\GestionPromotionController::apply` |
| GET | `/api/gestion/promotions/lots/{id}` | ROLE_ADMIN | `Gestion\Controller\GestionPromotionController::batch` |
| GET | `/api/gestion/promotions/lots` | ROLE_ADMIN | `Gestion\Controller\GestionPromotionController::batches` |
| POST | `/api/gestion/promotions/terminer` | ROLE_ADMIN | `Gestion\Controller\GestionPromotionController::end` |
| POST | `/api/gestion/promotions/variantes/apercu` | ROLE_ADMIN | `Gestion\Controller\GestionVariantPromotionController::preview` |
| POST | `/api/gestion/promotions/variantes/appliquer` | ROLE_ADMIN | `Gestion\Controller\GestionVariantPromotionController::apply` |
| GET | `/api/gestion/promotions/variantes/lots/{id}` | ROLE_ADMIN | `Gestion\Controller\GestionVariantPromotionController::batch` |
| GET | `/api/gestion/promotions/variantes/lots` | ROLE_ADMIN | `Gestion\Controller\GestionVariantPromotionController::batches` |
| POST | `/api/gestion/promotions/variantes/terminer` | ROLE_ADMIN | `Gestion\Controller\GestionVariantPromotionController::end` |
| GET | `/api/gestion/promotions/variantes` | ROLE_ADMIN | `Gestion\Controller\GestionVariantPromotionController::current` |
| GET | `/api/gestion/promotions` | ROLE_ADMIN | `Gestion\Controller\GestionPromotionController::current` |
| GET | `/api/gestion/reglages/exercice` | ROLE_ADMIN | `Gestion\Controller\GestionFiscalYearController::settings` |
| PUT | `/api/gestion/reglages/exercice` | ROLE_ADMIN | `Gestion\Controller\GestionFiscalYearController::put` |
| POST | `/api/gestion/reprise/annuler` | ROLE_ADMIN | `Gestion\Controller\RepriseController::cancel` |
| POST | `/api/gestion/reprise/appliquer` | ROLE_ADMIN | `Gestion\Controller\RepriseController::apply` |
| POST | `/api/gestion/reprise/simulation` | ROLE_ADMIN | `Gestion\Controller\RepriseController::simulate` |
| GET | `/api/gestion/reprise` | ROLE_ADMIN | `Gestion\Controller\RepriseController::state` |
| POST | `/api/gestion/statistiques/annee/avis` | ROLE_ADMIN | `Gestion\Controller\GestionStatisticsController::yearAdvice` |
| GET | `/api/gestion/statistiques/annee` | ROLE_ADMIN | `Gestion\Controller\GestionStatisticsController::year` |
| GET | `/api/gestion/statistiques` | ROLE_ADMIN | `Gestion\Controller\GestionStatisticsController::overview` |
| DELETE | `/api/gestion/tresorerie/mouvements/{id}` | ROLE_ADMIN | `Gestion\Controller\GestionCashController::deleteMovement` |
| PATCH | `/api/gestion/tresorerie/mouvements/{id}` | ROLE_ADMIN | `Gestion\Controller\GestionCashController::updateMovement` |
| GET | `/api/gestion/tresorerie/mouvements` | ROLE_ADMIN | `Gestion\Controller\GestionCashController::movements` |
| POST | `/api/gestion/tresorerie/mouvements` | ROLE_ADMIN | `Gestion\Controller\GestionCashController::createMovement` |
| GET | `/api/gestion/tresorerie/rapprochements` | ROLE_ADMIN | `Gestion\Controller\GestionCashController::reconciliations` |
| GET | `/api/gestion/tresorerie/reglages` | ROLE_ADMIN | `Gestion\Controller\GestionCashController::settings` |
| PUT | `/api/gestion/tresorerie/reglages` | ROLE_ADMIN | `Gestion\Controller\GestionCashController::putSettings` |
| POST | `/api/gestion/tresorerie/soldes/{id}/ecart` | ROLE_ADMIN | `Gestion\Controller\GestionCashController::decideGap` |
| DELETE | `/api/gestion/tresorerie/soldes/{id}` | ROLE_ADMIN | `Gestion\Controller\GestionCashController::deleteBalance` |
| PATCH | `/api/gestion/tresorerie/soldes/{id}` | ROLE_ADMIN | `Gestion\Controller\GestionCashController::updateBalance` |
| GET | `/api/gestion/tresorerie/soldes` | ROLE_ADMIN | `Gestion\Controller\GestionCashController::balances` |
| POST | `/api/gestion/tresorerie/soldes` | ROLE_ADMIN | `Gestion\Controller\GestionCashController::saveBalance` |
| GET | `/api/gestion/tresorerie` | ROLE_ADMIN | `Gestion\Controller\GestionCashController::projection` |
| POST | `/api/gestion/voyages/rattacher` | ROLE_ADMIN | `Gestion\Controller\GestionTripController::attach` |
| GET | `/api/gestion/voyages/{id}/suivi` | ROLE_ADMIN | `Gestion\Controller\GestionTripController::follow` |
| DELETE | `/api/gestion/voyages/{id}` | ROLE_ADMIN | `Gestion\Controller\GestionTripController::delete` |
| GET | `/api/gestion/voyages/{id}` | ROLE_ADMIN | `Gestion\Controller\GestionTripController::get` |
| PATCH | `/api/gestion/voyages/{id}` | ROLE_ADMIN | `Gestion\Controller\GestionTripController::update` |
| GET | `/api/gestion/voyages` | ROLE_ADMIN | `Gestion\Controller\GestionTripController::list` |
| POST | `/api/gestion/voyages` | ROLE_ADMIN | `Gestion\Controller\GestionTripController::create` |
| GET | `/api/homeslider` | PUBLIC_ACCESS | `Controller\HomeSliderController\HomeSliderController::getHomeSlider` |
| DELETE | `/api/media/{id}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\MediaApiController\MediaApiController::delete` |
| PATCH | `/api/media/{id}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\MediaApiController\MediaApiController::rename` |
| GET | `/api/media` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\MediaApiController\MediaApiController::list` |
| POST | `/api/media` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\MediaApiController\MediaApiController::upload` |
| GET | `/api/mobile/app-theme` | PUBLIC_ACCESS | `Controller\AppProfileController\AppThemeController::show` |
| DELETE | `/api/notes-de-frais/{id}` | ROLE_ADMIN | `Controller\NoteDeFraisController\NoteDeFraisController::deleteNoteDeFrais` |
| PUT | `/api/notes-de-frais/{id}` | ROLE_ADMIN | `Controller\NoteDeFraisController\NoteDeFraisController::updateNoteDeFrais` |
| POST | `/api/notes-de-frais` | ROLE_ADMIN | `Controller\NoteDeFraisController\NoteDeFraisController::createNoteDeFraisByDate` |
| DELETE | `/api/notifications/appareils/{token}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN + `#[IsGranted(IS_AUTHENTICATED_FULLY)]` | `Controller\NotificationController\PushDeviceController::unregister` |
| POST | `/api/notifications/appareils` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN + `#[IsGranted(IS_AUTHENTICATED_FULLY)]` | `Controller\NotificationController\PushDeviceController::register` |
| GET | `/api/offers` | PUBLIC_ACCESS | `Controller\PlatformController\PublicOffersController::list` |
| GET | `/api/order-statuses` | PUBLIC_ACCESS | `Controller\OrderController\OrderController::getOrderStatuses` |
| POST | `/api/order/cancel/{id}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\OrderController\OrderController::cancelOrder` |
| POST | `/api/order/create-guest` | PUBLIC_ACCESS | `Controller\OrderController\OrderController::createGuestOrder` |
| POST | `/api/order/create-multi-payment` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\OrderController\OrderController::createOrderWithMultiplePayments` |
| POST | `/api/order/create` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\OrderController\OrderController::createOrder` |
| GET | `/api/orders/{id}` | PUBLIC_ACCESS | `Controller\OrderController\OrderController::getCustomerOrder` |
| GET | `/api/orders` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\OrderController\OrderController::getOrders` |
| GET | `/api/ordersuser` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\OrderController\OrderController::getUserOrders` |
| POST | `/api/payment` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\paymentController\PaymentsController::processPayment` |
| GET | `/api/payments/statistics/{source}` | ROLE_ADMIN | `Controller\StatistiqueDashboard\PaymentsController::getPaymentStatistics` |
| GET | `/api/payments` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\paymentController\PaymentsController::getPayments` |
| GET | `/api/periodes/courante` | ROLE_ADMIN | `Gestion\Controller\PeriodeController::current` |
| POST | `/api/periodes/{id}/budget/copier` | ROLE_ADMIN | `Gestion\Controller\GestionBudgetController::copy` |
| GET | `/api/periodes/{id}/budget` | ROLE_ADMIN | `Gestion\Controller\GestionBudgetController::get` |
| PUT | `/api/periodes/{id}/budget` | ROLE_ADMIN | `Gestion\Controller\GestionBudgetController::put` |
| GET | `/api/periodes/{id}/resultat` | ROLE_ADMIN | `Gestion\Controller\PeriodeController::result` |
| POST | `/api/periodes/{id}/simulation/commentaire` | ROLE_ADMIN | `Gestion\Controller\GestionSimulationController::comment` |
| POST | `/api/periodes/{id}/simulation` | ROLE_ADMIN | `Gestion\Controller\GestionSimulationController::simulate` |
| PATCH | `/api/periodes/{id}` | ROLE_ADMIN | `Gestion\Controller\PeriodeController::patch` |
| GET | `/api/periodes` | ROLE_ADMIN | `Gestion\Controller\PeriodeController::list` |
| POST | `/api/platform/billing-events` | ROLE_SUPER_ADMIN + `#[IsGranted(ROLE_SUPER_ADMIN)]` | `Controller\PlatformController\PlatformBillingEventsController::receive` |
| PATCH | `/api/platform/entitlements/{id}` | ROLE_SUPER_ADMIN + `#[IsGranted(ROLE_SUPER_ADMIN)]` | `Controller\PlatformController\PlatformEntitlementsController::act` |
| GET | `/api/platform/entitlements` | ROLE_SUPER_ADMIN + `#[IsGranted(ROLE_SUPER_ADMIN)]` | `Controller\PlatformController\PlatformEntitlementsController::list` |
| POST | `/api/platform/entitlements` | ROLE_SUPER_ADMIN + `#[IsGranted(ROLE_SUPER_ADMIN)]` | `Controller\PlatformController\PlatformEntitlementsController::grant` |
| GET | `/api/platform/events` | ROLE_SUPER_ADMIN + `#[IsGranted(ROLE_SUPER_ADMIN)]` | `Controller\PlatformController\PlatformEntitlementsController::events` |
| PUT | `/api/platform/offers/{code}` | ROLE_SUPER_ADMIN + `#[IsGranted(ROLE_SUPER_ADMIN)]` | `Controller\PlatformController\PlatformOffersController::put` |
| GET | `/api/platform/offers` | ROLE_SUPER_ADMIN + `#[IsGranted(ROLE_SUPER_ADMIN)]` | `Controller\PlatformController\PlatformOffersController::list` |
| PUT | `/api/platform/policies/{feature}` | ROLE_SUPER_ADMIN + `#[IsGranted(ROLE_SUPER_ADMIN)]` | `Controller\PlatformController\PlatformEntitlementsController::policy` |
| GET | `/api/platform/policies` | ROLE_SUPER_ADMIN + `#[IsGranted(ROLE_SUPER_ADMIN)]` | `Controller\PlatformController\PlatformEntitlementsController::policies` |
| PATCH | `/api/platform/requests/{id}` | ROLE_SUPER_ADMIN + `#[IsGranted(ROLE_SUPER_ADMIN)]` | `Controller\PlatformController\PlatformEntitlementsController::decide` |
| GET | `/api/platform/requests` | ROLE_SUPER_ADMIN + `#[IsGranted(ROLE_SUPER_ADMIN)]` | `Controller\PlatformController\PlatformEntitlementsController::requests` |
| GET | `/api/platform/settings` | ROLE_SUPER_ADMIN + `#[IsGranted(ROLE_SUPER_ADMIN)]` | `Controller\PlatformController\PlatformSettingsController::show` |
| PUT | `/api/platform/settings` | ROLE_SUPER_ADMIN + `#[IsGranted(ROLE_SUPER_ADMIN)]` | `Controller\PlatformController\PlatformSettingsController::update` |
| POST | `/api/platform/shipping/balances/{code}/credit` | ROLE_SUPER_ADMIN | `Controller\PlatformController\PlatformShippingController::credit` |
| GET | `/api/platform/shipping/balances/{code}` | ROLE_SUPER_ADMIN | `Controller\PlatformController\PlatformShippingController::balance` |
| PUT | `/api/platform/shipping/settings` | ROLE_SUPER_ADMIN | `Controller\PlatformController\PlatformShippingController::settings` |
| GET | `/api/platform/shipping` | ROLE_SUPER_ADMIN | `Controller\PlatformController\PlatformShippingController::index` |
| GET | `/api/platform/sites` | ROLE_SUPER_ADMIN + `#[IsGranted(ROLE_SUPER_ADMIN)]` | `Controller\PlatformController\PlatformEntitlementsController::sites` |
| POST | `/api/pos/approvals` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosPinController::approve` |
| GET | `/api/pos/archive/{year}` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosArchiveController::download` |
| GET | `/api/pos/audit/verify` | ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosAuditController::verify` |
| GET | `/api/pos/audit` | ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosAuditController::list` |
| GET | `/api/pos/coupons/claims.csv` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosCouponController::claimsCsv` |
| POST | `/api/pos/coupons/claims/mark` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosCouponController::markClaimed` |
| GET | `/api/pos/coupons/claims` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosCouponController::claims` |
| POST | `/api/pos/coupons/parse` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosCouponController::parse` |
| POST | `/api/pos/customers/{id}/erase` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosCustomerController::erase` |
| GET | `/api/pos/customers/{id}/export` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosCustomerController::export` |
| GET | `/api/pos/customers/{id}/history` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosCustomerController::history` |
| POST | `/api/pos/customers/{id}/link` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosCustomerController::link` |
| POST | `/api/pos/customers/{id}/loyalty/adjust` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosCustomerController::adjustLoyalty` |
| POST | `/api/pos/customers/{id}/merge` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosCustomerController::merge` |
| GET | `/api/pos/customers/{id}` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosCustomerController::get` |
| PATCH | `/api/pos/customers/{id}` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosCustomerController::update` |
| GET | `/api/pos/customers` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosCustomerController::search` |
| POST | `/api/pos/customers` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosCustomerController::create` |
| POST | `/api/pos/exchanges/quote` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosReturnController::exchangeQuote` |
| POST | `/api/pos/exchanges` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosReturnController::exchange` |
| GET | `/api/pos/exports/accounting/entries.csv` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosReportingController::entries` |
| GET | `/api/pos/exports/accounting/summary.csv` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosReportingController::summary` |
| GET | `/api/pos/exports/accounting` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosReportingController::accounting` |
| GET | `/api/pos/favorites/pins` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosFavoriteController::pins` |
| PUT | `/api/pos/favorites/pins` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosFavoriteController::replacePins` |
| GET | `/api/pos/favorites` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosFavoriteController::grid` |
| POST | `/api/pos/gift-cards/replace` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosGiftCardController::replace` |
| POST | `/api/pos/gift-cards/{code}/adjust` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosGiftCardController::adjust` |
| POST | `/api/pos/gift-cards/{code}/cancel` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosGiftCardController::cancel` |
| POST | `/api/pos/gift-cards/{code}/cash-out` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosGiftCardController::cashOut` |
| GET | `/api/pos/gift-cards/{code}` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosGiftCardController::lookup` |
| POST | `/api/pos/gift-cards` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosGiftCardController::create` |
| GET | `/api/pos/health` | ROLE_ADMIN | `Controller\PosController\PosHealthController::health` |
| GET | `/api/pos/offline-cases` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosOfflineController::cases` |
| GET | `/api/pos/offline-pack` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosOfflineController::pack` |
| GET | `/api/pos/offline-sales` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosOfflineController::history` |
| POST | `/api/pos/offline-sales` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosOfflineController::sales` |
| POST | `/api/pos/pin-login` | PUBLIC_ACCESS | `Controller\PosController\PosPinController::pinLogin` |
| POST | `/api/pos/pin` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosPinController::setPin` |
| POST | `/api/pos/quote` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosQuoteController::quote` |
| POST | `/api/pos/receipts/email` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosReceiptController::email` |
| POST | `/api/pos/receipts/revoke-link` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosReceiptController::revokeLink` |
| GET | `/api/pos/receipts` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosReceiptController::reprint` |
| POST | `/api/pos/rentals/bookings/{id}/return` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosRentalController::returnBooking` |
| POST | `/api/pos/rentals/deposits/{id}/return` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosRentalController::returnDeposit` |
| GET | `/api/pos/rentals/deposits/{id}` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosRentalController::deposit` |
| GET | `/api/pos/rentals/deposits` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosRentalController::deposits` |
| POST | `/api/pos/rentals/deposits` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosRentalController::collect` |
| GET | `/api/pos/rentals/ongoing` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosRentalController::ongoing` |
| GET | `/api/pos/rentals` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosRentalController::catalogue` |
| GET | `/api/pos/returns/lookup` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosReturnController::lookup` |
| POST | `/api/pos/returns` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosReturnController::create` |
| GET | `/api/pos/settings` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosPinController::settings` |
| GET | `/api/pos/statistics/cashiers` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosReportingController::cashiers` |
| POST | `/api/pos/terminal/connection-token` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosTerminalController::connectionToken` |
| POST | `/api/pos/terminal/payments/{id}/cancel` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosTerminalController::cancel` |
| POST | `/api/pos/terminal/payments/{id}/refund/confirm` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosTerminalController::confirmRefund` |
| POST | `/api/pos/terminal/payments/{id}/refund` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosTerminalController::refund` |
| POST | `/api/pos/terminal/payments/{id}/simulate` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosTerminalController::simulate` |
| GET | `/api/pos/terminal/payments/{id}` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosTerminalController::get` |
| GET | `/api/pos/terminal/payments` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosTerminalController::list` |
| POST | `/api/pos/terminal/payments` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosTerminalController::create` |
| GET | `/api/pos/terminal` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosTerminalController::status` |
| POST | `/api/pos/unlock` | ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\PosController\PosPinController::unlock` |
| GET | `/api/pos/users` | PUBLIC_ACCESS | `Controller\PosController\PosPinController::users` |
| POST | `/api/product-variants/{id}/customization-combinations` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\LandingContentController\BoutiqueCatalogController::addCombination` |
| DELETE | `/api/product-variants/{id}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\LandingContentController\BoutiqueCatalogController::deleteVariant` |
| GET | `/api/products/ai/bulk/plan` | PUBLIC_ACCESS + `#[IsGranted(ROLE_ADMIN)]` | `Controller\ProductAiController\ProductAiController::bulkPlan` |
| GET | `/api/products/ai/bulk/{jobId}` | PUBLIC_ACCESS + `#[IsGranted(ROLE_ADMIN)]` | `Controller\ProductAiController\ProductAiController::bulkStatus` |
| POST | `/api/products/ai/bulk` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\ProductAiController\ProductAiController::bulkStart` |
| POST | `/api/products/ai/create` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\ProductAiController\ProductAiController::create` |
| POST | `/api/products/ai/sheet` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\ProductAiController\ProductAiController::sheet` |
| POST | `/api/products/ai/translate` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\ProductAiController\ProductAiController::translate` |
| GET | `/api/products/by-category` | PUBLIC_ACCESS | `Controller\CategoriesControlleur\CategoryController::getProductsByCategory` |
| GET | `/api/products/by-slug/{slug}` | PUBLIC_ACCESS | `Controller\ProductController\ProductController::getProductBySlug` |
| GET | `/api/products/{id}/reviews/eligibility` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\ReviewController\ReviewController::eligibility` |
| GET | `/api/products/{id}/reviews` | PUBLIC_ACCESS | `Controller\ReviewController\ReviewController::list` |
| POST | `/api/products/{id}/reviews` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_ADMIN | `Controller\ReviewController\ReviewController::submit` |
| GET | `/api/products/{id}/variants` | PUBLIC_ACCESS | `Controller\ProductVariantController\ProductVariantController::getProductVariants` |
| POST | `/api/products/{id}/variants` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\LandingContentController\BoutiqueCatalogController::createVariant` |
| DELETE | `/api/products/{id}` | ROLE_ADMIN | `Controller\ProductController\ProductController::deleteProduct` |
| GET | `/api/products/{id}` | PUBLIC_ACCESS | `Controller\ProductController\ProductController::getProductDetail` |
| GET | `/api/products/{offer}` | PUBLIC_ACCESS | `Controller\ProductController\ProductController::getProductsByOffer` |
| GET | `/api/products` | ROLE_ADMIN | `Controller\ProductController\ProductController::getAllProducts` |
| GET | `/api/productsid/{id}` | PUBLIC_ACCESS | `Controller\ProductController\ProductController::getProductById` |
| GET | `/api/profile` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\Account\ProfileApiController::getProfile` |
| PATCH, PUT | `/api/profile` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\Account\ProfileApiController::updateProfile` |
| GET | `/api/r/{token}` | PUBLIC_ACCESS | `Controller\PosController\PosPublicReceiptController::show` |
| GET | `/api/reviews/mine` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\ReviewController\ReviewController::mine` |
| DELETE | `/api/reviews/{id}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\ReviewController\ReviewController::delete` |
| PUT, PATCH | `/api/reviews/{id}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\ReviewController\ReviewController::update` |
| DELETE | `/api/service-families/{id}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\ServiceFamilyController\ServiceFamilyController::delete` |
| PUT | `/api/service-families/{id}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\ServiceFamilyController\ServiceFamilyController::update` |
| GET | `/api/service-families` | PUBLIC_ACCESS | `Controller\ServiceFamilyController\ServiceFamilyController::index` |
| POST | `/api/service-families` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\ServiceFamilyController\ServiceFamilyController::create` |
| POST | `/api/shipping/buy` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\ShippingController\ShippingController::buy` |
| POST | `/api/shipping/parcels` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\ShippingController\ShippingController::parcels` |
| POST | `/api/shipping/rates` | PUBLIC_ACCESS | `Controller\ShippingController\ShippingController::rates` |
| POST | `/api/shipping/summary` | PUBLIC_ACCESS | `Controller\ShippingController\ShippingController::summary` |
| DELETE | `/api/site-content/demo` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\SiteContentController\SiteContentController::deleteDemo` |
| GET | `/api/site-content/demo` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\SiteContentController\SiteContentController::demo` |
| POST | `/api/site-content/seed` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\SiteContentController\SiteContentController::seed` |
| POST | `/api/site/assistant/actions/{id}/confirm` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\SiteAssistantController\SiteAssistantController::confirm` |
| GET | `/api/site/assistant/credits` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\SiteAssistantController\SiteAssistantController::credits` |
| POST | `/api/site/assistant` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\SiteAssistantController\SiteAssistantController::chat` |
| DELETE | `/api/site/charter/document` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\SiteFeaturesController\SiteCharterController::removeDocument` |
| POST | `/api/site/charter/document` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\SiteFeaturesController\SiteCharterController::document` |
| GET | `/api/site/charter` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\SiteFeaturesController\SiteCharterController::show` |
| PUT | `/api/site/charter` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\SiteFeaturesController\SiteCharterController::update` |
| POST | `/api/site/domain/check` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\SiteFeaturesController\SiteDomainController::check` |
| DELETE | `/api/site/domain` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\SiteFeaturesController\SiteDomainController::delete` |
| GET | `/api/site/domain` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\SiteFeaturesController\SiteDomainController::show` |
| PUT | `/api/site/domain` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\SiteFeaturesController\SiteDomainController::put` |
| POST | `/api/site/features/{key}/request` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\SiteFeaturesController\SiteFeaturesController::request` |
| GET | `/api/site/features` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\SiteFeaturesController\SiteFeaturesController::show` |
| PUT | `/api/site/features` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\SiteFeaturesController\SiteFeaturesController::update` |
| GET | `/api/site/help/{key}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\HelpController\HelpController::page` |
| GET | `/api/site/help` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\HelpController\HelpController::list` |
| GET | `/api/site/readiness` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\SiteFeaturesController\SiteReadinessController::show` |
| GET | `/api/site/shipping` | ROLE_ADMIN | `Controller\SiteFeaturesController\SiteShippingController::index` |
| GET | `/api/sizes` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\SizesController\SizeController::getSizes` |
| POST | `/api/sizes` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\LandingContentController\BoutiqueCatalogController::createSize` |
| GET | `/api/social-networks` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\SocialNetworkController\SocialNetworkController::list` |
| GET | `/api/statistiques/chiffre-affaires/{source}` | ROLE_ADMIN | `Controller\StatistiqueDashboard\StatistiqueGeneraleController::getChiffreAffaires` |
| GET | `/api/statistiques/nombre-commandes/{source}` | ROLE_ADMIN | `Controller\StatistiqueDashboard\StatistiqueGeneraleController::getOrderStatistics` |
| GET | `/api/statistiques/panier-moyen/{source}` | ROLE_ADMIN | `Controller\StatistiqueDashboard\StatistiqueGeneraleController::getAverageOrderValueStatistics` |
| GET | `/api/stock-evolution` | ROLE_ADMIN | `Controller\StockStatisticsController\StockStatisticsController::getStockEvolution` |
| GET | `/api/stock-value` | ROLE_ADMIN | `Controller\StockStatisticsController\StockValueController::getStockValues` |
| GET | `/api/stripe-config` | PUBLIC_ACCESS | `Controller\paymentController\PaymentsController::getStripeConfig` |
| POST | `/api/stripe/create-intent` | PUBLIC_ACCESS | `Controller\paymentController\PaymentsController::createStripePaymentIntent` |
| POST | `/api/stripe/webhook` | PUBLIC_ACCESS | `Controller\StripeController\StripeWebhookController::handleWebhook` |
| GET | `/api/styles` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\StyleController\StyleController::getStyles` |
| GET | `/api/subscription-plans` | PUBLIC_ACCESS | `Controller\SubscriptionController\SubscriptionController::plans` |
| POST | `/api/subscriptions/portal-session` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN + `#[IsGranted(ROLE_USER_INTERNET)]` | `Controller\SubscriptionController\SubscriptionController::portal` |
| POST | `/api/subscriptions/{id}/cancel` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN + `#[IsGranted(ROLE_USER_INTERNET)]` | `Controller\SubscriptionController\SubscriptionController::cancel` |
| POST | `/api/subscriptions/{id}/change-plan` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN + `#[IsGranted(ROLE_USER_INTERNET)]` | `Controller\SubscriptionController\SubscriptionController::changePlan` |
| POST | `/api/subscriptions/{id}/pause` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN + `#[IsGranted(ROLE_USER_INTERNET)]` | `Controller\SubscriptionController\SubscriptionController::pause` |
| POST | `/api/subscriptions/{id}/resume` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN + `#[IsGranted(ROLE_USER_INTERNET)]` | `Controller\SubscriptionController\SubscriptionController::resume` |
| GET | `/api/subscriptions/{id}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN + `#[IsGranted(ROLE_USER_INTERNET)]` | `Controller\SubscriptionController\SubscriptionController::get` |
| GET | `/api/subscriptions` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN + `#[IsGranted(ROLE_USER_INTERNET)]` | `Controller\SubscriptionController\SubscriptionController::list` |
| POST | `/api/subscriptions` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN + `#[IsGranted(ROLE_USER_INTERNET)]` | `Controller\SubscriptionController\SubscriptionController::subscribe` |
| GET | `/api/taxes/monthly` | ROLE_ADMIN | `Controller\StatistiqueDashboard\TaxController::getMonthlyTaxes` |
| DELETE | `/api/transporteurs/{id}` | ROLE_ADMIN | `Controller\TransporteurController\TransporteurController::deleteTransporteur` |
| GET | `/api/transporteurs` | ROLE_ADMIN | `Controller\TransporteurController\TransporteurController::getTransporteurs` |
| POST | `/api/transporteurs` | ROLE_ADMIN | `Controller\TransporteurController\TransporteurController::createTransporteur` |
| GET | `/api/type-fournisseurs` | ROLE_ADMIN | `Controller\TypeFournisseurController\TypeFournisseurController::list` |
| GET | `/api/type-note-de-frais` | ROLE_ADMIN | `Controller\TypeNoteDeFraisController\TypeNoteDeFraisController::list` |
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

## Authentification et comptes (10)

| Méthodes | Route | Accès | Contrôleur |
|---|---|---|---|
| POST | `/api/login` | PUBLIC_ACCESS | `Controller\Account\SecurityController::loginApi` |
| POST | `/api/logout` | PUBLIC_ACCESS | `Controller\Account\SecurityController::logoutWeb` |
| POST | `/api/otp-verify` | PUBLIC_ACCESS | `Controller\Account\OtpApiController::otpVerifyApi` |
| POST | `/api/password-reset/confirm` | PUBLIC_ACCESS | `Controller\Account\ResetPasswordController::confirmPasswordResetApi` |
| POST | `/api/password-reset/request` | PUBLIC_ACCESS | `Controller\Account\ResetPasswordController::requestPasswordReset` |
| POST | `/api/register` | PUBLIC_ACCESS | `Controller\Account\RegistrationController::registerApi` |
| POST | `/api/resend-verification` | PUBLIC_ACCESS | `Controller\Account\RegistrationController::resendVerificationEmail` |
| POST | `/api/token/refresh` | PUBLIC_ACCESS | `Controller\Account\SecurityController::refreshToken` |
| GET | `/api/validate-token` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | `Controller\Account\SecurityController::validateToken` |
| GET | `/verify/email` | aucune règle | `Controller\Account\RegistrationController::verifyEmail` |

## Tenants (6)

| Méthodes | Route | Accès | Contrôleur |
|---|---|---|---|
| GET | `/api/health` | PUBLIC_ACCESS | `Controller\HealthController::index` |
| GET | `/api/tenant/check-mobile` | PUBLIC_ACCESS | `Controller\TenantSetupController\TenantController::checkMobile` |
| GET, POST | `/api/tenant/check` | PUBLIC_ACCESS | `Controller\TenantSetupController\TenantController::check` |
| GET | `/api/tenant/hosts` | PUBLIC_ACCESS | `Controller\TenantSetupController\TenantController::hosts` |
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

## Administration (EasyAdmin) (91)

| Méthodes | Route | Accès | Contrôleur |
|---|---|---|---|
| GET | `/admin/aide/{key}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\HelpAdminController::page` |
| GET | `/admin/aide` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\HelpAdminController::index` |
| GET | `/admin/apercu/{code}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\ApercuController::show` |
| POST | `/admin/app-keys/generate` | ROLE_ADMIN | `Controller\Admin\AppKeyAdminController::generate` |
| POST | `/admin/app-keys/{id}/revoke` | ROLE_ADMIN | `Controller\Admin\AppKeyAdminController::revoke` |
| GET | `/admin/app-keys` | ROLE_ADMIN | `Controller\Admin\AppKeyAdminController::index` |
| GET | `/admin/barcode-image/{id}` | ROLE_ADMIN | `Controller\Admin\BarcodeManagementController::barcodeImage` |
| GET | `/admin/barcode-management/search` | ROLE_ADMIN | `Controller\Admin\BarcodeManagementController::search` |
| ANY | `/admin/barcode-management` | ROLE_ADMIN | `Controller\Admin\BarcodeManagementController::index` |
| GET | `/admin/caisse` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\PosConsoleAdminController::index` |
| GET | `/admin/commandes/a-traiter` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\OrderDeskAdminController::todo` |
| GET | `/admin/commandes/{id}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\OrderDeskAdminController::sheet` |
| POST | `/admin/configuration/courriel-essai` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\ConfigurationConsoleAdminController::emailTest` |
| POST | `/admin/configuration/referentiels` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\ConfigurationConsoleAdminController::defaults` |
| GET | `/admin/configuration` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\ConfigurationConsoleAdminController::index` |
| POST | `/admin/contenu-exemple/effacer/{resource}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\SiteDemoContentAdminController::delete` |
| GET | `/admin/controles` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\ControlsConsoleAdminController::index` |
| GET, POST | `/admin/livraison/poids` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\ShippingConsoleAdminController::weights` |
| POST | `/admin/livraison/regler` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\ShippingConsoleAdminController::settle` |
| GET | `/admin/livraison` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\ShippingConsoleAdminController::index` |
| POST | `/admin/location/configurer/{productId}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\RentalConfiguratorAdminController::save` |
| GET | `/admin/location/configurer` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\RentalConfiguratorAdminController::index` |
| GET | `/admin/location` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\RentalConsoleAdminController::index` |
| GET | `/admin/memoires/ai-quotas` | ROLE_ADMIN | `Controller\Admin\Memoires\AiQuotaPageController::index` |
| POST | `/admin/memoires/ai-quotas` | ROLE_ADMIN | `Controller\Admin\Memoires\AiQuotaPageController::save` |
| GET | `/admin/mobile-theme` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\AppThemeShortcutController::open` |
| POST | `/admin/mon-site/assistant/actions/{id}/confirm` | ROLE_ADMIN | `Controller\Admin\SiteConsoleAdminController::assistantConfirm` |
| POST | `/admin/mon-site/assistant` | ROLE_ADMIN | `Controller\Admin\SiteConsoleAdminController::assistant` |
| POST | `/admin/mon-site/demander/{key}` | ROLE_ADMIN | `Controller\Admin\SiteConsoleAdminController::ask` |
| POST | `/admin/mon-site/domaine` | ROLE_ADMIN | `Controller\Admin\SiteConsoleAdminController::domain` |
| GET | `/admin/mon-site/forfait` | ROLE_ADMIN | `Controller\Admin\SiteConsoleAdminController::plan` |
| POST | `/admin/mon-site/regler` | ROLE_ADMIN | `Controller\Admin\SiteConsoleAdminController::settle` |
| GET | `/admin/mon-site` | ROLE_ADMIN | `Controller\Admin\SiteConsoleAdminController::index` |
| GET, POST | `/admin/orders/{id}/adresse` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\OrderReturnAdminController::address` |
| GET, POST | `/admin/orders/{id}/annuler` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\OrderOperationsAdminController::cancel` |
| POST | `/admin/orders/{id}/capturer` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\OrderOperationsAdminController::capture` |
| GET, POST | `/admin/orders/{id}/courriels` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\OrderOperationsAdminController::emails` |
| GET, POST | `/admin/orders/{id}/etiquette` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\OrderLabelAdminController::label` |
| GET, POST | `/admin/orders/{id}/expedier` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\OrderShipmentAdminController::ship` |
| POST | `/admin/orders/{id}/livree` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\OrderShipmentAdminController::deliver` |
| GET, POST | `/admin/orders/{id}/rembourser` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\OrderOperationsAdminController::refund` |
| GET, POST | `/admin/orders/{id}/retour` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\OrderReturnAdminController::return` |
| ANY | `/admin/pdf/print-barcode/{id}/{copies}` | ROLE_ADMIN | `Controller\Admin\BarcodeManagementController::pdfPrintBarcode` |
| POST | `/admin/plateforme/accorder` | ROLE_ADMIN | `Controller\Admin\PlatformConsoleAdminController::grant` |
| POST | `/admin/plateforme/boutique` | ROLE_ADMIN | `Controller\Admin\PlatformConsoleAdminController::shop` |
| POST | `/admin/plateforme/demande/{id}/{decision}` | ROLE_ADMIN | `Controller\Admin\PlatformConsoleAdminController::request` |
| POST | `/admin/plateforme/droit/{id}/{action}` | ROLE_ADMIN | `Controller\Admin\PlatformConsoleAdminController::act` |
| POST | `/admin/plateforme/ia-credits` | ROLE_ADMIN | `Controller\Admin\PlatformConsoleAdminController::aiCredits` |
| POST | `/admin/plateforme/ia-defauts` | ROLE_ADMIN | `Controller\Admin\PlatformConsoleAdminController::aiDefaults` |
| POST | `/admin/plateforme/livraison/credit` | ROLE_ADMIN | `Controller\Admin\PlatformConsoleAdminController::shippingCredit` |
| POST | `/admin/plateforme/livraison/frais` | ROLE_ADMIN | `Controller\Admin\PlatformConsoleAdminController::shippingFees` |
| POST | `/admin/plateforme/mode` | ROLE_ADMIN | `Controller\Admin\PlatformConsoleAdminController::switchMode` |
| POST | `/admin/plateforme/offre/{code}` | ROLE_ADMIN | `Controller\Admin\PlatformConsoleAdminController::offer` |
| POST | `/admin/plateforme/politique/{feature}` | ROLE_ADMIN | `Controller\Admin\PlatformConsoleAdminController::policy` |
| GET | `/admin/plateforme` | ROLE_ADMIN | `Controller\Admin\PlatformConsoleAdminController::index` |
| GET, POST | `/admin/pos/archive` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\PosArchiveAdminController::index` |
| GET, POST | `/admin/pos/coupons` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\PosCouponAdminController::index` |
| GET, POST | `/admin/pos/exports` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\PosAccountingExportController::index` |
| GET | `/admin/pos/stripe/return` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\PosStripeAdminController::return` |
| GET, POST | `/admin/pos/stripe` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\PosStripeAdminController::index` |
| ANY | `/admin/print-barcode/{id}/{copies}` | ROLE_ADMIN | `Controller\Admin\BarcodeManagementController::printBarcode` |
| ANY | `/admin/product-variant/{id}/edit-custom` | ROLE_ADMIN | `Controller\Admin\CustomProductVariantController::edit` |
| ANY | `/admin/product/{id}/specifications` | ROLE_ADMIN | `Controller\Admin\ProductSpecificationController::edit` |
| GET, POST | `/admin/produits/depuis-photo` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\ProductPhotoAdminController::index` |
| GET | `/admin/produits/ia-en-lot/etat/{jobId}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\ProductAiBulkAdminController::status` |
| GET, POST | `/admin/produits/ia-en-lot` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\ProductAiBulkAdminController::index` |
| POST | `/admin/produits/{id}/ia/{action}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\ProductAiAdminController::run` |
| GET | `/admin/produits/{id}/ia` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\ProductAiAdminController::index` |
| POST | `/admin/produits/{id}/photos/{action}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\ProductPhotosAdminController::act` |
| GET | `/admin/produits/{id}/photos` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\ProductPhotosAdminController::index` |
| GET, POST | `/admin/redis/flush` | ROLE_SUPER_ADMIN | `Controller\Admin\RedisAdminController::flushRedis` |
| GET, POST | `/admin/short-code` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\ShortCodeAdminController::index` |
| POST | `/admin/site/configurer/composer-boutique` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\SiteSetupAdminController::composeShop` |
| POST | `/admin/site/configurer/composer` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\SiteSetupAdminController::compose` |
| POST | `/admin/site/configurer/composition/{jobId}/appliquer` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\SiteSetupAdminController::apply` |
| GET | `/admin/site/configurer/composition/{jobId}` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\SiteSetupAdminController::composition` |
| GET | `/admin/site/configurer` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\SiteSetupAdminController::index` |
| POST | `/admin/site/configurer` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\SiteSetupAdminController::save` |
| GET, POST | `/admin/stripe/connect` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\AdminStripeController::connect` |
| GET, POST | `/admin/stripe/disconnect` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\AdminStripeController::disconnect` |
| GET | `/admin/stripe/success` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\AdminStripeController::success` |
| POST | `/admin/super-admin/appliquer` | ROLE_SUPER_ADMIN | `Controller\Admin\SuperAdminAccountController::apply` |
| GET | `/admin/super-admin` | ROLE_SUPER_ADMIN | `Controller\Admin\SuperAdminAccountController::index` |
| POST | `/admin/technique/seuils` | ROLE_SUPER_ADMIN | `Controller\Admin\TechDashboardAdminController::thresholds` |
| GET | `/admin/technique` | ROLE_SUPER_ADMIN | `Controller\Admin\TechDashboardAdminController::index` |
| GET, POST | `/admin/users/{id}/effacer` | ROLE_ADMIN + `#[IsGranted(ROLE_ADMIN)]` | `Controller\Admin\CustomerAccountEraseAdminController::confirm` |
| POST | `/admin/workers/chapters/{id}/fail` | ROLE_SUPER_ADMIN | `Controller\Admin\WorkerAdminController::failChapter` |
| POST | `/admin/workers/chapters/{id}/relaunch` | ROLE_SUPER_ADMIN | `Controller\Admin\WorkerAdminController::relaunchChapter` |
| POST | `/admin/workers/restart` | ROLE_SUPER_ADMIN | `Controller\Admin\WorkerAdminController::restart` |
| GET | `/admin/workers` | ROLE_SUPER_ADMIN | `Controller\Admin\WorkerAdminController::index` |
| ANY | `/admin` | ROLE_ADMIN | `Controller\Admin\DashboardController::index` |

## API Platform (28)

| Méthodes | Route | Accès | Contrôleur |
|---|---|---|---|
| GET | `/api/categories.{_format}` | ROLE_ADMIN | API Platform (Categories) |
| POST | `/api/categories.{_format}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | API Platform (Categories) |
| DELETE | `/api/categories/{id}.{_format}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | API Platform (Categories) |
| GET | `/api/categories/{id}.{_format}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | API Platform (Categories) |
| PATCH | `/api/categories/{id}.{_format}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | API Platform (Categories) |
| PUT | `/api/categories/{id}.{_format}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | API Platform (Categories) |
| GET, HEAD | `/api/errors/{status}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | API Platform (?) |
| GET | `/api/products.{_format}` | ROLE_ADMIN | API Platform (Product) |
| POST | `/api/products.{_format}` | ROLE_ADMIN | API Platform (Product) |
| DELETE | `/api/products/{id}.{_format}` | ROLE_ADMIN | API Platform (Product) |
| GET | `/api/products/{id}.{_format}` | PUBLIC_ACCESS | API Platform (Product) |
| PATCH | `/api/products/{id}.{_format}` | ROLE_ADMIN | API Platform (Product) |
| PUT | `/api/products/{id}.{_format}` | ROLE_ADMIN | API Platform (Product) |
| GET | `/api/validation_errors/{id}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | API Platform (ValidationException) |
| GET | `/api/validation_errors/{id}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | API Platform (ValidationException) |
| GET | `/api/validation_errors/{id}` | ROLE_USER_INTERNET ou ROLE_USER_POS ou ROLE_POS_MANAGER ou ROLE_ADMIN | API Platform (ValidationException) |
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
