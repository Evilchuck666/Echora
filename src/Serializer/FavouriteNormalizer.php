<?php

namespace App\Serializer;

use App\Entity\Album;
use App\Entity\Artist;
use App\Entity\Track;
use App\Favourites\CurrentFavourites;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Fills Track::$favourite, Album::$favourite and Artist::$favourite for the user making the request, right before
 * they are serialized, in lists, single items and search results alike.
 */
final class FavouriteNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const string ALREADY_CALLED = 'favourite_normalizer_already_called_for_';

    public function __construct(private readonly CurrentFavourites $favourites)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): \ArrayObject|array|string|int|float|bool|null
    {
        \assert($data instanceof Track || $data instanceof Album || $data instanceof Artist);
        $data->setFavourite($this->favourites->contains($data));
        $context[self::ALREADY_CALLED.$data::class] = true;

        return $this->normalizer->normalize($data, $format, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return ($data instanceof Track || $data instanceof Album || $data instanceof Artist) && !isset($context[self::ALREADY_CALLED.$data::class]);
    }

    public function getSupportedTypes(?string $format): array
    {
        return [Track::class => false, Album::class => false, Artist::class => false];
    }
}
