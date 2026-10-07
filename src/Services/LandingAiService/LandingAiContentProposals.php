<?php

namespace App\Services\LandingAiService;

use App\Services\LandingContentService\LandingContentEditor;
use App\Services\LandingContentService\PresentationGroupEditor;

/**
 * Contrôle des propositions de l'assistant sur les données du site (jamais appliquées ici) :
 * - contentChanges : [{ resource, id, fields }] : modification d'un contenu affiché par la section ;
 * - groupChanges : [{ groupId, add: [{ fields, after }], remove: [ids], order: [ids] }] : composition du groupe ;
 * - limits : [{ request, reason, howTo }] : parties de la demande que l'assistant ne peut pas faire, et comment
 *   l'administrateur peut les faire.
 * Seuls les contenus donnés à l'assistant (la donnée de la section et, pour un groupe, ses présentations) peuvent être
 * visés. Les valeurs suivent les mêmes règles que les routes de modification (LandingContentEditor::validate).
 */
final class LandingAiContentProposals
{
    public const MAX_CHANGES = 20;
    public const MAX_LIMITS = 6;

    public function __construct(private readonly LandingContentEditor $editor)
    {
    }

    /**
     * @param array|null $content contenu donné à l'assistant (LandingAiContentContext::editableContent)
     * @return array{0: array{contentChanges: list<array>, groupChanges: list<array>, limits: list<array>}, 1: list<array{path: string, message: string}>}
     */
    public function check(object $input, ?array $content): array
    {
        $errors = [];
        $allowed = [];
        if ($content !== null) {
            $allowed[$content['resource']][] = $content['id'];
            foreach ($content['presentations'] ?? [] as $presentation) {
                $allowed['presentations'][] = $presentation['id'];
            }
        }

        $contentChanges = [];
        foreach (array_slice(is_array($input->contentChanges ?? null) ? $input->contentChanges : [], 0, self::MAX_CHANGES) as $i => $change) {
            $path = "contentChanges[$i]";
            $resource = is_object($change) ? ($change->resource ?? null) : null;
            $id = is_object($change) ? ($change->id ?? null) : null;
            if (!is_string($resource) || !is_int($id) || !in_array($id, $allowed[$resource] ?? [], true)) {
                $errors[] = ['path' => $path, 'message' => 'resource et id d\'un contenu listé dans « contenus modifiables » attendus'];
                continue;
            }
            if (!is_object($change->fields ?? null) || get_object_vars($change->fields) === []) {
                $errors[] = ['path' => "$path.fields", 'message' => 'objet des champs à modifier attendu'];
                continue;
            }
            ['values' => $values, 'errors' => $fieldErrors] = $this->editor->validate($resource, $change->fields);
            foreach ($fieldErrors as $error) {
                $errors[] = ['path' => "$path.fields.{$error['path']}", 'message' => $error['message']];
            }
            if (!$fieldErrors) {
                $contentChanges[] = ['resource' => $resource, 'id' => $id, 'fields' => (object) $this->display($change->fields, $values)];
            }
        }

        $groupChanges = [];
        foreach (array_slice(is_array($input->groupChanges ?? null) ? $input->groupChanges : [], 0, self::MAX_CHANGES) as $i => $change) {
            $path = "groupChanges[$i]";
            $groupId = is_object($change) ? ($change->groupId ?? null) : null;
            if (!is_int($groupId) || ($content['resource'] ?? null) !== 'presentation-groups' || $content['id'] !== $groupId) {
                $errors[] = ['path' => "$path.groupId", 'message' => 'identifiant du groupe affiché par la section attendu'];
                continue;
            }
            $members = $allowed['presentations'] ?? [];
            $remove = is_array($change->remove ?? null) ? $change->remove : [];
            if (array_diff($remove, $members) || array_filter($remove, fn ($id) => !is_int($id))) {
                $errors[] = ['path' => "$path.remove", 'message' => 'identifiants de présentations du groupe attendus'];
            }
            $add = [];
            foreach (is_array($change->add ?? null) ? $change->add : [] as $j => $item) {
                $fields = is_object($item) && is_object($item->fields ?? null) ? $item->fields : null;
                $after = is_object($item) ? ($item->after ?? null) : null;
                if ($fields === null || !isset($fields->titre)) {
                    $errors[] = ['path' => "$path.add[$j].fields", 'message' => 'champs de la nouvelle présentation attendus, titre obligatoire'];
                    continue;
                }
                if ($after !== null && (!in_array($after, $members, true) || in_array($after, $remove, true))) {
                    $errors[] = ['path' => "$path.add[$j].after", 'message' => 'identifiant d\'une présentation gardée dans le groupe, ou null (fin du groupe)'];
                    continue;
                }
                ['values' => $values, 'errors' => $fieldErrors] = $this->editor->validate('presentations', $fields);
                foreach ($fieldErrors as $error) {
                    $errors[] = ['path' => "$path.add[$j].fields.{$error['path']}", 'message' => $error['message']];
                }
                if (!$fieldErrors) {
                    $add[] = ['fields' => (object) $this->display($fields, $values), 'after' => $after];
                }
            }
            $order = is_array($change->order ?? null) ? $change->order : null;
            if ($order !== null) {
                $expected = array_values(array_diff($members, $remove));
                $sorted = $order;
                sort($sorted);
                sort($expected);
                if ($sorted !== $expected) {
                    $errors[] = ['path' => "$path.order", 'message' => 'toutes les présentations restantes du groupe, une fois chacune (les nouvelles se placent par after)'];
                }
            }
            if (count($members) - count($remove) + count($add) > PresentationGroupEditor::MAX_PRESENTATIONS) {
                $errors[] = ['path' => $path, 'message' => sprintf('%d présentations au plus par groupe', PresentationGroupEditor::MAX_PRESENTATIONS)];
            }
            $groupChanges[] = ['groupId' => $groupId, 'add' => $add, 'remove' => array_values($remove), 'order' => $order];
        }

        return [['contentChanges' => $contentChanges, 'groupChanges' => $groupChanges, 'limits' => $this->limits($input)], $errors];
    }

    /**
     * Ce que l'assistant ne peut pas faire, en texte brut : jamais bloquant (une entrée sans « howTo » est ignorée).
     *
     * @return list<array{request: string, reason: string, howTo: string}>
     */
    public function limits(object $input): array
    {
        $limits = [];
        foreach (array_slice(is_array($input->limits ?? null) ? $input->limits : [], 0, self::MAX_LIMITS) as $limit) {
            if (is_object($limit) && is_string($limit->howTo ?? null) && trim($limit->howTo) !== '') {
                $limits[] = [
                    'request' => self::plain($limit->request ?? '', 200),
                    'reason' => self::plain($limit->reason ?? '', 300),
                    'howTo' => self::plain($limit->howTo, 600),
                ];
            }
        }

        return $limits;
    }

    private static function plain(mixed $text, int $max): string
    {
        return mb_substr(trim(html_entity_decode(strip_tags(is_string($text) ? $text : ''), ENT_QUOTES | ENT_HTML5)), 0, $max);
    }

    /**
     * Valeurs telles qu'à envoyer aux routes de modification : celles du modèle, sauf les médias, qui gardent la
     * forme reçue (clé ou adresse), comme le corps de PATCH les attend.
     */
    private function display(object $sent, array $values): array
    {
        $result = [];
        foreach ($values as $field => $value) {
            $result[$field] = is_string($value) && str_starts_with($value, '/media/secure/') ? trim((string) $sent->$field) : $value;
        }

        return $result;
    }
}
