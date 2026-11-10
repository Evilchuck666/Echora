<?php

/*
 * One change to a playlist, in a process of its own, as one request would make it: run by PlaylistConcurrencyTest.
 *
 *   php tests/Playlists/change-playlist.php add <username> <playlist id> <track id>...
 *   php tests/Playlists/change-playlist.php move <username> <item id> <position>
 */

use App\Entity\User;
use App\Kernel;
use App\Playlists\PlaylistEditor;
use App\Repository\PlaylistItemRepository;
use App\Repository\PlaylistRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');

$kernel = new Kernel('test', true);
$kernel->boot();
$doctrine = $kernel->getContainer()->get('doctrine');
$entityManager = $doctrine->getManager();
\assert($entityManager instanceof EntityManagerInterface);

$arguments = $_SERVER['argv'] ?? [];
\assert(is_array($arguments) && count($arguments) >= 5);
$arguments = array_values(array_map(static fn (mixed $argument): string => is_string($argument) ? $argument : '', $arguments));
[, $action, $username, $target] = $arguments;
$rest = array_slice($arguments, 4);

$user = $entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
\assert($user instanceof User);
$editor = new PlaylistEditor($entityManager, new PlaylistRepository($doctrine), new PlaylistItemRepository($doctrine));

match ($action) {
    'add' => $editor->add($user, $target, $rest),
    'move' => $editor->move($user, $target, (int) $rest[0]),
    default => throw new InvalidArgumentException("Unknown action \"$action\"."),
};
