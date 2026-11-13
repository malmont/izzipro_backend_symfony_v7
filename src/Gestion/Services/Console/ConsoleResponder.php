<?php

namespace App\Gestion\Services\Console;

use App\Entity\Collections;
use App\Gestion\Analysis\AnalysisCatalog;
use App\Gestion\Entity\GestionConversationMessage;
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
 * Unités : 1 par réponse rédigée par le modèle (demande de précision rédigée par lui comprise) ; aucune si le serveur répond lui-même
 * (question hors périmètre, précision demandée par le serveur) ou si l'IA échoue.
 */
class ConsoleResponder
{
    public const MAX_PROPOSALS = 3;
    /**
     * Une réponse de REPLI (les deux essais de rédaction portaient un nombre inconnu : texte du serveur et chiffres des analyses)
     * est-elle décomptée ? Non par défaut : le gérant ne paie pas une réponse que l'IA n'a pas su écrire. Chaque repli est compté
     * (marque `fallback` sur la réponse, ligne « [Console] repli » au journal) : on voit ainsi si le modèle de rédaction doit monter.
     */
    public const CONSOLE_FALLBACK_CHARGED = false;
    /** Champs d'une ligne qui sont des montants en cents */
    private const MONEY_KEYS = ['price', 'cost', 'sales', 'grossMargin', 'result', 'planned', 'actual', 'remaining', 'effect', 'revenue'];
    private const PERCENT_KEYS = ['similarSoldShare'];

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
        $stats = new LandingAiUsageStats(ConsoleModel::MODEL_WRITE);
        try {
            $history = [];
            foreach ($em->getRepository(GestionConversationMessage::class)->findBy(['conversation' => $conversation], ['id' => 'DESC'], ConsoleService::HISTORY_MESSAGES + 2) as $m) {
                if ($m->getId() >= $question->getId()) {
                    continue;
                }
                $history[] = $m->getKind() === ConsoleService::QUESTION ? ['role' => 'gérant', 'text' => (string) ($m->getContent()['text'] ?? '')] : ['role' => 'console', 'text' => self::plain($m->getContent()['blocks'] ?? [])];
            }
            $choice = $this->model->choose($text, $context, array_reverse(array_slice($history, 0, ConsoleService::HISTORY_MESSAGES)), $this->analyses->listed($locale), $this->periods($locale), $this->calendar->today(), $locale, $stats);

            if ($choice['kind'] === 'out_of_scope') {
                // Hors périmètre : le serveur répond lui-même, gratuitement
                $this->console->finish($messageId, $content, ConsoleService::STATE_DONE, ['blocks' => [self::text($this->tr('console.out_of_scope', $locale))]], false, $stats);

                return;
            }
            $selected = [];
            foreach ($choice['kind'] === 'analyses' ? $choice['analyses'] : [] as $a) {
                $params = $this->analyses->accept($a['key'], $a['params']);
                if ($params !== null && !isset($selected[$a['key'] . json_encode($params)])) {
                    $selected[$a['key'] . json_encode($params)] = [$a['key'], $params];
                }
            }
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
            [$forModel, $allowed] = $this->forModel($results, $text, $locale);
            $actions = array_map(fn (string $k) => ['key' => $k, 'title' => $this->tr('conversation.actions.' . str_replace('.', '_', $k) . '.title', $locale)], ActionCatalog::KEYS);

            $written = $this->model->write($text, $forModel, $actions, $locale, $stats);
            [$blocks, $rejected] = $this->blocks($written['blocks'], $results, $allowed, $locale);
            if ($rejected > 0) {
                // un nombre inconnu : un nouvel essai, en le disant au modèle
                $written = $this->model->write($text, $forModel, $actions, $locale, $stats, 'Ta réponse précédente contenait un nombre absent des champs « display ». Recopie les nombres tels quels, sans rien calculer.');
                [$blocks, $rejected] = $this->blocks($written['blocks'], $results, $allowed, $locale);
            }
            $fallback = $rejected > 0 || array_filter($blocks, fn (array $b) => $b['type'] === 'text') === [];
            if ($fallback) {
                // texte de repli écrit par le serveur, à partir des chiffres ; les blocs sûrs du modèle sont gardés
                $this->logger->notice(sprintf('[Console] repli : réponse %d rédigée par le serveur après %d texte(s) rejeté(s)', $messageId, $rejected));
                array_unshift($blocks, self::text($this->tr('console.fallback', $locale)));
                if (array_filter($blocks, fn (array $b) => $b['type'] === 'figures') === []) {
                    foreach ($results as $r) {
                        $blocks[] = $this->figuresBlock(array_column($r['figures'], 'id'), $results);
                    }
                }
            }
            $proposals = $this->proposals($written['proposals'], $results, $locale);
            $this->console->finish($messageId, $content, ConsoleService::STATE_DONE, [
                'blocks' => array_values(array_filter($blocks)),
                'analyses' => array_map(fn (array $r) => ['key' => $r['key'], 'ref' => $r['ref'], 'title' => $r['title'], 'params' => $r['source']['params'], 'source' => $r['source'], 'period' => $r['period'], 'computedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)], $results),
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
                        in_array($k, self::MONEY_KEYS, true) && is_numeric($v) => $note($this->display($v, 'money', $locale)),
                        in_array($k, self::PERCENT_KEYS, true) && is_numeric($v) => $note($this->display($v, 'percent', $locale)),
                        is_float($v) || is_int($v) => $note($this->display($v, 'ratio', $locale)),
                        default => $note((string) $v),
                    };
                }
                $rows[] = $line;
            }
            $out[] = ['ref' => $r['ref'], 'key' => $r['key'], 'title' => $note((string) $r['title']), 'period' => $r['period'] === null ? null : $note((string) $r['period']['label']), 'figures' => $figures, 'rows' => $rows,
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
        $ids = fn (mixed $list): array => array_values(array_filter(is_array($list) ? $list : [], fn ($id) => is_string($id) && isset($known[$id])));
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
                $blocks[] = self::text($text, $ids($b['figureIds'] ?? []));
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
                    $blocks[] = ['type' => 'verdicts', 'items' => array_values($items), 'source' => $byRef[$b['analysis']]['source']];
                }
            }
        }

        return [$blocks, $rejected];
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
        return trim(implode(' ', array_map(fn (array $b) => ($b['type'] ?? null) === 'text' ? (string) $b['text'] : '', array_filter($blocks, 'is_array'))));
    }

    /** @param array<string, string> $params */
    private function tr(string $id, string $locale, array $params = []): string
    {
        return $this->translator->trans($id, $params, 'gestion', $locale === 'en' ? 'en' : 'fr');
    }
}
