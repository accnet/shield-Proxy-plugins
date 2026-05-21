<?php
/**
 * Shield Option Manager
 * 
 * Centralized option management with request-level caching to reduce database queries.
 * 
 * @package Shield_Manager
 * @since 1.5.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Shield_Option_Manager
{

    /**
     * In-memory cache for options during current request
     * 
     * @var array
     */
    private static $cache = array();

    /**
     * Track which options have been loaded
     * 
     * @var array
     */
    private static $loaded = array();

    /**
     * Get option with caching
     * 
     * Reduces database queries by caching options in memory for the current request.
     * Subsequent calls for the same option will return cached value.
     * 
     * @since 1.5.0
     * @param string $key Option name
     * @param mixed $default Default value if option doesn't exist
     * @return mixed Option value
     */
    public static function get($key, $default = false)
    {
        // Return from cache if already loaded
        if (isset(self::$loaded[$key])) {
            return self::$cache[$key];
        }

        // Load from database
        $value = get_option($key, $default);

        // Cache the value
        self::$cache[$key] = $value;
        self::$loaded[$key] = true;

        return $value;
    }

    /**
     * Update option and update cache
     * 
     * Updates both database and cache to keep them in sync.
     * 
     * @since 1.5.0
     * @param string $key Option name
     * @param mixed $value Option value
     * @param bool|string $autoload Whether to autoload this option
     * @return bool True if value was updated, false otherwise
     */
    public static function update($key, $value, $autoload = true)
    {
        // Update cache first
        self::$cache[$key] = $value;
        self::$loaded[$key] = true;

        // Update database
        return update_option($key, $value, $autoload);
    }

    /**
     * Delete option and remove from cache
     * 
     * @since 1.5.0
     * @param string $key Option name
     * @return bool True if option was deleted, false otherwise
     */
    public static function delete($key)
    {
        // Remove from cache
        unset(self::$cache[$key]);
        unset(self::$loaded[$key]);

        // Delete from database
        return delete_option($key);
    }

    /**
     * Clear cache for specific key or all keys
     * 
     * Useful for testing or when you need to force a fresh database read.
     * 
     * @since 1.5.0
     * @param string|null $key Option name to clear, or null to clear all
     * @return void
     */
    public static function clear_cache($key = null)
    {
        if ($key === null) {
            // Clear all cache
            self::$cache = array();
            self::$loaded = array();
        }
        else {
            // Clear specific key
            unset(self::$cache[$key]);
            unset(self::$loaded[$key]);
        }
    }

    /**
     * Get cache statistics (for debugging)
     * 
     * @since 1.5.0
     * @return array Cache statistics
     */
    public static function get_cache_stats()
    {
        return array(
            'cached_keys' => count(self::$loaded),
            'keys' => array_keys(self::$loaded),
        );
    }

    /**
     * Check if a key is cached
     * 
     * @since 1.5.0
     * @param string $key Option name
     * @return bool True if cached, false otherwise
     */
    public static function is_cached($key)
    {
        return isset(self::$loaded[$key]);
    }
}
