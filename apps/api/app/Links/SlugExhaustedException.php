<?php

declare(strict_types=1);

namespace App\Links;

use RuntimeException;

/**
 * Raised when generation could not find a free slug within its retry budget.
 *
 * Distinct from any other failure on purpose: returning a duplicate would
 * silently overwrite someone's link, and reporting a generic error would hide
 * that the configured slug length has become too short for the corpus.
 */
final class SlugExhaustedException extends RuntimeException {}
