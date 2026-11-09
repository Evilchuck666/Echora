<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Asks twice for a new password without echoing it. Passwords are never taken as
 * arguments or options, so they don't end up in the shell history.
 */
final class PasswordPrompt
{
    public const int MIN_LENGTH = 8;

    /** A wrong entry is asked again this many times at most, never forever. */
    private const int MAX_ATTEMPTS = 3;

    /**
     * @return string|null The password, or null when it couldn't be asked or the two entries differ (the error is already shown)
     */
    public function ask(InputInterface $input, SymfonyStyle $io): ?string
    {
        if (!$this->canAsk($input)) {
            $io->error('This command asks for a password: run it in an interactive terminal (docker compose exec php ..., without -T).');

            return null;
        }

        $question = new \Symfony\Component\Console\Question\Question('Password');
        $question->setHidden(true);
        $question->setMaxAttempts(self::MAX_ATTEMPTS);
        $question->setValidator(static function (mixed $value): string {
            if (!\is_string($value) || mb_strlen($value) < self::MIN_LENGTH) {
                throw new \RuntimeException(\sprintf('Use at least %d characters.', self::MIN_LENGTH));
            }

            return $value;
        });

        try {
            $password = $io->askQuestion($question);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return null;
        }

        if ($password !== $io->askHidden('Repeat the password')) {
            $io->error('The passwords do not match.');

            return null;
        }

        return \is_string($password) ? $password : null;
    }

    /**
     * Symfony 8 no longer turns interactivity off when there is no terminal, so check:
     * a hidden prompt without one would wait for input that never comes.
     */
    private function canAsk(InputInterface $input): bool
    {
        if (!$input->isInteractive()) {
            return false;
        }

        // Input given as a stream (CommandTester) needs no terminal.
        if ($input instanceof StreamableInputInterface && null !== $input->getStream()) {
            return true;
        }

        return stream_isatty(\STDIN);
    }
}
