<?php
// src/EventSubscriber/CacheInvalidationSubscriber.php

namespace App\EventSubscriber;

use App\Entity\Adress;
use App\Entity\Caisse;
use App\Entity\Carrier;
use App\Entity\Size;
use App\Entity\Order;
use App\Entity\AdminSettings;
use App\Entity\Entreprise;
use App\Entity\HomeSlider;
use App\Entity\Product;
use App\Entity\Payments;
use App\Entity\ProductVariant;
use App\Entity\SquareConfig;
use App\Entity\Color;
use App\Entity\Style;
use App\Entity\Collections;
use App\Entity\Commande;
use App\Entity\NoteDeFrais;
use App\Entity\Categories;
use App\Entity\TypeFournisseur;
use App\Entity\FraisDePort;
use App\Entity\Transporteur;
use App\Entity\TypeNoteDeFrais;
use App\Entity\TransactionCaisse;
use App\Entity\Fournisseur;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Events;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use App\Services\TenantConnectionProvider;

class CacheInvalidationSubscriber implements EventSubscriber
{
    private CacheInterface $cache;
    private TenantConnectionProvider $tcp;

    /**
     * Tableau de correspondance définissant pour chaque groupe d'entités
     * les clés à supprimer et/ou les tags à invalider.
     */
    private const CACHE_INVALIDATIONS = [
        // Invalidation par tag pour Adress
        [
            'classes' => [Adress::class],
            'delete' => [],
            'invalidate_tags' => ['adresses_user'],
        ],
        // Pour TransactionCaisse : suppression d'une clé et invalidation d'un tag
        [
            'classes' => [TransactionCaisse::class],
            'delete' => ['open_caisse_transactions'],
            'invalidate_tags' => ['caisses_tag'],
        ],
        // Pour AdminSettings
        [
            'classes' => [AdminSettings::class],
            'delete' => ['admin_settings'],
            'invalidate_tags' => [],
        ],
        // Pour Carrier
        [
            'classes' => [Carrier::class],
            'delete' => ['carriers'],
            'invalidate_tags' => [],
        ],
        // Pour Entreprise
        [
            'classes' => [Entreprise::class],
            'delete' => ['entreprise_1'],
            'invalidate_tags' => [],
        ],
        // Pour HomeSlider
        [
            'classes' => [HomeSlider::class],
            'delete' => ['homeslider'],
            'invalidate_tags' => [],
        ],
        // Pour SquareConfig
        [
            'classes' => [SquareConfig::class],
            'delete' => ['square_config'],
            'invalidate_tags' => [],
        ],
        // Pour Product, Style, ProductVariant, Categories : tag products_by_category
        [
            'classes' => [Product::class, Style::class, ProductVariant::class, Categories::class],
            'delete' => [],
            'invalidate_tags' => ['products_by_category'],
        ],
        // Collections
        [
            'classes' => [Collections::class],
            'delete' => ['collections_all'],
            'invalidate_tags' => [],
        ],
        // Colors
        [
            'classes' => [Color::class],
            'delete' => ['colors_all'],
            'invalidate_tags' => [],
        ],
        // Pour Commande et Fournisseur : tag commandes_by_collection
        [
            'classes' => [Commande::class, Fournisseur::class],
            'delete' => [],
            'invalidate_tags' => ['commandes_by_collection'],
        ],
        // Pour dashboard_commande : Commande ou Product
        [
            'classes' => [Commande::class, Product::class],
            'delete' => [],
            'invalidate_tags' => ['dashboard_commande'],
        ],
        // Pour dashboard_collection : Commande, Product, Collections, NoteDeFrais, FraisDePort
        [
            'classes' => [Commande::class, Product::class, Collections::class, NoteDeFrais::class, FraisDePort::class],
            'delete' => [],
            'invalidate_tags' => ['dashboard_collection'],
        ],
        // Pour Fournisseur : suppression de la clé
        [
            'classes' => [Fournisseur::class],
            'delete' => ['fournisseurs_all'],
            'invalidate_tags' => [],
        ],
        // Pour FraisDePort et Transporteur : tag frais_de_port
        [
            'classes' => [FraisDePort::class, Transporteur::class],
            'delete' => [],
            'invalidate_tags' => ['frais_de_port'],
        ],
        // Pour NoteDeFrais : tag notes_de_frais
        [
            'classes' => [NoteDeFrais::class],
            'delete' => [],
            'invalidate_tags' => ['notes_de_frais'],
        ],
        // Pour Order : tags orders_source et orders_user
        [
            'classes' => [Order::class],
            'delete' => [],
            'invalidate_tags' => ['orders_source', 'orders_user'],
        ],
        // Pour Payments
        [
            'classes' => [Payments::class],
            'delete' => [],
            'invalidate_tags' => ['payments'],
        ],
        // Pour products_command
        [
            'classes' => [Product::class, Style::class, ProductVariant::class, Categories::class],
            'delete' => [],
            'invalidate_tags' => ['products_command'],
        ],
        // Pour products_all
        [
            'classes' => [Product::class, Style::class, ProductVariant::class, Categories::class],
            'delete' => [],
            'invalidate_tags' => ['products_all'],
        ],
        // Pour products_by_offer
        [
            'classes' => [Product::class, Style::class, ProductVariant::class, Categories::class],
            'delete' => [],
            'invalidate_tags' => ['products_by_offer'],
        ],
        // Pour product_variants (Style, ProductVariant, Categories)
        [
            'classes' => [Style::class, ProductVariant::class, Categories::class],
            'delete' => [],
            'invalidate_tags' => ['product_variants'],
        ],
        // Pour Size
        [
            'classes' => [Size::class],
            'delete' => ['sizes_all'],
            'invalidate_tags' => [],
        ],
        // Pour Style : suppression de styles_all
        [
            'classes' => [Style::class],
            'delete' => ['styles_all'],
            'invalidate_tags' => [],
        ],
        // Pour Transporteur : suppression de transporteurs_all
        [
            'classes' => [Transporteur::class],
            'delete' => ['transporteurs_all'],
            'invalidate_tags' => [],
        ],
        // Pour TypeFournisseur
        [
            'classes' => [TypeFournisseur::class],
            'delete' => ['api_type_fournisseurs_all'],
            'invalidate_tags' => [],
        ],
        // Pour TypeNoteDeFrais
        [
            'classes' => [TypeNoteDeFrais::class],
            'delete' => ['api_type_note_de_frais_all'],
            'invalidate_tags' => [],
        ],
    ];

    public function __construct(CacheInterface $cache, TenantConnectionProvider $tcp)
    {
        $this->cache = $cache;
        $this->tcp = $tcp;
    }

    public function getSubscribedEvents(): array
    {
        return [
            Events::postPersist,
            Events::postUpdate,
            Events::postRemove,
        ];
    }

    public function postPersist(LifecycleEventArgs $args): void
    {
        $this->invalidateCache($args);
    }

    public function postUpdate(LifecycleEventArgs $args): void
    {
        $this->invalidateCache($args);
    }

    public function postRemove(LifecycleEventArgs $args): void
    {
        $this->invalidateCache($args);
    }

    /**
     * Parcourt la table de correspondance pour appliquer les opérations
     * d'invalidation du cache selon le type de l'entité modifiée.
     */
    private function invalidateCache(LifecycleEventArgs $args): void
    {
        $entity = $args->getEntity();
        $tenantCode = $this->tcp->getTenantCode() ?: 'master';
        $prefix = $tenantCode . ':';       // Pour les clés
        $tagPrefix = $tenantCode;          // Pour les tags (SANS les caractères interdits)

        foreach (self::CACHE_INVALIDATIONS as $operation) {
            foreach ($operation['classes'] as $class) {
                if ($entity instanceof $class) {
                    // Suppression des clés préfixées par tenant
                    foreach ($operation['delete'] as $key) {
                        $this->cache->delete($prefix . $key);
                    }
                    // Invalidation des tags préfixés par tenant (sans “:” !)
                    if (!empty($operation['invalidate_tags']) && $this->cache instanceof TagAwareCacheInterface) {
                        $tags = array_map(fn($tag) => $tagPrefix . $tag, $operation['invalidate_tags']);
                        $this->cache->invalidateTags($tags);
                    }
                    break;
                }
            }
        }
    }
}