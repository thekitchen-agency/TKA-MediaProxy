<?php

declare(strict_types=1);

namespace Tka\MediaProxy\Http;

use Tka\MediaProxy\Core\Config;
use WP_Error;

/**
 * A robust HTTP client wrapper for downloading remote assets safely and efficiently.
 */
class Client
{
    private string $userAgent;
    private int $timeout;
    private bool $sslVerify;

    /**
     * Client Constructor.
     *
     * @param string|null $userAgent Custom user agent to use, or null to auto-generate a browser agent.
     * @param int         $timeout   Timeout in seconds.
     * @param bool|null   $sslVerify Custom SSL verify setting, or null to load from Config.
     */
    public function __construct(
        ?string $userAgent = null,
        int $timeout = 15,
        ?bool $sslVerify = null
    ) {
        $this->timeout = $timeout;
        $this->sslVerify = $sslVerify ?? Config::getSslVerify();
        $this->userAgent = $userAgent ?? $this->getRandomBrowserUserAgent();
    }

    /**
     * Downloads a file from the production URL and streams it to a local temporary file.
     *
     * @param string $remoteUrl    The production file URL.
     * @param string $tempFilePath The local temporary path where the file should be saved.
     * @return array|WP_Error      An array containing response code and headers on success, or WP_Error.
     */
    public function download(string $remoteUrl, string $tempFilePath): array|WP_Error
    {
        $args = [
            'timeout'    => $this->timeout,
            'user-agent' => $this->userAgent,
            'sslverify'  => $this->sslVerify,
            'stream'     => true,
            'filename'   => $tempFilePath,
        ];

        // Perform the request, streaming the output to the temp file
        $response = wp_remote_get($remoteUrl, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $headers = wp_remote_retrieve_headers($response);

        return [
            'code'    => $code,
            'headers' => $headers,
        ];
    }

    /**
     * Returns a random, realistic browser user-agent to prevent bot detection issues.
     *
     * @return string
     */
    private function getRandomBrowserUserAgent(): string
    {
        $agents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/121.0',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:109.0) Gecko/20100101 Firefox/121.0',
        ];

        return $agents[array_rand($agents)];
    }
}
