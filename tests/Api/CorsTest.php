<?php

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The web client runs on another origin: the browser asks first (preflight) whether it may call the API.
 * In development, CORS_ALLOW_ORIGIN (.env) allows localhost on any port.
 */
final class CorsTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    public function testAnAllowedOriginMaySendTheTokenAndAskForByteRanges(): void
    {
        $response = $this->preflight('http://localhost:5173');

        self::assertResponseHeaderSame('access-control-allow-origin', 'http://localhost:5173');
        $allowedHeaders = $response->getHeaders()['access-control-allow-headers'][0];
        self::assertStringContainsString('authorization', $allowedHeaders);
        self::assertStringContainsString('range', $allowedHeaders);
    }

    public function testOtherOriginsAreNotAllowed(): void
    {
        $this->preflight('https://evil.example');

        self::assertResponseNotHasHeader('access-control-allow-origin');
    }

    private function preflight(string $origin): ResponseInterface
    {
        return self::createClient()->request('OPTIONS', '/api/tracks', ['headers' => [
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'authorization, range',
        ]]);
    }
}
