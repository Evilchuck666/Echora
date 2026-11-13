<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Dto\PasswordChange;
use App\Entity\User;
use App\Security\SessionTokens;
use App\Security\UserAccounts;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * POST /api/me/password: checks the current password, sets the new one, signs the account out of every device and
 * answers with a new session for the one that asked. After 5 wrong current passwords in 15 minutes, 429 (the
 * "password_change" rate limiter, framework.yaml).
 *
 * @implements ProcessorInterface<PasswordChange, Response>
 */
final readonly class PasswordChangeProcessor implements ProcessorInterface
{
    public function __construct(
        private UserAccounts $accounts,
        private SessionTokens $tokens,
        private Security $security,
        #[Target('password_change')]
        private RateLimiterFactoryInterface $wrongPasswords,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        $user = $this->security->getUser();
        \assert($user instanceof User);

        // Like signing in (login_throttling): a stolen JWT must not allow guessing the password, 5 tries at a time.
        $limiter = $this->wrongPasswords->create($user->getUserIdentifier());
        $limit = $limiter->consume(0);
        if (0 === $limit->getRemainingTokens()) {
            $seconds = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            throw new TooManyRequestsHttpException($seconds, \sprintf('Too many wrong passwords: try again in %d minute%s.', ceil($seconds / 60), $seconds > 60 ? 's' : ''));
        }

        if (!$this->accounts->isPasswordValid($user, $data->currentPassword)) {
            $limiter->consume();

            throw new ValidationException(new ConstraintViolationList([new ConstraintViolation('The current password is wrong.', null, [], $data, 'currentPassword', null)]));
        }
        $limiter->reset();
        $this->accounts->changePassword($user, $data->newPassword);

        return $this->tokens->respond($user);
    }
}
