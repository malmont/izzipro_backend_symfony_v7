<?php

namespace App\Services\Notification;

/**
 * La liste UNIQUE qui classe chaque courriel que le serveur envoie (lot NOTIF, `docs/notifications-courriels.md`).
 *
 * Trois familles, plus les courriels internes à la plateforme :
 * - `indispensable` : la réponse à une action de la personne, ou un document qu'elle attend. Jamais filtré, jamais retardé, jamais compté.
 * - `urgent` : un fait qui demande d'agir vite. Part tout de suite à partir du niveau « Essentiel » ; compte dans le plafond, jamais retenu.
 * - `followup` (suivi) : une information sur l'activité, rangée dans un sujet. La seule famille que les niveaux retiennent ou regroupent.
 * - `internal` : courriel de l'équipe de la plateforme (hors des niveaux d'un site).
 *
 * `SENDERS` dit, fichier par fichier, quels courriels y sont envoyés. Le garde-fou `NotificationCatalogGuardTest` balaie `src/` : un
 * fichier qui envoie un courriel sans être ici fait échouer le test. Un nouveau courriel ne peut donc pas échapper au classement :
 * l'ajouter à `KINDS`, l'inscrire dans `SENDERS`, et, s'il est urgent ou de suivi, le faire passer par `NotificationGate`.
 */
final class NotificationCatalog
{
    public const INDISPENSABLE = 'indispensable';
    public const URGENT = 'urgent';
    public const FOLLOWUP = 'followup';
    public const INTERNAL = 'internal';

    // Sujets des courriels de suivi : au niveau « Normal », au plus un courriel par jour et par sujet
    public const TOPIC_ORDERS = 'orders';
    public const TOPIC_BOOKINGS = 'bookings';
    public const TOPIC_PAYMENTS = 'payments';
    public const TOPIC_LOSS = 'loss';
    public const TOPIC_SUPPORT = 'support';
    public const TOPIC_ENTITLEMENTS = 'entitlements';
    public const TOPIC_MANAGEMENT = 'management';
    public const TOPICS = [self::TOPIC_ORDERS, self::TOPIC_BOOKINGS, self::TOPIC_PAYMENTS, self::TOPIC_LOSS, self::TOPIC_SUPPORT, self::TOPIC_ENTITLEMENTS, self::TOPIC_MANAGEMENT];

    // Courriels urgents et de suivi : ceux qui passent par NotificationGate
    public const ORDER_TO_SHIP = 'order_to_ship';
    public const BOOKING_NEW = 'booking_new';
    public const BOOK_PAYMENT_RECEIVED = 'book_payment_received';
    public const CHAPTER_INVITATION_EXHAUSTED = 'chapter_invitation_exhausted';
    public const LOSS_DIGEST = 'loss_digest';
    public const ENTITLEMENT_FOLLOWUP = 'entitlement_followup';
    public const ENTITLEMENT_URGENT = 'entitlement_urgent';
    public const POS_HEALTH_ALERT = 'pos_health_alert';
    public const WEEKLY_DIGEST = 'weekly_digest';
    /** Lot ABO-3b : un abonné a écrit dans le fil de son abonnement (à l'adresse du site) */
    public const SUBSCRIPTION_MESSAGE = 'subscription_message';
    /** Le courriel qui réunit les courriels de suivi retenus d'un sujet ; son sujet est celui des courriels qu'il porte */
    public const FOLLOWUP_SUMMARY = 'followup_summary';

    /** @var array<string, array{0: string, 1: ?string}> type de courriel → [famille, sujet] */
    public const KINDS = [
        // Suivi
        self::ORDER_TO_SHIP => [self::FOLLOWUP, self::TOPIC_ORDERS],
        self::BOOKING_NEW => [self::FOLLOWUP, self::TOPIC_BOOKINGS],
        self::BOOK_PAYMENT_RECEIVED => [self::FOLLOWUP, self::TOPIC_PAYMENTS],
        self::LOSS_DIGEST => [self::FOLLOWUP, self::TOPIC_LOSS],
        self::ENTITLEMENT_FOLLOWUP => [self::FOLLOWUP, self::TOPIC_ENTITLEMENTS], // essai commencé, droit accordé à la main ou à l'essai, fin d'essai dans 7 jours, accès rétabli, demande enregistrée ou refusée
        self::WEEKLY_DIGEST => [self::FOLLOWUP, null], // le résumé de la semaine lui-même (NotificationWeeklyService) : type coupable dans « Avancé »
        self::FOLLOWUP_SUMMARY => [self::FOLLOWUP, null],
        // Urgent
        self::ENTITLEMENT_URGENT => [self::URGENT, null], // paiement en attente, accès suspendu, accès terminé, fin d'essai demain
        self::POS_HEALTH_ALERT => [self::URGENT, null],
        // Indispensable
        'otp_code' => [self::INDISPENSABLE, null], // connexion, changement d'adresse, suppression de compte
        'email_verification' => [self::INDISPENSABLE, null],
        'password_reset' => [self::INDISPENSABLE, null],
        'email_changed_notice' => [self::INDISPENSABLE, null],
        'account_deleted_notice' => [self::INDISPENSABLE, null],
        'memoires_account_invitation' => [self::INDISPENSABLE, null],
        'support_agent_invitation' => [self::INDISPENSABLE, null],
        'site_admin_invitation' => [self::INDISPENSABLE, null],
        'group_catalog_copied' => [self::INDISPENSABLE, null], // lot G-4 : le directeur du groupe a copié des articles dans le catalogue de la boutique
        'loss_box_plugged' => [self::INDISPENSABLE, null], // lot L6-b : un boîtier vendu est branché dans la boutique, à confirmer ; jamais retenu
        'loss_box_cancelled' => [self::INDISPENSABLE, null], // lot L6-b : la plateforme a annulé l'expédition d'un boîtier déjà branché
        'loss_box_code' => [self::INDISPENSABLE, null], // lot L6-c : le code de l'étiquette d'un boîtier vendu, envoyé une fois, jamais gardé
        'assistance_request' => [self::INDISPENSABLE, null], // lot AG-ASSIST : l'agence apprend qu'un commerçant demande son aide ; jamais retenu // lot AG-2 : l'administrateur désigné d'un site créé par une agence choisit son mot de passe
        'group_invitation' => [self::INDISPENSABLE, null], // lot G-1a : un groupe invite la boutique, qui doit consentir ; jamais retenu
        'group_transfer' => [self::INDISPENSABLE, null], // lot G-5 : un transfert à envoyer, en route, ou en échec d'envoi ; jamais retenu
        'group_same_company' => [self::INDISPENSABLE, null], // lot G-1d : le lien « même entreprise » demandé, confirmé, refusé ou retiré (consentement) ; jamais retenu
        'customer_import_invitation' => [self::INDISPENSABLE, null], // lot IMP-2 : un seul courriel par client repris d'une ancienne boutique, décidé par le commerçant, jamais relancé
        'chapter_invitation' => [self::INDISPENSABLE, null], // garde son propre lien de désabonnement
        // Avis au CLIENT propriétaire d'un livre (invitation épuisée sans réponse) : il parle de son livre à un client du site, pas à
        // l'équipe du site ; les niveaux, qui règlent les courriels de l'espace d'administration, ne s'y appliquent pas
        self::CHAPTER_INVITATION_EXHAUSTED => [self::INDISPENSABLE, null],
        'order_confirmation' => [self::INDISPENSABLE, null],
        'order_shipped' => [self::INDISPENSABLE, null],
        'order_notice' => [self::INDISPENSABLE, null], // remboursement, annulation, retour, reçu
        'subscription_notice' => [self::INDISPENSABLE, null], // les avis d'abonnement d'un client d'une boutique (lot ABO-2 : « séance annulée » par la boutique, Q2)
        // lot ABO-3a : « prestation faite », au client ; comme tout courriel à un client, hors des niveaux (§ 1) ; l'abonné le coupe lui-même
        // (`subscription.done_emails`, PATCH /api/subscriptions/{id}/courriels)
        'subscription_done' => [self::INDISPENSABLE, null],
        // lot ABO-3b : « Vous avez un message » à l'abonné (sans le texte, un par heure au plus), l'invitation d'un intervenant ; à l'équipe, un abonné a écrit
        'subscription_thread' => [self::INDISPENSABLE, null],
        'abo_staff_invitation' => [self::INDISPENSABLE, null],
        self::SUBSCRIPTION_MESSAGE => [self::FOLLOWUP, self::TOPIC_SUPPORT],
        'pos_receipt' => [self::INDISPENSABLE, null],
        'booking_customer' => [self::INDISPENSABLE, null], // accusé de réservation, réservation confirmée, annulée ou modifiée
        'customer_message' => [self::INDISPENSABLE, null], // message écrit par un client : contact, formulaire à étapes, financement (décision A du propriétaire)
        'customer_acknowledgement' => [self::INDISPENSABLE, null],
        'book_payment_link' => [self::INDISPENSABLE, null],
        'book_invoice' => [self::INDISPENSABLE, null],
        'support_reply' => [self::INDISPENSABLE, null],
        'app_key' => [self::INDISPENSABLE, null],
        'test_email' => [self::INDISPENSABLE, null],
        'loss_exercise' => [self::INDISPENSABLE, null], // courriel d'exercice : il répond à un geste du gérant
        'entitlement_purchase' => [self::INDISPENSABLE, null], // droit acheté sur la boutique de la plateforme : confirmation d'achat (décision C)
        // Interne à la plateforme
        'platform_feature_request' => [self::INTERNAL, null],
        'platform_pos_health_copy' => [self::INTERNAL, null],
        'platform_incidents_digest' => [self::INTERNAL, null], // lot INC-1a : courriel du jour des incidents, à l'adresse de la plateforme
        'platform_alert_test' => [self::INTERNAL, null], // 19/11/2026 : courriel d'essai de l'adresse des alertes de la plateforme
        'platform_bug_report_answer' => [self::INDISPENSABLE, null], // lot INC-3 : la plateforme répond au signalement d'un membre du personnel
    ];

    /** @var array<string, list<string>> fichier de `src/` qui envoie un courriel → les types qu'il envoie */
    public const SENDERS = [
        'src/Command/TestTenantSmtpCommand.php' => ['test_email'],
        'src/Controller/Account/RegistrationController.php' => ['email_verification'],
        'src/Controller/Account/ResetPasswordController.php' => ['password_reset'],
        'src/Controller/FinancementApiController.php' => ['customer_message'],
        'src/LossPrevention/Services/LossDigestService.php' => [self::LOSS_DIGEST],
        'src/LossPrevention/Services/LossExerciseService.php' => ['loss_exercise'],
        'src/MemoiresVivantes/Controller/Admin/UserManagementController.php' => ['memoires_account_invitation'],
        'src/MemoiresVivantes/Services/BookPaymentService.php' => ['book_payment_link', self::BOOK_PAYMENT_RECEIVED, 'book_invoice'],
        'src/MemoiresVivantes/Services/ChapterInvitationService.php' => ['chapter_invitation', self::CHAPTER_INVITATION_EXHAUSTED],
        'src/MessageHandler/EntitlementChangedHandler.php' => [self::ENTITLEMENT_FOLLOWUP, self::ENTITLEMENT_URGENT, 'entitlement_purchase', 'platform_feature_request'],
        'src/Security/EmailVerifier.php' => ['email_verification'],
        'src/Services/AccountService/EmailChangeGuard.php' => ['email_changed_notice'],
        'src/Services/ContactService/ContactMailerService.php' => ['customer_message', 'customer_acknowledgement'],
        'src/Services/FormSubmissionService/FormSubmissionNotifier.php' => ['customer_message', 'customer_acknowledgement'],
        'src/Services/Notification/NotificationSummaryService.php' => [self::FOLLOWUP_SUMMARY],
        'src/Services/Notification/NotificationWeeklyService.php' => [self::WEEKLY_DIGEST],
        'src/Services/Agency/AgencySiteInviter.php' => ['site_admin_invitation'],
        'src/Services/Agency/Assistance/AssistanceMailer.php' => ['assistance_request'],
        'src/LossPrevention/Services/LossBoxPluggedNotifier.php' => ['loss_box_plugged', 'loss_box_cancelled'],
        'src/LossPrevention/Services/LossBoxCodeMailer.php' => ['loss_box_code'],
        'src/Services/Group/GroupInvitationMailer.php' => ['group_invitation'],
        'src/Services/Group/GroupCompanyMailer.php' => ['group_same_company'],
        'src/Services/Group/Transfer/GroupTransferMailer.php' => ['group_transfer'],
        'src/Services/Group/Copy/GroupCopyNotifier.php' => ['group_catalog_copied'],
        'src/Services/OrderService/OrderMailerService.php' => ['order_confirmation', self::ORDER_TO_SHIP, 'order_notice'],
        'src/Services/OrderService/OrderShipmentService.php' => ['order_shipped'],
        'src/Services/OtpService.php' => ['otp_code'],
        'src/Import/CustomerImportService.php' => ['customer_import_invitation'],
        'src/Services/Platform/SiteConfigurationPathsService.php' => ['test_email'],
        'src/Services/PosAuditService/PosReceiptMailer.php' => ['pos_receipt'],
        'src/Services/PosStripeService/PosHealthService.php' => [self::POS_HEALTH_ALERT, 'platform_pos_health_copy'],
        'src/Incident/Command/PlatformIncidentsDigestCommand.php' => ['platform_incidents_digest'],
        'src/Services/Platform/PlatformAlertRecipient.php' => ['platform_alert_test'],
        'src/Incident/Services/BugReportMailer.php' => ['platform_bug_report_answer'],
        'src/Services/ReservationService/ReservationMailerService.php' => ['booking_customer', self::BOOKING_NEW, 'customer_message'], // customer_message : demande d'un formulaire à étapes
        'src/Services/SubscriptionService/SubscriptionMailer.php' => ['subscription_notice', 'subscription_done', 'subscription_thread'],
        'src/Abo/Services/AboStaffService.php' => ['abo_staff_invitation'],
        'src/Abo/Services/AboThreadService.php' => [self::SUBSCRIPTION_MESSAGE],
        'src/Support/Services/SupportAgentService.php' => ['support_agent_invitation'],
        'src/Support/Services/SupportMailer.php' => ['support_reply'],
        'src/UseCase/AccountUseCase/AdminEraseCustomerAccountUseCase.php' => ['account_deleted_notice'],
        'src/UseCase/AccountUseCase/DeleteAccountUseCase.php' => ['account_deleted_notice'],
        'src/UseCase/AppKeyUseCase/GenerateAppKeyUseCase.php' => ['app_key'],
        'src/UseCase/ContactUseCase/SubmitContactUseCase.php' => ['customer_message', 'customer_acknowledgement'],
    ];

    /** Fichiers qui tiennent l'envoi lui-même (l'outil, pas un courriel) : hors du classement */
    public const TRANSPORT = [
        'src/Services/EmailConfigurationService/EmailSenderService.php',
        'src/Services/EmailConfigurationService/QueuedMailer.php',
        'src/Services/EmailConfigurationService/TenantMailerFactory.php',
        'src/Security/LoginAuthenticator.php', // envoie le code de connexion par OtpService
    ];

    public static function family(string $kind): string
    {
        return self::KINDS[$kind][0] ?? throw new \InvalidArgumentException("Courriel non classé : $kind (NotificationCatalog::KINDS)");
    }

    public static function topic(string $kind): ?string
    {
        return self::KINDS[$kind][1] ?? null;
    }

    /** Les types que les niveaux gouvernent (urgent ou suivi) : ceux qui passent par NotificationGate */
    public static function governed(): array
    {
        return array_keys(array_filter(self::KINDS, fn (array $k) => in_array($k[0], [self::URGENT, self::FOLLOWUP], true)));
    }
}
