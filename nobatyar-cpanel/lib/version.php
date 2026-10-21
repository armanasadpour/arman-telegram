<?php
declare(strict_types=1);

/**
 * Single source of truth for the release number and the latest database schema step.
 * Every page, the API and the upgrader read these constants instead of hard-coded strings.
 */
const APP_VERSION = '1.0.22';
const APP_VERSION_FA = '۱.۰.۲۲';
const SCHEMA_VERSION = 29;
