<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Dto\SetupInput;
use App\Security\AccountConflict;
use App\Security\SessionTokens;
use App\Security\UserAccounts;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * POST /api/setup: creates the administrator, if the server has no account yet, and signs them in.
 *
 * @implements ProcessorInterface<SetupInput, Response>
 */
final readonly class SetupProcessor implements ProcessorInterface
{
    public function __construct(
        private UserAccounts $accounts,
        private SessionTokens $tokens,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        try {
            $administrator = $this->accounts->setUp($data->email, $data->name, $data->password);
        } catch (AccountConflict $e) {
            throw new ConflictHttpException($e->getMessage(), $e);
        } catch (ValidationFailedException $e) {
            throw new ValidationException($e->getViolations(), previous: $e);
        }

        return $this->tokens->respond($administrator, Response::HTTP_CREATED);
    }
}
