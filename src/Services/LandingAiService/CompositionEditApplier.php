<?php

namespace App\Services\LandingAiService;

/**
 * Applique les opérations de retouche proposées par l'IA sur la composition actuelle. Les blocs non visés
 * restent strictement identiques (copie profonde, aucune normalisation).
 *
 *   { "op": "update", "id": "b1", "set": {…}, "unset": ["shadow"] }
 *   { "op": "add", "block": {…}, "after": "b0" | null }     null ou absent : à la fin du tableau
 *   { "op": "remove", "id": "b3" }                            retire aussi les descendants
 *   { "op": "move", "id": "b3", "parentId": "c1" | null, "after": "b0" | null }
 *                                                             déplace un bloc avec ses descendants, sans les recréer
 *   { "op": "section", "set": {…}, "unset": […] }
 *
 * « set » fusionne récursivement les objets simples (mobile, repeat, bindings, translations,
 * translations.<langue>…) : set { mobile: { align: "center" } } garde mobile.w. Les tableaux (links, images,
 * iconCycle, mediaCycle, backgroundCycle…) et les valeurs simples sont remplacés entiers.
 * « unset » accepte des chemins pointés vers une clé d'objet imbriqué : "mobile.w", "bindings.offer".
 */
final class CompositionEditApplier
{
    private const PROTECTED_BLOCK_KEYS = ['id'];
    private const PROTECTED_SECTION_KEYS = ['blocks', 'schemaVersion'];

    /**
     * @param list<mixed> $operations
     * @return array{composition: object, errors: list<array{path: string, message: string}>, touched: list<string>}
     */
    public function apply(object $composition, array $operations): array
    {
        $result = self::copy($composition);
        if (!is_array($result->blocks ?? null)) {
            $result->blocks = [];
        }
        $errors = [];
        $touched = [];

        foreach ($operations as $i => $operation) {
            $path = "operations[$i]";
            if (!is_object($operation)) {
                $errors[] = ['path' => $path, 'message' => 'opération attendue sous forme d\'objet'];
                continue;
            }
            $op = $operation->op ?? null;
            $error = match ($op) {
                'update' => $this->update($result, $operation, $touched),
                'add' => $this->add($result, $operation, $touched),
                'remove' => $this->remove($result, $operation, $touched),
                'move' => $this->move($result, $operation, $touched),
                'section' => $this->section($result, $operation),
                default => 'op inconnue (attendu : update, add, remove, move, section)',
            };
            if ($error !== null) {
                $errors[] = ['path' => is_array($error) ? "$path.{$error[0]}" : $path, 'message' => is_array($error) ? $error[1] : $error];
            }
        }

        return ['composition' => $result, 'errors' => $errors, 'touched' => array_values(array_unique($touched))];
    }

    private function update(object $composition, object $operation, array &$touched): string|array|null
    {
        $index = $this->indexOf($composition, $operation->id ?? null);
        if ($index === null) {
            return ['id', sprintf('bloc « %s » introuvable dans la composition actuelle', (string) ($operation->id ?? ''))];
        }
        $block = $composition->blocks[$index];
        $error = $this->setAndUnset($block, $operation, self::PROTECTED_BLOCK_KEYS);
        $touched[] = $block->id;

        return $error;
    }

    private function add(object $composition, object $operation, array &$touched): string|array|null
    {
        $block = $operation->block ?? null;
        if (!is_object($block) || !is_string($block->id ?? null) || $block->id === '') {
            return ['block', 'bloc à ajouter attendu, avec un identifiant'];
        }
        if ($this->indexOf($composition, $block->id) !== null) {
            return ['block.id', sprintf('identifiant « %s » déjà utilisé', $block->id)];
        }
        $after = $operation->after ?? null;
        $position = count($composition->blocks);
        if ($after !== null) {
            $afterIndex = $this->indexOf($composition, $after);
            if ($afterIndex === null) {
                return ['after', sprintf('bloc « %s » introuvable', is_string($after) ? $after : json_encode($after))];
            }
            $position = $afterIndex + 1;
        }
        array_splice($composition->blocks, $position, 0, [self::copy($block)]);
        $touched[] = $block->id;

        return null;
    }

    private function remove(object $composition, object $operation, array &$touched): string|array|null
    {
        $id = $operation->id ?? null;
        if ($this->indexOf($composition, $id) === null) {
            return ['id', sprintf('bloc « %s » introuvable dans la composition actuelle', (string) $id)];
        }
        // Le bloc et tous ses descendants
        $removed = [$id => true];
        do {
            $added = false;
            foreach ($composition->blocks as $block) {
                if (is_object($block) && isset($removed[$block->parentId ?? null]) && !isset($removed[$block->id ?? null])) {
                    $removed[$block->id] = true;
                    $added = true;
                }
            }
        } while ($added);
        $composition->blocks = array_values(array_filter($composition->blocks, fn ($b) => !is_object($b) || !isset($removed[$b->id ?? null])));
        array_push($touched, ...array_keys($removed));

        return null;
    }

    /**
     * Déplace un bloc (ses descendants le suivent par leur parentId) : nouveau parent facultatif (« parentId » absent :
     * inchangé ; null : racine ; sinon un container qui n'est pas un de ses descendants), puis place le bloc juste
     * après « after » dans le tableau (null ou absent : à la fin). L'ordre du tableau fait l'ordre d'affichage entre
     * frères. Avant cette opération, l'IA déplaçait un bloc par « remove » puis « add » de tout son contenu : coûteux,
     * et le 03/10/2026 seul le « remove » est arrivé, ce qui vidait la section.
     */
    private function move(object $composition, object $operation, array &$touched): string|array|null
    {
        $id = $operation->id ?? null;
        $index = $this->indexOf($composition, $id);
        if ($index === null) {
            return ['id', sprintf('bloc « %s » introuvable dans la composition actuelle', (string) $id)];
        }
        $block = $composition->blocks[$index];
        if (property_exists($operation, 'parentId')) {
            $parent = $operation->parentId;
            if ($parent !== null) {
                $parentIndex = $this->indexOf($composition, $parent);
                if ($parentIndex === null || ($composition->blocks[$parentIndex]->type ?? null) !== 'container') {
                    return ['parentId', sprintf('« %s » n\'est pas un bloc container de la composition', is_string($parent) ? $parent : json_encode($parent))];
                }
                for ($node = $parent, $depth = 0; is_string($node) && $depth < 20; $depth++) {
                    if ($node === $id) {
                        return ['parentId', 'un bloc ne peut pas entrer dans un de ses descendants'];
                    }
                    $node = $composition->blocks[$this->indexOf($composition, $node) ?? -1]->parentId ?? null;
                }
            }
            $block->parentId = $parent;
        }
        $after = $operation->after ?? null;
        if ($after === $id) {
            return ['after', 'un bloc ne peut pas être placé après lui-même'];
        }
        if ($after !== null && $this->indexOf($composition, $after) === null) {
            return ['after', sprintf('bloc « %s » introuvable', is_string($after) ? $after : json_encode($after))];
        }
        array_splice($composition->blocks, $index, 1);
        $position = $after !== null ? $this->indexOf($composition, $after) + 1 : count($composition->blocks);
        array_splice($composition->blocks, $position, 0, [$block]);
        $touched[] = $id;

        return null;
    }

    private function section(object $composition, object $operation): string|array|null
    {
        return $this->setAndUnset($composition, $operation, self::PROTECTED_SECTION_KEYS);
    }

    private function setAndUnset(object $target, object $operation, array $protected): string|array|null
    {
        $set = $operation->set ?? null;
        if ($set !== null && !is_object($set)) {
            return ['set', 'objet attendu'];
        }
        foreach ((array) ($set ?? []) as $key => $value) {
            if (in_array($key, $protected, true)) {
                return ["set.$key", 'propriété non modifiable par une retouche'];
            }
            $target->$key = self::merge($target->$key ?? null, $value);
        }
        $unset = $operation->unset ?? [];
        if (!is_array($unset)) {
            return ['unset', 'liste de propriétés attendue'];
        }
        foreach ($unset as $i => $path) {
            $segments = is_string($path) ? explode('.', $path) : [];
            if ($segments === [] || in_array('', $segments, true) || in_array($segments[0], $protected, true)) {
                return ["unset[$i]", sprintf('propriété « %s » non supprimable', is_string($path) ? $path : json_encode($path))];
            }
            $error = $this->unsetPath($target, $segments);
            if ($error !== null) {
                return ["unset[$i]", $error];
            }
        }

        return null;
    }

    /** Fusion récursive : objet dans objet fusionné, tout le reste (tableaux compris) remplacé */
    private static function merge(mixed $current, mixed $value): mixed
    {
        if (!is_object($current) || !is_object($value)) {
            return self::copy($value);
        }
        $merged = self::copy($current);
        foreach ((array) $value as $key => $item) {
            $merged->$key = self::merge($merged->$key ?? null, $item);
        }

        return $merged;
    }

    /** Retire une clé, éventuellement imbriquée ; chemin absent : sans effet */
    private function unsetPath(object $target, array $segments): ?string
    {
        $last = array_pop($segments);
        $node = $target;
        foreach ($segments as $segment) {
            if (!property_exists($node, $segment)) {
                return null;
            }
            if (!is_object($node->$segment)) {
                return sprintf('« %s » n\'est pas un objet : seuls les objets imbriqués acceptent un chemin pointé', $segment);
            }
            $node = $node->$segment;
        }
        unset($node->$last);

        return null;
    }

    private function indexOf(object $composition, mixed $id): ?int
    {
        if (!is_string($id)) {
            return null;
        }
        foreach ($composition->blocks as $i => $block) {
            if (is_object($block) && ($block->id ?? null) === $id) {
                return $i;
            }
        }

        return null;
    }

    /** Copie profonde fidèle ({} reste un objet, 1.0 reste un nombre décimal) */
    public static function copy(mixed $value): mixed
    {
        return json_decode(json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }
}
