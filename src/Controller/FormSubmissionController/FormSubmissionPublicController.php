<?php

namespace App\Controller\FormSubmissionController;

use App\Dto\FormSubmissionInputDto;
use App\UseCase\FormSubmissionUseCase\FormSubmissionException;
use App\UseCase\FormSubmissionUseCase\SubmitFormUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Envoi d'un formulaire à étapes par un visiteur (public, 28/10/2026) : limite par IP (PublicEndpointRateLimitSubscriber,
 * seuls les envois acceptés comptent), corps de 64 Ko au plus. Réponses :
 * 201 { reference } ; 400 ; 404 { code: form_not_found } ; 409 { code: form_changed | slot_taken } ;
 * 413 ; 422 { message: "Formulaire incomplet", errors: [{ path: "answers.<name>", message }] } ; 429.
 */
class FormSubmissionPublicController extends AbstractController
{
    public function __construct(private readonly SubmitFormUseCase $submit)
    {
    }

    #[Route('/api/form-submissions', name: 'api_form_submissions_submit', methods: ['POST'])]
    public function submit(Request $request): JsonResponse
    {
        $content = $request->getContent();
        if (strlen($content) > FormSubmissionInputDto::MAX_BODY_BYTES) {
            return $this->json(['message' => sprintf('Demande trop volumineuse (%d Ko au plus).', FormSubmissionInputDto::MAX_BODY_BYTES / 1024)], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        try {
            $result = $this->submit->execute(json_decode($content, false), (string) ($request->headers->get('X-Tenant-Host') ?: $request->getHost()));
        } catch (FormSubmissionException $e) {
            return $this->json(['message' => $e->getMessage()] + ($e->reason ? ['code' => $e->reason] : []) + ($e->errors ? ['errors' => $e->errors] : []), $e->getStatusCode());
        } catch (\Throwable $e) {
            error_log(sprintf('[form-submissions] envoi en échec : %s : %s', $e::class, $e->getMessage()));

            return $this->json(['message' => 'Une erreur est survenue : votre demande n\'a pas été enregistrée. Réessayez dans un instant.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json($result, Response::HTTP_CREATED);
    }
}
