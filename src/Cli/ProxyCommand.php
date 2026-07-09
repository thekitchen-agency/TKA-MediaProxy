<?php

declare(strict_types=1);

namespace Tka\MediaProxy\Cli;

use Tka\MediaProxy\Core\Config;
use Tka\MediaProxy\Core\Interceptor;
use Tka\MediaProxy\Http\Client;
use WP_CLI;

/**
 * Commands for managing the TKA Media Proxy.
 */
class ProxyCommand
{
    /**
     * Configures the production URL mapping.
     *
     * ## OPTIONS
     *
     * <production_url>
     * : The production site URL target (e.g. https://example.com).
     *
     * [--ssl-verify=<bool>]
     * : Enable or disable SSL verification for proxies (default: true).
     *
     * ## EXAMPLES
     *
     *     wp tka-proxy configure https://production.example.com --ssl-verify=true
     *
     * @subcommand configure
     */
    public function configure(array $args, array $assocArgs): void
    {
        $url = esc_url_raw(trim($args[0]));

        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            WP_CLI::error('Invalid URL format provided.');
        }

        Config::setProductionUrl($url);
        WP_CLI::success("Production URL configured successfully: $url");

        if (isset($assocArgs['ssl-verify'])) {
            $sslVerify = filter_var($assocArgs['ssl-verify'], FILTER_VALIDATE_BOOLEAN);
            Config::setSslVerify($sslVerify);
            WP_CLI::success("SSL Verification preference configured: " . ($sslVerify ? 'Enabled' : 'Disabled'));
        }
    }

    /**
     * Displays configuration settings, connectivity to production, and localized folder sizes.
     *
     * ## EXAMPLES
     *
     *     wp tka-proxy status
     *
     * @subcommand status
     */
    public function status(array $args, array $assocArgs): void
    {
        $productionUrl = Config::getProductionUrl();

        WP_CLI::line('--------------------------------------------------');
        WP_CLI::line('TKA Media Proxy Configuration Status');
        WP_CLI::line('--------------------------------------------------');
        WP_CLI::line('Environment Status:  ' . (Interceptor::isLocalEnvironment() ? 'Local (Active)' : 'Non-Local (Disabled)'));
        WP_CLI::line('Production URL:      ' . (empty($productionUrl) ? 'Not configured' : $productionUrl));
        WP_CLI::line('SSL Verification:    ' . (Config::getSslVerify() ? 'Enabled' : 'Disabled'));

        if (!empty($productionUrl)) {
            WP_CLI::line('');
            WP_CLI::line('Verifying connectivity to production origin...');

            $pingUrl = rtrim($productionUrl, '/') . '/';
            $response = wp_remote_head($pingUrl, [
                'sslverify' => Config::getSslVerify(),
                'timeout'   => 8,
            ]);

            if (is_wp_error($response)) {
                WP_CLI::warning('Connectivity failed: ' . $response->get_error_message());
            } else {
                $code = (int) wp_remote_retrieve_response_code($response);
                if ($code >= 200 && $code < 400) {
                    WP_CLI::success("Connectivity verified! (HTTP Status: $code)");
                } else {
                    WP_CLI::warning("Production reached but returned unexpected HTTP status: $code");
                }
            }
        }

        // Fetch uploads folder sizes
        $uploads = wp_upload_dir();
        if (empty($uploads['error'])) {
            $uploadsDir = $uploads['basedir'];
            WP_CLI::line('');
            WP_CLI::line('Local Cache Directory Details:');
            WP_CLI::line("Path:  $uploadsDir");

            $stats = $this->getDirectoryStats($uploadsDir);
            WP_CLI::line('Files: ' . $stats['count']);
            WP_CLI::line('Size:  ' . $this->formatBytes($stats['size']));
        } else {
            WP_CLI::warning('Could not retrieve local uploads directory path.');
        }

        WP_CLI::line('--------------------------------------------------');
    }

    /**
     * Safely clears all downloaded media assets from the local uploads directory.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Skip confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp tka-proxy clear --yes
     *
     * @subcommand clear
     */
    public function clear(array $args, array $assocArgs): void
    {
        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            WP_CLI::error('Could not resolve uploads directory path.');
        }

        $uploadsDir = $uploads['basedir'];
        if (!is_dir($uploadsDir)) {
            WP_CLI::success('Local uploads directory does not exist or is already empty.');
            return;
        }

        if (!isset($assocArgs['yes'])) {
            WP_CLI::confirm("Are you sure you want to delete all cached files recursively inside '{$uploadsDir}'?");
        }

        WP_CLI::line('Clearing local uploads cache folder...');
        $this->deleteDirectoryContents($uploadsDir);

        // Ensure directory continues to exist for future mirroring
        wp_mkdir_p($uploadsDir);

        WP_CLI::success('Local uploads cache folder cleared.');
    }

    /**
     * Recursively retrieves directory file count and byte size.
     *
     * @param string $dir
     * @return array
     */
    private function getDirectoryStats(string $dir): array
    {
        $size  = 0;
        $count = 0;

        if (!is_dir($dir)) {
            return ['size' => 0, 'count' => 0];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
                $count++;
            }
        }

        return ['size' => $size, 'count' => $count];
    }

    /**
     * Formats bytes to human-readable size.
     *
     * @param int $bytes
     * @param int $precision
     * @return string
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow   = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow   = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Recursively deletes all files and subdirectories inside a directory.
     *
     * @param string $dir
     */
    private function deleteDirectoryContents(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $realPath = $fileinfo->getRealPath();
            if ($realPath === false) {
                continue;
            }

            if ($fileinfo->isDir()) {
                @rmdir($realPath);
            } else {
                @unlink($realPath);
            }
        }
    }
}
