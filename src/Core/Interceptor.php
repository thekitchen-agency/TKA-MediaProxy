<?php

declare(strict_types=1);

namespace Tka\MediaProxy\Core;

use Tka\MediaProxy\Http\Client;

/**
 * Intercepts missing local media assets early in the WordPress lifecycle and proxies them from production.
 */
class Interceptor
{
    /**
     * Initializes the request interceptor.
     *
     * Runs on plugins_loaded priority 1.
     */
    public static function init(): void
    {
        // 1. Strictly verify local environment to prevent execution on production.
        if (!self::isLocalEnvironment()) {
            return;
        }

        // 2. Do not intercept CLI requests.
        if (PHP_SAPI === 'cli') {
            return;
        }

        self::handleRequest();
    }

    /**
     * Inspects the current request URI and handles intercept logic if targeting a missing uploads file.
     */
    public static function handleRequest(): void
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if (empty($requestUri)) {
            return;
        }

        // Strip query parameters to match physical file paths cleanly
        $requestPath = parse_url($requestUri, PHP_URL_PATH);
        if (empty($requestPath)) {
            return;
        }

        // Retrieve local WordPress uploads folder configurations
        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            return;
        }

        $uploadsUrlPath = parse_url($uploads['baseurl'], PHP_URL_PATH);
        if (empty($uploadsUrlPath)) {
            return;
        }

        // Ensure leading/trailing slashes are standardized
        $uploadsUrlPath = '/' . trim($uploadsUrlPath, '/') . '/';

        // Match using a regex pattern for uploads assets
        $escapedPath = preg_quote($uploadsUrlPath, '#');
        $pattern     = '#^' . $escapedPath . '(?P<relative_path>.+)$#i';

        if (!preg_match($pattern, $requestPath, $matches)) {
            return;
        }

        $relativePath = urldecode($matches['relative_path']);

        // Prevent directory traversal attacks for safety
        if (str_contains($relativePath, '..') || str_contains($relativePath, './')) {
            return;
        }

        $localFilePath = $uploads['basedir'] . '/' . ltrim($relativePath, '/');

        // Check if a directory is requested instead of a file
        if (is_dir($localFilePath)) {
            return;
        }

        // Phase 1 (Check): Let the webserver serve it natively if the file is physically present
        if (file_exists($localFilePath)) {
            return;
        }

        // Phase 2 (Fetch & Stream): Intercept missing local asset and query production URL
        $productionUrl = Config::getProductionUrl();
        if (empty($productionUrl)) {
            return;
        }

        $remoteUrl = rtrim($productionUrl, '/') . $uploadsUrlPath . ltrim($relativePath, '/');

        // Prepare local temporary file destination
        $tempDir = get_temp_dir();
        $tempFilePath = tempnam($tempDir, 'tka_media_proxy_');
        if (!$tempFilePath) {
            self::serve500('Failed to create temporary file.');
        }

        $client = new Client();
        $result = $client->download($remoteUrl, $tempFilePath);

        // Fallback translation: If local uses Bedrock (/app/uploads/) but production is standard WP (/wp-content/uploads/) or vice-versa
        if (is_wp_error($result) || $result['code'] !== 200) {
            $fallbackUrl = null;
            if (str_starts_with($uploadsUrlPath, '/app/')) {
                $fallbackUrlPath = str_replace('/app/', '/wp-content/', $uploadsUrlPath);
                $fallbackUrl = rtrim($productionUrl, '/') . $fallbackUrlPath . ltrim($relativePath, '/');
            } elseif (str_starts_with($uploadsUrlPath, '/wp-content/')) {
                $fallbackUrlPath = str_replace('/wp-content/', '/app/', $uploadsUrlPath);
                $fallbackUrl = rtrim($productionUrl, '/') . $fallbackUrlPath . ltrim($relativePath, '/');
            }

            if ($fallbackUrl) {
                $fallbackResult = $client->download($fallbackUrl, $tempFilePath);
                if (!is_wp_error($fallbackResult) && $fallbackResult['code'] === 200) {
                    $result = $fallbackResult;
                }
            }
        }

        if (is_wp_error($result)) {
            if (file_exists($tempFilePath)) {
                @unlink($tempFilePath);
            }
            self::serve404();
        }

        $responseCode = $result['code'];
        if ($responseCode !== 200) {
            if (file_exists($tempFilePath)) {
                @unlink($tempFilePath);
            }
            self::serve404();
        }

        // Ensure destination directory path exists
        $localFileDirectory = dirname($localFilePath);
        if (!is_dir($localFileDirectory)) {
            wp_mkdir_p($localFileDirectory);
        }

        // Atomic move to the local target uploads path
        $moved = @rename($tempFilePath, $localFilePath);
        if (!$moved) {
            // Fallback to copy and delete if rename fails across partitions/volumes
            $copied = @copy($tempFilePath, $localFilePath);
            if (file_exists($tempFilePath)) {
                @unlink($tempFilePath);
            }
            if (!$copied) {
                self::serve500('Failed to write proxy file to local storage.');
            }
        }

        $contentType = wp_remote_retrieve_header($result, 'content-type');
        $contentLength = wp_remote_retrieve_header($result, 'content-length');

        // Stream binary output back to client browser with appropriate headers
        self::streamFile($localFilePath, $contentType, $contentLength);
    }

    /**
     * Strictly verifies if the current system is running in a local environment.
     *
     * @return bool
     */
    public static function isLocalEnvironment(): bool
    {
        // 1. Check common environment constants
        if (defined('WP_ENV') && in_array(strtolower(WP_ENV), ['local', 'development', 'dev'], true)) {
            return true;
        }

        if (defined('WP_ENVIRONMENT_TYPE') && in_array(strtolower(WP_ENVIRONMENT_TYPE), ['local', 'development'], true)) {
            return true;
        }

        // 2. Fallback to host/domain extensions checking
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        if (!empty($host)) {
            $host = strtolower($host);

            // Strip port number if present (e.g. localhost:8080)
            if (($pos = strpos($host, ':')) !== false) {
                $host = substr($host, 0, $pos);
            }

            if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
                return true;
            }

            $localTlds = ['.local', '.test', '.ddev.site', '.ddev'];
            foreach ($localTlds as $tld) {
                if (str_ends_with($host, $tld)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Cleanly streams a local file to the browser and exits.
     *
     * @param string $filePath      The absolute local file path.
     * @param string $contentType   The file content type header.
     * @param string $contentLength The file content length header.
     */
    private static function streamFile(string $filePath, string $contentType, string $contentLength): void
    {
        if (empty($contentType)) {
            $contentType = self::getMimeType($filePath);
        }

        if (empty($contentLength)) {
            $contentLength = (string) filesize($filePath);
        }

        // Clear output buffer stacks to avoid corrupting raw binary contents
        while (ob_get_level()) {
            ob_end_clean();
        }

        header("HTTP/1.1 200 OK");
        header("Content-Type: " . $contentType);
        header("Content-Length: " . $contentLength);
        header("Cache-Control: public, max-age=31536000");

        readfile($filePath);
        exit;
    }

    /**
     * Resolves mime types for files in case headers are omitted.
     *
     * @param string $filePath
     * @return string
     */
    private static function getMimeType(string $filePath): string
    {
        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($filePath);
            if ($mime && $mime !== 'text/plain') {
                return $mime;
            }
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimes = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'svg'  => 'image/svg+xml',
            'pdf'  => 'application/pdf',
            'mp4'  => 'video/mp4',
            'mp3'  => 'audio/mpeg',
            'zip'  => 'application/zip',
        ];

        return $mimes[$ext] ?? 'application/octet-stream';
    }

    /**
     * Terminates and returns a 404 response.
     */
    private static function serve404(): void
    {
        while (ob_get_level()) {
            ob_end_clean();
        }

        header("HTTP/1.1 404 Not Found");
        header("Content-Type: text/plain; charset=utf-8");
        echo "404 Not Found (TKA Media Proxy: Asset missing or unreachable)";
        exit;
    }

    /**
     * Terminates and returns a 500 response.
     *
     * @param string $message
     */
    private static function serve500(string $message): void
    {
        while (ob_get_level()) {
            ob_end_clean();
        }

        header("HTTP/1.1 500 Internal Server Error");
        header("Content-Type: text/plain; charset=utf-8");
        echo "500 Internal Server Error (TKA Media Proxy: " . esc_html($message) . ")";
        exit;
    }
}
