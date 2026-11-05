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
        'short_code' => 'Code court de la boutique', 'easypost' => 'Tarifs de livraison (EasyPost)', 'google_places' => 'Adresses (Google Places)',
        'ai_credits' => 'Assistant IA : crédits et consignes', 'mv_ai_quotas' => 'Quotas IA Mémoires Vivantes', 'review_settings' => 'Réglages des avis', 'esg_questions' => 'Questions du diagnostic ESG',
    ];

    /** @return list<array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            ['name' => 'get_settings', 'description' => 'Relit le registre des fonctionnalités du site (valeurs, états, prérequis manquants).', 'input_schema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'get_readiness', 'description' => 'Relit le cheminement de préparation du site (étapes faites, à faire, à obtenir).', 'input_schema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'open_screen', 'description' => 'Indique à l\'administrateur l\'écran du back-office où faire une étape ; rend un lien.', 'input_schema' => ['type' => 'object', 'properties' => ['screen' => ['type' => 'string', 'enum' => array_keys(self::SCREENS)]], 'required' => ['screen']]],
            ['name' => 'set_site_settings', 'description' => 'Propose de modifier des réglages du site (clés de portée site du registre). L\'administrateur confirme avant écriture.', 'input_schema' => ['type' => 'object', 'properties' => ['values' => ['type' => 'object', 'description' => 'clé du registre => valeur, ex. {"commerce.sales_modes": ["sale","rental"]}'], 'summary' => ['type' => 'string', 'description' => 'résumé en une phrase pour l\'administrateur']], 'required' => ['values', 'summary']]],
            ['name' => 'request_feature', 'description' => 'Propose de demander à la plateforme une fonctionnalité non accordée (module ou application). L\'administrateur confirme.', 'input_schema' => ['type' => 'object', 'properties' => ['feature' => ['type' => 'string'], 'message' => ['type' => 'string']], 'required' => ['feature']]],
        ];
    }
}
