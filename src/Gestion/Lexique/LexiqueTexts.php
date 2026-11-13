<?php

namespace App\Gestion\Lexique;

use App\Gestion\Analysis\AnalysisCatalog;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Les TEXTES du lexique de la gestion (lot W2-APPRENDRE, A-1, 13/11/2026) : une entrée telle que les applications et la console la
 * lisent, dans la langue demandée. Aucune IA, aucune donnée du site : les mêmes textes pour tous les sites
 * (`translations/lexique.{fr,en}.yaml`), dans l'ordre de `LexiqueCatalog`.
 *
 * Entrée : `{ key, term, short, definition, synonyms, related: [{ key, term }], analysis: { key, params, figures } | null, screens, quiz }`.
 * `short` est la première phrase de la définition ; `screens`, l'écran de l'analyse liée ; `quiz`, le nombre de questions de vérification.
 */
class LexiqueTexts
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /** @return array<string, mixed>|null l'entrée, ou null pour une clé inconnue */
    public function entry(string $key, string $locale): ?array
    {
        if (!LexiqueCatalog::has($key)) {
            return null;
        }
        $e = LexiqueCatalog::ENTRIES[$key];
        $definition = (string) $this->text("$key.definition", $locale);
        $synonyms = $this->text("$key.synonyms", $locale);

        return [
            'key' => $key,
            'term' => $this->term($key, $locale),
            'short' => self::firstSentence($definition),
            'definition' => $definition,
            'synonyms' => $synonyms === null ? [] : array_values(array_filter(array_map('trim', explode(',', $synonyms)))),
            'related' => array_map(fn (string $k) => ['key' => $k, 'term' => $this->term($k, $locale)], $e['related']),
            'analysis' => $e['analysis'],
            'screens' => $e['analysis'] === null ? [] : [AnalysisCatalog::DEFINITIONS[$e['analysis']['key']]['screen']],
            'quiz' => count($e['quiz']),
        ];
    }

    /** @return list<array<string, mixed>> toutes les notions, dans l'ordre du lexique */
    public function all(string $locale): array
    {
        return array_map(fn (string $key) => $this->entry($key, $locale), LexiqueCatalog::keys());
    }

    /** Change dès qu'un texte ou la structure change : les applications savent quand recharger le lexique */
    public function version(): string
    {
        return substr(sha1((string) json_encode([$this->all('fr'), $this->all('en'), $this->questions('fr'), $this->questions('en'), LexiqueCatalog::ENTRIES])), 0, 12);
    }

    public function term(string $key, string $locale): string
    {
        return (string) $this->text("$key.term", $locale);
    }

    /**
     * Les questions de vérification d'une notion, SANS la bonne réponse : `[{ id, question, choices: [{ id, label }] }]`.
     *
     * @return list<array{id: string, question: string, choices: list<array{id: string, label: string}>}>
     */
    public function quiz(string $key, string $locale): array
    {
        $out = [];
        foreach (array_keys(LexiqueCatalog::ENTRIES[$key]['quiz'] ?? []) as $id) {
            $out[] = ['id' => (string) $id, 'question' => (string) $this->text("$key.quiz.$id.question", $locale),
                'choices' => array_map(fn (string $c) => ['id' => $c, 'label' => (string) $this->text("$key.quiz.$id.$c", $locale)], LexiqueCatalog::CHOICES)];
        }

        return $out;
    }

    public function explanation(string $key, string $question, string $locale): string
    {
        return (string) $this->text("$key.quiz.$question.explanation", $locale);
    }

    /** Un texte du lexique, ou null s'il n'existe pas ; `%count%` choisit la forme d'un texte à deux formes */
    public function text(string $id, string $locale, array $params = []): ?string
    {
        $text = $this->translator->trans($id, $params, 'lexique', $locale === 'en' ? 'en' : 'fr');

        return $text === $id ? null : $text;
    }

    /** @return array<string, list<array<string, mixed>>> les questions de toutes les notions (pour la version) */
    private function questions(string $locale): array
    {
        $out = [];
        foreach (LexiqueCatalog::keys() as $key) {
            $out[$key] = array_map(fn (array $q) => $q + ['explanation' => $this->explanation($key, $q['id'], $locale)], $this->quiz($key, $locale));
        }

        return $out;
    }

    private static function firstSentence(string $text): string
    {
        return preg_match('/^(.+?[.!?])(\s|$)/u', $text, $m) === 1 ? $m[1] : $text;
    }
}
