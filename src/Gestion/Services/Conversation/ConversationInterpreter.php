<?php

namespace App\Gestion\Services\Conversation;

use App\Services\LandingAiService\LandingAiClientInterface;
use App\Services\LandingAiService\LandingAiException;
use App\Services\LandingAiService\LandingAiUsageStats;

/**
 * Phrase libre du gérant dans un fil de la gestion (lot IA-FACT-2-B, 13/11/2026, `docs/conversation-gestion.md` § 8) : l'IA la traduit en
 * réponses aux questions du serveur, rien d'autre. Elle ne fournit jamais un montant, un article ou un fournisseur de son cru :
 * - pour un choix, elle désigne une option proposée par son rang ;
 * - pour créer un fournisseur ou un article, elle ne peut donner qu'un nom écrit par le gérant ou lu sur le document ;
 * - pour un montant, un taux ou une date, elle ne peut rendre qu'une valeur présente dans la phrase du gérant.
 * Ces garde-fous sont appliqués ICI, par le serveur, après la réponse du modèle ; chaque valeur gardée repasse ensuite par le contrôle
 * d'une réponse par bouton (`InvoiceQuestions::accept`). L'IA ne confirme et n'annule jamais.
 *
 * Sortie structurée (`output_config.format`, schéma JSON) comme la lecture de documents : l'API refuse l'outil imposé depuis Sonnet 5.5.
 */
class ConversationInterpreter
{
    public const MODEL = 'claude-sonnet-5-5';
    public const COST = 1;
    public const MAX_TEXT = 1000;
    private const TIMEOUT = 60.0;

    public function __construct(private readonly LandingAiClientInterface $client)
    {
    }

    /**
     * @param list<array<string, mixed>> $questions questions du fil (id, kind, title, text, input, options, answer)
     * @param list<string> $documentNames noms lus sur le document (fournisseur, libellés des lignes), admis pour une création
     *
     * @return array{reply: string, answers: list<array{questionId: string, value: mixed}>, ignored: list<array{questionId: string, reason: string}>}
     *
     * @throws LandingAiException 503 si le modèle ne rend rien d'exploitable
     */
    public function interpret(string $text, array $questions, array $documentNames, \DateTimeImmutable $today, string $locale, LandingAiUsageStats $stats): array
    {
        $byId = array_column($questions, null, 'id');
        $listed = array_map(fn (array $q) => [
            'questionId' => $q['id'], 'title' => $q['title'], 'text' => $q['text'], 'inputType' => $q['input']['type'], 'answered' => $q['answer'] !== null,
            'options' => array_map(fn (int $i, array $o) => ['index' => $i, 'label' => $o['label']], array_keys($q['options']), $q['options']),
        ], $questions);
        $response = $this->client->createMessage([
            'model' => self::MODEL, 'max_tokens' => 1500, 'system' => $this->system($locale === 'en' ? 'anglais' : 'français', $today),
            'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => "Questions du fil :\n" . json_encode($listed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\nPhrase du gérant :\n" . $text]]]],
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => self::schema()]],
        ], self::TIMEOUT);
        $stats->attempts++;
        $stats->add($response->usage ?? null);
        $output = null;
        foreach (is_array($response->content ?? null) ? $response->content : [] as $block) {
            if (($block->type ?? null) === 'text') {
                $decoded = json_decode((string) ($block->text ?? ''), true);
                $output = is_array($decoded) ? $decoded : $output;
            }
        }
        if ($output === null || in_array((string) ($response->stop_reason ?? ''), ['refusal', 'max_tokens'], true)) {
            throw new LandingAiException(503, 'Assistant indisponible', 'L\'assistant n\'a pas pu lire votre phrase, réessayez ou répondez par les boutons.');
        }

        $answers = [];
        $ignored = [];
        foreach (is_array($output['answers'] ?? null) ? $output['answers'] : [] as $raw) {
            $id = is_array($raw) ? (string) ($raw['questionId'] ?? '') : '';
            if (!isset($byId[$id])) {
                $ignored[] = ['questionId' => $id, 'reason' => 'UNKNOWN_QUESTION'];
                continue;
            }
            [$value, $reason] = $this->value($byId[$id], $raw, $text, $documentNames, $today);
            if ($reason !== null) {
                $ignored[] = ['questionId' => $id, 'reason' => $reason];
            } else {
                $answers[] = ['questionId' => $id, 'value' => $value];
            }
        }

        return ['reply' => mb_substr(trim((string) ($output['reply'] ?? '')), 0, 600), 'answers' => $answers, 'ignored' => $ignored];
    }

    /**
     * Valeur d'une réponse proposée par le modèle, après les garde-fous du serveur.
     *
     * @return array{0: mixed, 1: ?string} valeur, ou raison du refus
     */
    private function value(array $question, array $raw, string $text, array $documentNames, \DateTimeImmutable $today): array
    {
        $type = (string) $question['input']['type'];
        if ($type === 'choice') {
            $index = is_int($raw['optionIndex'] ?? null) ? $raw['optionIndex'] : -1;
            $option = $question['options'][$index] ?? null;
            if ($option === null) {
                return [null, 'NOT_AN_OPTION'];
            }
            $value = $option['value'];
            $name = trim((string) ($raw['name'] ?? ''));
            if (is_array($value) && is_array($value['create'] ?? null) && $name !== '' && $name !== ($value['create']['name'] ?? '')) {
                // nom d'un fournisseur ou d'un article à créer : seulement un nom écrit par le gérant ou lu sur le document
                if (!self::mentions($text, $name) && !in_array(mb_strtolower($name), array_map('mb_strtolower', $documentNames), true)) {
                    return [null, 'NAME_NOT_IN_SENTENCE'];
                }
                $value['create']['name'] = $name;
            }

            return [$value, null];
        }
        $given = trim((string) ($raw['value'] ?? ''));
        if ($type === 'date') {
            return self::dateMentioned($text, $given, $today) ? [$given, null] : [null, 'VALUE_NOT_IN_SENTENCE'];
        }
        $number = is_numeric(str_replace(',', '.', $given)) ? (float) str_replace(',', '.', $given) : null;
        if ($number === null || !in_array(round($number, 4), self::numbers($text), true)) {
            return [null, 'VALUE_NOT_IN_SENTENCE'];
        }

        return [$type === 'money' ? (int) round($number * 100) : $number, null];
    }

    /** @return list<float> les nombres écrits dans la phrase (virgule ou point, espaces de milliers) */
    public static function numbers(string $text): array
    {
        preg_match_all('/\d[\d\x{0020}\x{00A0}\x{202F}]*(?:[.,]\d+)?/u', $text, $m);

        return array_values(array_unique(array_map(fn (string $n) => round((float) str_replace(['.', ','], ['.', '.'], preg_replace('/[\x{0020}\x{00A0}\x{202F}]/u', '', $n)), 4), $m[0])));
    }

    /** La phrase contient-elle ce nom (sans casse) */
    public static function mentions(string $text, string $name): bool
    {
        return $name !== '' && mb_stripos($text, $name) !== false;
    }

    /** La date est-elle dite dans la phrase : « aujourd'hui », « hier », ou son jour avec son mois (en chiffres ou en lettres) */
    public static function dateMentioned(string $text, string $iso, \DateTimeImmutable $today): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $iso);
        if ($date === false || $date->format('Y-m-d') !== $iso) {
            return false;
        }
        $lower = mb_strtolower($text);
        if ($iso === $today->format('Y-m-d') && preg_match('/aujourd.?hui|today/u', $lower)) {
            return true;
        }
        if ($iso === $today->modify('-1 day')->format('Y-m-d') && preg_match('/\bhier\b|yesterday/u', $lower)) {
            return true;
        }
        if (str_contains($text, $iso)) {
            return true;
        }
        $months = [1 => ['janvier', 'january', 'jan'], ['février', 'fevrier', 'february', 'feb', 'fév'], ['mars', 'march'], ['avril', 'april', 'avr', 'apr'], ['mai', 'may'], ['juin', 'june'], ['juillet', 'july'],
            ['août', 'aout', 'august', 'aug'], ['septembre', 'september', 'sept', 'sep'], ['octobre', 'october', 'oct'], ['novembre', 'november', 'nov'], ['décembre', 'decembre', 'december', 'déc', 'dec']];
        $numbers = array_map('intval', self::numbers($text));
        $day = in_array((int) $date->format('j'), $numbers, true);
        $monthNamed = array_filter($months[(int) $date->format('n')], fn (string $m) => preg_match('/\b' . preg_quote($m, '/') . '\b/u', $lower) === 1) !== [];
        $monthNumbered = preg_match('/\b0?' . $date->format('j') . '\s*[\/.-]\s*0?' . $date->format('n') . '\b/', $text) === 1;

        return $day && ($monthNamed || $monthNumbered);
    }

    private function system(string $language, \DateTimeImmutable $today): string
    {
        return "Tu aides un commerçant à enregistrer une facture fournisseur. Le serveur a calculé une liste de questions ; le commerçant répond par une phrase libre.\n"
            . "Ta seule tâche : traduire sa phrase en réponses à ces questions, puis lui répondre en une ou deux phrases, en $language.\n"
            . "Règles strictes :\n"
            . "- Ne réponds qu'aux questions de la liste, par leur questionId. Ne réponds pas à une question dont la phrase ne parle pas.\n"
            . "- Question à choix (inputType choice) : donne optionIndex, le rang d'une option proposée. Si le commerçant veut créer un fournisseur ou un article sous un autre nom, mets ce nom dans name, tel qu'il l'a écrit ; sinon name reste vide.\n"
            . "- Question de date : value au format AAAA-MM-JJ, seulement si le commerçant donne la date. Nous sommes le " . $today->format('Y-m-d') . ".\n"
            . "- Question de montant ou de nombre : value est le nombre écrit par le commerçant, avec un point décimal. Tu n'inventes et ne calcules jamais un montant, un taux ou une quantité.\n"
            . "- Pour une question sans option, optionIndex vaut -1 ; pour une question à choix, value reste vide.\n"
            . "- Tu ne confirmes rien et tu n'annules rien : la confirmation est un geste du commerçant. Si la phrase ne répond à aucune question, rends answers vide et dis-le simplement.\n"
            . "- reply : court, sans chiffres que le commerçant n'a pas donnés.";
    }

    /** @return array<string, mixed> */
    private static function schema(): array
    {
        $string = ['type' => 'string'];

        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['reply', 'answers'], 'properties' => [
            'reply' => $string,
            'answers' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['questionId', 'optionIndex', 'value', 'name'],
                'properties' => ['questionId' => $string, 'optionIndex' => ['type' => 'integer'], 'value' => $string, 'name' => $string]]],
        ]];
    }
}
