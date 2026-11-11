<?php

/*
 * POST /api/setup in a process of its own, handled by the kernel as the web server would: run by SetupConcurrencyTest.
 * Exits with 0 when the account was created (201), 3 when the server was already set up (409), 1 otherwise.
 *
 *   php tests/Accounts/post-setup.php <email>
 */

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');

$arguments = $_SERVER['argv'] ?? [];
$email = is_array($arguments) && is_string($arguments[1] ?? null) ? $arguments[1] : 'first@example.com';

$kernel = new Kernel('test', true);
$response = $kernel->handle(Request::create('/api/setup', 'POST', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: (string) json_encode([
    'email' => $email,
    'name' => 'First',
    'password' => 'first-admin-pass',
])));
echo $response->getStatusCode(), "\n";

exit(match ($response->getStatusCode()) {
    201 => 0,
    409 => 3,
    default => 1,
});
