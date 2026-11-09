<?php

namespace App\Library;

/**
 * An image file that GD can't decode, or too large to resize safely.
 */
final class UnreadableImage extends \RuntimeException
{
}
