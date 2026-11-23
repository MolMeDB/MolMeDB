<?php

namespace Modules\PredictionWorkers\Exceptions;

use RuntimeException;

/**
 * The COSMO result file was read, but what it holds is not a result (not
 * XML, cut short, another document): reading it again gives the same.
 */
class InvalidCosmoResultException extends RuntimeException {}
