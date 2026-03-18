<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap file.
 *
 * All src/ classes guard themselves with `defined('ABSPATH') || exit;` because
 * they are designed to run inside WordPress. This file defines the constant
 * before the autoloader loads any of those classes, so the test suite can run
 * without a real WordPress installation.
 *
 * The value of ABSPATH is not used by any class under test; only its existence
 * matters for the guard check.
 */
if (! defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
