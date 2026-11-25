<?php

namespace App\RouterOs;

use RuntimeException;

/** Router answered with an error (bad credentials, unknown path, invalid value…). Persian message. */
class RouterOsException extends RuntimeException {}
