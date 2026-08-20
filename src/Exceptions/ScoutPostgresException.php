<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Exceptions;

use Throwable;

/**
 * Marker for every exception this package throws.
 *
 * An adopter catching this interface catches the package and nothing else, which
 * matters most for the scope failures below: SC-1 requires an unresolvable scope
 * to throw rather than widen to an unfiltered query, so these exceptions are part
 * of the isolation guarantee and not merely diagnostics.
 */
interface ScoutPostgresException extends Throwable {}
