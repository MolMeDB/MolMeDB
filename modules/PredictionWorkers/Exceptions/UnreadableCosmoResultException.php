<?php

namespace Modules\PredictionWorkers\Exceptions;

use RuntimeException;

/**
 * The COSMO result file could not be read as a whole (missing, not read, read
 * only in part, an archive): what it holds is not known, so reading it again
 * later may succeed.
 */
class UnreadableCosmoResultException extends RuntimeException {}
