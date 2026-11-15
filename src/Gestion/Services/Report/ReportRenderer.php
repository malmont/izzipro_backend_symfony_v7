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
        $t = fn (string $key, array $params = []): string => $this->translator->trans('reports.' . $key, $params, 'gestion', $locale === 'en' ? 'en' : 'fr');
        $write = static fn (mixed $value, ?string $unit): string => ReportValue::write($value, $unit, $locale, $currency);
        $sections = [];
        foreach ((array) ($answer['blocks'] ?? []) as $block) {
            if (!is_array($block)) {
                continue;
            }
            switch ($block['type'] ?? null) {
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
                            foreach ([['previous', 'deltaPercent', 'compare_previous'], ['lastYear', 'deltaPercentLastYear', 'compare_last_year']] as [$key, $percent, $label]) {
                                if (isset($item[$key]) && is_numeric($item[$key])) {
                                    $change = isset($item[$percent]) && is_numeric($item[$percent]) ? ' (' . ((float) $item[$percent] > 0 ? '+' : '') . $write($item[$percent], 'percent') . ')' : '';
                                    $compared[] = $t($label) . ' ' . $write($item[$key], $unit) . $change;
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
        $sources = [];
        foreach ((array) ($answer['analyses'] ?? []) as $analysis) {
            if (is_array($analysis)) {
                $sources[] = ['title' => (string) ($analysis['title'] ?? ''), 'period' => is_array($analysis['period'] ?? null) ? (string) ($analysis['period']['label'] ?? '') : '',
                    'computedAt' => $this->moment($analysis['computedAt'] ?? null, $locale)];
            }
        }
        $title = mb_substr(trim($question), 0, 120);

        return [
            'title' => $title !== '' ? $title : $t('answer_title'), 'kindLabel' => $t('answer_title'), 'question' => trim($question), 'questionLabel' => $t('question'),
            'answeredLabel' => $t('computed_at', ['%moment%' => $this->moment($answer['answeredAt'] ?? null, $locale)]), 'sections' => $sections, 'sources' => $sources,
            'labels' => ['sources' => $t('sources_title'), 'computed' => $t('source_computed'), 'page' => $t('page'), 'next' => $t('guide_next')],
            'site' => $this->site(), 'locale' => $locale === 'en' ? 'en' : 'fr',
        ];
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
