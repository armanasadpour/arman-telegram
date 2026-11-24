<?php

namespace App\Ibs;

use RuntimeException;

/** IBSng could not be reached or answered garbage (network, timeout, HTTP 500, breaker open). */
class IbsUnavailableException extends RuntimeException {}
