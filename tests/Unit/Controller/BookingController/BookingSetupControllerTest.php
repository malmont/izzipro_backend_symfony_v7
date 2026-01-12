<?php

namespace App\Tests\Unit\Controller\BookingController;

use App\Controller\BookingController\BookingSetupController;
use App\Entity\Product;
use App\Services\Booking\BookingAuthService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\Booking\UpdateBookingConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;

class BookingSetupControllerTest extends TestCase
{
    private $authService;
    private $tenantManager;
    private $emProvider;
    private $controller;
    private $container;

    // Container services mocks
    private $twig;
    private $formFactory;
    private $router;
    private $requestStack;
    private $session;
    private $flashBag;

    protected function setUp(): void
    {
        $this->authService = $this->createMock(BookingAuthService::class);
        $this->tenantManager = $this->createMock(TenantConnectionManager::class);
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);

        $this->controller = new BookingSetupController(
            $this->authService,
            $this->tenantManager,
            $this->emProvider
        );

        $this->container = $this->createMock(ContainerInterface::class);
        $this->controller->setContainer($this->container);

        // Mock container services
        $this->twig = $this->createMock(Environment::class);
        $this->formFactory = $this->createMock(FormFactoryInterface::class);
        $this->router = $this->createMock(RouterInterface::class);
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->session = $this->createMock(Session::class);
        $this->flashBag = $this->createMock(FlashBagInterface::class);

        // Setup RequestStack chain for addFlash
        $this->requestStack->method('getSession')->willReturn($this->session);
        $this->session->method('getFlashBag')->willReturn($this->flashBag);

        // Configure container map
        $this->container->method('has')->willReturnMap([
            ['twig', true],
            ['form.factory', true],
            ['router', true],
            ['request_stack', true],
        ]);

        $this->container->method('get')->willReturnMap([
            ['twig', $this->twig],
            ['form.factory', $this->formFactory],
            ['router', $this->router],
            ['request_stack', $this->requestStack],
        ]);
    }

    public function testAuthPageRender(): void
    {
        $this->tenantManager->method('getCurrentTenantCode')->willReturn('test-shop');
        $this->authService->method('isAuthenticated')->willReturn(false);

        // Form mock
        $form = $this->createMock(FormInterface::class);
        $this->formFactory->method('create')->willReturn($form);
        $form->method('createView')->willReturn(new \Symfony\Component\Form\FormView());

        $this->twig->expects($this->once())
            ->method('render')
            ->with('booking_setup/auth.html.twig', $this->anything())
            ->willReturn('Rendered Content');

        $request = new Request();
        $response = $this->controller->auth($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testAuthRedirectIfAuthenticated(): void
    {
        $this->tenantManager->method('getCurrentTenantCode')->willReturn('test-shop');
        $this->authService->method('isAuthenticated')->willReturn(true);

        $this->router->expects($this->once())
            ->method('generate')
            ->with('app_booking_list')
            ->willReturn('/booking-setup/list');

        $request = new Request();
        $response = $this->controller->auth($request);

        $this->assertEquals(Response::HTTP_FOUND, $response->getStatusCode());
    }

    public function testAuthSuccess(): void
    {
        $this->tenantManager->method('getCurrentTenantCode')->willReturn('test-shop');
        $this->authService->method('isAuthenticated')->willReturn(false);

        $form = $this->createMock(FormInterface::class);
        $this->formFactory->method('create')->willReturn($form);

        $form->method('handleRequest');
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);

        $form->method('get')->with('token')->willReturn($form);
        $form->method('getData')->willReturn('valid-token');

        $this->authService->expects($this->once())
            ->method('attemptLogin')
            ->with('valid-token')
            ->willReturn(true);

        $this->flashBag->expects($this->once())->method('add')->with('success', 'Connexion réussie.');

        $this->router->expects($this->once())
            ->method('generate')
            ->with('app_booking_list')
            ->willReturn('/booking-setup/list');

        $request = new Request();
        $response = $this->controller->auth($request);

        $this->assertEquals(Response::HTTP_FOUND, $response->getStatusCode());
    }

    public function testListAccessDenied(): void
    {
        $this->authService->method('isAuthenticated')->willReturn(false);

        $this->router->expects($this->once())
            ->method('generate')
            ->with('app_booking_auth')
            ->willReturn('/booking-setup/');

        $response = $this->controller->list();

        $this->assertEquals(Response::HTTP_FOUND, $response->getStatusCode());
    }

    public function testListRender(): void
    {
        $this->authService->method('isAuthenticated')->willReturn(true);

        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(EntityRepository::class);

        $this->emProvider->method('getEntityManager')->willReturn($em);
        $em->method('getRepository')->with(Product::class)->willReturn($repo);
        $repo->method('findAll')->willReturn([]);

        $this->twig->expects($this->once())
            ->method('render')
            ->with('booking_setup/list.html.twig')
            ->willReturn('List Content');

        $response = $this->controller->list();

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testConfigureAccessDenied(): void
    {
        $this->authService->method('isAuthenticated')->willReturn(false);

        // Use mocks to avoid actually creating Product entity with deps if complex, 
        // but Product is usually simple or we can just pass null if typehint allows (it doesn't here, strictly)
        // We'll trust Product is simple enough to instantiate
        $product = new Product();
        $request = new Request();
        $useCase = $this->createMock(UpdateBookingConfiguration::class);

        $this->router->expects($this->once())
            ->method('generate')
            ->with('app_booking_auth')
            ->willReturn('/booking-setup/');

        $response = $this->controller->configure($product, $request, $useCase);

        $this->assertEquals(Response::HTTP_FOUND, $response->getStatusCode());
    }

    public function testLogout(): void
    {
        $this->authService->expects($this->once())->method('logout');
        $this->flashBag->expects($this->once())->method('add')->with('info');

        $this->router->expects($this->once())
            ->method('generate')
            ->with('app_booking_auth')
            ->willReturn('/booking-setup/');

        $response = $this->controller->logout();

        $this->assertEquals(Response::HTTP_FOUND, $response->getStatusCode());
    }
}
