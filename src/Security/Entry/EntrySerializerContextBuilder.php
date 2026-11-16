<?php

namespace App\Security\Entry;

use ApiPlatform\Serializer\SerializerContextBuilderInterface;
use App\Entity\Categories;
use App\Entity\Product;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;

/**
 * Lot AG-3b (relecture indépendante) : les ÉCRITURES d'API Platform (`POST`, `PUT`, `PATCH` d'un article, d'une catégorie) n'ont
 * aucun groupe de sortie : leur réponse rend toute l'entité et ses relations, jusqu'aux lignes de commande d'une variante et à leur
 * client. Pour l'OPÉRATEUR EXTÉRIEUR seulement (requête marquée par la garde du mode), une sortie sans groupe reçoit le groupe de lecture
 * de sa ressource (`product:list`, `category:list` : champs simples, aucune relation imbriquée), ou, pour toute autre ressource, un
 * groupe qu'aucun champ ne porte : rien de plus que l'identifiant. Les comptes du site gardent la réponse d'avant.
 */
#[AsDecorator(decorates: 'api_platform.serializer.context_builder', priority: -100)]
final class EntrySerializerContextBuilder implements SerializerContextBuilderInterface
{
    /** Groupes de lecture bornés, par ressource (ceux de leur lecture d'administrateur) */
    private const READ_GROUPS = [Product::class => ['product:list'], Categories::class => ['category:list']];
    /** Aucun champ ne porte ce groupe */
    public const NONE = 'entry:none';

    public function __construct(#[AutowireDecorated] private readonly SerializerContextBuilderInterface $inner)
    {
    }

    public function createFromRequest(Request $request, bool $normalization, ?array $extractedAttributes = null): array
    {
        $context = $this->inner->createFromRequest($request, $normalization, $extractedAttributes);
        if (!$normalization || !$request->attributes->has(EntryModeGuardSubscriber::ATTRIBUTE) || isset($context['groups'])) {
            return $context;
        }
        $class = (string) ($context['resource_class'] ?? '');
        foreach (self::READ_GROUPS as $resource => $groups) {
            if ($class === $resource || is_subclass_of($class, $resource)) {
                return ['groups' => $groups] + $context;
            }
        }

        return ['groups' => [self::NONE]] + $context;
    }
}
