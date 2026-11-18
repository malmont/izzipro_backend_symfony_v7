<?php

namespace App\Services\Group\AllShops;

/**
 * Lot G-2 (18/11/2026, contrat validé par le chef, `docs/ecrans-et-contextes.md` § 13) : « Toutes les boutiques ». Le directeur d'un groupe
 * (un administrateur du SIÈGE, connecté avec son propre compte, jamais en entrée) envoie l'en-tête `X-Group-Scope: all` sur le site du siège ;
 * le serveur lit chaque boutique du groupe tour à tour et réunit les réponses. Tout est en lecture seule.
 *
 * Liste blanche : seules les routes de `ROUTES` savent fusionner. Toute autre route reçoit l'en-tête par un refus (400
 * `GROUP_SCOPE_UNSUPPORTED`) : jamais les seules données du siège servies en silence.
 */
final class GroupScope
{
    public const HEADER = 'X-Group-Scope';
    public const VALUE = 'all';
    /** attribut de la requête posé par la garde : le groupe relu à cette requête */
    public const ATTRIBUTE = '_group_scope';

    public const INVALID = 'GROUP_SCOPE_INVALID';
    public const READ_ONLY = 'GROUP_SCOPE_READ_ONLY';
    public const UNSUPPORTED = 'GROUP_SCOPE_UNSUPPORTED';
    public const FORBIDDEN = 'GROUP_SCOPE_FORBIDDEN';

    /**
     * Route => [chemin, module en plus de celui de la route (null : aucun), paramètres refusés dans ce mode, en cache ?].
     * Les tables lues sont vérifiées par `GroupScopeReadTablesTest` : une table lue absente de la liste fait échouer la suite (une écriture
     * dans cette table laisserait une part périmée en cache).
     */
    public const ROUTES = [
        // `sections` (écrans des anciennes statistiques) et `periodId` (une période est propre à un site) ne se réunissent pas
        'gestion_statistiques' => ['path' => '/api/gestion/statistiques', 'module' => null, 'refused' => ['sections', 'periodId'], 'cache' => true],
        'gestion_journee' => ['path' => '/api/gestion/journee', 'module' => null, 'refused' => [], 'cache' => true],
        // les pertes : jamais d'analyse lancée (analyse=0 imposé), jamais en cache (constats écrits en SQL, comme la route seule)
        'gestion_pertes' => ['path' => '/api/gestion/pertes', 'module' => \App\Services\FeatureAccess\ModuleAccessService::LOSS_PREVENTION, 'refused' => [], 'cache' => false],
    ];

    /**
     * Tables lues par chaque route en cache : leur compteur d'écritures forme l'empreinte de la clé. Liste vérifiée par
     * `GroupScopeReadTablesTest` (toute table lue par la route doit y être) et complétée des tables que citent les requêtes de ses
     * services : une base d'essai sans ventes ne lit pas le côté intérieur d'une jointure. Une table en trop ne coûte qu'un calcul de
     * plus ; une table oubliée laisserait une part périmée. Le compte `user` n'y est pas (réécrit à chaque connexion, il ne change aucun chiffre).
     */
    public const TABLES = [
        'gestion_statistiques' => ['address_entreprise', 'booking', 'commande', 'commande_line', 'entreprise', 'gestion_stock_snapshot', 'import_order',
            'import_order_line', 'order', 'order_items', 'product', 'product_variant'],
        'gestion_journee' => ['address_entreprise', 'booking', 'color', 'commande', 'commande_line', 'entreprise', 'frais_de_port', 'gestion_cash_balance',
            'gestion_cash_movement', 'gestion_cash_setting', 'gestion_document', 'gestion_stock_snapshot', 'import_order', 'import_order_line',
            'marketing_campaign', 'note_de_frais', 'order', 'order_items', 'payments', 'pos_gift_card', 'pos_gift_card_transaction', 'pos_return',
            'product', 'product_variant', 'size', 'support_ticket'],
    ];

    /** Plafond de boutiques lues par réponse ; au-delà, `truncated` */
    public const MAX_SHOPS = 20;
    /** Délai d'une boutique (ms) : au-delà, statut `timeout`, les autres répondent */
    public const SHOP_TIMEOUT_MS = 3000;
    /** Budget de la réponse entière (ms) : les boutiques restantes passent en `skipped` */
    public const BUDGET_MS = 15000;
    /** Durée de vie d'une part de boutique en cache (s) ; une écriture dans une table lue la périme aussitôt (empreinte) */
    public const CACHE_TTL = 300;

    public const OK = 'ok';
    public const MODULE_OFF = 'module_off';
    public const ERROR = 'error';
    public const TIMEOUT = 'timeout';
    public const SKIPPED = 'skipped';
    /** deux codes de site sur la même base : lue et comptée une fois, sous le premier code (`sameAs`) */
    public const DUPLICATE = 'duplicate';
    /** Paramètres des statistiques qui entrent dans la lecture et dans la clé du cache (tout autre est ignoré : pas de contournement du cache) */
    public const STATISTICS_PARAMS = ['range', 'from', 'to', 'source', 'locale'];

    /** @return list<string> les chemins des routes qui savent fusionner (lus par `/api/me/contexts`, `group.allShops`) */
    public static function routes(): array
    {
        return array_values(array_map(static fn (array $r): string => $r['path'], self::ROUTES));
    }
}
