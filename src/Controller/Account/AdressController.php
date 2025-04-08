<?php

namespace App\Controller\Account;

use App\Entity\Adress;
use App\Form\AdressType;
use App\Services\CartServices;
use App\Repository\AdressRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route('/adress')]
class AdressController extends AbstractController
{
    private $session;
    public function __construct(RequestStack $requestStack)
    {
        $this->session = $requestStack->getSession();
    }
    #[Route('/', name: 'app_adress_index', methods: ['GET'])]
    public function index(AdressRepository $adressRepository): Response
    {
        return $this->render('adress/index.html.twig', [
            'adresses' => $adressRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_adress_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        AdressRepository $adressRepository,
        CartServices $cartServices
    ): Response {
        $adress = new Adress();
        $form = $this->createForm(AdressType::class, $adress);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user = $this->getUser();
            $adress->setUserAdress($user);
            $adressRepository->save($adress, true);

            if ($cartServices->getFullCart()) {
                return $this->redirectToRoute('app_check_out');
            }

            $this->addFlash('adress_message', 'your message has been saved');
            return $this->redirectToRoute(
                'app_account',
                [],
                Response::HTTP_SEE_OTHER
            );
        }

        return $this->renderForm('adress/new.html.twig', [
            'adress' => $adress,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_adress_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        Adress $adress,
        AdressRepository $adressRepository
    ): Response {
        $form = $this->createForm(AdressType::class, $adress);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $adressRepository->save($adress, true);

            if($this->session->get('checkout_data')){
                $data = $this->session->get('checkout_data');
                $data['address'] = $adress;
                $this->session->set('checkout_data',$data);
                return $this->redirectToRoute('checkout_confirm');
            }
            $this->addFlash('adress_message', 'your message has been edited');
            return $this->redirectToRoute(
                'app_account',
                [],
                Response::HTTP_SEE_OTHER
            );
        }

        return $this->renderForm('adress/edit.html.twig', [
            'adress' => $adress,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_adress_delete', methods: ['POST'])]
    public function delete(
        Request $request,
        Adress $adress,
        AdressRepository $adressRepository
    ): Response {
        if (
            $this->isCsrfTokenValid(
                'delete' . $adress->getId(),
                $request->request->get('_token')
            )
        ) {
            $adressRepository->remove($adress, true);
            $this->addFlash('adress_message', 'your message has been deleted');
        }

        return $this->redirectToRoute(
            'app_account',
            [],
            Response::HTTP_SEE_OTHER
        );
    }
}
