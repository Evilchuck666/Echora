<?php

namespace App\Serializer;

use App\Entity\Album;
use App\Entity\Track;
use App\Library\SignedUrlGenerator;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Fills Track::$streamUrl and Album::$coverUrl with freshly signed URLs right before
 * they are serialized, including an album embedded in a track.
 */
final class SignedUrlNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const string ALREADY_CALLED = 'signed_url_normalizer_already_called_for_';

    public function __construct(private readonly SignedUrlGenerator $signedUrls)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): \ArrayObject|array|string|int|float|bool|null
    {
        if ($data instanceof Track) {
            $data->setStreamUrl($this->signedUrls->stream($data));
        } elseif ($data instanceof Album) {
            $data->setCoverUrl($this->signedUrls->cover($data));
        }

        \assert(\is_object($data));
        $context[self::ALREADY_CALLED.$data::class] = true;

        return $this->normalizer->normalize($data, $format, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return ($data instanceof Track || $data instanceof Album) && !isset($context[self::ALREADY_CALLED.$data::class]);
    }

    public function getSupportedTypes(?string $format): array
    {
        return [Track::class => false, Album::class => false];
    }
}
