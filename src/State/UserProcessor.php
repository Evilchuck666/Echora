<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Dto\UserInput;
use App\Entity\User;
use App\Security\AccountConflict;
use App\Security\UserAccounts;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * POST /api/users (a new account), PATCH /api/users/{id} and PATCH /api/me (changes to an account, already validated
 * by API Platform): through App\Security\UserAccounts, which never leaves the server without an administrator.
 *
 * @implements ProcessorInterface<UserInput|User, User>
 */
final readonly class UserProcessor implements ProcessorInterface
{
    public function __construct(private UserAccounts $accounts)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): User
    {
        try {
            if ($data instanceof UserInput) {
                return $this->accounts->create($data->email, $data->name, $data->password, $data->admin);
            }

            $previous = $context['previous_data'] ?? null;
            $password = $data->takePlainPassword();
            $this->accounts->save($data, $previous instanceof User ? $previous->isAdmin() : $data->isAdmin());
            if (null !== $password) {
                $this->accounts->changePassword($data, $password);
            }

            return $data;
        } catch (ValidationFailedException $e) {
            throw new ValidationException($e->getViolations(), previous: $e);
        } catch (AccountConflict $e) {
            throw new ConflictHttpException($e->getMessage(), $e);
        }
    }
}
