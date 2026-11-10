<?php

namespace App\Tests\Playlists;

use App\Doctrine\SqlValue;
use App\Entity\Playlist;
use App\Entity\User;
use App\Library\LibraryScanner;
use App\Playlists\PlaylistEditor;
use App\Repository\TrackRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The order of a playlist stays exactly what the changes made it, however many: compared with a plain PHP list.
 */
final class PlaylistEditorTest extends KernelTestCase
{
    private PlaylistEditor $editor;
    private User $user;
    private Playlist $playlist;

    /** @var list<string> */
    private array $trackIds;

    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->get(LibraryScanner::class)->scan(download: false);
        $this->editor = self::getContainer()->get(PlaylistEditor::class);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->user = new User('editor');
        $this->playlist = new Playlist($this->user, 'Order');
        $entityManager->persist($this->user);
        $entityManager->persist($this->playlist);
        $entityManager->flush();
        $this->trackIds = self::getContainer()->get(TrackRepository::class)->findAllIds();
    }

    public function testMovingAnItemOverAndOverToTheSameSpotRenumbersTheKeysAndKeepsTheOrder(): void
    {
        $this->add([$this->trackIds[0], $this->trackIds[1], $this->trackIds[2], $this->trackIds[3]]);
        $expected = $this->itemIds();

        // Each move lands between the first item and the one moved before: the gap halves every time.
        for ($i = 0; $i < 30; ++$i) {
            $last = array_pop($expected);
            \assert(null !== $last);
            array_splice($expected, 1, 0, [$last]);
            self::assertSame(1, $this->editor->move($this->user, $last, 1)->getPosition());
            self::assertSame($expected, $this->itemIds(), "After move $i");
        }

        // The first item never moved: its key is still one step unless renumbering moved every key above the highest.
        self::assertGreaterThan(PlaylistEditor::SORT_KEY_STEP, $this->lowestKey(), 'The keys were spread again.');
    }

    public function testAnyMixOfAddingMovingAndRemovingKeepsTheExpectedOrder(): void
    {
        mt_srand(20261110);
        $expected = [];
        for ($step = 0; $step < 200; ++$step) {
            $action = [] === $expected ? 0 : mt_rand(0, 9);
            if ($action <= 2) {
                $tracks = array_map(fn (): string => $this->trackIds[mt_rand(0, \count($this->trackIds) - 1)], range(1, mt_rand(1, 3)));
                $this->add($tracks);
                $expected = array_merge($expected, \array_slice($this->itemIds(), \count($expected)));
            } elseif ($action <= 7) {
                $from = mt_rand(0, \count($expected) - 1);
                $to = mt_rand(0, \count($expected) + 1);
                [$item] = array_splice($expected, $from, 1);
                array_splice($expected, min($to, \count($expected)), 0, [$item]);
                $this->editor->move($this->user, $item, $to);
            } else {
                [$item] = array_splice($expected, mt_rand(0, \count($expected) - 1), 1);
                $this->editor->remove($this->user, $item);
            }
            self::assertSame($expected, $this->itemIds(), "After step $step");
        }
    }

    /**
     * @param list<string> $trackIds
     */
    private function add(array $trackIds): void
    {
        $this->editor->add($this->user, $this->playlist->getId()->toRfc4122(), $trackIds);
    }

    /**
     * @return list<string> The items of the playlist, in its order
     */
    private function itemIds(): array
    {
        return SqlValue::strings($this->connection()->fetchFirstColumn(
            'SELECT id FROM playlist_item WHERE playlist_id = ? ORDER BY sort_key',
            [$this->playlist->getId()->toRfc4122()],
        ));
    }

    private function lowestKey(): int
    {
        return SqlValue::int($this->connection()->fetchOne(
            'SELECT MIN(sort_key) FROM playlist_item WHERE playlist_id = ?',
            [$this->playlist->getId()->toRfc4122()],
        ));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }
}
