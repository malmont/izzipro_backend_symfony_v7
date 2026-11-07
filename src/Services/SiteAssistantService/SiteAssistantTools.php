<?php

namespace App\Services\SiteAssistantService;

/** Outils de l'assistant de configuration (définitions pour l'API Messages) et écrans qu'il peut indiquer */
final class SiteAssistantTools
{
    /** clé d'écran (même vocabulaire que la page Mon site) => libellé */
    public const SCREENS = [
        'entreprise' => 'Fiche Entreprise (identité, adresse, caisse, SEO)', 'email_configuration' => 'Expéditeur des courriels', 'products' => 'Produits',
        'taxes' => 'Taxes', 'stripe_config' => 'Paiements en ligne (Stripe)', 'pos_stripe' => 'Paiements de caisse (Stripe)', 'carriers' => 'Transporteurs',
        'subscription_plans' => 'Formules d\'abonnement', 'app_keys' => 'Clés d\'activation des applications', 'cash_registers' => 'Postes de caisse',
        'short_code' => 'Code court de la boutique', 'shipping' => 'Livraison : réglages, cheminement, transporteurs', 'product_weights' => 'Poids et dimensions des produits (grille de saisie, estimation par l\'IA)', 'easypost' => 'Clés EasyPost du site', 'google_places' => 'Adresses (Google Places)',
        'ai_credits' => 'Assistant IA : crédits et consignes', 'mv_ai_quotas' => 'Quotas IA Mémoires Vivantes', 'review_settings' => 'Réglages des avis', 'configuration' => 'Configuration : cheminements Stripe, taxes, courriels, avis clients', 'orders' => 'Commandes à traiter (à expédier, paiement à capturer, incomplètes, en transit)', 'reviews' => 'Avis clients à modérer', 'address_entreprise' => 'Adresse de l\'entreprise', 'categories' => 'Catégories du catalogue', 'booking_configuration' => 'Configuration de réservation des produits louables', 'rental_packs' => 'Packs de location (tarifs)', 'users' => 'Utilisateurs et caissiers', 'social_networks' => 'Réseaux sociaux', 'app_profiles' => 'Profils des applications (apparence)', 'currencies' => 'Devises', 'pos' => 'Caisse : réglages et cheminement (ouverture du magasin)', 'product_photo' => 'Créer un produit depuis une photo (fiche rédigée par l\'IA, traduction automatique)', 'denominations' => 'Coupures de la devise (billets et pièces)', 'esg_questions' => 'Questions du diagnostic ESG',
    ];

    /**
     * Outils de lecture et d'orientation : palier « Aide IA » (lot 6, 07/11/2026). Tout le reste (propositions de réglages, estimations,
     * opérations sur les commandes, demandes) exige « IA complète » ; le propriétaire de la plateforme a tout.
     */
    public const HELP_TOOLS = ['get_settings', 'get_readiness', 'open_screen', 'get_domain_status', 'delegate_to_editor', 'get_orders_todo', 'get_order', 'get_configuration_paths'];

    /** Champs de la fiche Entreprise que l'assistant peut proposer de remplir (cheminement complet, 07/11/2026) ; les textes légaux peuvent être rédigés par l'IA */
    public const ENTREPRISE_FIELDS = ['name', 'email', 'tel', 'website', 'LegalNotice', 'conditionOfUse', 'privacyPolicy', 'apropos', 'metaTitle', 'metaDescription', 'seoKeywords', 'tvaIntracommunautaire', 'gstNumber', 'qstNumber', 'siret'];

    /** Outils d'opérations sur une commande web (lot 6) : proposés par l'assistant, appliqués après confirmation par les services des lots 1 à 3 */
    public const ORDER_TOOLS = ['refund_order', 'cancel_order', 'capture_order', 'resend_order_email', 'ship_order'];

    /** @return list<array<string, mixed>> outils d'un palier : help | full */
    public static function definitionsFor(string $tier): array
    {
        $all = self::definitions();

        return $tier === 'help' ? array_values(array_filter($all, fn ($d) => in_array($d['name'], self::HELP_TOOLS, true))) : $all;
    }

    /** @return list<array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            ['name' => 'get_settings', 'description' => 'Relit le registre des fonctionnalités du site (valeurs, états, prérequis manquants).', 'input_schema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'get_readiness', 'description' => 'Relit le cheminement de préparation du site (étapes faites, à faire, à obtenir).', 'input_schema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'open_screen', 'description' => 'Indique à l\'administrateur l\'écran du back-office où faire une étape ; rend un lien.', 'input_schema' => ['type' => 'object', 'properties' => ['screen' => ['type' => 'string', 'enum' => array_keys(self::SCREENS)]], 'required' => ['screen']]],
            ['name' => 'set_site_settings', 'description' => 'Propose de modifier des réglages du site (clés de portée site du registre). L\'administrateur confirme avant écriture.', 'input_schema' => ['type' => 'object', 'properties' => ['values' => ['type' => 'object', 'description' => 'clé du registre => valeur, ex. {"commerce.sales_modes": ["sale","rental"]}'], 'summary' => ['type' => 'string', 'description' => 'résumé en une phrase pour l\'administrateur']], 'required' => ['values', 'summary']]],
            ['name' => 'get_domain_status', 'description' => 'Lit le domaine propre du site : état, enregistrements DNS attendus, procédure.', 'input_schema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'set_custom_domain', 'description' => 'Propose d\'enregistrer le domaine propre du site (acheté par l\'administrateur chez un registraire). L\'administrateur confirme ; ensuite il crée les enregistrements DNS indiqués.', 'input_schema' => ['type' => 'object', 'properties' => ['domain' => ['type' => 'string'], 'summary' => ['type' => 'string']], 'required' => ['domain', 'summary']]],
            ['name' => 'delegate_to_editor', 'description' => 'Confie une tâche à l\'assistant de l\'éditeur du site (front) : composition des pages, panneau de réglages de l\'éditeur, contenus. Ne fait rien côté serveur : le front exécute avec ses propres outils.', 'input_schema' => ['type' => 'object', 'properties' => ['task' => ['type' => 'string', 'description' => 'consigne en langage courant pour l\'assistant de l\'éditeur'], 'section' => ['type' => 'string', 'enum' => ['pages', 'commerce', 'site', 'landing', 'boutique']], 'feature' => ['type' => 'string']], 'required' => ['task', 'section']]],
            ['name' => 'estimate_product_dimensions', 'description' => 'Estime par l\'IA le poids et les dimensions des produits à expédier qui n\'ont pas de fiche (25 au plus par appel, un crédit), puis propose de les enregistrer ; l\'administrateur confirme. Rend les propositions avec leur niveau de confiance.', 'input_schema' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'description' => 'nombre de produits, 25 au plus']]]],
            ['name' => 'request_feature', 'description' => 'Propose de demander à la plateforme une fonctionnalité non accordée (module ou application). L\'administrateur confirme.', 'input_schema' => ['type' => 'object', 'properties' => ['feature' => ['type' => 'string'], 'message' => ['type' => 'string']], 'required' => ['feature']]],
            ['name' => 'set_entreprise_fields', 'description' => 'Propose de remplir ou corriger la fiche Entreprise : nom, courriel, téléphone, site web, textes légaux (LegalNotice = mentions légales, conditionOfUse = conditions générales de vente ou d\'utilisation, privacyPolicy = politique de confidentialité, en HTML simple : p, h2, ul, li, strong ; tu peux les rédiger toi-même d\'après la fiche, le pays et l\'activité), apropos, référencement (metaTitle, metaDescription, seoKeywords), numéros de taxe (gstNumber TPS/TVH, qstNumber TVQ, siret, tvaIntracommunautaire). L\'administrateur confirme avant écriture.', 'input_schema' => ['type' => 'object', 'properties' => ['fields' => ['type' => 'object', 'description' => 'champ => valeur, parmi : ' . implode(', ', self::ENTREPRISE_FIELDS)], 'summary' => ['type' => 'string']], 'required' => ['fields', 'summary']]],
            // Commandes et opérations de support (lot 6, 07/11/2026)
            ['name' => 'get_orders_todo', 'description' => 'Liste les commandes web qui attendent une action : à expédier, paiement à capturer, incomplètes depuis plus d\'une heure, en transit depuis plus de 10 jours (compteurs et premières lignes).', 'input_schema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'get_order', 'description' => 'Lit une commande web par identifiant ou référence : état, paiement, client, articles, expédition, chronologie et actions permises par son état.', 'input_schema' => ['type' => 'object', 'properties' => ['orderId' => ['type' => 'integer'], 'reference' => ['type' => 'string']]]],
            ['name' => 'get_configuration_paths', 'description' => 'Lit les cheminements de configuration (paiements Stripe, taxes, courriels, avis clients) : étapes faites, à faire, en attente de la plateforme.', 'input_schema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'refund_order', 'description' => 'Propose de rembourser une commande web payée par carte (total remboursable par défaut, ou un montant partiel en cents), avec motif et courriel au client. L\'administrateur confirme ; Stripe rembourse réellement.', 'input_schema' => ['type' => 'object', 'properties' => ['orderId' => ['type' => 'integer'], 'amountCents' => ['type' => 'integer', 'description' => 'absent = tout ce qui reste remboursable'], 'reason' => ['type' => 'string'], 'notify' => ['type' => 'boolean']], 'required' => ['orderId']]],
            ['name' => 'cancel_order', 'description' => 'Propose d\'annuler une commande web non expédiée, avec motif ; le paiement capturé est remboursé chez Stripe. L\'administrateur confirme.', 'input_schema' => ['type' => 'object', 'properties' => ['orderId' => ['type' => 'integer'], 'reason' => ['type' => 'string'], 'notify' => ['type' => 'boolean']], 'required' => ['orderId', 'reason']]],
            ['name' => 'capture_order', 'description' => 'Propose de capturer le paiement autorisé mais non capturé d\'une commande web. L\'administrateur confirme.', 'input_schema' => ['type' => 'object', 'properties' => ['orderId' => ['type' => 'integer']], 'required' => ['orderId']]],
            ['name' => 'resend_order_email', 'description' => 'Propose de renvoyer au client un courriel de la commande : confirmation, reçu (receipt) ou avis d\'expédition (shipped). L\'administrateur confirme.', 'input_schema' => ['type' => 'object', 'properties' => ['orderId' => ['type' => 'integer'], 'kind' => ['type' => 'string', 'enum' => ['confirmation', 'receipt', 'shipped']]], 'required' => ['orderId', 'kind']]],
            ['name' => 'ship_order', 'description' => 'Propose de marquer une commande web expédiée par le marchand (transporteur, numéro de suivi, courriel au client). L\'administrateur confirme.', 'input_schema' => ['type' => 'object', 'properties' => ['orderId' => ['type' => 'integer'], 'carrier' => ['type' => 'string'], 'trackingNumber' => ['type' => 'string'], 'trackingUrl' => ['type' => 'string'], 'notify' => ['type' => 'boolean']], 'required' => ['orderId', 'carrier']]],
        ];
    }
}
