<?php

namespace Caixingyue\LaravelStarLog\Support;

use Closure;
use Detection\Cache\CacheException;
use Detection\Exception\MobileDetectException;
use Detection\MobileDetect;

class UserAgentDetector extends MobileDetect
{
    /**
     * List of desktop devices.
     *
     * @var array<string, string>
     */
    protected static array $desktopDevices = [
        'Macintosh' => 'Macintosh',
    ];

    /**
     * List of additional operating systems.
     *
     * @var array<string, string>
     */
    protected static array $additionalOperatingSystems = [
        'Windows' => 'Windows',
        'Windows NT' => 'Windows NT',
        'OS X' => 'Mac OS X',
        'Debian' => 'Debian',
        'Ubuntu' => 'Ubuntu',
        'Macintosh' => 'PPC',
        'OpenBSD' => 'OpenBSD',
        'Linux' => 'Linux',
        'ChromeOS' => 'CrOS',
    ];

    /**
     * List of additional browsers.
     *
     * @var array<string, string>
     */
    protected static array $additionalBrowsers = [
        'Opera Mini' => 'Opera Mini',
        'Opera' => 'Opera|OPR',
        'Edge' => 'Edge|Edg',
        'Coc Coc' => 'coc_coc_browser',
        'UCBrowser' => 'UCBrowser',
        'Vivaldi' => 'Vivaldi',
        'Chrome' => 'Chrome',
        'Firefox' => 'Firefox',
        'Safari' => 'Safari',
        'IE' => 'MSIE|IEMobile|MSIEMobile|Trident/[.0-9]+',
        'Netscape' => 'Netscape',
        'Mozilla' => 'Mozilla',
        'WeChat' => 'MicroMessenger',
    ];

    /**
     * Key value store for detection results.
     *
     * @var array<string, mixed>
     */
    protected array $store = [];

    /**
     * Get the device name.
     *
     * @throws CacheException
     */
    public function device(): ?string
    {
        return $this->rememberDetection('starlog.device', function () {
            return $this->matchUserAgentRules(
                $this->mergeRules(
                    static::$desktopDevices,
                    static::getPhoneDevices(),
                    static::getTabletDevices(),
                    static::$additionalOperatingSystems
                )
            );
        });
    }

    /**
     * Get the platform name from the User Agent.
     *
     * @throws CacheException
     */
    public function platform(): ?string
    {
        return $this->rememberDetection('starlog.platform', function () {
            return $this->matchUserAgentRules(
                $this->mergeRules(MobileDetect::getOperatingSystems(), static::$additionalOperatingSystems)
            );
        });
    }

    /**
     * Get the browser name from the User Agent.
     *
     * @throws CacheException
     */
    public function browser(): ?string
    {
        return $this->rememberDetection('starlog.browser', function () {
            return $this->matchUserAgentRules(
                $this->mergeRules(static::$additionalBrowsers, MobileDetect::getBrowsers())
            );
        });
    }

    /**
     * Determine if the device is a desktop computer.
     *
     * @throws CacheException
     * @throws MobileDetectException
     */
    public function isDesktop(): bool
    {
        return $this->rememberDetection('starlog.desktop', function () {
            if (
                $this->getUserAgent() === static::$cloudFrontUA
                && $this->getHttpHeader('HTTP_CLOUDFRONT_IS_DESKTOP_VIEWER') === 'true'
            ) {
                return true;
            }

            return ! $this->isMobile() && ! $this->isTablet();
        });
    }

    /**
     * Match a detection rule and return the matched key.
     *
     * @param  array<string, string|string[]>  $rules
     */
    protected function matchUserAgentRules(array $rules): ?string
    {
        $userAgent = $this->getUserAgent();

        foreach ($rules as $key => $regex) {
            if (empty($regex)) {
                continue;
            }

            if (is_array($regex)) {
                foreach ($regex as $regexItem) {
                    if ($this->match($regexItem, $userAgent)) {
                        return $key ?: reset($this->matchesArray);
                    }
                }
            } else {
                if ($this->match($regex, $userAgent)) {
                    return $key ?: reset($this->matchesArray);
                }
            }
        }

        return null;
    }

    /**
     * Retrieve a cached detection result or resolve the value.
     *
     * @param  Closure():mixed  $callback
     *
     * @throws CacheException
     */
    protected function rememberDetection(string $key, Closure $callback): mixed
    {
        $cacheKey = $this->createCacheKey($key);

        if (! is_null($cacheItem = $this->store[$cacheKey] ?? null)) {
            return $cacheItem;
        }

        return tap($callback(), function (mixed $result) use ($cacheKey): void {
            $this->store[$cacheKey] = $result;
        });
    }

    /**
     * Merge multiple rules into one array.
     *
     * @param  array<string, string|string[]>  ...$all
     * @return array<string, string|string[]>
     */
    protected function mergeRules(array ...$all): array
    {
        $merged = [];

        foreach ($all as $rules) {
            foreach ($rules as $key => $value) {
                if (empty($merged[$key])) {
                    $merged[$key] = $value;
                } elseif (is_array($merged[$key])) {
                    $merged[$key][] = $value;
                } else {
                    $merged[$key] .= '|' . $value;
                }
            }
        }

        return $merged;
    }
}
