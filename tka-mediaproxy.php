<?php
/**
 * Plugin Name: TKA Media Proxy
 * Description: Intercepts 404 local media asset requests and proxies/mirrors them from the production URL on-demand.
 * Version: 1.0.0
 * Author: TKA
 * Text Domain: tka-mediaproxy
 * Requires PHP: 8.2
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// 1. PSR-4 Autoloader
spl_autoload_register(static function (string $class): void {
    $prefix = 'Tka\\MediaProxy\\';
    $baseDir = __DIR__ . '/src/';
    $len = strlen($prefix);

    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// 2. Initialize the Interceptor early on plugins_loaded (Priority 1)
add_action('plugins_loaded', [Tka\MediaProxy\Core\Interceptor::class, 'init'], 1);

// 3. Register WP-CLI command if WP-CLI is available
if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('tka-proxy', Tka\MediaProxy\Cli\ProxyCommand::class);
}
