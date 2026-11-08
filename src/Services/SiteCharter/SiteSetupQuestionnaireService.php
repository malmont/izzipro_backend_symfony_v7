<?php

namespace App\Services\SiteCharter;

use App\Dto\LandingAiComposeInputDto;
use App\Entity\Entreprise;
use App\Services\BoutiqueSettingsService\BoutiqueConfigurationValidator;
use App\Services\BoutiqueSettingsService\BoutiqueSettingsService;
use App\Services\LandingAiService\LandingAiCatalogue;
use App\Services\LandingAiService\LandingAiException;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\LandingAiService\LandingAiSiteOrchestrator;
use App\Services\Platform\EntitlementService;
use App\Services\SiteContentService\SiteContentSpec;
use App\Services\SiteContentService\SiteDemoContentService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\LandingAiUseCase\ComposeLandingSectionUseCase;
use App\UseCase\SiteFeaturesUseCase\UpdateSiteFeaturesUseCase;

/**
 * Questionnaire « Mon site pas à pas » (08/11/2026) : huit questions en langage courant qui remplissent la charte et le brief du site
 * (`SiteCharterService`), les modes de vente de la boutique (registre `commerce.sales_modes`), puis un résumé en phrases de ce que
 * l'assistant va composer et « ce qu'il vous reste à faire, et où » (`SiteRouting`). `compose()` lance la composition du site complet
 * (mode `site` de l'assistant : accueil + une page par onglet voulu) ; `SiteCompositionApplier` enregistre le résultat.
 */
class SiteSetupQuestionnaireService
{
    public function __construct(
        private readonly SiteCharterService $charters,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionManager $tenants,
        private readonly EntitlementService $entitlements,
        private readonly BoutiqueSettingsService $boutique,
        private readonly UpdateSiteFeaturesUseCase $siteFeatures,
        private readonly ComposeLandingSectionUseCase $compose,
        private readonly LandingAiQuotaService $quota,
        private readonly ?SiteDemoContentService $demo = null, // contenu d'exemple (lot 3, 08/11/2026)
        private readonly ?LandingAiCatalogue $catalogue = null, // pages de la boutique (08/11/2026)
    ) {
    }

    /** Pages système de la boutique par défaut dans le questionnaire */
    public const SHOP_PAGES_DEFAULT = ['catalogue', 'product', 'cart', 'checkout', 'account', 'login'];

    /** @return array<string, string> clé de page système => titre dans la langue du site */
    public function shopPages(): array
    {
        $locale = $this->answers()['locale'] === 'en' ? 'en' : 'fr';
        $pages = [];
        foreach ($this->catalogue?->systemPages() ?? [] as $key => $page) {
            $pages[$key] = (string) ($page['title'][$locale] ?? $page['title']['fr'] ?? $key);
        }

        return $pages;
    }

    /**
     * Compose les pages de la boutique (accueil de la boutique + pages système choisies), avec la charte du site.
     *
     * @param list<string> $pages clés de pages système
     * @return array{jobId: string, credits: array<string, mixed>}
     * @throws LandingAiException
     */
    public function composeShop(array $pages, ?string $by): array
    {
        $known = array_keys($this->shopPages());
        $pages = array_values(array_unique(array_filter($pages, fn ($p) => is_string($p) && in_array($p, $known, true))));
        if ($pages === []) {
            throw LandingAiException::badRequest('Choisissez au moins une page de la boutique.');
        }
        $a = $this->answers();
        $body = (object) [
            'mode' => 'site', 'app' => 'boutique', 'locale' => $a['locale'] === 'en' ? 'en' : 'fr',
            'prompt' => mb_substr('Pages de la boutique en ligne. ' . $this->prompt(), 0, LandingAiComposeInputDto::MAX_PROMPT_LENGTH),
            'pages' => array_slice($pages, 0, LandingAiComposeInputDto::MAX_SITE_PAGES),
        ];
        $result = $this->compose->execute(LandingAiComposeInputDto::fromRequestBody($body), $by);
        if ($result->status !== 202 || !isset($result->body['jobId'])) {
            throw LandingAiException::badRequest('La composition n\'a pas pu être lancée.');
        }
        $this->charters->rememberJob((string) $result->body['jobId'], $by);

        return ['jobId' => (string) $result->body['jobId'], 'credits' => $result->body['credits'] ?? []];
    }

    /** Fiches d'exemple de la landing page encore présentes (lot 3) */
    public function demoCount(): int
    {
        try {
            return $this->demo !== null ? count($this->demo->list('fr', SiteContentSpec::resourcesFor(['landing']))) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    public function shopEnabled(): bool
    {
        $code = $this->tenants->getCurrentTenantCode();

        return $code !== null && $this->entitlements->isEnabled($code, 'module.boutique');
    }

    /** @return array<string, mixed> réponses actuelles : charte enregistrée, sinon fiche Entreprise et adresse */
    public function answers(): array
    {
        $em = $this->emProvider->getEntityManager();
        $entreprise = $em->getRepository(Entreprise::class)->findOneBy([], ['id' => 'ASC']);
        $address = $em->getConnection()->fetchAssociative('SELECT city, state, country FROM address_entreprise ORDER BY id LIMIT 1') ?: [];
        $charter = $this->charters->get();
        $brief = $charter?->getBrief() ?? [];
        $c = $charter?->getCharter() ?? [];
        $style = $charter?->getStyle() ?? [];
        $place = $brief['place'] ?? trim(implode(', ', array_filter([$address['city'] ?? null, $address['state'] ?? null])));
        $conf = $this->boutique->findOrCreateSettings()->getConfiguration();
        $salesModes = is_array($conf['commerce']['salesModes'] ?? null) ? array_values($conf['commerce']['salesModes']) : BoutiqueConfigurationValidator::SALES_MODES;

        return [
            'companyName' => (string) ($entreprise?->getName() ?? ''),
            'activity' => $brief['activity'] ?? (string) ($entreprise?->getMetaDescription() ?? ''),
            'audience' => $brief['audience'] ?? '', 'place' => $place, 'goals' => $brief['goals'] ?? [],
            'tone' => $style['tone'] ?? 'equilibre', 'motion' => $style['motion'] ?? 'discret', 'density' => $style['density'] ?? 'normale',
            'pages' => $brief['pages'] ?? ['Services', 'À propos', 'Contact'],
            'primaryColor' => $c['primaryColor'] ?? '', 'accentColor' => $c['accentColor'] ?? '', 'textColor' => $c['textColor'] ?? '', 'backgroundColor' => $c['backgroundColor'] ?? '',
            'headingFont' => $c['headingFont'] ?? '', 'bodyFont' => $c['bodyFont'] ?? '', 'buttonStyle' => $c['buttonStyle'] ?? '', 'radius' => isset($c['radius']) ? (string) $c['radius'] : '',
            'designMd' => $charter?->getDesignMd() ?? '', 'documentName' => $charter?->getDocumentName(),
            'sells' => $brief['sells'] ?? '', 'salesModes' => $salesModes, 'handover' => $brief['handover'] ?? [], 'extra' => $brief['extra'] ?? '',
            'replaceDemo' => $brief === [] ? true : (bool) ($brief['replaceDemo'] ?? false), 'demoCount' => $this->demoCount(),
            'locale' => (string) ($entreprise?->getLocale() ?? 'fr'),
        ];
    }

    /**
     * Enregistre les réponses. Rien n'est écrit tant qu'une réponse est fausse.
     *
     * @param array<string, mixed> $post champs du formulaire
     * @param array{content: string, name: string}|null $document PDF déposé
     * @return array{errors: array<string, string>}
     */
    public function apply(array $post, ?array $document, bool $removeDocument, ?string $by, string $host, string $locale): array
    {
        $errors = [];
        $text = fn (string $k) => trim((string) ($post[$k] ?? ''));
        $list = fn (string $k) => array_values(array_filter(array_map('strval', is_array($post[$k] ?? null) ? $post[$k] : []), fn ($v) => $v !== ''));
        if ($text('activity') === '') {
            $errors['activity'] = 'Dites en une ou deux phrases ce que vous faites : c\'est la base de tout le site.';
        }
        $pages = $list('pages');
        foreach (array_filter(array_map('trim', explode(',', $text('pagesExtra')))) as $extra) {
            $pages[] = $extra;
        }
        $brief = [
            'activity' => $text('activity'), 'audience' => $text('audience'), 'place' => $text('place'), 'goals' => $list('goals'), 'pages' => $pages,
            'sells' => $text('sells'), 'handover' => $list('handover'), 'extra' => $text('extra'), 'replaceDemo' => (bool) ($post['replaceDemo'] ?? false),
        ];
        $charter = array_filter([
            'primaryColor' => $text('primaryColor'), 'accentColor' => $text('accentColor'), 'textColor' => $text('textColor'), 'backgroundColor' => $text('backgroundColor'),
            'headingFont' => $text('headingFont'), 'bodyFont' => $text('bodyFont'), 'buttonStyle' => $text('buttonStyle'),
            'radius' => $text('radius') !== '' && ctype_digit($text('radius')) ? (int) $text('radius') : $text('radius'),
        ], fn ($v) => $v !== '' && $v !== null);
        $style = ['tone' => $text('tone'), 'motion' => $text('motion'), 'density' => $text('density')];
        foreach ($this->charters->save(['brief' => $brief, 'charter' => $charter ?: null, 'style' => $style, 'designMd' => $text('designMd')], null) as $e) {
            // les erreurs de la charte sont relues sans rien écrire : save() refuse tout en bloc
            $key = preg_replace('/^(brief|charter|style)\./', '', $e['path']);
            $errors[$key] = $e['message'];
        }
        $salesModes = $this->shopEnabled() ? array_values(array_intersect(BoutiqueConfigurationValidator::SALES_MODES, $list('salesModes'))) : null;
        if ($salesModes === []) {
            $errors['salesModes'] = 'Choisissez au moins une façon de vendre.';
        }
        if ($document !== null && !str_starts_with($document['content'], '%PDF-')) {
            $errors['document'] = 'Le document doit être un PDF.';
        }
        if ($errors !== []) {
            return ['errors' => $errors];
        }
        $this->charters->save(['brief' => $brief, 'charter' => $charter ?: null, 'style' => $style, 'designMd' => $text('designMd')], $by);
        if ($removeDocument) {
            $this->charters->removeDocument($by);
        }
        if ($document !== null && ($message = $this->charters->storeDocument($document['content'], $document['name'], $by)) !== null) {
            return ['errors' => ['document' => $message]];
        }
        if ($salesModes !== null) {
            $this->siteFeatures->execute(['commerce.sales_modes' => $salesModes], $host, $locale);
        }

        return ['errors' => []];
    }

    /** @return array{lines: list<string>, pages: list<string>, next: list<array{need: string, where: string, screen: ?string, label: string}>, cost: int, credits: array<string, mixed>, ready: bool} */
    public function summary(): array
    {
        $a = $this->answers();
        $lines = [];
        $lines[] = $a['activity'] !== '' ? sprintf('Le site présente « %s » : %s', $a['companyName'] ?: 'votre entreprise', $a['activity']) : 'Vous n\'avez pas encore dit ce que fait le site.';
        if ($a['audience'] !== '') {
            $lines[] = 'Il s\'adresse à : ' . $a['audience'] . '.';
        }
        if ($a['place'] !== '') {
            $lines[] = 'Lieu mis en avant : ' . $a['place'] . '.';
        }
        if ($a['goals'] !== []) {
            $lines[] = 'Objectifs : ' . implode(', ', array_map(fn ($g) => mb_strtolower(SiteCharterService::BRIEF_GOALS[$g] ?? $g), $a['goals'])) . '.';
        }
        $pages = array_merge(['Accueil'], $a['pages']);
        $lines[] = sprintf('%d page(s) dans le menu : %s.', count($pages), implode(', ', $pages));
        $tone = ['sobre' => 'sobre', 'equilibre' => 'équilibré', 'expressif' => 'expressif'][$a['tone']] ?? $a['tone'];
        $motion = ['aucun' => 'sans animation', 'discret' => 'animations discrètes', 'vivant' => 'animations vivantes'][$a['motion']] ?? $a['motion'];
        $lines[] = sprintf('Style %s, %s.', $tone, $motion);
        $lines[] = $a['primaryColor'] !== '' ? sprintf('Couleurs : principale %s%s%s.', $a['primaryColor'], $a['accentColor'] !== '' ? ', accent ' . $a['accentColor'] : '', $a['headingFont'] !== '' ? ' ; titres en ' . $a['headingFont'] : '') : 'Couleurs et polices : l\'assistant les proposera (aucune charte donnée).';
        if ($a['documentName'] !== null) {
            $lines[] = 'Document de charte joint : ' . $a['documentName'] . ' (lu par l\'assistant à chaque composition).';
        } elseif ($a['designMd'] !== '') {
            $lines[] = 'Charte en texte fournie (lue par l\'assistant à chaque composition).';
        }
        if ($this->shopEnabled()) {
            $modes = array_map(fn ($m) => ['sale' => 'vente', 'rental' => 'location', 'subscription' => 'abonnement'][$m] ?? $m, $a['salesModes']);
            $lines[] = sprintf('Boutique : %s%s%s.', $a['sells'] !== '' ? $a['sells'] . ' ; ' : '', 'modes ' . implode(', ', $modes), $a['handover'] !== [] ? ' ; remise par ' . mb_strtolower(implode(' ou ', array_map(fn ($h) => SiteCharterService::HANDOVER[$h] ?? $h, $a['handover']))) : '');
        }
        if ($a['demoCount'] > 0) {
            $lines[] = $a['replaceDemo']
                ? sprintf('Le contenu d\'exemple de la landing page (%d fiche(s)) sera effacé quand vous appliquerez la composition, sauf ce que les pages composées affichent encore.', $a['demoCount'])
                : sprintf('Le contenu d\'exemple de la landing page (%d fiche(s)) est gardé : remplacez-le ou effacez-le depuis chaque écran.', $a['demoCount']);
        }
        $cost = LandingAiQuotaService::cost('site') + count($a['pages']) * LandingAiQuotaService::cost('page');

        return ['lines' => $lines, 'pages' => $pages, 'next' => SiteRouting::TABLE, 'cost' => $cost, 'credits' => $this->quota->credits(), 'ready' => $a['activity'] !== ''];
    }

    /** Texte du brief donné à l'assistant (2 000 caractères au plus) */
    public function prompt(): string
    {
        $a = $this->answers();
        $parts = [sprintf('Site vitrine de « %s ».', $a['companyName'] ?: 'l\'entreprise'), 'Activité : ' . $a['activity']];
        if ($a['audience'] !== '') {
            $parts[] = 'Public visé : ' . $a['audience'];
        }
        if ($a['place'] !== '') {
            $parts[] = 'Lieu : ' . $a['place'];
        }
        if ($a['goals'] !== []) {
            $parts[] = 'Objectifs : ' . implode(', ', array_map(fn ($g) => SiteCharterService::BRIEF_GOALS[$g] ?? $g, $a['goals']));
        }
        if ($this->shopEnabled() && $a['sells'] !== '') {
            $parts[] = 'Boutique en ligne : ' . $a['sells'] . ' (' . implode(', ', $a['salesModes']) . ')';
        }
        if ($a['extra'] !== '') {
            $parts[] = 'Précisions : ' . $a['extra'];
        }
        if ($a['replaceDemo'] && $a['demoCount'] > 0) {
            $parts[] = 'Les contenus existants du site (présentations, bannières, vidéos, logos) sont des exemples fournis à la création : ne t\'y lie que si c\'est indispensable et écris toi-même les textes.';
        }
        $parts[] = 'Écris de vrais textes en ' . ($a['locale'] === 'en' ? 'anglais' : 'français') . ', adaptés à cette entreprise, jamais de texte d\'exemple.';

        return mb_substr(implode(' ', $parts), 0, LandingAiComposeInputDto::MAX_PROMPT_LENGTH);
    }

    /**
     * Lance la composition du site complet. La charte, le style, le DESIGN.md et le PDF du site sont ajoutés par l'assistant lui-même.
     *
     * @return array{jobId: string, credits: array<string, mixed>}
     * @throws LandingAiException (402 crédits, 409 tâche en cours, 400)
     */
    public function compose(?string $by): array
    {
        $a = $this->answers();
        $body = (object) [
            'mode' => 'site', 'app' => 'landingpage', 'locale' => $a['locale'] === 'en' ? 'en' : 'fr', 'prompt' => $this->prompt(),
            'pages' => array_map(fn (string $title) => (object) ['title' => $title], array_slice($a['pages'], 0, LandingAiComposeInputDto::MAX_SITE_PAGES)),
        ];
        $result = $this->compose->execute(LandingAiComposeInputDto::fromRequestBody($body), $by);
        if ($result->status !== 202 || !isset($result->body['jobId'])) {
            throw LandingAiException::badRequest('La composition n\'a pas pu être lancée.');
        }
        $this->charters->rememberJob((string) $result->body['jobId'], $by);

        return ['jobId' => (string) $result->body['jobId'], 'credits' => $result->body['credits'] ?? []];
    }

    public static function siteCost(int $pages): int
    {
        return LandingAiQuotaService::cost('site') + $pages * LandingAiQuotaService::cost('page');
    }
}
