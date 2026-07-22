<?php
require __DIR__.'/vendor/autoload.php';
$kernel = new App\Kernel('dev', true);
$kernel->boot();
$container = $kernel->getContainer();
$request = Symfony\Component\HttpFoundation\Request::create('/api/products/by-category', 'GET', ['locale' => 'fr', 'page' => 1, 'pageSize' => 1]);
$response = $kernel->handle($request);
echo "STATUS: " . $response->getStatusCode() . "\n";
echo "CONTENT: " . substr($response->getContent(), 0, 500) . "\n";
