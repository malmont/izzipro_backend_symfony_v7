<?php

namespace App\Gestion\Lexique;

use App\Gestion\Analysis\AnalysisCatalog;
use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use Psr\Log\LoggerInterface;

/**
 * Le lexique de la gestion tel que les routes le servent (lot W2-APPRENDRE, A-1 et A-3, 13/11/2026, `docs/lexique-gestion.md`).
 * SANS IA et GRATUIT : les définitions et les questions sont écrites dans le dépôt ; les chiffres du site viennent de l'analyse
 * nommée liée à la notion (`lexique.term`), l'illustration est une phrase du serveur. Rien n'est enregistré : pas de suivi par
 * utilisateur dans ce lot.
 */
class LexiqueService
{
    public function __construct(
        private readonly LexiqueTexts $texts,
        private readonly LexiqueIllustration $illustration,
        private readonly AnalysisCatalog $analyses,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** GET /api/gestion/lexique : `{ version, entries }` ; `version` change dès qu'un texte change */
    public function list(string $locale): array
    {
        return ['version' => $this->texts->version(), 'entries' => $this->texts->all($locale)];
    }

    /**
     * GET /api/gestion/lexique/{key} : l'entrée, et `site` : les chiffres du site pour cette notion (analyse liée) avec leur
     * illustration ; `site` vaut null pour une notion sans analyse liée. 404 `LEXIQUE_TERM_NOT_FOUND`.
     *
     * @return array<string, mixed>
     */
    public function entry(string $key, string $locale): array
    {
        return $this->known($key, $locale) + ['site' => $this->site($key, $locale)];
    }

    /**
     * Les chiffres du site pour une notion, par l'analyse `lexique.term` (celle que la console lance) : `{ analysis, period,
     * figures, illustration }`, ou null (notion sans analyse liée, ou analyse en échec : la définition reste servie).
     *
     * @return array<string, mixed>|null
     */
    public function site(string $key, string $locale): ?array
    {
        if (!LexiqueCatalog::has($key) || LexiqueCatalog::ENTRIES[$key]['analysis'] === null) {
            return null;
        }
        try {
            $r = $this->analyses->run('lexique.term', ['term' => $key], $locale);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('[Lexique] chiffres du site indisponibles pour « %s » : %s', $key, $e->getMessage()));

            return null;
        }

        return [
            'analysis' => ['key' => LexiqueCatalog::ENTRIES[$key]['analysis']['key'], 'title' => $this->analyses->title(LexiqueCatalog::ENTRIES[$key]['analysis']['key'], $locale)],
            'period' => $r['period'],
            'figures' => array_map(fn (array $f) => $f + ['source' => $r['source']], $r['figures']),
            'illustration' => $this->illustration->of($key, $r['figures'], $r['period'], $locale),
        ];
    }

    /** GET /api/gestion/lexique/{key}/quiz : les questions de vérification, jamais la bonne réponse. 404 `LEXIQUE_TERM_NOT_FOUND`. */
    public function quiz(string $key, string $locale): array
    {
        $entry = $this->known($key, $locale);

        return ['key' => $key, 'term' => $entry['term'], 'questions' => $this->texts->quiz($key, $locale)];
    }

    /**
     * POST /api/gestion/lexique/{key}/quiz/{question} { choice } : corrigé par le serveur, sans IA, rien d'enregistré.
     * 404 `LEXIQUE_TERM_NOT_FOUND`, `LEXIQUE_QUESTION_NOT_FOUND` ; 422 `choice` (`REQUIRED`, `INVALID_CHOICE`).
     *
     * @return array{correct: bool, correctChoice: string, explanation: string}
     */
    public function answer(string $key, string $question, mixed $choice, string $locale): array
    {
        $this->known($key, $locale);
        $right = LexiqueCatalog::ENTRIES[$key]['quiz'][$question] ?? null;
        if ($right === null) {
            throw new GestionConflictException('LEXIQUE_QUESTION_NOT_FOUND', 'Question introuvable pour cette notion.', 404, ['key' => $key, 'question' => $question]);
        }
        if (!is_string($choice) || !in_array($choice, LexiqueCatalog::CHOICES, true)) {
            throw new GestionValidationException(['choice' => $choice === null ? 'REQUIRED' : 'INVALID_CHOICE'], 'Réponse invalide : choisissez a, b ou c.');
        }

        return ['correct' => $choice === $right, 'correctChoice' => $right, 'explanation' => $this->texts->explanation($key, $question, $locale)];
    }

    /** @return array<string, mixed> */
    private function known(string $key, string $locale): array
    {
        return $this->texts->entry($key, $locale) ?? throw new GestionConflictException('LEXIQUE_TERM_NOT_FOUND', 'Notion inconnue du lexique.', 404, ['key' => $key]);
    }
}
