<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Setup;
use App\Security\UserAccounts;

/**
 * GET /api/setup.
 *
 * @implements ProviderInterface<Setup>
 */
final readonly class SetupProvider implements ProviderInterface
{
    public function __construct(private UserAccounts $accounts)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Setup
    {
        return new Setup(!$this->accounts->isSetUp());
    }
}
