<?php

namespace App\Search;

use App\ApiResource\Search;
use App\Entity\Album;
use App\Entity\Artist;
use App\Entity\Track;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Finds the artists, albums and tracks that match a free-text query, for GET /api/search.
 *
 * Every word of the query has to appear, in any order, ignoring case and accents ("motorhead"
 * finds "Motörhead"). Items are also found through the names around them: an album by its
 * artist, a track by its artist or its album. They are ranked by how well their own name
 * matches: equal to the query, starting with it, containing every word, and last the ones only
 * found through the names around them; within a rank, the more words in their own name, the
 * higher.
 */
final readonly class LibrarySearch
{
    /** Words beyond this are ignored: it bounds the size of the database query. */
    public const int MAX_WORDS = 8;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return list<string> The words of the query, at most MAX_WORDS
     */
    public static function words(string $query): array
    {
        $words = preg_split('/\s+/u', trim($query), -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        return \array_slice($words, 0, self::MAX_WORDS);
    }

    public function search(string $query, int $limit): Search
    {
        $words = self::words($query);
        $query = implode(' ', $words);
        if ([] === $words) {
            return new Search($query, [], 0, [], 0, [], 0);
        }

        [$artists, $totalArtists] = $this->find(
            Artist::class, 'artist', [], 'artist.name', [], $words, $query, $limit, ['artist.name'],
        );
        [$albums, $totalAlbums] = $this->find(
            Album::class, 'album', ['albumArtist' => 'album.artist'], 'album.title', ['albumArtist.name'], $words, $query, $limit,
            ['album.year', 'album.title'],
        );
        [$tracks, $totalTracks] = $this->find(
            Track::class, 'track',
            // The album's artist isn't searched, but it is serialized with the album: fetch it in the same query.
            ['trackArtist' => 'track.artist', 'trackAlbum' => 'track.album', 'trackAlbumArtist' => 'trackAlbum.artist'],
            'track.title', ['trackArtist.name', 'trackAlbum.title'], $words, $query, $limit,
            ['track.title', 'trackArtist.name'],
        );

        return new Search($query, $artists, $totalArtists, $albums, $totalAlbums, $tracks, $totalTracks);
    }

    /**
     * @template T of object
     *
     * @param class-string<T>       $class
     * @param array<string, string> $joins       Fetched with the items: alias => association
     * @param string                $nameField   The item's own name, which decides its rank
     * @param list<string>          $otherFields Names around the item that also find it
     * @param list<string>          $words
     * @param list<string>          $orderBy     Tie-breakers after the rank
     *
     * @return array{list<T>, int} The first $limit items, and how many match in all
     */
    private function find(string $class, string $alias, array $joins, string $nameField, array $otherFields, array $words, string $query, int $limit, array $orderBy): array
    {
        $total = $this->matching($class, $alias, $joins, [$nameField, ...$otherFields], $words)
            ->select(\sprintf('COUNT(%s.id)', $alias))
            ->getQuery()
            ->getSingleScalarResult();
        \assert(is_numeric($total));
        if (0 === (int) $total) {
            return [[], 0];
        }

        $name = self::normalized($nameField);
        $hasWord = array_map(
            static fn (int $i): string => \sprintf('%s LIKE %s', $name, self::normalized(':word'.$i)),
            array_keys($words),
        );

        $builder = $this->matching($class, $alias, $joins, [$nameField, ...$otherFields], $words)
            ->select($alias, ...array_keys($joins))
            ->addSelect(\sprintf(
                'CASE WHEN %1$s = %2$s THEN 0 WHEN %1$s LIKE %3$s THEN 1 WHEN %4$s THEN 2 ELSE 3 END AS HIDDEN relevance',
                $name, self::normalized(':query'), self::normalized(':prefix'), implode(' AND ', $hasWord),
            ))
            ->addSelect(\sprintf('(%s) AS HIDDEN ownWords', implode(' + ', array_map(
                static fn (string $condition): string => \sprintf('(CASE WHEN %s THEN 1 ELSE 0 END)', $condition),
                $hasWord,
            ))))
            ->setParameter('query', $query)
            ->setParameter('prefix', self::escapeLike($query).'%')
            ->orderBy('relevance')
            ->addOrderBy('ownWords', 'DESC')
            ->setMaxResults($limit);
        foreach ($orderBy as $field) {
            $builder->addOrderBy($field);
        }

        /** @var list<T> $items */
        $items = $builder->getQuery()->getResult();

        return [$items, (int) $total];
    }

    /**
     * Items of which every word appears in at least one of $fields.
     *
     * @param array<string, string> $joins
     * @param list<string>          $fields
     * @param list<string>          $words
     */
    private function matching(string $class, string $alias, array $joins, array $fields, array $words): QueryBuilder
    {
        $builder = $this->entityManager->createQueryBuilder()->from($class, $alias);
        foreach ($joins as $joinAlias => $association) {
            $builder->join($association, $joinAlias);
        }

        foreach ($words as $i => $word) {
            $builder->andWhere($builder->expr()->orX(...array_map(
                static fn (string $field): string => \sprintf('%s LIKE %s', self::normalized($field), self::normalized(':word'.$i)),
                $fields,
            )));
            $builder->setParameter('word'.$i, '%'.self::escapeLike($word).'%');
        }

        return $builder;
    }

    /**
     * Lower case and without accents, on both sides of every comparison.
     */
    private static function normalized(string $expression): string
    {
        return \sprintf('UNACCENT(LOWER(%s))', $expression);
    }

    /**
     * A query is text, not a pattern: "%" and "_" match themselves. Backslash is LIKE's escape character in PostgreSQL.
     */
    private static function escapeLike(string $text): string
    {
        return addcslashes($text, '%_\\');
    }
}
