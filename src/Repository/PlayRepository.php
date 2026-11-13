<?php

namespace App\Repository;

use App\Doctrine\SqlValue;
use App\Entity\Play;
use App\Entity\Track;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Play>
 */
class PlayRepository extends ServiceEntityRepository
{
    /** A play this close to another play of the same track by the same user is the same one (a client retrying). */
    public const int SAME_PLAY_SECONDS = 30;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Play::class);
    }

    /**
     * Records a play, unless the user has one of the same track within SAME_PLAY_SECONDS of it.
     *
     * @return bool Whether it was recorded
     */
    public function record(User $user, Track $track, \DateTimeImmutable $playedAt): bool
    {
        // Stored without a time zone, in PHP's own: convert it first (a client may send any offset).
        $playedAt = $playedAt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $format = 'Y-m-d H:i:s';
        $userId = $user->getId()->toRfc4122();
        $trackId = $track->getId()->toRfc4122();

        return 1 === $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO play (id, user_id, track_id, played_at)
             SELECT ?, ?, ?, ?
             WHERE NOT EXISTS (SELECT 1 FROM play WHERE user_id = ? AND track_id = ? AND played_at BETWEEN ? AND ?)',
            [
                Uuid::v7()->toRfc4122(), $userId, $trackId, $playedAt->format($format),
                $userId, $trackId,
                $playedAt->modify(\sprintf('-%d seconds', self::SAME_PLAY_SECONDS))->format($format),
                $playedAt->modify(\sprintf('+%d seconds', self::SAME_PLAY_SECONDS))->format($format),
            ],
        );
    }

    /**
     * @return int Number of plays deleted
     */
    public function clear(User $user): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement('DELETE FROM play WHERE user_id = ?', [$user->getId()->toRfc4122()]);
    }

    /**
     * The tracks a user played, each once, with how many times and when last: the most recently played first, or the
     * most played first (then the most recently played).
     *
     * @return list<array{trackId: string, playCount: int, lastPlayedAt: \DateTimeImmutable}>
     */
    public function findPlayedTracks(User $user, bool $mostPlayedFirst, ?\DateTimeImmutable $since, int $offset, int $limit): array
    {
        [$where, $parameters] = $this->filter($user, $since);
        $order = $mostPlayedFirst ? 'play_count DESC, last_played_at DESC, track_id' : 'last_played_at DESC, track_id';

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT track_id, COUNT(*) AS play_count, MAX(played_at) AS last_played_at FROM play WHERE $where
             GROUP BY track_id ORDER BY $order LIMIT ? OFFSET ?",
            [...$parameters, $limit, $offset],
        );

        return array_map(static fn (array $row): array => [
            'trackId' => SqlValue::string($row['track_id']),
            'playCount' => SqlValue::int($row['play_count']),
            'lastPlayedAt' => new \DateTimeImmutable(SqlValue::string($row['last_played_at'])),
        ], $rows);
    }

    public function countPlayedTracks(User $user, ?\DateTimeImmutable $since): int
    {
        [$where, $parameters] = $this->filter($user, $since);

        return SqlValue::int($this->getEntityManager()->getConnection()->fetchOne("SELECT COUNT(DISTINCT track_id) FROM play WHERE $where", $parameters));
    }

    /**
     * @return array{string, list<string>}
     */
    private function filter(User $user, ?\DateTimeImmutable $since): array
    {
        if (null === $since) {
            return ['user_id = ?', [$user->getId()->toRfc4122()]];
        }

        return ['user_id = ? AND played_at >= ?', [$user->getId()->toRfc4122(), $since->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s')]];
    }
}
