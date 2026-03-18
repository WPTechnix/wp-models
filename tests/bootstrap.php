<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap file.
 *
 * Defines the WordPress ABSPATH constant so that library source files
 * (which guard with `defined('ABSPATH') || exit;`) do not terminate
 * the process when loaded outside of a WordPress environment.
 */

if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
