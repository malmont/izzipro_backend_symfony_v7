<?php

namespace App\Tests\Unit\Controller\StripeController;

use PHPUnit\Framework\TestCase;
use App\Controller\StripeController\StripeController;
use App\Services\StripeService\StripeService;
use App\Services\TenantConnectionManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Twig\Environment;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Form\FormView;
use App\Entity\StripeConfig;

class StripeControllerTest extends TestCase
{
    private $stripeService;
    private $urlGenerator;
    private $tenantConnectionManager;
    private $controller;
    private $container;
    private $formFactory;
    private $twig;
    private $router;
    private $requestStack;

    protected function setUp(): void
    {
        $this->stripeService = $this->createMock(StripeService::class);
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->tenantConnectionManager = $this->createMock(TenantConnectionManager::class);
        $this->formFactory = $this->createMock(FormFactoryInterface::class);
        $this->twig = $this->createMock(Environment::class);
        $this->router = $this->createMock(UrlGeneratorInterface::class);

        $this->requestStack = new RequestStack();
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        $this->requestStack->push($request);

        $this->controller = new StripeController();

        $this->container = $this->createMock(ContainerInterface::class);

        $this->container->method('has')->willReturnCallback(function ($id) {
            return in_array($id, ['form.factory', 'twig', 'router', 'request_stack', 'session']);
        });

        $this->container->method('get')->willReturnCallback(function ($id) {
            switch ($id) {
                case 'form.factory':
                    return $this->formFactory;
                case 'twig':
                    return $this->twig;
                case 'router':
                    return $this->router; // AbstractController uses 'router' for redirectToRoute
                case 'request_stack':
                    return $this->requestStack;
            }
            return null;
        });

        $this->controller->setContainer($this->container);
    }

    public function testConnectNoSubdomain(): void
    {
        $request = new Request();
        $this->tenantConnectionManager->method('getCurrentTenantCode')->willReturn(null);

        $this->router->expects($this->once())
            ->method('generate')
            ->with('app_tenant_setup', [], 1) // UrlGeneratorInterface::ABSOLUTE_PATH = 1
            ->willReturn('/setup');

        $response = $this->controller->connect($request, $this->stripeService, $this->urlGenerator, $this->tenantConnectionManager);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertEquals('/setup', $response->getTargetUrl());
    }

    public function testConnectAlreadyConfigured(): void
    {
        $request = new Request();
        $this->tenantConnectionManager->method('getCurrentTenantCode')->willReturn('tenant1');

        $stripeConfig = $this->createMock(StripeConfig::class);
        $this->stripeService->method('getStripeConfigForCurrentTenant')->willReturn($stripeConfig);

        $this->twig->expects($this->once())
            ->method('render')
            ->with('stripe/status.html.twig', ['stripe_config' => $stripeConfig])
            ->willReturn('rendered_template');

        $response = $this->controller->connect($request, $this->stripeService, $this->urlGenerator, $this->tenantConnectionManager);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals('rendered_template', $response->getContent());
    }

    public function testConnectFormSubmittedValidToken(): void
    {
        $request = new Request();
        $this->tenantConnectionManager->method('getCurrentTenantCode')->willReturn('tenant1');
        $this->stripeService->method('getStripeConfigForCurrentTenant')->willReturn(null);

        $form = $this->createMock(FormInterface::class);
        $this->formFactory->method('create')->willReturn($form);

        $form->expects($this->once())->method('handleRequest')->with($request);
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);

        $form->method('get')->with('token')->willReturn($this->createConfiguredMock(FormInterface::class, ['getData' => 'valid_token']));

        $this->tenantConnectionManager->method('getTenantToken')->with('tenant1')->willReturn('valid_token');

        $this->urlGenerator->method('generate')->willReturnMap([
            ['app_stripe_success', [], UrlGeneratorInterface::ABSOLUTE_URL, 'http://success'],
            ['app_stripe_connect', [], UrlGeneratorInterface::ABSOLUTE_URL, 'http://connect'],
        ]);

        $this->stripeService->expects($this->once())
            ->method('createOnboardingLink')
            ->with('http://connect', 'http://success')
            ->willReturn('http://stripe_onboarding');

        $response = $this->controller->connect($request, $this->stripeService, $this->urlGenerator, $this->tenantConnectionManager);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertEquals('http://stripe_onboarding', $response->getTargetUrl());
    }

    public function testConnectFormSubmittedInvalidToken(): void
    {
        $request = new Request();
        $this->tenantConnectionManager->method('getCurrentTenantCode')->willReturn('tenant1');
        $this->stripeService->method('getStripeConfigForCurrentTenant')->willReturn(null);

        $form = $this->createMock(FormInterface::class);
        $this->formFactory->method('create')->willReturn($form);

        $form->expects($this->once())->method('handleRequest')->with($request);
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);

        $form->method('get')->with('token')->willReturn($this->createConfiguredMock(FormInterface::class, ['getData' => 'invalid_token']));

        $this->tenantConnectionManager->method('getTenantToken')->with('tenant1')->willReturn('valid_token');

        $formView = $this->createMock(FormView::class);
        $form->method('createView')->willReturn($formView);

        $this->twig->expects($this->once())
            ->method('render')
            ->with('stripe/connect.html.twig', ['form' => $formView])
            ->willReturn('rendered_form');

        $response = $this->controller->connect($request, $this->stripeService, $this->urlGenerator, $this->tenantConnectionManager);

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testConnectFormNotSubmitted(): void
    {
        $request = new Request();
        $this->tenantConnectionManager->method('getCurrentTenantCode')->willReturn('tenant1');
        $this->stripeService->method('getStripeConfigForCurrentTenant')->willReturn(null);

        $form = $this->createMock(FormInterface::class);
        $this->formFactory->method('create')->willReturn($form);

        $form->expects($this->once())->method('handleRequest')->with($request);
        $form->method('isSubmitted')->willReturn(false);

        $formView = $this->createMock(FormView::class);
        $form->method('createView')->willReturn($formView);

        $this->twig->expects($this->once())
            ->method('render')
            ->with('stripe/connect.html.twig', ['form' => $formView])
            ->willReturn('rendered_form');

        $response = $this->controller->connect($request, $this->stripeService, $this->urlGenerator, $this->tenantConnectionManager);

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testSuccessTrue(): void
    {
        $this->stripeService->expects($this->once())->method('finalizeConnection')->willReturn(true);

        $this->twig->expects($this->once())
            ->method('render')
            ->with('stripe/success.html.twig', ['connection_success' => true])
            ->willReturn('success_page');

        $response = $this->controller->success($this->stripeService);

        $this->assertEquals('success_page', $response->getContent());
    }

    public function testSuccessFalse(): void
    {
        $this->stripeService->expects($this->once())->method('finalizeConnection')->willReturn(false);

        $this->twig->expects($this->once())
            ->method('render')
            ->with('stripe/success.html.twig', ['connection_success' => false])
            ->willReturn('failure_page');

        $response = $this->controller->success($this->stripeService);

        $this->assertEquals('failure_page', $response->getContent());
    }

    public function testDisconnect(): void
    {
        $this->stripeService->expects($this->once())->method('disconnectCurrentTenant');

        $this->router->expects($this->once())
            ->method('generate')
            ->with('app_stripe_connect', [], 1)
            ->willReturn('/connect');

        $response = $this->controller->disconnect($this->stripeService);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertEquals('/connect', $response->getTargetUrl());
    }
}
