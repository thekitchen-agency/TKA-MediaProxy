<?php

declare(strict_types=1);

namespace Tka\MediaProxy\Core;

/**
 * Handles plugin configuration from constants and database options.
 */
class Config
{
    public const OPTION_PRODUCTION_URL = 'tka_media_proxy_production_url';
    public const OPTION_SSL_VERIFY     = 'tka_media_proxy_ssl_verify';

    /**
     * Gets the configured production site URL.
     *
     * @return string Production URL or empty string if not set.
     */
    public static function getProductionUrl(): string
    {
        if (defined('TKA_MEDIA_PROXY_PRODUCTION_URL')) {
            return (string) TKA_MEDIA_PROXY_PRODUCTION_URL;
        }

        return (string) get_option(self::OPTION_PRODUCTION_URL, '');
    }

    /**
     * Sets the production site URL option.
     *
     * @param string $url
     * @return bool True if value changed, false otherwise.
     */
    public static function setProductionUrl(string $url): bool
    {
        return update_option(self::OPTION_PRODUCTION_URL, rtrim($url, '/'));
    }

    /**
     * Checks if SSL verification is enabled.
     *
     * @return bool
     */
    public static function getSslVerify(): bool
    {
        if (defined('TKA_MEDIA_PROXY_SSL_VERIFY')) {
            return (bool) TKA_MEDIA_PROXY_SSL_VERIFY;
        }

        $value = get_option(self::OPTION_SSL_VERIFY, '1');
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Sets the SSL verification option.
     *
     * @param bool $verify
     * @return bool True if value changed, false otherwise.
     */
    public static function setSslVerify(bool $verify): bool
    {
        return update_option(self::OPTION_SSL_VERIFY, $verify ? '1' : '0');
    }
}
