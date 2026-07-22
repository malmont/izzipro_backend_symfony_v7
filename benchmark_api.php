<?php
require __DIR__.'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(".env");
$kernel = new App\Kernel("dev", true);
$kernel->boot();

$start = microtime(true);
$request = Symfony\Component\HttpFoundation\Request::create("/api/products/by-category", "GET", ["locale" => "fr", "page" => 1, "pageSize" => 12]);
$response = $kernel->handle($request);
$end = microtime(true);

echo "STATUS: " . $response->getStatusCode() . "\n";
echo "TIME: " . ($end - $start) . " seconds\n";
