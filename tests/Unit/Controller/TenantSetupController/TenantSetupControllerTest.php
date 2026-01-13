<?php

namespace App\Tests\Unit\Controller\TenantSetupController;

use PHPUnit\Framework\TestCase;
use App\Controller\TenantSetupController\TenantSetupController;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\GemsuiteImporterService\GemsuiteImporter;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Twig\Environment;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\SyncJob;
use App\Message\StartGemsuiteImportJob;
use App\Dto\TenantSetupDTO;

class TenantSetupControllerTest extends TestCase
{
    private $tenantManager;
    private $emProvider;
    private $gemsuiteImporter;
    private $httpClient;
    private $messageBus;
    private $controller;
    private $container;

    // Container services mocks
    private $router;
    private $formFactory;
    private $requestStack;
    private $session;
    private $flashBag;
    private $twig;

    protected function setUp(): void
    {
        $this->tenantManager = $this->createMock(TenantConnectionManager::class);
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->gemsuiteImporter = $this->createMock(GemsuiteImporter::class);
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);

        $this->controller = new TenantSetupController(
            'myshop.com',
            'https://api.gemsuite.com/'
        );

        $this->container = $this->createMock(ContainerInterface::class);

        $this->router = $this->createMock(RouterInterface::class);
        $this->formFactory = $this->createMock(FormFactoryInterface::class);
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->session = $this->createMock(\Symfony\Component\HttpFoundation\Session\Session::class);
        $this->flashBag = $this->createMock(FlashBagInterface::class);
        $this->twig = $this->createMock(Environment::class);

        // Setup FlashBag chain
        $this->requestStack->method('getSession')->willReturn($this->session);
        $this->session->method('getFlashBag')->willReturn($this->flashBag);

        // Configure Container
        $this->container->method('has')->willReturnMap([
            ['router', true],
            ['form.factory', true],
            ['request_stack', true],
            ['twig', true],
        ]);

        $this->container->method('get')->willReturnMap([
            ['router', 1, $this->router],
            ['form.factory', 1, $this->formFactory],
            ['request_stack', 1, $this->requestStack],
            ['twig', 1, $this->twig],
        ]);

        $this->controller->setContainer($this->container);
    }

    private function mockPdoAvailability(bool $available): void
    {
        $pdo = $this->createMock(\PDO::class);
        $stmt = $this->createMock(\PDOStatement::class);

        $this->tenantManager->method('getPdoMaster')->willReturn($pdo);
        $pdo->method('prepare')->willReturn($stmt);
        $stmt->method('execute')->willReturn(true);
        // If fetch returns false (no row), it is available. If returns row, it's NOT available (taken).
        $stmt->method('fetch')->willReturn($available ? false : ['some' => 'data']);
    }

    public function testSetupRedirectsIfSubdomainInvalid(): void
    {
        $request = new Request([], [], [], [], [], ['HTTP_HOST' => 'admin.myshop.com']); // 'admin' is reserved

        $this->router->expects($this->once())
            ->method('generate')
            ->with('app_home')
            ->willReturn('/home');

        $this->flashBag->expects($this->once())->method('add')->with('danger', $this->stringContains('Accès invalide'));

        $response = $this->controller->setup(
            $request,
            $this->tenantManager,
            $this->emProvider,
            $this->gemsuiteImporter,
            $this->httpClient,
            $this->messageBus
        );

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testSetupRedirectsIfIdentifierNotAvailable(): void
    {
        $request = new Request([], [], [], [], [], ['HTTP_HOST' => 'taken.myshop.com']);

        $this->mockPdoAvailability(false); // Taken

        $this->router->expects($this->once())
            ->method('generate')
            ->with('app_home')
            ->willReturn('/home');

        $this->flashBag->expects($this->once())->method('add')->with('danger', $this->stringContains('existe déjà'));

        $response = $this->controller->setup(
            $request,
            $this->tenantManager,
            $this->emProvider,
            $this->gemsuiteImporter,
            $this->httpClient,
            $this->messageBus
        );

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testSetupDisplaysFormIfValidAndAvailable(): void
    {
        $request = new Request([], [], [], [], [], ['HTTP_HOST' => 'newstore.myshop.com']);

        $this->mockPdoAvailability(true); // Available

        $form = $this->createMock(FormInterface::class);
        $formView = $this->createMock(FormView::class);

        $this->formFactory->expects($this->once())->method('create')->willReturn($form);
        $form->method('has')->willReturn(true);
        $form->method('get')->willReturn($this->createMock(FormInterface::class)); // For subdomain_display
        $form->method('createView')->willReturn($formView);
        $form->method('handleRequest');
        $form->method('isSubmitted')->willReturn(false);

        $this->twig->expects($this->once())
            ->method('render')
            ->with('tenant_setup/form.html.twig', ['form' => $formView])
            ->willReturn('<html>form</html>');

        $response = $this->controller->setup(
            $request,
            $this->tenantManager,
            $this->emProvider,
            $this->gemsuiteImporter,
            $this->httpClient,
            $this->messageBus
        );

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals('<html>form</html>', $response->getContent());
    }

    public function testSetupCreatesTenantAndDispatchesJobOnSuccess(): void
    {


        $request = new Request([], [], [], [], [], ['HTTP_HOST' => 'success.myshop.com']);

        // 1. Availability Check


        // 2. Form submission
        $form = $this->createMock(FormInterface::class);
        $this->formFactory->method('create')->willReturn($form);

        $form->method('has')->willReturn(true);
        $form->method('get')->willReturn($this->createMock(FormInterface::class));

        $form->method('handleRequest')->with($request);
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);

        // Populate DTO via form logic (TenantController uses $dto passed to createForm)
        // Since we mock createForm, we can't easily reference the $dto created inside controller unless we use a callback
        // However, the controller creates 'new TenantSetupDTO' properly before createForm
        // We need to simulate that the DTO HAS data *after* handleRequest. 
        // But $dto is an object reference passed to createForm.
        // In unit test, strict ref binding is hard.
        // But wait! Controller code:
        // $dto = new TenantSetupDTO(); 
        // $form = $this->createForm(..., $dto);
        // $form->handleRequest($request);
        // ... if ($form->isValid()) use $dto

        // Since I cannot change the $dto inside the controller method from here easily (unless `handleRequest` mock does it),
        // I will rely on the fact that I mocked `isSubmitted` and `isValid` to true.
        // BUT, the controller checks `$dto->gemsuiteToken`. If I don't populate it, it will fail validation A.

        // Strategy: We can't inject data into the local $dto variable inside controller.
        // But `handleRequest` takes the request. The real `handleRequest` updates the data object.
        // I can use `willReturnCallback` on `handleRequest` to update the data object if I had access to it.
        // `createForm` receives data.

        $this->formFactory->method('create')->willReturnCallback(function ($type, $data) use ($form) {
            // $data is the TenantSetupDTO instance
            $data->gemsuiteToken = 'valid_token';
            $data->code = 'success';
            return $form;
        });

        // 3. API Call Mock
        $apiResponse = $this->createMock(ResponseInterface::class);
        $apiResponse->method('getStatusCode')->willReturn(200);
        $apiResponse->method('toArray')->willReturn(['data' => [['nom' => 'My Company']]]);

        $this->httpClient->expects($this->once())->method('request')->willReturn($apiResponse);

        // 4. Prerequisites
        $this->gemsuiteImporter->expects($this->once())->method('checkPrerequisites');

        // 5. Create Tenant
        $this->tenantManager->expects($this->once())->method('createTenant');

        // Mock getting Tenant ID (re-mock PDO for this specific call inside 'createTenant' block isn't easy as it uses same getPdoMaster)
        // The controller calls getPdoMaster() twice: once for verify, once for get ID.
        // My `mockPdoAvailability` mocks ALL calls. This is a problem because `getId` query is different.
        // I need to be more sophisticated with PDO mock.

        $pdo = $this->createMock(\PDO::class);
        $this->tenantManager->method('getPdoMaster')->willReturn($pdo);

        $stmtVerify = $this->createMock(\PDOStatement::class);
        $stmtVerify->method('execute')->willReturn(true);
        $stmtVerify->method('fetch')->willReturn(false); // First call check availability -> false means available

        $stmtGetId = $this->createMock(\PDOStatement::class);
        $stmtGetId->method('execute');
        $stmtGetId->method('fetchColumn')->willReturn(42); // Second call get ID -> 42

        $pdo->method('prepare')->willReturnCallback(function ($sql) use ($stmtVerify, $stmtGetId) {
            if (stripos($sql, 'id FROM tenants') !== false) {
                return $stmtGetId;
            }
            return $stmtVerify;
        });

        // 6. EntityManager & Dispatch
        $tenantEm = $this->createMock(EntityManagerInterface::class);
        $this->emProvider->expects($this->once())->method('switchTenant')->with('db_success', 'success');
        $this->emProvider->method('getEntityManager')->willReturn($tenantEm);

        $tenantEm->expects($this->once())->method('persist')->with($this->isInstanceOf(SyncJob::class));
        $tenantEm->expects($this->once())->method('flush');

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(StartGemsuiteImportJob::class))
            ->willReturn(new \Symfony\Component\Messenger\Envelope(new \stdClass()));

        // 7. Redirect
        $this->router->expects($this->once())
            ->method('generate')
            ->with('app_setup_status')
            ->willReturn('/setup/status/success/1');

        $response = $this->controller->setup(
            $request,
            $this->tenantManager,
            $this->emProvider,
            $this->gemsuiteImporter,
            $this->httpClient,
            $this->messageBus
        );

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testSetupHandlesTenantCreationError(): void
    {
        $request = new Request([], [], [], [], [], ['HTTP_HOST' => 'fail.myshop.com']);

        // 1. Availability (Manual mock)
        $pdo = $this->createMock(\PDO::class);
        $this->tenantManager->method('getPdoMaster')->willReturn($pdo);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false); // Available
        $pdo->method('prepare')->willReturn($stmt);

        // 2. Form
        $form = $this->createMock(FormInterface::class);
        $this->formFactory->method('create')->willReturn($form);
        $form->method('handleRequest');
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);
        $form->method('has')->willReturn(true);
        $form->method('get')->willReturn($this->createMock(FormInterface::class));

        $this->formFactory->method('create')->willReturnCallback(function ($type, $data) use ($form) {
            $data->gemsuiteToken = 'valid';
            $data->code = 'fail';
            return $form;
        });

        // 3. API
        $apiResponse = $this->createMock(ResponseInterface::class);
        $apiResponse->method('getStatusCode')->willReturn(200);
        $apiResponse->method('toArray')->willReturn(['data' => [['nom' => 'My Company']]]);
        $this->httpClient->method('request')->willReturn($apiResponse);

        // 4. Create Tenant Fails
        $this->tenantManager->method('createTenant')->willThrowException(new \Exception('Creation Failed'));

        // 5. Expect Redirect and Flash
        $this->router->expects($this->once())
            ->method('generate')
            ->with('app_tenant_setup')
            ->willReturn('/setup/new-store');
        $this->flashBag->expects($this->once())->method('add')->with('error', $this->stringContains('Creation Failed'));

        $response = $this->controller->setup(
            $request,
            $this->tenantManager,
            $this->emProvider,
            $this->gemsuiteImporter,
            $this->httpClient,
            $this->messageBus
        );

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }
}
