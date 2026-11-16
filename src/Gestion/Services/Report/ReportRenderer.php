<?php

namespace App\Gestion\Services\Report;

use App\Entity\Entreprise;
use App\Services\CaisseService\StoreClock;
use App\Services\TenantEntityManagerProvider;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Lot DOC-1 (15/11/2026, `docs/rapports-gestion.md`) : met en page un rapport de la gestion. Deux temps, pour que les tests lisent ce
 * qui est écrit avant sa mise en PDF : `document()` range les blocs d'une réponse de la console en sections déjà RÉDIGÉES (montants à
 * la devise du site, pourcentages, dates, à la langue demandée) ; `html()` puis `pdf()` les mettent en page (A4 portrait, en-tête du
 * site, pied « page x / y »).
 *
 * Rien n'est recalculé et aucun modèle n'est appelé : un chiffre du document est celui du bloc gardé. Un bloc d'un type inconnu est
 * laissé de côté ; les questions de vérification d'une leçon (un jeu de l'écran) ne vont pas au papier.
 */
class ReportRenderer
{
    public const LOGO_MAX_BYTES = 500_000;
    private const LOGO_TYPES = ['image/png', 'image/jpeg', 'image/gif'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly StoreClock $clock,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    /**
     * Le document d'une réponse de la console : ses blocs, tels qu'ils ont été gardés.
     *
     * @param array<string, mixed> $answer   la réponse rendue par `ConsoleService::message` (blocks, analyses, answeredAt)
     * @param string               $question la question posée
     * @return array<string, mixed> { title, question, answeredLabel, sections, sources, site, labels, locale }
     */
    public function document(array $answer, string $question, string $locale, string $currency): array
    {
        $t = fn (string $key, array $params = []): string => $this->tr($key, $locale, $params);
        $title = mb_substr(trim($question), 0, 120);

        return [
            'title' => $title !== '' ? $title : $t('answer_title'), 'kindLabel' => $t('answer_title'), 'subtitle' => null, 'notice' => null, 'question' => trim($question), 'questionLabel' => $t('question'),
            'answeredLabel' => $t('computed_at', ['%moment%' => $this->moment($answer['answeredAt'] ?? null, $locale)]), 'sections' => self::keepTitles($this->sections((array) ($answer['blocks'] ?? []), $locale, $currency)),
            'sources' => $this->sources((array) ($answer['analyses'] ?? []), null, $locale), 'labels' => $this->labels($locale), 'site' => $this->site(), 'locale' => $locale === 'en' ? 'en' : 'fr',
        ];
    }

    /**
     * Lot DOC-2 : le document d'un bilan de période ou d'année, depuis le contenu calculé à la demande (`ReportReview`). Avec `advice`,
     * la partie « Avis de la console » ferme le bilan : les blocs de la réponse de la console, ou une ligne qui dit qu'elle n'a pas pu
     * être rédigée.
     *
     * @param array<string, mixed>      $content
     * @param array<string, mixed>|null $advice  null : avis non demandé ; sinon `{ included, blocks, answeredAt }`
     * @return array<string, mixed>
     */
    public function review(array $content, ?array $advice, string $locale, string $currency): array
    {
        $t = fn (string $key, array $params = []): string => $this->tr($key, $locale, $params);
        $sections = $this->sections((array) ($content['blocks'] ?? []), $locale, $currency);
        if ($advice !== null) {
            $included = ($advice['included'] ?? false) === true;
            $sections[] = ['type' => 'heading', 'title' => $t('advice_title'), 'note' => $included ? $t('advice_note', ['%moment%' => $this->moment($advice['answeredAt'] ?? null, $locale)]) : ''];
            $sections = $included ? [...$sections, ...$this->sections((array) ($advice['blocks'] ?? []), $locale, $currency)] : [...$sections, ['type' => 'text', 'paragraphs' => [$t('advice_missing')]]];
        }

        return [
            'title' => (string) ($content['title'] ?? ''), 'kindLabel' => (string) ($content['kindLabel'] ?? ''), 'subtitle' => (string) ($content['range'] ?? '') !== '' ? (string) $content['range'] : null,
            'notice' => is_string($content['notice'] ?? null) && $content['notice'] !== '' ? $content['notice'] : null, 'question' => '', 'questionLabel' => $t('question'),
            'answeredLabel' => $t('computed_at', ['%moment%' => $this->moment($content['computedAt'] ?? null, $locale)]), 'sections' => self::keepTitles($sections),
            'sources' => $this->sources((array) ($content['analyses'] ?? []), is_string($content['computedAt'] ?? null) ? $content['computedAt'] : null, $locale),
            'labels' => $this->labels($locale), 'site' => $this->site(), 'locale' => $locale === 'en' ? 'en' : 'fr',
        ];
    }

    /**
     * Un titre ne reste jamais seul en bas de page (retouche du 16/11/2026). Chaque suite de titres de partie est tenue avec le premier
     * bloc qui la suit, et un bloc qui porte son propre titre (tableau, verdicts, guide) est tenu avec lui : la première section du
     * groupe porte `keepOpen`, la dernière `keepClose`, et le gabarit les enferme dans un cadre qui passe ENTIER à la page suivante
     * quand la place manque. Un bloc trop long pour tenir d'un seul tenant (`fitsTogether`) n'est pas enfermé : son titre reste tenu
     * « au mieux » par la règle `page-break-after: avoid`.
     *
     * @param list<array<string, mixed>> $sections
     * @return list<array<string, mixed>>
     */
    private static function keepTitles(array $sections): array
    {
        $count = count($sections);
        for ($i = 0; $i < $count; ++$i) {
            $start = $i;
            while ($i < $count && $sections[$i]['type'] === 'heading') {
                ++$i;
            }
            if ($i >= $count) {
                break; // des titres en fin de document : rien à tenir avec eux
            }
            $titled = $start < $i || (in_array($sections[$i]['type'], ['table', 'verdicts', 'guide'], true) && ($sections[$i]['title'] ?? '') !== '');
            if ($titled && self::fitsTogether($sections[$i])) {
                $sections[$start]['keepOpen'] = true;
                $sections[$i]['keepClose'] = true;
            }
        }

        return $sections;
    }

    /** Ce bloc est-il assez court pour passer d'un seul tenant à la page suivante ? @param array<string, mixed> $section */
    private static function fitsTogether(array $section): bool
    {
        return match ($section['type']) {
            'figures' => count($section['rows'] ?? []) <= 6,
            'table' => count($section['rows'] ?? []) <= 25,
            'verdicts' => count($section['items'] ?? []) <= 20,
            'guide' => count($section['points'] ?? []) <= 5,
            'text' => mb_strlen(implode('', $section['paragraphs'] ?? [])) <= 1500,
            'lesson' => true,
            default => false,
        };
    }

    /**
     * Les blocs (ceux de la console, plus `heading` pour le titre d'une partie d'un bilan) rangés en sections déjà rédigées.
     *
     * @param array<int, mixed> $blocks
     * @return list<array<string, mixed>>
     */
    private function sections(array $blocks, string $locale, string $currency): array
    {
        $t = fn (string $key, array $params = []): string => $this->tr($key, $locale, $params);
        $write = static fn (mixed $value, ?string $unit): string => ReportValue::write($value, $unit, $locale, $currency);
        $sections = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            switch ($block['type'] ?? null) {
                case 'heading':
                    if (trim((string) ($block['title'] ?? '')) !== '') {
                        $sections[] = ['type' => 'heading', 'title' => (string) $block['title'], 'note' => (string) ($block['note'] ?? '')];
                    }
                    break;
                case 'text':
                    if (trim((string) ($block['text'] ?? '')) !== '') {
                        $sections[] = ['type' => 'text', 'paragraphs' => preg_split('/\n{2,}/', trim((string) $block['text'])) ?: []];
                    }
                    break;
                case 'figures':
                    $cards = [];
                    foreach ((array) ($block['items'] ?? []) as $item) {
                        if (is_array($item)) {
                            $unit = $item['unit'] ?? null;
                            $compared = [];
                            // les comparaisons sont celles que l'analyse a gardées avec la carte (clés à plat) : rien n'est recalculé
                            // lot AG-3a : un an dernier venu des ventes d'avant la migration est nommé comme tel sur la carte
                            $lastYearLabel = ($item['lastYearSource'] ?? null) === \App\Import\OrderArchive::SOURCE ? 'compare_last_year_before_migration' : 'compare_last_year';
                            foreach ([['previous', 'deltaPercent', 'compare_previous'], ['lastYear', 'deltaPercentLastYear', $lastYearLabel]] as [$key, $percent, $label]) {
                                if (isset($item[$key]) && is_numeric($item[$key])) {
                                    // la variation entre parenthèses est rendue à part : le gabarit l'écrit sans coupure possible
                                    $change = isset($item[$percent]) && is_numeric($item[$percent]) ? '(' . ((float) $item[$percent] > 0 ? '+' : '') . $write($item[$percent], 'percent') . ')' : null;
                                    $compared[] = ['text' => $t($label) . ' ' . $write($item[$key], $unit), 'change' => $change];
                                }
                            }
                            $cards[] = ['label' => (string) ($item['label'] ?? ''), 'value' => $write($item['value'] ?? null, $unit), 'compared' => $compared];
                        }
                    }
                    if ($cards !== []) {
                        $sections[] = ['type' => 'figures', 'rows' => array_chunk($cards, 3)];
                    }
                    break;
                case 'table':
                    $columns = array_values(array_filter((array) ($block['columns'] ?? []), 'is_array'));
                    $rows = [];
                    foreach ((array) ($block['rows'] ?? []) as $row) {
                        if (is_array($row)) {
                            $rows[] = array_map(static fn (array $c): array => ['text' => $write($row[$c['key'] ?? ''] ?? null, $c['unit'] ?? 'text'), 'number' => !in_array($c['unit'] ?? 'text', ['text', 'month', 'date'], true)], $columns);
                        }
                    }
                    // `more` : { count, total } quand la console n'a gardé qu'une partie des lignes (`count` : celles qui ne sont pas là), sinon null
                    $more = is_array($block['more'] ?? null) ? (int) ($block['more']['count'] ?? 0) : (is_numeric($block['more'] ?? null) ? (int) $block['more'] : 0);
                    $sections[] = ['type' => 'table', 'title' => (string) ($block['title'] ?? ''), 'columns' => array_map(static fn (array $c): array => ['label' => (string) ($c['label'] ?? ''), 'number' => !in_array($c['unit'] ?? 'text', ['text', 'month', 'date'], true)], $columns),
                        'rows' => $rows, 'more' => $more > 0 ? $t('table_more', ['%count%' => $more]) : null];
                    break;
                case 'verdicts':
                    $items = [];
                    foreach ((array) ($block['items'] ?? []) as $item) {
                        if (is_array($item)) {
                            $verdict = (string) ($item['verdict'] ?? '');
                            $items[] = ['subject' => (string) ($item['subject']['name'] ?? ''), 'verdict' => in_array($verdict, ['keep', 'raise', 'lower'], true) ? $t('verdict.' . $verdict) : $verdict, 'why' => (string) ($item['why'] ?? '')];
                        }
                    }
                    $groups = [];
                    foreach ((array) ($block['groups'] ?? []) as $group) {
                        if (is_array($group) && isset($group['label'])) {
                            $groups[] = ['label' => (string) $group['label'], 'count' => (int) ($group['count'] ?? 0)];
                        }
                    }
                    if ($items !== [] || $groups !== []) {
                        $sections[] = ['type' => 'verdicts', 'title' => $t('verdicts_title'), 'groups' => $groups, 'items' => $items];
                    }
                    break;
                case 'lesson':
                    $related = [];
                    foreach ((array) ($block['related'] ?? []) as $r) {
                        $related[] = is_array($r) ? (string) ($r['term'] ?? $r['key'] ?? '') : (string) $r;
                    }
                    $sections[] = ['type' => 'lesson', 'term' => (string) ($block['term'] ?? ''), 'definition' => (string) ($block['definition'] ?? ''),
                        'illustration' => is_string($block['illustration'] ?? null) ? $block['illustration'] : null, 'related' => array_values(array_filter($related)), 'relatedLabel' => $t('lesson_related')];
                    break;
                case 'guide':
                    $points = [];
                    foreach ((array) ($block['points'] ?? []) as $point) {
                        if (is_array($point)) {
                            $points[] = ['label' => (string) ($point['label'] ?? ''), 'text' => (string) ($point['text'] ?? '')];
                        }
                    }
                    $sections[] = ['type' => 'guide', 'title' => (string) ($block['title'] ?? ''), 'purpose' => (string) ($block['purpose'] ?? ''), 'points' => $points, 'next' => is_string($block['next'] ?? null) ? $block['next'] : null];
                    break;
            }
        }

        return $sections;
    }

    /**
     * @param array<int, mixed> $analyses
     * @param string|null       $computedAt l'instant commun du calcul (bilan) ; null : celui de chaque analyse (réponse de la console)
     * @return list<array{title: string, period: string, computedAt: string}>
     */
    private function sources(array $analyses, ?string $computedAt, string $locale): array
    {
        $sources = [];
        foreach ($analyses as $analysis) {
            if (is_array($analysis)) {
                $sources[] = ['title' => (string) ($analysis['title'] ?? ''), 'period' => is_array($analysis['period'] ?? null) ? (string) ($analysis['period']['label'] ?? '') : '',
                    'computedAt' => $this->moment($computedAt ?? ($analysis['computedAt'] ?? null), $locale)];
            }
        }

        return $sources;
    }

    /** @return array<string, string> */
    private function labels(string $locale): array
    {
        return ['sources' => $this->tr('sources_title', $locale), 'computed' => $this->tr('source_computed', $locale), 'page' => $this->tr('page', $locale), 'next' => $this->tr('guide_next', $locale)];
    }

    /** @param array<string, string|int> $params */
    private function tr(string $key, string $locale, array $params = []): string
    {
        return $this->translator->trans('reports.' . $key, $params, 'gestion', $locale === 'en' ? 'en' : 'fr');
    }

    /** @param array<string, mixed> $document */
    public function html(array $document): string
    {
        return $this->twig->render('gestion/report/document.html.twig', ['doc' => $document]);
    }

    /**
     * @param array<string, mixed> $document
     * @return array{content: string, pages: int}
     */
    public function pdf(array $document): array
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($document), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $pages = $dompdf->getCanvas()->get_page_count();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $dompdf->getCanvas()->page_text(500, 812, $document['labels']['page'] . ' {PAGE_NUM} / {PAGE_COUNT}', $font, 8, [0.4, 0.4, 0.4]);
        $dompdf->getCanvas()->page_text(40, 812, mb_substr((string) $document['site']['name'], 0, 70), $font, 8, [0.4, 0.4, 0.4]);

        return ['content' => (string) $dompdf->output(), 'pages' => $pages];
    }

    /** Ce qui change quand l'en-tête du site change (nom, adresse, numéros, logo) : un rapport fait avant n'est plus « le même » */
    public function siteKey(): string
    {
        $site = $this->site();

        return hash('sha256', (string) json_encode([$site['name'], $site['lines'], $site['logo'] !== null ? hash('sha256', $site['logo']) : null]));
    }

    /** @return array{name: string, logo: ?string, lines: list<string>} l'en-tête du site : nom, logo, adresse, numéros d'entreprise saisis */
    private function site(): array
    {
        $entreprise = $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([], ['id' => 'ASC']);
        $lines = [];
        $address = $entreprise?->getAddressEntreprise();
        if ($address !== null) {
            $street = trim(implode(' ', array_filter([(string) $address->getStreet1(), (string) $address->getStreet2()])));
            $city = trim(implode(' ', array_filter([(string) $address->getZip(), (string) $address->getCity(), (string) $address->getState()])));
            $lines = array_values(array_filter([$street, trim($city . ((string) $address->getCountry() !== '' ? ', ' . $address->getCountry() : ''), ', ')]));
        }
        foreach (['SIRET' => $entreprise?->getSiret(), 'TPS / GST' => $entreprise?->getGstNumber(), 'TVQ / QST' => $entreprise?->getQstNumber()] as $label => $number) {
            if (is_string($number) && trim($number) !== '') {
                $lines[] = $label . ' : ' . trim($number);
            }
        }

        return ['name' => (string) ($entreprise?->getName() ?: ''), 'logo' => $this->logo($entreprise?->getLogo()), 'lines' => $lines];
    }

    /** Le logo du site en image intégrée (jamais une adresse distante), ou null : fichier absent, trop lourd ou d'un type non pris */
    private function logo(?string $stored): ?string
    {
        if ($stored === null || $stored === '' || str_starts_with($stored, 'http') || str_contains($stored, '..')) {
            return null;
        }
        $base = $this->projectDir . '/var/storage/public_bucket/';
        $path = str_contains($stored, '/') ? $base . ltrim($stored, '/') : $base . 'assets/uploads/email-logos/' . $stored;
        if (!is_file($path) || filesize($path) > self::LOGO_MAX_BYTES) {
            return null;
        }
        $type = (string) (new \finfo(\FILEINFO_MIME_TYPE))->file($path);

        return in_array($type, self::LOGO_TYPES, true) ? 'data:' . $type . ';base64,' . base64_encode((string) file_get_contents($path)) : null;
    }

    /** « 14 novembre 2026 à 15 h 42 » (heure du magasin), ou un tiret */
    private function moment(mixed $iso, string $locale): string
    {
        if (!is_string($iso) || $iso === '') {
            return ReportValue::NONE;
        }
        try {
            $at = (new \DateTimeImmutable($iso))->setTimezone($this->clock->timezone());
        } catch (\Throwable) {
            return ReportValue::NONE;
        }
        $formatter = new \IntlDateFormatter($locale === 'en' ? 'en_CA' : 'fr_CA', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $this->clock->timezone()->getName(), null,
            $locale === 'en' ? "MMMM d, y 'at' h:mm a" : "d MMMM y 'à' HH 'h' mm");
        $written = $formatter->format($at);

        return $written === false ? $at->format('Y-m-d H:i') : $written;
    }
}
