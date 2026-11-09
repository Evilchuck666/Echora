<?php

namespace App\Serializer;

use App\Entity\Track;
use App\Library\StreamUrlGenerator;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Fills Track::$streamUrl with a freshly signed URL right before a track is serialized.
 */
final class TrackStreamUrlNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const string ALREADY_CALLED = 'track_stream_url_normalizer_already_called';

    public function __construct(private readonly StreamUrlGenerator $streamUrls)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): \ArrayObject|array|string|int|float|bool|null
    {
        \assert($data instanceof Track);

        $data->setStreamUrl($this->streamUrls->generate($data));
        $context[self::ALREADY_CALLED] = true;

        return $this->normalizer->normalize($data, $format, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Track && !isset($context[self::ALREADY_CALLED]);
    }

    public function getSupportedTypes(?string $format): array
    {
        return [Track::class => false];
    }
}
