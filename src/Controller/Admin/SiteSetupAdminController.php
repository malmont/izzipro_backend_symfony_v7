<?php

namespace App\Controller\Admin;

use App\Services\LandingAiService\LandingAiException;
use App\Services\Platform\AdminStepUrlResolver;
use App\Services\SiteCharter\SiteCharterService;
use App\Services\SiteCharter\SiteCompositionApplier;
use App\Services\SiteCharter\SiteSetupQuestionnaireService;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Mon site pas à pas » (08/11/2026) : questionnaire en langage courant (activité, public, lieu, objectifs, pages, style, couleurs et
 * polices, charte en texte ou PDF, boutique), résumé de ce que l'assistant va composer, « ce qu'il vous reste à faire, et où », puis
 * composition du site complet par l'assistant (tâche de fond), écran d'attente et application sur le site.
 */
#[IsGranted('ROLE_ADMIN')]
class SiteSetupAdminController extends AbstractController
{
    private const CSRF = 'site_setup';

    public function __construct(
        private readonly SiteSetupQuestionnaireService $questionnaire,
        private readonly SiteCompositionApplier $applier,
        private readonly SiteCharterService $charters,
        private readonly AdminUrlGenerator $urls,
        private readonly AdminStepUrlResolver $screens,
    ) {
    }

    #[Route('/admin/site/configurer', name: 'admin_site_configurer', methods: ['GET'])]
    public function index(): Response
    {
        return $this->page($this->questionnaire->answers(), []);
    }

    #[Route('/admin/site/configurer', name: 'admin_site_configurer_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Formulaire expiré : réessayez.');

            return $this->redirect($this->pageUrl());
        }
        $post = $request->request->all();
        $upload = $request->files->get('document');
        $document = $upload !== null && $upload->isValid() ? ['content' => (string) file_get_contents($upload->getPathname()), 'name' => (string) $upload->getClientOriginalName()] : null;
        $result = $this->questionnaire->apply($post, $document, (bool) $request->request->get('removeDocument'), $this->getUser()?->getUserIdentifier(), $request->getSchemeAndHttpHost(), 'fr');
        if ($result['errors'] !== []) {
            $this->addFlash('danger', sprintf('%d réponse(s) à corriger : rien n\'a été enregistré.', count($result['errors'])));
            $answers = $this->questionnaire->answers();
            foreach ($post as $k => $v) {
                if (array_key_exists($k, $answers)) {
                    $answers[$k] = $v;
                }
            }

            return $this->page($answers, $result['errors']);
        }
        $this->addFlash('success', 'Réponses enregistrées : voici ce que l\'assistant va composer, et ce qu\'il vous reste à faire.');

        return $this->redirect($this->pageUrl() . '#resume');
    }

    #[Route('/admin/site/configurer/composer', name: 'admin_site_configurer_compose', methods: ['POST'])]
    public function compose(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Formulaire expiré : réessayez.');

            return $this->redirect($this->pageUrl());
        }
        try {
            $launch = $this->questionnaire->compose($this->getUser()?->getUserIdentifier());
        } catch (LandingAiException $e) {
            $this->addFlash('danger', 'Composition non lancée : ' . $e->getMessage());

            return $this->redirect($this->pageUrl() . '#resume');
        }
        $this->addFlash('success', 'L\'assistant compose votre site : comptez une à trois minutes.');

        return $this->redirect($this->compositionUrl($launch['jobId']));
    }

    #[Route('/admin/site/configurer/composition/{jobId}', name: 'admin_site_configurer_composition', methods: ['GET'], requirements: ['jobId' => '[0-9a-f-]{36}'])]
    public function composition(string $jobId): Response
    {
        $status = $this->applier->status($jobId);

        return $this->render('admin/site/composition.html.twig', [
            'jobId' => $jobId, 'status' => $status, 'pageUrl' => $this->pageUrl(), 'refreshUrl' => $this->compositionUrl($jobId),
            'applyUrl' => $this->urls->unsetAll()->setRoute('admin_site_configurer_apply', ['jobId' => $jobId])->generateUrl(),
            'csrf_id' => self::CSRF,
            'composedAt' => $this->charters->get()?->getComposedAt(), 'applied' => $this->charters->get()?->getLastJobId() === $jobId && $this->charters->get()?->getComposedAt() !== null,
        ]);
    }

    #[Route('/admin/site/configurer/composition/{jobId}/appliquer', name: 'admin_site_configurer_apply', methods: ['POST'], requirements: ['jobId' => '[0-9a-f-]{36}'])]
    public function apply(string $jobId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Formulaire expiré : réessayez.');

            return $this->redirect($this->compositionUrl($jobId));
        }
        $result = $this->applier->apply($jobId);
        if (!$result['applied']) {
            $this->addFlash('danger', 'Composition non appliquée : ' . implode(' ; ', $result['errors']));

            return $this->redirect($this->compositionUrl($jobId));
        }
        $this->addFlash('success', sprintf('Votre site est composé : %d page(s) enregistrée(s). Regardez-le en ligne, puis retouchez dans l\'éditeur si besoin.', $result['tabs']));

        return $this->redirect($this->compositionUrl($jobId));
    }

    private function page(array $answers, array $errors): Response
    {
        $summary = $this->questionnaire->summary();
        $next = [];
        foreach ($summary['next'] as $row) {
            $row['url'] = $row['where'] === 'backend' && $row['screen'] !== null ? $this->screens->screenUrl($row['screen']) : null;
            $next[] = $row;
        }

        return $this->render('admin/site/configurer.html.twig', [
            'a' => $answers, 'errors' => $errors, 'summary' => $summary, 'next' => $next, 'shop' => $this->questionnaire->shopEnabled(),
            'options' => ['goals' => SiteCharterService::BRIEF_GOALS, 'pages' => SiteCharterService::PAGE_CHOICES, 'style' => SiteCharterService::STYLE_OPTIONS, 'handover' => SiteCharterService::HANDOVER],
            'saveUrl' => $this->urls->unsetAll()->setRoute('admin_site_configurer_save')->generateUrl(),
            'composeUrl' => $this->urls->unsetAll()->setRoute('admin_site_configurer_compose')->generateUrl(),
            'lastJobUrl' => ($job = $this->charters->get()?->getLastJobId()) !== null ? $this->compositionUrl($job) : null,
            'csrf_id' => self::CSRF,
            'monSiteUrl' => $this->urls->unsetAll()->setRoute('admin_mon_site')->generateUrl(),
        ]);
    }

    private function pageUrl(): string
    {
        return $this->urls->unsetAll()->setRoute('admin_site_configurer')->generateUrl();
    }

    private function compositionUrl(string $jobId): string
    {
        return $this->urls->unsetAll()->setRoute('admin_site_configurer_composition', ['jobId' => $jobId])->generateUrl();
    }
}
