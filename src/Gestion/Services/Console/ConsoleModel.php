<?php

namespace App\Gestion\Services\Console;

use App\Gestion\Analysis\AnalysisCatalog;
use App\Services\LandingAiService\LandingAiClientInterface;
use App\Services\LandingAiService\LandingAiException;
use App\Services\LandingAiService\LandingAiUsageStats;

/**
 * Les DEUX appels au modèle d'une réponse de la console IA de la gestion (lot W2, 13/11/2026, `docs/console-gestion.md` § 3).
 * 1. CHOISIR : le modèle ne répond pas à la question ; il désigne des analyses du catalogue (4 au plus) avec leurs paramètres, ou dit que
 *    la question sort de la gestion, ou demande une précision. Le serveur contrôle chaque clé et chaque paramètre avant d'exécuter.
 * 2. RÉDIGER : le modèle reçoit les résultats et rend des blocs. Il n'écrit que du texte ; les chiffres, tableaux et verdicts sont
 *    désignés par référence et assemblés par le serveur (`ConsoleResponder`), qui refuse tout nombre qu'il n'a pas fourni.
 * Sortie structurée (`output_config.format`), sans outil imposé. Un modèle par appel, en constante : on peut monter celui de la rédaction
 * sans toucher au reste.
 */
class ConsoleModel
{
    public const MODEL_CHOOSE = 'claude-sonnet-5-5';
    public const MODEL_WRITE = 'claude-sonnet-5-5';
    public const MAX_ANALYSES = 4;
    private const TIMEOUT = 60.0;

    public function __construct(private readonly LandingAiClientInterface $client)
    {
    }

    /**
     * @param array<string, mixed>|null $context écran d'où vient la question, déjà contrôlé
     * @param list<array{role: string, text: string}> $history les derniers messages de l'échange, texte seul
     * @param list<array<string, mixed>> $catalog analyses du catalogue (clé, titre, paramètres)
     * @param list<array{id: int, label: string, from: string, to: string, current: bool}> $periods périodes que le modèle peut désigner
     *
     * @return array{kind: string, analyses: list<array{key: string, params: array<string, mixed>}>, clarification: string}
     */
    public function choose(string $question, ?array $context, array $history, array $catalog, array $periods, \DateTimeImmutable $today, string $locale, LandingAiUsageStats $stats): array
    {
        $payload = ['question' => $question, 'ecran' => $context, 'echange' => $history, 'analyses' => $catalog, 'periodes' => $periods, 'aujourdhui' => $today->format('Y-m-d')];
        $out = $this->call(self::MODEL_CHOOSE, 1200, $this->systemChoose($locale === 'en' ? 'anglais' : 'français'), $payload, self::schemaChoose(), $stats);
        $kind = in_array($out['kind'] ?? null, ['analyses', 'out_of_scope', 'clarification'], true) ? $out['kind'] : 'out_of_scope';
        $analyses = [];
        foreach (is_array($out['analyses'] ?? null) ? $out['analyses'] : [] as $a) {
            if (!is_array($a) || !is_string($a['key'] ?? null)) {
                continue;
            }
            $params = [];
            foreach (array_keys(AnalysisCatalog::PARAM_TYPES) as $name) {
                if (($a[$name] ?? null) !== null) {
                    $params[$name] = $a[$name];
                }
            }
            $analyses[] = ['key' => $a['key'], 'params' => $params];
        }

        return ['kind' => $kind, 'analyses' => array_slice($analyses, 0, self::MAX_ANALYSES), 'clarification' => mb_substr(trim((string) ($out['clarification'] ?? '')), 0, 300)];
    }

    /**
     * @param list<array<string, mixed>> $results résultats des analyses, chiffres déjà écrits comme ils doivent être lus (`display`)
     * @param list<array{key: string, title: string}> $actions actions du catalogue que la console peut proposer
     *
     * @return array{blocks: list<array<string, mixed>>, proposals: list<array<string, mixed>>}
     */
    public function write(string $question, array $results, array $actions, string $locale, LandingAiUsageStats $stats, ?string $retryNote = null): array
    {
        $payload = ['question' => $question, 'resultats' => $results, 'actions' => $actions] + ($retryNote === null ? [] : ['correction' => $retryNote]);
        $out = $this->call(self::MODEL_WRITE, 3000, $this->systemWrite($locale === 'en' ? 'anglais' : 'français'), $payload, self::schemaWrite(), $stats);

        return ['blocks' => array_values(array_filter(is_array($out['blocks'] ?? null) ? $out['blocks'] : [], 'is_array')),
            'proposals' => array_values(array_filter(is_array($out['proposals'] ?? null) ? $out['proposals'] : [], 'is_array'))];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function call(string $model, int $maxTokens, string $system, array $payload, array $schema, LandingAiUsageStats $stats): array
    {
        $response = $this->client->createMessage([
            'model' => $model, 'max_tokens' => $maxTokens, 'system' => $system,
            'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]]]],
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $schema]],
        ], self::TIMEOUT);
        $stats->attempts++;
        $stats->add($response->usage ?? null);
        $out = null;
        foreach (is_array($response->content ?? null) ? $response->content : [] as $block) {
            if (($block->type ?? null) === 'text') {
                $decoded = json_decode((string) ($block->text ?? ''), true);
                $out = is_array($decoded) ? $decoded : $out;
            }
        }
        if ($out === null || in_array((string) ($response->stop_reason ?? ''), ['refusal', 'max_tokens'], true)) {
            throw new LandingAiException(503, 'Assistant indisponible', 'La console n\'a pas pu répondre, réessayez dans un instant.');
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function schemaChoose(): array
    {
        $nullable = fn (string $type) => ['anyOf' => [['type' => $type], ['type' => 'null']]];

        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['kind', 'analyses', 'clarification'], 'properties' => [
            'kind' => ['type' => 'string', 'enum' => ['analyses', 'out_of_scope', 'clarification']],
            'analyses' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['key', 'periodId', 'productId', 'year', 'days', 'range'], 'properties' => [
                'key' => ['type' => 'string', 'enum' => array_keys(AnalysisCatalog::DEFINITIONS)],
                'periodId' => $nullable('integer'), 'productId' => $nullable('integer'), 'year' => $nullable('integer'), 'days' => $nullable('integer'),
                'range' => ['anyOf' => [['type' => 'string', 'enum' => AnalysisCatalog::RANGES], ['type' => 'null']]],
            ]]],
            'clarification' => ['type' => 'string'],
        ]];
    }

    /** @return array<string, mixed> */
    public static function schemaWrite(): array
    {
        $strings = ['type' => 'array', 'items' => ['type' => 'string']];

        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['blocks', 'proposals'], 'properties' => [
            'blocks' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['type', 'text', 'figureIds', 'analysis', 'items'], 'properties' => [
                'type' => ['type' => 'string', 'enum' => ['text', 'figures', 'table', 'verdicts']],
                'text' => ['type' => 'string'],
                'figureIds' => $strings,
                'analysis' => ['type' => 'string'],
                'items' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'why', 'figureIds'], 'properties' => [
                    'id' => ['type' => 'integer'], 'why' => ['type' => 'string'], 'figureIds' => $strings,
                ]]],
            ]]],
            'proposals' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['action', 'productIds', 'periodId', 'label'], 'properties' => [
                'action' => ['type' => 'string'], 'productIds' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'periodId' => ['anyOf' => [['type' => 'integer'], ['type' => 'null']]], 'label' => ['type' => 'string'],
            ]]],
        ]];
    }

    private function systemChoose(string $language): string
    {
        return <<<TXT
            Tu aides le gérant d'un commerce à interroger son application de gestion (achats, ventes, marge, périodes, budget, trésorerie, stock, prix).
            Tu NE RÉPONDS PAS à la question. Tu choisis, dans « analyses », celles qu'il faut lancer pour y répondre : 4 au plus, par leur « key », avec leurs paramètres.
            Règles :
            - n'emploie que les clés et les paramètres listés ; un paramètre que tu ne connais pas vaut null ;
            - « periodId » : seulement un identifiant de la liste « periodes » ; sans période nommée, laisse null (la période en cours est prise) ;
            - « productId » : seulement s'il figure dans « ecran » ; sinon null, et choisis une analyse qui n'en demande pas ;
            - si « ecran » est donné et que la question parle de ce que le gérant regarde, choisis les analyses de cet écran avec ses paramètres ;
            - question sans rapport avec la gestion du commerce (description d'articles, photos, comptes, réservations, autre sujet) : kind = "out_of_scope", analyses vide ;
            - question trop vague pour choisir : kind = "clarification" et, dans « clarification », UNE question courte au gérant, en $language, sans aucun chiffre ;
            - sinon kind = "analyses" et « clarification » vide.
            TXT;
    }

    private function systemWrite(string $language): string
    {
        return <<<TXT
            Tu rédiges, en $language, la réponse de la console de gestion d'un commerce à la question du gérant, à partir des SEULS résultats fournis.
            Tu ne calcules rien et tu n'inventes aucun nombre : tout nombre que tu écris doit être recopié tel quel d'un champ « display » des résultats (ou de la question). Pas de somme, de différence ni de pourcentage de ton cru : les comparaisons utiles sont déjà fournies.
            Rends des blocs, dans l'ordre d'affichage :
            - "text" : un paragraphe court en phrases simples ; « figureIds » cite les chiffres dont tu parles ;
            - "figures" : une rangée de chiffres clés, désignés par « figureIds » (le serveur les affiche, n'écris rien dans « text ») ;
            - "table" : le tableau d'une analyse, désignée par « analysis » (son « ref ») ;
            - "verdicts" : pour un bilan d'articles, « analysis » = le ref de l'analyse et, par article, « id » (son productId), « why » (une phrase qui explique le verdict du serveur avec ses chiffres) ; le verdict lui-même est fourni, tu ne le changes pas.
            Laisse vides les champs qui ne servent pas au bloc ("" ou []).
            « proposals » : 3 au plus, seulement si une action de « actions » aide vraiment le gérant ; « productIds » et « periodId » ne contiennent que des identifiants présents dans les résultats ; « label » : un libellé court, sans chiffre. Sinon, liste vide.
            Dis ce qui compte d'abord. N'explique pas ta méthode. Aucune mise en forme (ni astérisques, ni titres).
            TXT;
    }
}
