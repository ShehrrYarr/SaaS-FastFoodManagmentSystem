<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A POS rule was broken ("Open the business day first", "T-04 already has an
 * open tab"...). The message is written for the cashier and shown as-is.
 */
class PosException extends RuntimeException {}
