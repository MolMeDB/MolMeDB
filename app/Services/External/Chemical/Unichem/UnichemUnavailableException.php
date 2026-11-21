<?php

namespace App\Services\External\Chemical\Unichem;

use RuntimeException;

/**
 * UniChem gave no usable answer (no connection, a server error or rate
 * limiting), which says nothing about whether the identifier exists.
 */
class UnichemUnavailableException extends RuntimeException {}
