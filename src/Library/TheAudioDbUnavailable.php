<?php

namespace App\Library;

/**
 * TheAudioDB couldn't be reached, or refused the request (e.g. too many requests): try again on a later scan.
 */
final class TheAudioDbUnavailable extends \RuntimeException
{
}
