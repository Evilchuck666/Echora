<?php

namespace App\Library;

/**
 * The steps of a library scan, in this order (App\Library\LibraryScanner::scan()).
 */
enum ScanPhase: string
{
    /** Finding the audio files of the library: how many is not known yet. */
    case Listing = 'listing';

    /** Reading the new and changed files (unchanged ones are only counted). */
    case Files = 'files';

    /** Finding the cover of each album, and looking up the missing ones on TheAudioDB. */
    case Covers = 'covers';

    /** Finding the image of each artist, and looking up the missing ones on TheAudioDB (30 a minute at most). */
    case ArtistImages = 'artist_images';
}
