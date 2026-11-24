<?php

namespace App\Ibs;

use RuntimeException;

/** Panel-side misconfiguration (no host/credentials saved). */
class IbsNotConfiguredException extends RuntimeException {}
