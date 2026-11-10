<?php

namespace App\Library;

/**
 * An audio file that the encoder could not convert (unreadable, not really FLAC, or the encoder hung).
 */
final class TranscodingFailed extends \RuntimeException
{
}
