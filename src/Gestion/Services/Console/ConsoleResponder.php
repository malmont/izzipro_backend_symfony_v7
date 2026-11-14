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
 * Lot W2-d (13/11/2026, après un échange réel où « 2 juin » faisait garder la carte d'un compte de 2, et « 0 % » celle d'un budget de
 * 0,00 $) : un nombre du texte est reconnu selon sa NATURE (montant, pourcentage, nombre nu ; jamais le jour d'une date) et un nombre nu
 * ne désigne un chiffre qu'avec un mot de son libellé dans la même phrase ; les cartes sont placées en deux passes, chaque chiffre sous
 * le premier texte qui l'évoque ; un tableau rend ses colonnes libellées et ses cellules rédigées ; un état est donné au modèle en
 * minuscule, pour s'écrire au milieu d'une phrase.
 *
 * Lot W2-APPRENDRE A-4 (13/11/2026) : le GUIDE de l'écran d'où vient la question (analyse `screen.guide`) est un bloc `guide` écrit
 * par le serveur. Demandé seul (« explique-moi cet écran »), il est assemblé sans appel 2, avec les chiffres des analyses de l'écran :
 * aucune unité. Un état qui n'est pas un jugement (« sans prévu », « aucun solde saisi ») est donné au modèle par une phrase entière.
 *
 * Lot A-4b (14/11/2026, après l'essai réel) : « explique-moi cet écran », sans autre demande, est reconnu par le SERVEUR, qui répond par
 * le guide et les chiffres du moment sans aucun appel au modèle (le modèle ajoutait des analyses, donc une rédaction et une unité).
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
    /** Chiffre d'état => jeu de libellés de son code (`console.values.*`, `console.sentences.*`) */
    private const STATE_SETS = ['cashStatus' => 'cash', 'budgetStatus' => 'budget', 'verdict' => 'verdict'];
    /** Chiffres montrés par analyse de l'écran sous un guide demandé seul */
    private const GUIDE_FIGURES = 6;
    /** Champs d'une ligne dont la valeur est un code : le modèle en reçoit le libellé rédigé (jeu de libellés `console.values.*`) */
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

    /**
     * Vrai si le serveur répondra à cette question en attente SANS AUCUN appel au modèle (aujourd'hui : « explique-moi cet écran »,
     * lot A-4b). Le POST y répond alors dans la requête même (`GestionConsoleUseCase::ask`) au lieu de confier la réponse au worker.
     * Une définition seule ou un guide choisi par le modèle demandent l'appel 1 : ils restent en attente.
     */
    public function answersAlone(int $messageId): bool
    {
        $em = $this->emProvider->getEntityManager();
        $message = $em->find(GestionConversationMessage::class, $messageId);
        $question = $message === null ? null : $em->find(GestionConversationMessage::class, (int) ($message->getContent()['replyTo'] ?? 0));
        $context = $question?->getContent()['context'] ?? null;

        return $question !== null && $this->guideOnly($this->screen(is_array($context) ? $context : null, $message->getConversation()->getLocale()), (string) ($question->getContent()['text'] ?? ''));
    }

    /** @param array<string, mixed>|null $screen l'écran connu d'où vient la question */
    private function guideOnly(?array $screen, string $text): bool
    {
        return $screen !== null && self::asksOnlyForTheScreen($text) && $this->analyses->accept('screen.guide', ['screen' => $screen['code']]) !== null;
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
            // « Explique-moi cet écran », sans autre demande (lot A-4b) : c'est le SERVEUR qui répond, par le guide de l'écran et ses chiffres
            // du moment. Aucun appel au modèle, aucune unité : le comportement ne dépend pas de ce que le modèle aurait choisi.
            if ($this->guideOnly($screen, $text)) {
                $choice = ['kind' => 'analyses', 'analyses' => [['key' => 'screen.guide', 'params' => []]], 'clarification' => ''];
            } else {
                $choice = $this->model->choose($text, $screen, array_reverse(array_slice($history, 0, ConsoleService::HISTORY_MESSAGES)), $this->analyses->listed($locale), $this->periods($locale), $this->calendar->today(), $locale, $stats,
                    array_map(fn (array $e) => ['key' => $e['key'], 'term' => $e['term'], 'synonymes' => $e['synonyms']], $this->lexique->all($locale)));
            }

            if ($choice['kind'] === 'out_of_scope') {
                // Hors périmètre : le serveur répond lui-même, gratuitement
                $this->console->finish($messageId, $content, ConsoleService::STATE_DONE, ['blocks' => [self::text($this->tr('console.out_of_scope', $locale))]], false, $stats);

                return;
            }
            $selected = [];
            foreach ($choice['kind'] === 'analyses' ? $choice['analyses'] : [] as $a) {
                if ($a['key'] === 'screen.guide') {
                    $a['params'] = ['screen' => $screen['code'] ?? null]; // l'écran du guide est celui du contexte, jamais celui que dirait le modèle
                }
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
                } elseif ($r['guide'] !== null) {
                    $lessons[] = ['type' => 'guide'] + $r['guide'] + ['source' => $r['source']];
                }
            }
            // les analyses de la réponse, lues au moment de la rendre : un guide demandé seul y ajoute celles de l'écran
            $listed = function () use (&$results): array {
                return array_map(fn (array $r) => ['key' => $r['key'], 'ref' => $r['ref'], 'title' => $r['title'], 'params' => $r['lesson'] === null ? $r['source']['params'] : ['term' => $r['lesson']['key']], 'source' => $r['source'], 'period' => $r['period'], 'computedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)], $results);
            };
            if (count($lessons) === count($results)) {
                // La question ne demande QUE des notions : la réponse est assemblée par le serveur (la leçon, puis ses chiffres), sans appel 2.
                // Aucune unité : rien n'est rédigé par le modèle. Comptée au journal, pour savoir combien de questions sont des définitions.
                $blocks = [];
                $taught = [];
                foreach ($results as $i => $r) {
                    $blocks[] = $lessons[$i];
                    $taught[] = $r['lesson']['key'] ?? ('écran ' . $r['guide']['screen']);
                    if ($r['figures'] !== []) {
                        $blocks[] = $this->figuresBlock(array_column($r['figures'], 'id'), $results);
                    }
                }
                if (array_filter($results, fn (array $r) => $r['guide'] !== null) !== []) {
                    // « explique-moi cet écran » : après le guide, les chiffres du moment de l'écran, par ses analyses par défaut
                    foreach ($screen['analysesParDefaut'] ?? [] as $default) {
                        $r = $this->analyses->run($default['key'], array_diff_key($default, ['key' => 1]), $locale);
                        $r['ref'] = 'a' . (count($results) + 1);
                        foreach ($r['figures'] as $i => $f) {
                            $r['figures'][$i]['id'] = $r['ref'] . '.' . $f['id'];
                        }
                        $results[] = $r;
                        $canon = self::canonical($results);
                        $own = array_slice(array_values(array_filter(array_column($r['figures'], 'id'), fn (string $id) => $canon[$id] === $id)), 0, self::GUIDE_FIGURES);
                        if ($own !== []) {
                            $blocks[] = $this->figuresBlock($own, $results);
                        }
                    }
                }
                $this->logger->notice(sprintf('[Console] leçon : réponse %d assemblée par le serveur, sans rédaction (%s)', $messageId, implode(', ', $taught)));
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
                // un état (chiffre à code) est donné en minuscule : il s'écrit au milieu d'une phrase (« la trésorerie est correcte ») ; la carte garde sa majuscule
                $written = $this->display($f['value'], (string) $f['unit'], $locale);
                $figure = ['id' => $f['id'], 'label' => $note((string) $f['label']), 'display' => $note(isset($f['code']) ? $this->state($f, mb_strtolower($written), $locale) : $written)];
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
                        isset(AnalysisCatalog::CODED[$k]) && is_string($v) => $note(mb_strtolower(AnalysisCatalog::valueLabel($this->translator, AnalysisCatalog::CODED[$k], $v, $locale))), // « à monter », jamais « raise » ; en minuscule : il s'écrit au milieu d'une phrase
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
            if ($r['guide'] !== null) {
                $lesson = ['guide' => ['ecran' => $note((string) $r['guide']['title']), 'role' => $note((string) $r['guide']['purpose']),
                    'points' => array_map(fn (array $p) => ['libelle' => $note((string) $p['label']), 'texte' => $note((string) $p['text'])], $r['guide']['points'])]];
            }
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
                // colonnes libellées avec leur unité ; cellules rédigées (un état, un verdict, un mois), le code gardé à part (`statusCode`)
                $blocks[] = ['type' => 'table', 'title' => $r['title'], 'columns' => $this->analyses->columns($r['key'], $locale), 'rows' => array_map(fn (array $row) => $this->writtenRow($row, $locale), $r['rows']), 'more' => $r['more'], 'source' => $r['source']];
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

        // Placement des cartes, en deux passes (lot W2-d). 1. Chaque chiffre appartient au PREMIER texte qui l'évoque. 2. Sa carte suit
        // ce texte ; un bloc `figures` du modèle ne garde que les chiffres qu'aucun texte n'évoque, et disparaît s'il est vide. Une
        // carte par chiffre dans la réponse, jamais deux, et jamais loin du texte qui en parle.
        $owner = [];
        foreach ($blocks as $i => $b) {
            foreach ($b['type'] === 'text' ? $b['figureIds'] : [] as $id) {
                $owner[$id] ??= $i;
            }
        }
        $out = [];
        foreach ($blocks as $i => $b) {
            if ($b['type'] === 'figures') {
                $free = array_values(array_filter(array_column($b['items'], 'id'), fn (string $id) => !isset($owner[$id])));
                if ($free !== []) {
                    $out[] = $this->figuresBlock($free, $results);
                    $owner += array_fill_keys($free, $i); // montré ici : un bloc suivant du modèle ne le répète pas
                }
                continue;
            }
            $out[] = $b;
            $own = $b['type'] === 'text' ? array_values(array_filter($b['figureIds'], fn (string $id) => $owner[$id] === $i)) : [];
            if ($own !== []) {
                $out[] = $this->figuresBlock($own, $results);
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
        $canon = self::canonical($results);
        $found = [];
        foreach (self::tokens($text) as $token) {
            $candidates = [];
            foreach ($results as $r) {
                foreach ($r['figures'] as $f) {
                    if ($canon[$f['id']] === $f['id'] && $this->written($token, $f, $locale)) {
                        $candidates[] = $f;
                    }
                }
            }
            if ($candidates === [] || array_intersect(array_column($candidates, 'id'), array_merge($cited, $found)) !== []) {
                continue;
            }
            // Un nombre nu ne désigne un chiffre que si un mot de son libellé est dans la même phrase (« 33 » avec « commandes »). Un
            // montant ou un taux désigne son chiffre ; porté par deux chiffres différents, le libellé dans la phrase tranche, sinon rien.
            if ($token['kind'] === 'plain' || count($candidates) > 1) {
                // le chiffre dont le libellé est le mieux écrit dans la phrase ; à égalité (ou sans aucun mot), on ne devine pas
                $scores = array_map(fn (array $f) => self::named($token['sentence'], (string) $f['label']), $candidates);
                $best = max($scores);
                $candidates = $best <= 0.0 || count(array_keys($scores, $best, true)) !== 1 ? [] : [$candidates[(int) array_search($best, $scores, true)]];
            }
            if ($candidates !== []) {
                $found[] = $candidates[0]['id'];
            }
        }

        return $found;
    }

    /**
     * Les nombres d'un texte avec leur NATURE (lot W2-d) : `money` (accolé à un signe ou à un code de devise : « 957,00 $ », « $957.00 »,
     * « 25,00 CAD »), `percent` (suivi de %), `plain` (ni l'un ni l'autre). Le jour et l'année d'une date (« du 2 juin au 31 août
     * 2026 », « November 11, 2026 », « 2026-11-11 ») ne sont pas des nombres cités. Chaque nombre garde la phrase où il est écrit.
     *
     * @return list<array{value: float, kind: string, sentence: string}>
     */
    public static function tokens(string $text): array
    {
        $months = 'janvier|février|fevrier|mars|avril|mai|juin|juillet|août|aout|septembre|octobre|novembre|décembre|decembre|january|february|march|april|may|june|july|august|september|october|november|december';
        $out = [];
        foreach (preg_split('/(?<=[.!?…])\s+(?=\p{Lu}|\d)/u', $text) ?: [] as $sentence) {
            $t = preg_replace('/(?<=\d)[\x{0020}\x{00A0}\x{202F}](?=\d{3}(?!\d))/u', '', $sentence) ?? $sentence; // 1 234 → 1234
            $t = preg_replace_callback('/(?<![\d.,])\d{1,3}(?:,\d{3})+(?![\d,])/', fn (array $m) => str_replace(',', '', $m[0]), $t) ?? $t; // 1,234 → 1234
            // les dates sortent du texte avant de lire les nombres
            $t = preg_replace(['/\b\d{4}-\d{2}-\d{2}\b/', "/\b(?:1er|\d{1,2})\s+(?:$months)(?:\s+\d{4})?\b/iu", "/\b(?:$months)\s+\d{1,2}(?:,?\s+\d{4})?\b/iu", "/\b(?:$months)\s+\d{4}\b/iu"], ' ', $t) ?? $t;
            preg_match_all('/(?P<pre>[$€£]\s?)?(?P<num>\d+(?:[.,]\d+)?)(?P<post>[\x{0020}\x{00A0}\x{202F}]?(?:%|[$€£]|[A-Z]{3}\b))?/u', $t, $found, PREG_SET_ORDER);
            foreach ($found as $m) {
                $post = trim((string) ($m['post'] ?? ''), " \u{00A0}\u{202F}");
                $kind = $post === '%' ? 'percent' : (($m['pre'] ?? '') !== '' || $post !== '' ? 'money' : 'plain');
                $out[] = ['value' => round((float) str_replace(',', '.', $m['num']), 4), 'kind' => $kind, 'sentence' => $sentence];
            }
        }

        return $out;
    }

    /**
     * Ce nombre du texte est-il la valeur de ce chiffre, écrite selon sa nature ? Un montant pour un chiffre en argent, un pourcentage
     * pour un taux, un nombre nu pour un compte, des jours ou un rapport.
     *
     * @param array{value: float, kind: string, sentence: string} $token @param array<string, mixed> $figure
     */
    private function written(array $token, array $figure, string $locale): bool
    {
        $kind = match ($figure['unit']) { 'money' => 'money', 'percent' => 'percent', 'count', 'days', 'ratio' => 'plain', default => null };
        if ($kind === null || $kind !== $token['kind'] || !is_numeric($figure['value'])) {
            return false;
        }

        return self::unknown($this->display($figure['value'], (string) $figure['unit'], $locale), [$token['value']]) === [];
    }

    /**
     * Le libellé est-il écrit dans cette phrase ? La PART de ses mots (4 lettres ou plus, reconnus par leur début) qu'on y lit, de 0 à
     * 1 : « Commandes » dans « 33 commandes » : 1 ; « Notes de frais » dans « vos frais » : 0,5. Seul compte le début du libellé, avant
     * une précision entre parenthèses ou après une virgule (« Panier moyen », pas les mots de sa formule).
     */
    private static function named(string $sentence, string $label): float
    {
        $lower = mb_strtolower($sentence);
        $words = array_values(array_filter(array_unique(preg_split('/[^\p{L}]+/u', mb_strtolower((string) (preg_split('/[,(:]/u', $label)[0] ?? ''))) ?: []), fn (string $w) => mb_strlen($w) >= 4));
        if ($words === []) {
            return 0.0;
        }

        return round(count(array_filter($words, fn (string $w) => str_contains($lower, mb_substr($w, 0, 5)))) / count($words), 4);
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
        if (in_array($figure['unit'], self::NUMERIC_UNITS, true) && is_numeric($figure['value'])) {
            // la valeur, écrite selon sa nature (lot W2-d) : le « 2 » de « 2 juin » n'est pas un compte, le « 0 » de « 0 % » pas un montant ;
            // un nombre nu ne compte qu'avec un mot du libellé dans la même phrase
            foreach (self::tokens($text) as $token) {
                if ($this->written($token, $figure, $locale) && ($token['kind'] !== 'plain' || self::named($token['sentence'], (string) $figure['label']) > 0)) {
                    return true;
                }
            }
        } else {
            $display = $this->display($figure['value'], (string) $figure['unit'], $locale);
            if ($display !== '' && str_contains($lower, mb_strtolower($display))) {
                return true;
            }
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
     * La question demande-t-elle SEULEMENT d'expliquer l'écran ? Oui si elle porte une demande d'explication (« explique-moi », « à quoi
     * sert », « c'est quoi », « je ne comprends pas », « qu'est-ce que je dois regarder »…) ET désigne l'écran (« cet écran », « cette
     * page », « ici »), sans autre demande : ni seconde proposition (après « : », « ; », « ? », ou « et … »), ni question d'état
     * (« est-ce que ça va », « va-t-elle tenir », « combien », « pourquoi »). Alors le guide seul répond, sans le modèle.
     */
    public static function asksOnlyForTheScreen(string $question): bool
    {
        $q = mb_strtolower(trim(str_replace(['’', '  '], ["'", ' '], $question)));
        $explain = preg_match("/expli(que|quer|quez)|à quoi (sert|servent)|c'est quoi|qu'est-ce que c'est|je ne comprends (pas|rien)|comment (lire|marche|fonctionne|utiliser)|qu'est-ce que je (dois|peux) (regarder|voir|faire|lire)|que (dois-je|faut-il) (regarder|voir|faire)|aide-moi|explain|what is|what's|what should i (look|do|see)|i (don't|do not) understand|how (do i (read|use)|does)/u", $q) === 1;
        $here = preg_match("/\\b(cet écran|cette page|cet onglet|ce tableau de bord|ici|this screen|this page|here)\\b/u", $q) === 1;
        if (!$explain || !$here) {
            return false;
        }
        $state = preg_match("/(?<!qu')est-ce qu|ça va|va-t-|vont-|\\btenir\\b|\\bcombien\\b|\\bpourquoi\\b|\\binqui|\\bis it\\b|\\bare (we|they)\\b|\\bhow (much|many)\\b|\\bwhy\\b/u", $q) === 1;
        $second = preg_match('/[?:;]\\s*\\S/u', rtrim($q, ' ?!.')) === 1 || preg_match("/\\bet\\s+(est|que|comment|combien|pourquoi|si|dis)\\b|\\band\\s+(is|are|how|why|what|tell)\\b/u", $q) === 1;

        return !$state && !$second;
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
     * L'état d'un chiffre à code, tel que le modèle le lit : une PHRASE entière du serveur quand l'état n'est pas un jugement (« Aucun
     * budget n'est prévu pour cette période », « Aucun solde n'a encore été saisi » : rien à glisser dans « est … »), sinon son libellé
     * en minuscule (« correct », « à monter »).
     *
     * @param array<string, mixed> $figure
     */
    private function state(array $figure, string $lowercase, string $locale): string
    {
        $name = str_contains((string) $figure['id'], '.') ? substr((string) $figure['id'], (int) strrpos((string) $figure['id'], '.') + 1) : (string) $figure['id'];
        $id = 'console.sentences.' . (self::STATE_SETS[$name] ?? 'x') . '.' . $figure['code'];
        $sentence = $this->translator->trans($id, [], 'gestion', $locale === 'en' ? 'en' : 'fr');

        return $sentence === $id ? $lowercase : $sentence;
    }

    /**
     * Ligne d'un tableau telle que l'application l'affiche : un champ à code (état du budget, verdict, confiance) rend son libellé
     * rédigé, le code restant à côté (`statusCode`, `verdictCode`) ; un mois « 2026-11 » rend « novembre 2026 » (`monthKey` garde la clé).
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function writtenRow(array $row, string $locale): array
    {
        foreach (AnalysisCatalog::CODED as $field => $set) {
            if (is_string($row[$field] ?? null)) {
                $row[$field . 'Code'] = $row[$field];
                $row[$field] = AnalysisCatalog::valueLabel($this->translator, $set, $row[$field], $locale);
            }
        }
        if (is_string($row['month'] ?? null) && preg_match('/^\d{4}-\d{2}$/', $row['month']) === 1) {
            $row['monthKey'] = $row['month'];
            $row['month'] = RangeLabel::month($row['month'], $locale);
        }

        return $row;
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
            'guide' => $b['title'] . ' : ' . $b['purpose'],
            default => '',
        }, array_filter($blocks, 'is_array'))));
    }

    /** @param array<string, string> $params */
    private function tr(string $id, string $locale, array $params = []): string
    {
        return $this->translator->trans($id, $params, 'gestion', $locale === 'en' ? 'en' : 'fr');
    }
}
