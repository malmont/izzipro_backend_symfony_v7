<?php

namespace App\Gestion\Services\Console;

use App\Entity\Collections;
use App\Gestion\Analysis\AnalysisCatalog;
use App\Gestion\Analysis\RangeLabel;
use App\Gestion\Entity\GestionConversationMessage;
use App\Gestion\Lexique\LexiqueCatalog;
use App\Gestion\Lexique\LexiqueIllustration;
use App\Gestion\Lexique\LexiqueTexts;
use App\Gestion\Services\Conversation\Action\ActionCatalog;
use App\Gestion\Services\Conversation\ActionThreadService;
use App\Gestion\Services\GestionAdviceService;
use App\Gestion\Services\PeriodCalendar;
use App\Services\LandingAiService\LandingAiUsageStats;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Fabrique la réponse d'une question de la console (lot W2, 13/11/2026, `docs/console-gestion.md` § 3), dans le worker `gestion_ai`.
 *
 * 1. Le modèle CHOISIT des analyses ; le serveur contrôle chaque clé et chaque paramètre (`AnalysisCatalog::accept`) puis les exécute.
 * 2. Le modèle RÉDIGE des blocs à partir des résultats. Il n'écrit que du texte : les blocs `figures`, `table` et `verdicts` sont
 *    assemblés ICI à partir des résultats, par référence. Tout nombre écrit dans un texte doit être un nombre que le serveur a fourni,
 *    sous une écriture admise ; sinon un nouvel essai, puis un texte de repli écrit par le serveur.
 * Les verdicts viennent de la règle du coefficient, jamais du modèle. Une proposition ne garde que des identifiants présents dans les
 * résultats et doit passer le contrôle d'ouverture d'un fil d'action. L'IA n'écrit rien dans les données.
 *
 * Lot W2-b (13/11/2026) : l'écran d'où vient la question est traduit par le serveur en thème et en analyses par défaut
 * (`ConsoleScreens`), et ses paramètres complètent ceux que le modèle laisse vides ; une question qui dit « période » lit les ventes sur
 * la période, pas sur le mois ; tout chiffre dont un texte parle reçoit sa carte (bloc `figures`) à la suite de ce texte ; un bilan
 * d'articles porte ses groupes (même verdict, même raison), comptés par le serveur.
 *
 * Lot W2-c (13/11/2026) : une carte ne suit que le texte qui parle VRAIMENT du chiffre (sa valeur ou son libellé y sont écrits) : un
 * chiffre cité par le modèle mais absent du texte est ignoré ; une même grandeur de même valeur sur la même plage, rendue par deux
 * analyses, n'a qu'une carte (la première source) ; les dates et les états sont donnés au modèle rédigés (« 11 novembre 2026 »,
 * « Correct »), jamais bruts ; l'écran donne aussi ses paramètres fixes (l'horizon de 90 jours de la Trésorerie).
 *
 * Lot W2-APPRENDRE (13/11/2026) : la LEÇON d'une notion (analyse `lexique.term`) est un bloc `lesson` écrit par le serveur (définition
 * du lexique, illustration avec les chiffres du site), suivi de ses chiffres. Quand l'appel 1 ne choisit QUE des notions, le serveur
 * assemble la réponse lui-même, sans appel 2 : aucune unité, ligne « [Console] leçon » au journal. En mode « apprendre » (`mode:
 * "learn"`, envoyé avec la question), toute réponse reçoit en plus les leçons des notions de ses chiffres, 3 au plus, sans appel de plus.
 *
 * Unités : 1 par réponse rédigée par le modèle (demande de précision rédigée par lui comprise) ; aucune si le serveur répond lui-même
 * (question hors périmètre, précision demandée par le serveur) ou si l'IA échoue.
 */
class ConsoleResponder
{
    public const MAX_PROPOSALS = 3;
    /** Leçons d'une réponse en mode « apprendre », celles demandées par la question comprises : pour ne pas noyer le gérant */
    public const MAX_LESSONS = 3;
    /**
     * Une réponse de REPLI (les deux essais de rédaction portaient un nombre inconnu : texte du serveur et chiffres des analyses)
     * est-elle décomptée ? Non par défaut : le gérant ne paie pas une réponse que l'IA n'a pas su écrire. Chaque repli est compté
     * (marque `fallback` sur la réponse, ligne « [Console] repli » au journal) : on voit ainsi si le modèle de rédaction doit monter.
     */
    public const CONSOLE_FALLBACK_CHARGED = false;
    /** Champs d'une ligne qui sont des montants en cents */
    private const MONEY_KEYS = ['price', 'cost', 'sales', 'grossMargin', 'result', 'planned', 'actual', 'remaining', 'effect', 'revenue'];
    private const PERCENT_KEYS = ['similarSoldShare'];
    /** Champs d'une ligne dont la valeur est un code : le modèle en reçoit le libellé rédigé (jeu de libellés `console.values.*`) */
    private const CODED_ROWS = ['verdict' => 'verdict', 'status' => 'budget'];
    /** Unités d'un chiffre qu'un nombre écrit dans un texte peut désigner (ni une date ni un texte) */
    private const NUMERIC_UNITS = ['money', 'percent', 'count', 'days', 'ratio'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly ConsoleService $console,
        private readonly ConsoleModel $model,
        private readonly AnalysisCatalog $analyses,
        private readonly ActionThreadService $actions,
        private readonly PeriodCalendar $calendar,
        private readonly GestionAdviceService $format,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly LexiqueTexts $lexique,
        private readonly LexiqueIllustration $illustration,
    ) {
    }

    public function answer(int $messageId): void
    {
        $em = $this->emProvider->getEntityManager();
        $message = $em->find(GestionConversationMessage::class, $messageId);
        $content = $message?->getContent() ?? [];
        if ($message === null || $message->getKind() !== ConsoleService::ANSWER || ($content['state'] ?? null) !== ConsoleService::STATE_PENDING) {
            return; // déjà répondue, en échec par délai, ou effacée
        }
        $conversation = $message->getConversation();
        $locale = $conversation->getLocale();
        $question = $em->find(GestionConversationMessage::class, (int) ($content['replyTo'] ?? 0));
        $text = (string) ($question?->getContent()['text'] ?? '');
        $context = $question?->getContent()['context'] ?? null;
        $screen = $this->screen(is_array($context) ? $context : null, $locale); // l'écran, traduit par le serveur
        $screenParams = $screen === null ? [] : (array) ($context['params'] ?? []);
        $learn = ($question?->getContent()['mode'] ?? null) === ConsoleService::MODE_LEARN;
        $stats = new LandingAiUsageStats(ConsoleModel::MODEL_WRITE);
        try {
            $history = [];
            foreach ($em->getRepository(GestionConversationMessage::class)->findBy(['conversation' => $conversation], ['id' => 'DESC'], ConsoleService::HISTORY_MESSAGES + 2) as $m) {
                if ($m->getId() >= $question->getId()) {
                    continue;
                }
                $history[] = $m->getKind() === ConsoleService::QUESTION ? ['role' => 'gérant', 'text' => (string) ($m->getContent()['text'] ?? '')] : ['role' => 'console', 'text' => self::plain($m->getContent()['blocks'] ?? [])];
            }
            $choice = $this->model->choose($text, $screen, array_reverse(array_slice($history, 0, ConsoleService::HISTORY_MESSAGES)), $this->analyses->listed($locale), $this->periods($locale), $this->calendar->today(), $locale, $stats,
                array_map(fn (array $e) => ['key' => $e['key'], 'term' => $e['term'], 'synonymes' => $e['synonyms']], $this->lexique->all($locale)));

            if ($choice['kind'] === 'out_of_scope') {
                // Hors périmètre : le serveur répond lui-même, gratuitement
                $this->console->finish($messageId, $content, ConsoleService::STATE_DONE, ['blocks' => [self::text($this->tr('console.out_of_scope', $locale))]], false, $stats);

                return;
            }
            $selected = [];
            foreach ($choice['kind'] === 'analyses' ? $choice['analyses'] : [] as $a) {
                // ce que le modèle laisse vide est pris à l'écran (sa période, son article, son année) ; ce qu'il nomme l'emporte
                $params = $this->analyses->accept($a['key'], self::withScreen($a['key'], $a['params'], $screenParams, $screen['code'] ?? null));
                if ($params !== null) {
                    $selected[] = [$a['key'], $params];
                }
            }
            $selected = $this->salesOnThePeriod($selected, $text, $screenParams);
            $selected = array_column(array_map(fn (array $s) => [$s[0] . json_encode($s[1]), $s], $selected), 1, 0); // une analyse et ses paramètres : une seule fois
            if ($selected === []) {
                // Demande de précision : rédigée par le modèle (1 unité) si elle est propre et sans nombre de son cru ; sinon par le serveur (gratuit)
                $asked = GestionAdviceService::cleanText($choice['clarification']) ?? '';
                $own = $choice['kind'] === 'clarification' && $asked !== '' && self::unknown($asked, self::numbers($text)) === [];
                $this->console->finish($messageId, $content, ConsoleService::STATE_DONE, ['blocks' => [self::text($own ? $asked : $this->tr('console.clarify', $locale))]], $own, $stats, $own ? mb_substr($asked, 0, 200) : null);

                return;
            }

            // Les analyses, exécutées par le serveur ; chaque chiffre reçoit un identifiant unique dans la réponse (« a1.revenue »)
            $results = [];
            foreach (array_values($selected) as $n => [$key, $params]) {
                $r = $this->analyses->run($key, $params, $locale);
                $r['ref'] = 'a' . ($n + 1);
                foreach ($r['figures'] as $i => $f) {
                    $r['figures'][$i]['id'] = $r['ref'] . '.' . $f['id'];
                }
                $results[] = $r;
            }
            $lessons = [];
            foreach ($results as $r) {
                if ($r['lesson'] !== null) {
                    $lessons[] = $this->lessonBlock($r['lesson'], $this->illustration->of($r['lesson']['key'], $r['figures'], $r['period'], $locale));
                }
            }
            $listed = fn (): array => array_map(fn (array $r) => ['key' => $r['key'], 'ref' => $r['ref'], 'title' => $r['title'], 'params' => $r['lesson'] === null ? $r['source']['params'] : ['term' => $r['lesson']['key']], 'source' => $r['source'], 'period' => $r['period'], 'computedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)], $results);
            if (count($lessons) === count($results)) {
                // La question ne demande QUE des notions : la réponse est assemblée par le serveur (la leçon, puis ses chiffres), sans appel 2.
                // Aucune unité : rien n'est rédigé par le modèle. Comptée au journal, pour savoir combien de questions sont des définitions.
                $blocks = [];
                foreach ($results as $i => $r) {
                    $blocks[] = $lessons[$i];
                    if ($r['figures'] !== []) {
                        $blocks[] = $this->figuresBlock(array_column($r['figures'], 'id'), $results);
                    }
                }
                $this->logger->notice(sprintf('[Console] leçon : réponse %d assemblée par le serveur, sans rédaction (%s)', $messageId, implode(', ', array_map(fn (array $r) => $r['lesson']['key'], $results))));
                $this->console->finish($messageId, $content, ConsoleService::STATE_DONE, ['blocks' => $blocks, 'analyses' => $listed(), 'proposals' => [], 'lesson' => true], false, $stats);

                return;
            }
            [$forModel, $allowed] = $this->forModel($results, $text, $locale);
            $actions = array_map(fn (string $k) => ['key' => $k, 'title' => $this->tr('conversation.actions.' . str_replace('.', '_', $k) . '.title', $locale)], ActionCatalog::KEYS);

            $named = $screen === null ? null : ['titre' => $screen['titre'], 'theme' => $screen['theme']];
            $written = $this->model->write($text, $forModel, $actions, $locale, $stats, null, $named);
            [$blocks, $rejected] = $this->blocks($written['blocks'], $results, $allowed, $locale);
            if ($rejected > 0) {
                // un nombre inconnu : un nouvel essai, en le disant au modèle
                $written = $this->model->write($text, $forModel, $actions, $locale, $stats, 'Ta réponse précédente contenait un nombre absent des champs « display ». Recopie les nombres tels quels, sans rien calculer.', $named);
                [$blocks, $rejected] = $this->blocks($written['blocks'], $results, $allowed, $locale);
            }
            $fallback = $rejected > 0 || array_filter($blocks, fn (array $b) => $b['type'] === 'text') === [];
            if ($fallback) {
                // texte de repli écrit par le serveur, à partir des chiffres ; les blocs sûrs du modèle sont gardés
                $this->logger->notice(sprintf('[Console] repli : réponse %d rédigée par le serveur après %d texte(s) rejeté(s)', $messageId, $rejected));
                array_unshift($blocks, self::text($this->tr('console.fallback', $locale)));
                if (array_filter($blocks, fn (array $b) => $b['type'] === 'figures') === []) {
                    $canon = self::canonical($results);
                    foreach ($results as $r) {
                        $own = array_values(array_filter(array_column($r['figures'], 'id'), fn (string $id) => $canon[$id] === $id)); // sans les doublons d'une analyse précédente
                        if ($own !== []) {
                            $blocks[] = $this->figuresBlock($own, $results);
                        }
                    }
                }
            }
            $proposals = $this->proposals($written['proposals'], $results, $locale);
            // les leçons demandées par la question passent devant ; en mode « apprendre », celles des notions des chiffres montrés s'ajoutent
            $blocks = array_merge($lessons, array_values(array_filter($blocks)));
            if ($learn) {
                $blocks = $this->withLessons($blocks, $results, $locale);
            }
            $this->console->finish($messageId, $content, ConsoleService::STATE_DONE, [
                'blocks' => $blocks,
                'analyses' => $listed(),
                'proposals' => $proposals,
            ] + ($fallback ? ['fallback' => true] : []), !$fallback || self::CONSOLE_FALLBACK_CHARGED, $stats, mb_substr(self::plain($blocks), 0, 200));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[Console] réponse %d en échec : %s', $messageId, $e->getMessage()));
            $this->console->finish($messageId, $content, ConsoleService::STATE_FAILED, ['error' => ['code' => 'AI_UNAVAILABLE', 'message' => 'La console n\'a pas pu répondre. Reposez votre question dans un instant.']], false, $stats);
        }
    }

    /**
     * Résultats tels que le modèle les lit : chaque chiffre avec son écriture (`display`), et la liste de tous les nombres admis dans un
     * texte (les valeurs écrites, les nombres des libellés et des noms, ceux de la question).
     *
     * @param list<array<string, mixed>> $results
     *
     * @return array{0: list<array<string, mixed>>, 1: list<float>}
     */
    private function forModel(array $results, string $question, string $locale): array
    {
        $allowed = self::numbers($question);
        $note = function (string $written) use (&$allowed): string {
            $allowed = array_merge($allowed, self::numbers($written));

            return $written;
        };
        $out = [];
        foreach ($results as $r) {
            $figures = [];
            foreach ($r['figures'] as $f) {
                $figure = ['id' => $f['id'], 'label' => $note((string) $f['label']), 'display' => $note($this->display($f['value'], (string) $f['unit'], $locale))];
                foreach (['previous', 'lastYear', 'delta', 'deltaLastYear'] as $k) {
                    if (isset($f[$k])) {
                        $figure[$k] = $note($this->display($f[$k], (string) $f['unit'], $locale));
                    }
                }
                foreach (['deltaPercent', 'deltaPercentLastYear'] as $k) {
                    if (isset($f[$k])) {
                        $figure[$k] = $note($this->display($f[$k], 'percent', $locale));
                    }
                }
                if (isset($f['rank'])) {
                    $figure['rank'] = $note((string) $f['rank']);
                }
                $figures[] = $figure;
            }
            $rows = [];
            foreach ($r['rows'] as $row) {
                $line = [];
                foreach ($row as $k => $v) {
                    if (is_array($v) || $v === null) {
                        continue;
                    }
                    $line[$k] = match (true) {
                        in_array($k, ['productId', 'adviceId'], true) => $v, // un identifiant : jamais un nombre à écrire
                        isset(self::CODED_ROWS[$k]) && is_string($v) => $note(AnalysisCatalog::valueLabel($this->translator, self::CODED_ROWS[$k], $v, $locale)), // « À monter », jamais « raise »
                        in_array($k, self::MONEY_KEYS, true) && is_numeric($v) => $note($this->display($v, 'money', $locale)),
                        in_array($k, self::PERCENT_KEYS, true) && is_numeric($v) => $note($this->display($v, 'percent', $locale)),
                        is_float($v) || is_int($v) => $note($this->display($v, 'ratio', $locale)),
                        default => $note((string) $v),
                    };
                }
                $rows[] = $line;
            }
            $groups = [];
            foreach ($r['groups'] as $g) {
                // le nombre d'articles de chaque groupe est fourni : le modèle ne compte rien
                $groups[] = ['verdict' => $g['verdict'], 'raison' => $note((string) $g['label']), 'nombre' => $note((string) $g['count']), 'articles' => array_map(fn (string $name) => $note($name), $g['names'])];
            }
            $lesson = $r['lesson'] === null ? [] : ['lecon' => ['terme' => $note((string) $r['lesson']['term']), 'definition' => $note((string) $r['lesson']['definition']),
                'illustration' => ($ill = $this->illustration->of($r['lesson']['key'], $r['figures'], $r['period'], $locale)) === null ? null : $note($ill)]];
            $period = $r['period'] === null ? [] : ['period' => $note((string) $r['period']['label'])];
            foreach (['previousLabel', 'lastYearLabel'] as $k) {
                if (isset($r['period'][$k])) {
                    $period[$k] = $note((string) $r['period'][$k]);
                }
            }
            $out[] = ['ref' => $r['ref'], 'key' => $r['key'], 'title' => $note((string) $r['title'])] + ($period ?: ['period' => null]) + ['figures' => $figures, 'rows' => $rows] + ($groups === [] ? [] : ['groupes' => $groups]) + $lesson + [
                'more' => $r['more'] === null ? null : $note($this->tr('console.more', $locale, ['%count%' => (string) $r['more']['count'], '%total%' => (string) $r['more']['total']]))];
        }

        return [$out, array_values(array_unique($allowed))];
    }

    /**
     * Blocs de la réponse, assemblés par le serveur. Un bloc de texte qui porte un nombre inconnu est rejeté et compté.
     *
     * @param list<array<string, mixed>> $written blocs rendus par le modèle
     * @param list<array<string, mixed>> $results
     * @param list<float> $allowed
     *
     * @return array{0: list<array<string, mixed>>, 1: int} blocs gardés, nombre de textes rejetés
     */
    private function blocks(array $written, array $results, array $allowed, string $locale): array
    {
        $byRef = array_column($results, null, 'ref');
        $known = [];
        foreach ($results as $r) {
            foreach ($r['figures'] as $f) {
                $known[$f['id']] = true;
            }
        }
        $canon = self::canonical($results); // une même grandeur de même valeur sur la même plage : un seul chiffre, celui de la première analyse
        $byId = [];
        foreach ($results as $r) {
            foreach ($r['figures'] as $f) {
                $byId[$f['id']] = $f;
            }
        }
        $ids = fn (mixed $list): array => array_values(array_unique(array_map(fn (string $id) => $canon[$id], array_filter(is_array($list) ? $list : [], fn ($id) => is_string($id) && isset($known[$id])))));
        $blocks = [];
        $rejected = 0;
        foreach ($written as $b) {
            $type = $b['type'] ?? null;
            if ($type === 'text') {
                $text = mb_substr(GestionAdviceService::cleanText((string) ($b['text'] ?? '')) ?? '', 0, 1500);
                if ($text === '') {
                    continue;
                }
                if (self::unknown($text, $allowed) !== []) {
                    ++$rejected;
                    continue;
                }
                // un chiffre cité par le modèle ne compte que si le texte en parle vraiment : sa valeur ou son libellé y sont écrits
                $cited = array_values(array_filter($ids($b['figureIds'] ?? []), fn (string $id) => $this->speaksOf($text, $byId[$id], $locale)));
                $blocks[] = self::text($text, array_values(array_unique(array_merge($cited, $this->citedByValue($text, $results, $cited, $locale)))));
            } elseif ($type === 'figures' && $ids($b['figureIds'] ?? []) !== []) {
                $blocks[] = $this->figuresBlock($ids($b['figureIds']), $results);
            } elseif ($type === 'table' && isset($byRef[$b['analysis'] ?? '']) && $byRef[$b['analysis']]['rows'] !== []) {
                $r = $byRef[$b['analysis']];
                $blocks[] = ['type' => 'table', 'title' => $r['title'], 'columns' => array_keys($r['rows'][0]), 'rows' => $r['rows'], 'more' => $r['more'], 'source' => $r['source']];
            } elseif ($type === 'verdicts' && isset($byRef[$b['analysis'] ?? ''])) {
                $rows = array_column(array_filter($byRef[$b['analysis']]['rows'], fn (array $row) => isset($row['productId'], $row['verdict'])), null, 'productId');
                $items = [];
                foreach (is_array($b['items'] ?? null) ? $b['items'] : [] as $item) {
                    $row = $rows[$item['id'] ?? 0] ?? null;
                    if ($row === null || isset($items[$row['productId']])) {
                        continue; // un article absent de l'analyse, ou sans verdict du serveur
                    }
                    $why = GestionAdviceService::cleanText((string) ($item['why'] ?? '')) ?? '';
                    if ($why === '' || self::unknown($why, $allowed) !== []) {
                        $why = (string) ($row['why'] ?? ''); // l'explication chiffrée du serveur
                    }
                    $items[$row['productId']] = ['subject' => ['type' => 'product', 'id' => (int) $row['productId'], 'name' => $row['name'] ?? null], 'verdict' => $row['verdict'], 'why' => mb_substr($why, 0, 400), 'figureIds' => $ids($item['figureIds'] ?? [])];
                }
                if ($items !== []) {
                    $groups = array_map(fn (array $g) => array_diff_key($g, ['names' => 1]), $byRef[$b['analysis']]['groups']);
                    $blocks[] = ['type' => 'verdicts', 'items' => array_values($items), 'groups' => $groups, 'source' => $byRef[$b['analysis']]['source']];
                }
            }
        }

        // Tout chiffre dont un texte parle a sa carte : le serveur ajoute, à la suite du texte, un bloc `figures` pour ceux qu'aucun
        // bloc `figures` de la réponse ne montre (une carte par chiffre dans la réponse, jamais deux)
        $shown = [];
        foreach ($blocks as $b) {
            if ($b['type'] === 'figures') {
                $shown = array_merge($shown, array_column($b['items'], 'id'));
            }
        }
        $out = [];
        foreach ($blocks as $b) {
            $out[] = $b;
            $missing = $b['type'] === 'text' ? array_values(array_diff($b['figureIds'], $shown)) : [];
            if ($missing !== []) {
                $out[] = $this->figuresBlock($missing, $results);
                $shown = array_merge($shown, $missing);
            }
        }

        return [$out, $rejected];
    }

    /**
     * Chiffres dont un texte parle SANS les citer dans `figureIds` : un nombre du texte qui est la valeur d'un chiffre des analyses le
     * désigne. S'il est la valeur d'un seul chiffre, c'est lui ; de plusieurs, celui dont le libellé partage un mot avec le texte
     * (« 11 commandes » : le chiffre « Commandes ») ; sinon aucun (on ne devine pas). Un nombre déjà couvert par un chiffre cité n'ajoute rien.
     *
     * @param list<array<string, mixed>> $results
     * @param list<string> $cited identifiants déjà cités par le modèle
     *
     * @return list<string>
     */
    private function citedByValue(string $text, array $results, array $cited, string $locale): array
    {
        $lower = mb_strtolower($text);
        $canon = self::canonical($results);
        $found = [];
        foreach (self::numbers($text) as $n) {
            $candidates = [];
            foreach ($results as $r) {
                foreach ($r['figures'] as $f) {
                    if ($canon[$f['id']] === $f['id'] && in_array($f['unit'], self::NUMERIC_UNITS, true) && is_numeric($f['value']) && self::unknown($this->display($f['value'], (string) $f['unit'], $locale), [$n]) === []) {
                        $candidates[] = $f;
                    }
                }
            }
            if ($candidates === [] || array_intersect(array_column($candidates, 'id'), array_merge($cited, $found)) !== []) {
                continue;
            }
            if (count($candidates) > 1) {
                $candidates = array_values(array_filter($candidates, function (array $f) use ($lower): bool {
                    foreach (preg_split('/[^\p{L}]+/u', mb_strtolower((string) $f['label'])) ?: [] as $word) {
                        if (mb_strlen($word) >= 5 && str_contains($lower, mb_substr($word, 0, 5))) {
                            return true;
                        }
                    }

                    return false;
                }));
            }
            if ($candidates !== []) {
                $found[] = $candidates[0]['id'];
            }
        }

        return $found;
    }

    /**
     * Le texte parle-t-il vraiment de ce chiffre ? Oui si sa valeur y est écrite (un nombre reconnu par sa valeur, une date ou un état
     * sous leur forme rédigée), ou si son libellé y est écrit (le début du libellé, avant une précision entre parenthèses ou après une
     * virgule : « Budget prévu »). Sinon la carte n'a rien à faire sous ce texte.
     *
     * @param array<string, mixed> $figure
     */
    private function speaksOf(string $text, array $figure, string $locale): bool
    {
        $lower = mb_strtolower($text);
        $display = $this->display($figure['value'], (string) $figure['unit'], $locale);
        if (in_array($figure['unit'], self::NUMERIC_UNITS, true) && is_numeric($figure['value'])) {
            if (self::unknown($display, self::numbers($text)) === []) {
                return true;
            }
        } elseif ($display !== '' && str_contains($lower, mb_strtolower($display))) {
            return true;
        }
        $head = mb_strtolower(trim((string) (preg_split('/[,(:]/u', (string) $figure['label'])[0] ?? '')));

        return $head !== '' && str_contains($lower, $head);
    }

    /**
     * Chiffres identiques rendus par deux analyses (les ventes de la période dans le résultat ET dans les statistiques) : même grandeur,
     * même unité, même valeur, même plage. Chaque identifiant est ramené à celui de la PREMIÈRE analyse qui le rend : une seule carte.
     *
     * @param list<array<string, mixed>> $results
     *
     * @return array<string, string> identifiant => identifiant gardé
     */
    private static function canonical(array $results): array
    {
        $first = $canon = [];
        foreach ($results as $r) {
            $range = ($r['period']['from'] ?? '') . '|' . ($r['period']['to'] ?? '');
            foreach ($r['figures'] as $f) {
                $name = str_contains((string) $f['id'], '.') ? substr((string) $f['id'], (int) strpos((string) $f['id'], '.') + 1) : (string) $f['id'];
                $same = $name . '|' . $f['unit'] . '|' . json_encode($f['value']) . '|' . $range;
                $canon[$f['id']] = $first[$same] ??= $f['id'];
            }
        }

        return $canon;
    }

    /**
     * L'écran d'où vient la question, tel que le modèle le lit : son code, son titre et son thème écrits par le serveur, ses paramètres,
     * et ses analyses par défaut, chacune déjà contrôlée par le catalogue (`ConsoleScreens`). Null sans écran connu.
     *
     * @param array<string, mixed>|null $context contexte gardé avec la question
     *
     * @return array{code: string, titre: string, theme: string, params: mixed, analysesParDefaut: list<array<string, mixed>>}|null
     */
    private function screen(?array $context, string $locale): ?array
    {
        $code = $context['screen'] ?? null;
        if (!ConsoleScreens::known($code)) {
            return null;
        }
        $params = is_array($context['params'] ?? null) ? $context['params'] : [];
        $defaults = [];
        foreach (ConsoleScreens::defaults($code, $params) as $d) {
            $accepted = $this->analyses->accept($d['key'], $d['params']);
            if ($accepted !== null) {
                $defaults[] = ['key' => $d['key']] + $accepted;
            }
        }

        return ['code' => $code, 'titre' => $this->tr('console.screens.' . str_replace(['.', '-'], '_', $code), $locale), 'theme' => $this->tr('console.themes.' . ConsoleScreens::theme($code, $params), $locale),
            'params' => $params ?: new \stdClass(), 'analysesParDefaut' => $defaults];
    }

    /**
     * Paramètres d'une analyse choisie, complétés par ceux de l'écran : ce que le modèle laisse vide est pris à l'écran. Une période ou
     * une plage nommée par le modèle l'emporte sur celles de l'écran.
     *
     * @param array<string, mixed> $params @param array<string, mixed> $screenParams
     *
     * @return array<string, mixed>
     */
    private static function withScreen(string $key, array $params, array $screenParams, ?string $screen = null): array
    {
        // les paramètres que l'écran montre, puis ceux qu'il fixe à cette analyse (l'horizon de 90 jours de la Trésorerie)
        $own = ConsoleScreens::own($key, $screenParams) + ($screen === null ? [] : ConsoleScreens::fixed($screen, $key));
        if (isset($params['periodId']) || isset($params['range'])) {
            unset($own['periodId'], $own['range']);
        }

        return $params + $own;
    }

    /**
     * Une question qui dit « période » lit les ventes sur la PÉRIODE, pas sur le mois : `sales.overview` choisie sans période prend
     * celle des autres analyses de la réponse, sinon celle de l'écran, sinon la période en cours. Une durée nommée dans la question
     * (aujourd'hui, semaine, mois, année) garde la plage choisie.
     *
     * @param list<array{0: string, 1: array<string, mixed>}> $selected @param array<string, mixed> $screenParams
     *
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function salesOnThePeriod(array $selected, string $question, array $screenParams): array
    {
        if (preg_match('/\bp[ée]riodes?\b/iu', $question) !== 1 || preg_match('/aujourd|today|\bjours?\b|\bdays?\b|semaine|week|\bmois\b|month|\bann[ée]e|\byear/iu', $question) === 1) {
            return $selected;
        }
        $period = null;
        foreach ($selected as [$key, $params]) {
            $period ??= $key !== 'sales.overview' && isset($params['periodId']) ? (int) $params['periodId'] : null;
        }
        foreach ($selected as $i => [$key, $params]) {
            if ($key === 'sales.overview' && !isset($params['periodId'])) {
                $selected[$i][1] = ['periodId' => $period ?? (isset($screenParams['periodId']) ? (int) $screenParams['periodId'] : (int) $this->calendar->current()->getId())];
            }
        }

        return $selected;
    }

    /**
     * Propositions : une action du catalogue, des identifiants présents dans les résultats, et le contrôle d'ouverture d'un fil.
     *
     * @param list<array<string, mixed>> $written
     * @param list<array<string, mixed>> $results
     *
     * @return list<array{action: string, params: array<string, mixed>, label: string}>
     */
    private function proposals(array $written, array $results, string $locale): array
    {
        $products = $periods = [];
        foreach ($results as $r) {
            foreach ($r['rows'] as $row) {
                if (isset($row['productId'])) {
                    $products[(int) $row['productId']] = true;
                }
            }
            $params = (array) $r['source']['params'];
            if (isset($params['periodId'])) {
                $periods[(int) $params['periodId']] = true;
            }
            if (isset($params['productId'])) {
                $products[(int) $params['productId']] = true;
            }
        }
        $out = [];
        foreach ($written as $p) {
            $action = $p['action'] ?? null;
            if (!is_string($action) || !in_array($action, ActionCatalog::KEYS, true) || count($out) >= self::MAX_PROPOSALS) {
                continue;
            }
            $ids = array_values(array_unique(array_filter(is_array($p['productIds'] ?? null) ? $p['productIds'] : [], fn ($id) => is_int($id) && isset($products[$id]))));
            $params = match (true) {
                $action === 'price.set' => $ids === [] ? null : ['scope' => ['productIds' => $ids]],
                $action === 'product.cost.set' => count($ids) === 1 ? ['productId' => $ids[0]] : null,
                in_array($action, ['budget.put', 'period.close'], true) => is_int($p['periodId'] ?? null) && isset($periods[$p['periodId']]) ? ['periodId' => $p['periodId']] : null,
                default => [],
            };
            if ($params === null || !$this->actions->accepts($action, $params, $locale)) {
                continue; // identifiant absent des résultats, ou paramètres refusés par le contrôle d'ouverture du fil
            }
            $label = GestionAdviceService::cleanText((string) ($p['label'] ?? '')) ?? '';
            if ($label === '' || self::numbers($label) !== []) {
                $label = $this->tr('conversation.actions.' . str_replace('.', '_', $action) . '.title', $locale);
            }
            $out[] = ['action' => $action, 'params' => $params ?: new \stdClass(), 'label' => mb_substr($label, 0, 120)];
        }

        return $out;
    }

    /**
     * Bloc `lesson` : la notion telle que le lexique l'écrit, et son illustration avec les chiffres du site (null sans donnée utile).
     *
     * @param array<string, mixed> $entry entrée du lexique
     *
     * @return array<string, mixed>
     */
    private function lessonBlock(array $entry, ?string $illustration): array
    {
        return ['type' => 'lesson', 'key' => $entry['key'], 'term' => $entry['term'], 'definition' => $entry['definition'], 'illustration' => $illustration,
            'related' => $entry['related'], 'quiz' => $entry['quiz'],
            'source' => ['screen' => 'lexique', 'route' => 'GET /api/gestion/lexique/{term}', 'params' => ['term' => $entry['key']]]];
    }

    /**
     * Mode « apprendre » : à la fin de la réponse, les leçons des notions des chiffres montrés (`term` de chaque carte), dans l'ordre
     * d'importance du lexique, sans répéter une leçon déjà là, `MAX_LESSONS` au plus en tout. Aucun appel au modèle : l'illustration
     * est écrite avec les chiffres de l'analyse qui porte la notion, quand ils suffisent.
     *
     * @param list<array<string, mixed>> $blocks @param list<array<string, mixed>> $results
     *
     * @return list<array<string, mixed>>
     */
    private function withLessons(array $blocks, array $results, string $locale): array
    {
        $present = array_column(array_filter($blocks, fn (array $b) => $b['type'] === 'lesson'), 'key');
        $terms = [];
        foreach ($blocks as $b) {
            foreach ($b['type'] === 'figures' ? $b['items'] : [] as $item) {
                if (isset($item['term'])) {
                    $terms[$item['term']] = true;
                }
            }
        }
        foreach (LexiqueCatalog::keys() as $key) {
            if (count($present) >= self::MAX_LESSONS) {
                break;
            }
            if (!isset($terms[$key]) || in_array($key, $present, true)) {
                continue;
            }
            $illustration = null;
            foreach ($results as $r) {
                if (in_array($key, array_column($r['figures'], 'term'), true)) {
                    $illustration ??= $this->illustration->of($key, $r['figures'], $r['period'], $locale);
                }
            }
            $blocks[] = $this->lessonBlock($this->lexique->entry($key, $locale), $illustration);
            $present[] = $key;
        }

        return $blocks;
    }

    /** @param list<string> $ids @param list<array<string, mixed>> $results */
    private function figuresBlock(array $ids, array $results): array
    {
        $items = [];
        foreach ($results as $r) {
            foreach ($r['figures'] as $f) {
                if (in_array($f['id'], $ids, true)) {
                    $items[] = $f + ['source' => $r['source']];
                }
            }
        }

        return ['type' => 'figures', 'items' => $items];
    }

    /** @return list<array{id: int, label: string, from: string, to: string, current: bool}> la période en cours et les précédentes */
    private function periods(string $locale): array
    {
        $current = $this->calendar->current();
        $out = [];
        foreach ($this->emProvider->getEntityManager()->getRepository(Collections::class)->findBy(['kind' => Collections::KIND_PERIOD], ['startDateCollection' => 'DESC'], 12) as $p) {
            if (!$p->isDel() && $p->getStartDateCollection() <= $current->getStartDateCollection() && count($out) < 8) {
                $out[] = ['id' => (int) $p->getId(), 'label' => PeriodCalendar::displayName($p, $locale), 'from' => $p->getStartDateCollection()->format('Y-m-d'), 'to' => $p->getEndDateCollection()->format('Y-m-d'), 'current' => $p->getId() === $current->getId()];
            }
        }

        return $out;
    }

    /** Écriture d'une valeur telle que le gérant la lit : « 1 234,56 CAD », « 45,2 % », « 2,7 », une date, un texte */
    private function display(mixed $value, string $unit, string $locale): string
    {
        if (in_array($unit, ['money', 'percent'], true) && is_numeric($value)) {
            return $this->format->format($value + 0, $unit, $locale);
        }
        if ($unit === 'date' && is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return RangeLabel::day($value, $locale); // « 11 novembre 2026 », jamais « 2026-11-11 »
        }
        if (is_float($value)) {
            return str_replace('.', $locale === 'en' ? '.' : ',', rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.'));
        }

        return (string) $value;
    }

    /**
     * Les nombres écrits dans un texte, quelle que soit leur écriture : « 25,00 $ », « 25 $ » et « 25,00 » valent 25 ; « 1 234,56 »
     * (espace, insécable ou non, entre les milliers) et « 1,234.56 » (virgule des milliers à l'anglaise) valent 1234,56. Un nombre
     * est donc comparé par sa valeur, jamais par ses caractères.
     *
     * @return list<float>
     */
    public static function numbers(string $text): array
    {
        $t = preg_replace('/(?<=\d)[\x{0020}\x{00A0}\x{202F}](?=\d{3}(?!\d))/u', '', $text) ?? $text; // 1 234 → 1234
        $t = preg_replace_callback('/(?<![\d.,])\d{1,3}(?:,\d{3})+(?![\d,])/', fn (array $m) => str_replace(',', '', $m[0]), $t) ?? $t; // 1,234(.56) → 1234(.56)
        preg_match_all('/\d+(?:[.,]\d+)?/', $t, $m);

        return array_values(array_unique(array_map(fn (string $n) => round((float) str_replace(',', '.', $n), 4), $m[0])));
    }

    /** @param list<float> $allowed @return list<float> nombres du texte que le serveur n'a pas fournis */
    public static function unknown(string $text, array $allowed): array
    {
        return array_values(array_filter(self::numbers($text), function (float $n) use ($allowed): bool {
            foreach ($allowed as $a) {
                if (abs($a - $n) < 0.005) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** @param list<string> $figureIds @return array<string, mixed> */
    private static function text(string $text, array $figureIds = []): array
    {
        return ['type' => 'text', 'text' => $text, 'figureIds' => $figureIds];
    }

    /** @param list<array<string, mixed>> $blocks texte seul d'une réponse */
    private static function plain(array $blocks): string
    {
        return trim(implode(' ', array_map(fn (array $b) => match ($b['type'] ?? null) {
            'text' => (string) $b['text'],
            'lesson' => $b['term'] . ' : ' . $b['definition'], // une leçon compte dans l'échange : la question suivante peut s'y référer
            default => '',
        }, array_filter($blocks, 'is_array'))));
    }

    /** @param array<string, string> $params */
    private function tr(string $id, string $locale, array $params = []): string
    {
        return $this->translator->trans($id, $params, 'gestion', $locale === 'en' ? 'en' : 'fr');
    }
}
