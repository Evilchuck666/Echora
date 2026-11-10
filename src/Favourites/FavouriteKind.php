<?php

namespace App\Favourites;

use App\Entity\Album;
use App\Entity\Artist;
use App\Entity\Track;

/**
 * What can be a favourite: the value is the Favourite field that points to it, and the path segment of its API
 * collection (/api/tracks, /api/favourites/tracks...).
 */
enum FavouriteKind: string
{
    case Track = 'track';
    case Album = 'album';
    case Artist = 'artist';

    public static function of(Track|Album|Artist $item): self
    {
        return match (true) {
            $item instanceof Track => self::Track,
            $item instanceof Album => self::Album,
            $item instanceof Artist => self::Artist,
        };
    }

    public static function fromPlural(string $plural): self
    {
        return self::from(rtrim($plural, 's'));
    }

    /** @return class-string<Track|Album|Artist> */
    public function entityClass(): string
    {
        return match ($this) {
            self::Track => Track::class,
            self::Album => Album::class,
            self::Artist => Artist::class,
        };
    }

    public function plural(): string
    {
        return $this->value.'s';
    }
}
