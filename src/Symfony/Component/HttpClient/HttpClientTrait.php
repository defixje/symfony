<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient;

use Symfony\Component\HttpClient\Exception\InvalidArgumentException;
use Symfony\Component\HttpClient\Exception\TransportException;

/**
 * Provides the common logic from writing HttpClientInterface implementations.
 *
 * All private methods are static to prevent implementers from creating memory leaks via circular references.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 */
trait HttpClientTrait
{
    private static int $CHUNK_SIZE = 16372;

    /**
     * {@inheritdoc}
     */
    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->defaultOptions = self::mergeDefaultOptions($options, $this->defaultOptions);

        return $clone;
    }

    /**
     * Validates and normalizes method, URL and options, and merges them with defaults.
     *
     * @throws InvalidArgumentException When a not-supported option is found
     */
    private static function prepareRequest(?string $method, ?string $url, array $options, array $defaultOptions = [], bool $allowExtraOptions = false): array
    {
        if (null !== $method) {
            if (\strlen($method) !== strspn($method, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ')) {
                throw new InvalidArgumentException(sprintf('Invalid HTTP method "%s", only uppercase letters are accepted.', $method));
            }
            if (!$method) {
                throw new InvalidArgumentException('The HTTP method cannot be empty.');
            }
        }

        $options = self::mergeDefaultOptions($options, $defaultOptions, $allowExtraOptions);

        $buffer = $options['buffer'] ?? true;

        if ($buffer instanceof \Closure) {
            $options['buffer'] = static function (array $headers) use ($buffer) {
                if (!\is_bool($buffer = $buffer($headers))) {
                    if (!\is_array($bufferInfo = @stream_get_meta_data($buffer))) {
                        throw new \LogicException(sprintf('The closure passed as option "buffer" must return bool or stream resource, got "%s".', get_debug_type($buffer)));
                    }

                    if (false === strpbrk($bufferInfo['mode'], 'acew+')) {
                        throw new \LogicException(sprintf('The stream returned by the closure passed as option "buffer" must be writeable, got mode "%s".', $bufferInfo['mode']));
                    }
                }

                return $buffer;
            };
        } elseif (!\is_bool($buffer)) {
            if (!\is_array($bufferInfo = @stream_get_meta_data($buffer))) {
                throw new InvalidArgumentException(sprintf('Option "buffer" must be bool, stream resource or Closure, "%s" given.', get_debug_type($buffer)));
            }

            if (false === strpbrk($bufferInfo['mode'], 'acew+')) {
                throw new InvalidArgumentException(sprintf('The stream in option "buffer" must be writeable, mode "%s" given.', $bufferInfo['mode']));
            }
        }

        if (isset($options['json'])) {
            if (isset($options['body']) && '' !== $options['body']) {
                throw new InvalidArgumentException('Define either the "json" or the "body" option, setting both is not supported.');
            }
            $options['body'] = self::jsonEncode($options['json']);
            unset($options['json']);

            if (!isset($options['normalized_headers']['content-type'])) {
                $options['normalized_headers']['content-type'] = [$options['headers'][] = 'Content-Type: application/json'];
            }
        }

        if (!isset($options['normalized_headers']['accept'])) {
            $options['normalized_headers']['accept'] = [$options['headers'][] = 'Accept: */*'];
        }

        if (isset($options['body'])) {
            $options['body'] = self::normalizeBody($options['body']);

            if (\is_string($options['body'])
                && (string) \strlen($options['body']) !== substr($h = $options['normalized_headers']['content-length'][0] ?? '', 16)
                && ('' !== $h || ('' !== $options['body'] && !isset($options['normalized_headers']['transfer-encoding'])))
            ) {
                $options['normalized_headers']['content-length'] = [substr_replace($h ?: 'Content-Length: ', \strlen($options['body']), 16)];
                $options['headers'] = array_merge(...array_values($options['normalized_headers']));
            }
        }

        if (isset($options['peer_fingerprint'])) {
            $options['peer_fingerprint'] = self::normalizePeerFingerprint($options['peer_fingerprint']);
        }

        // Validate on_progress
        if (!\is_callable($onProgress = $options['on_progress'] ?? 'var_dump')) {
            throw new InvalidArgumentException(sprintf('Option "on_progress" must be callable, "%s" given.', get_debug_type($onProgress)));
        }

        if (\is_array($options['auth_basic'] ?? null)) {
            $count = \count($options['auth_basic']);
            if ($count <= 0 || $count > 2) {
                throw new InvalidArgumentException(sprintf('Option "auth_basic" must contain 1 or 2 elements, "%s" given.', $count));
            }

            $options['auth_basic'] = implode(':', $options['auth_basic']);
        }

        if (!\is_string($options['auth_basic'] ?? '')) {
            throw new InvalidArgumentException(sprintf('Option "auth_basic" must be string or an array, "%s" given.', get_debug_type($options['auth_basic'])));
        }

        if (isset($options['auth_bearer'])) {
            if (!\is_string($options['auth_bearer'])) {
                throw new InvalidArgumentException(sprintf('Option "auth_bearer" must be a string, "%s" given.', get_debug_type($options['auth_bearer'])));
            }
            if (preg_match('{[^\x21-\x7E]}', $options['auth_bearer'])) {
                throw new InvalidArgumentException('Invalid character found in option "auth_bearer": '.json_encode($options['auth_bearer']).'.');
            }
        }

        if (isset($options['auth_basic'], $options['auth_bearer'])) {
            throw new InvalidArgumentException('Define either the "auth_basic" or the "auth_bearer" option, setting both is not supported.');
        }

        if (null !== $url) {
            // Merge auth with headers
            if (($options['auth_basic'] ?? false) && !($options['normalized_headers']['authorization'] ?? false)) {
                $options['normalized_headers']['authorization'] = [$options['headers'][] = 'Authorization: Basic '.base64_encode($options['auth_basic'])];
            }
            // Merge bearer with headers
            if (($options['auth_bearer'] ?? false) && !($options['normalized_headers']['authorization'] ?? false)) {
                $options['normalized_headers']['authorization'] = [$options['headers'][] = 'Authorization: Bearer '.$options['auth_bearer']];
            }

            unset($options['auth_basic'], $options['auth_bearer']);

            // Parse base URI
            if (\is_string($options['base_uri'])) {
                $options['base_uri'] = self::parseUrl($options['base_uri']);
            }

            // Validate and resolve URL
            $url = self::parseUrl($url, $options['query']);
            $url = self::resolveUrl($url, $options['base_uri'], $defaultOptions['query'] ?? []);
        }

        // Finalize normalization of options
        $options['http_version'] = (string) ($options['http_version'] ?? '') ?: null;
        if (0 > $options['timeout'] = (float) ($options['timeout'] ?? ini_get('default_socket_timeout'))) {
            $options['timeout'] = 172800.0; // 2 days
        }

        $options['max_duration'] = isset($options['max_duration']) ? (float) $options['max_duration'] : 0;

        return [$url, $options];
    }

    /**
     * @throws InvalidArgumentException When an invalid option is found
     */
    private static function mergeDefaultOptions(array $options, array $defaultOptions, bool $allowExtraOptions = false): array
    {
        $options['normalized_headers'] = self::normalizeHeaders($options['headers'] ?? []);

        if ($defaultOptions['headers'] ?? false) {
            $options['normalized_headers'] += self::normalizeHeaders($defaultOptions['headers']);
        }

        $options['headers'] = array_merge(...array_values($options['normalized_headers']) ?: [[]]);

        if ($resolve = $options['resolve'] ?? false) {
            $options['resolve'] = [];
            foreach ($resolve as $k => $v) {
                $options['resolve'][substr(self::parseUrl('http://'.$k)['authority'], 2)] = (string) $v;
            }
        }

        // Option "query" is never inherited from defaults
        $options['query'] ??= [];

        foreach ($defaultOptions as $k => $v) {
            if ('normalized_headers' !== $k && !isset($options[$k])) {
                $options[$k] = $v;
            }
        }

        if (isset($defaultOptions['extra'])) {
            $options['extra'] += $defaultOptions['extra'];
        }

        if ($resolve = $defaultOptions['resolve'] ?? false) {
            foreach ($resolve as $k => $v) {
                $options['resolve'] += [substr(self::parseUrl('http://'.$k)['authority'], 2) => (string) $v];
            }
        }

        if ($allowExtraOptions || !$defaultOptions) {
            return $options;
        }

        // Look for unsupported options
        foreach ($options as $name => $v) {
            if (\array_key_exists($name, $defaultOptions) || 'normalized_headers' === $name) {
                continue;
            }

            if ('auth_ntlm' === $name) {
                if (!\extension_loaded('curl')) {
                    $msg = 'try installing the "curl" extension to use "%s" instead.';
                } else {
                    $msg = 'try using "%s" instead.';
                }

                throw new InvalidArgumentException(sprintf('Option "auth_ntlm" is not supported by "%s", '.$msg, __CLASS__, CurlHttpClient::class));
            }

            $alternatives = [];

            foreach ($defaultOptions as $key => $v) {
                if (levenshtein($name, $key) <= \strlen($name) / 3 || str_contains($key, $name)) {
                    $alternatives[] = $key;
                }
            }

            throw new InvalidArgumentException(sprintf('Unsupported option "%s" passed to "%s", did you mean "%s"?', $name, __CLASS__, implode('", "', $alternatives ?: array_keys($defaultOptions))));
        }

        return $options;
    }

    /**
     * @return string[][]
     *
     * @throws InvalidArgumentException When an invalid header is found
     */
    private static function normalizeHeaders(array $headers): array
    {
        $normalizedHeaders = [];

        foreach ($headers as $name => $values) {
            if ($values instanceof \Stringable) {
                $values = (string) $values;
            }

            if (\is_int($name)) {
                if (!\is_string($values)) {
                    throw new InvalidArgumentException(sprintf('Invalid value for header "%s": expected string, "%s" given.', $name, get_debug_type($values)));
                }
                [$name, $values] = explode(':', $values, 2);
                $values = [ltrim($values)];
            } elseif (!is_iterable($values)) {
                if (\is_object($values)) {
                    throw new InvalidArgumentException(sprintf('Invalid value for header "%s": expected string, "%s" given.', $name, get_debug_type($values)));
                }

                $values = (array) $values;
            }

            $lcName = strtolower($name);
            $normalizedHeaders[$lcName] = [];

            foreach ($values as $value) {
                $normalizedHeaders[$lcName][] = $value = $name.': '.$value;

                if (\strlen($value) !== strcspn($value, "\r\n\0")) {
                    throw new InvalidArgumentException(sprintf('Invalid header: CR/LF/NUL found in "%s".', $value));
                }
            }
        }

        return $normalizedHeaders;
    }

    /**
     * @param array|string|resource|\Traversable|\Closure $body
     *
     * @return string|resource|\Closure
     *
     * @throws InvalidArgumentException When an invalid body is passed
     */
    private static function normalizeBody($body)
    {
        if (\is_array($body)) {
            array_walk_recursive($body, $caster = static function (&$v) use (&$caster) {
                if (\is_object($v)) {
                    if ($vars = get_object_vars($v)) {
                        array_walk_recursive($vars, $caster);
                        $v = $vars;
                    } elseif (method_exists($v, '__toString')) {
                        $v = (string) $v;
                    }
                }
            });

            return http_build_query($body, '', '&');
        }

        if (\is_string($body)) {
            return $body;
        }

        $generatorToCallable = static function (\Generator $body): \Closure {
            return static function () use ($body) {
                while ($body->valid()) {
                    $chunk = $body->current();
                    $body->next();

                    if ('' !== $chunk) {
                        return $chunk;
                    }
                }

                return '';
            };
        };

        if ($body instanceof \Generator) {
            return $generatorToCallable($body);
        }

        if ($body instanceof \Traversable) {
            return $generatorToCallable((static function ($body) { yield from $body; })($body));
        }

        if ($body instanceof \Closure) {
            $r = new \ReflectionFunction($body);
            $body = $r->getClosure();

            if ($r->isGenerator()) {
                $body = $body(self::$CHUNK_SIZE);

                return $generatorToCallable($body);
            }

            return $body;
        }

        if (!\is_array(@stream_get_meta_data($body))) {
            throw new InvalidArgumentException(sprintf('Option "body" must be string, stream resource, iterable or callable, "%s" given.', get_debug_type($body)));
        }

        return $body;
    }

    /**
     * @throws InvalidArgumentException When an invalid fingerprint is passed
     */
    private static function normalizePeerFingerprint(mixed $fingerprint): array
    {
        if (\is_string($fingerprint)) {
            $fingerprint = match (\strlen($fingerprint = str_replace(':', '', $fingerprint))) {
                32 => ['md5' => $fingerprint],
                40 => ['sha1' => $fingerprint],
                44 => ['pin-sha256' => [$fingerprint]],
                64 => ['sha256' => $fingerprint],
                default => throw new InvalidArgumentException(sprintf('Cannot auto-detect fingerprint algorithm for "%s".', $fingerprint)),
            };
        } elseif (\is_array($fingerprint)) {
            foreach ($fingerprint as $algo => $hash) {
                $fingerprint[$algo] = 'pin-sha256' === $algo ? (array) $hash : str_replace(':', '', $hash);
            }
        } else {
            throw new InvalidArgumentException(sprintf('Option "peer_fingerprint" must be string or array, "%s" given.', get_debug_type($fingerprint)));
        }

        return $fingerprint;
    }

    /**
     * @throws InvalidArgumentException When the value cannot be json-encoded
     */
    private static function jsonEncode(mixed $value, int $flags = null, int $maxDepth = 512): string
    {
        $flags ??= \JSON_HEX_TAG | \JSON_HEX_APOS | \JSON_HEX_AMP | \JSON_HEX_QUOT | \JSON_PRESERVE_ZERO_FRACTION;

        try {
            $value = json_encode($value, $flags | \JSON_THROW_ON_ERROR, $maxDepth);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('Invalid value for "json" option: '.$e->getMessage());
        }

        return $value;
    }

    /**
     * Resolves a URL against a base URI.
     *
     * @see https://tools.ietf.org/html/rfc3986#section-5.2.2
     *
     * @throws InvalidArgumentException When an invalid URL is passed
     */
    private static function resolveUrl(array $url, ?array $base, array $queryDefaults = []): array
    {
        if (null !== $base && '' === ($base['scheme'] ?? '').($base['authority'] ?? '')) {
            throw new InvalidArgumentException(sprintf('Invalid "base_uri" option: host or scheme is missing in "%s".', implode('', $base)));
        }

        if (null === $url['scheme'] && (null === $base || null === $base['scheme'])) {
            throw new InvalidArgumentException(sprintf('Invalid URL: scheme is missing in "%s". Did you forget to add "http(s)://"?', implode('', $base ?? $url)));
        }

        if (null === $base && '' === $url['scheme'].$url['authority']) {
            throw new InvalidArgumentException(sprintf('Invalid URL: no "base_uri" option was provided and host or scheme is missing in "%s".', implode('', $url)));
        }

        if (null !== $url['scheme']) {
            $url['path'] = self::removeDotSegments($url['path'] ?? '');
        } else {
            if (null !== $url['authority']) {
                $url['path'] = self::removeDotSegments($url['path'] ?? '');
            } else {
                if (null === $url['path']) {
                    $url['path'] = $base['path'];
                    $url['query'] ??= $base['query'];
                } else {
                    if ('/' !== $url['path'][0]) {
                        if (null === $base['path']) {
                            $url['path'] = '/'.$url['path'];
                        } else {
                            $segments = explode('/', $base['path']);
                            array_splice($segments, -1, 1, [$url['path']]);
                            $url['path'] = implode('/', $segments);
                        }
                    }

                    $url['path'] = self::removeDotSegments($url['path']);
                }

                $url['authority'] = $base['authority'];

                if ($queryDefaults) {
                    $url['query'] = '?'.self::mergeQueryString(substr($url['query'] ?? '', 1), $queryDefaults, false);
                }
            }

            $url['scheme'] = $base['scheme'];
        }

        if ('' === ($url['path'] ?? '')) {
            $url['path'] = '/';
        }

        if ('?' === ($url['query'] ?? '')) {
            $url['query'] = null;
        }

        return $url;
    }

    /**
     * Parses a URL and fixes its encoding if needed.
     *
     * @throws InvalidArgumentException When an invalid URL is passed
     */
    private static function parseUrl(string $url, array $query = [], array $allowedSchemes = ['http' => 80, 'https' => 443]): array
    {
        if (false === $parts = parse_url($url)) {
            throw new InvalidArgumentException(sprintf('Malformed URL "%s".', $url));
        }

        if ($query) {
            $parts['query'] = self::mergeQueryString($parts['query'] ?? null, $query, true);
        }

        $port = $parts['port'] ?? 0;

        if (null !== $scheme = $parts['scheme'] ?? null) {
            if (!isset($allowedSchemes[$scheme = strtolower($scheme)])) {
                throw new InvalidArgumentException(sprintf('Unsupported scheme in "%s".', $url));
            }

            $port = $allowedSchemes[$scheme] === $port ? 0 : $port;
            $scheme .= ':';
        }

        if (null !== $host = $parts['host'] ?? null) {
            if (!\defined('INTL_IDNA_VARIANT_UTS46') && preg_match('/[\x80-\xFF]/', $host)) {
                throw new InvalidArgumentException(sprintf('Unsupported IDN "%s", try enabling the "intl" PHP extension or running "composer require symfony/polyfill-intl-idn".', $host));
            }

            $host = \defined('INTL_IDNA_VARIANT_UTS46') ? idn_to_ascii($host, \IDNA_DEFAULT | \IDNA_USE_STD3_RULES | \IDNA_CHECK_BIDI | \IDNA_CHECK_CONTEXTJ | \IDNA_NONTRANSITIONAL_TO_ASCII, \INTL_IDNA_VARIANT_UTS46) ?: strtolower($host) : strtolower($host);
            $host .= $port ? ':'.$port : '';
        }

        foreach (['user', 'pass', 'path', 'query', 'fragment'] as $part) {
            if (!isset($parts[$part])) {
                continue;
            }

            if (str_contains($parts[$part], '%')) {
                // https://tools.ietf.org/html/rfc3986#section-2.3
                $parts[$part] = preg_replace_callback('/%(?:2[DE]|3[0-9]|[46][1-9A-F]|5F|[57][0-9A]|7E)++/i', function ($m) { return rawurldecode($m[0]); }, $parts[$part]);
            }

            // https://tools.ietf.org/html/rfc3986#section-3.3
            $parts[$part] = preg_replace_callback("#[^-A-Za-z0-9._~!$&/'()*+,;=:@%]++#", function ($m) { return rawurlencode($m[0]); }, $parts[$part]);
        }

        return [
            'scheme' => $scheme,
            'authority' => null !== $host ? '//'.(isset($parts['user']) ? $parts['user'].(isset($parts['pass']) ? ':'.$parts['pass'] : '').'@' : '').$host : null,
            'path' => isset($parts['path'][0]) ? $parts['path'] : null,
            'query' => isset($parts['query']) ? '?'.$parts['query'] : null,
            'fragment' => isset($parts['fragment']) ? '#'.$parts['fragment'] : null,
        ];
    }

    /**
     * Removes dot-segments from a path.
     *
     * @see https://tools.ietf.org/html/rfc3986#section-5.2.4
     */
    private static function removeDotSegments(string $path)
    {
        $result = '';

        while (!\in_array($path, ['', '.', '..'], true)) {
            if ('.' === $path[0] && (str_starts_with($path, $p = '../') || str_starts_with($path, $p = './'))) {
                $path = substr($path, \strlen($p));
            } elseif ('/.' === $path || str_starts_with($path, '/./')) {
                $path = substr_replace($path, '/', 0, 3);
            } elseif ('/..' === $path || str_starts_with($path, '/../')) {
                $i = strrpos($result, '/');
                $result = $i ? substr($result, 0, $i) : '';
                $path = substr_replace($path, '/', 0, 4);
            } else {
                $i = strpos($path, '/', 1) ?: \strlen($path);
                $result .= substr($path, 0, $i);
                $path = substr($path, $i);
            }
        }

        return $result;
    }

    /**
     * Merges and encodes a query array with a query string.
     *
     * @throws InvalidArgumentException When an invalid query-string value is passed
     */
    private static function mergeQueryString(?string $queryString, array $queryArray, bool $replace): ?string
    {
        if (!$queryArray) {
            return $queryString;
        }

        $query = [];

        if (null !== $queryString) {
            foreach (explode('&', $queryString) as $v) {
                if ('' !== $v) {
                    $k = urldecode(explode('=', $v, 2)[0]);
                    $query[$k] = (isset($query[$k]) ? $query[$k].'&' : '').$v;
                }
            }
        }

        if ($replace) {
            foreach ($queryArray as $k => $v) {
                if (null === $v) {
                    unset($query[$k]);
                }
            }
        }

        $queryString = http_build_query($queryArray, '', '&', \PHP_QUERY_RFC3986);
        $queryArray = [];

        if ($queryString) {
            foreach (explode('&', $queryString) as $v) {
                $queryArray[rawurldecode(explode('=', $v, 2)[0])] = $v;
            }
        }

        return implode('&', $replace ? array_replace($query, $queryArray) : ($query + $queryArray));
    }

    /**
     * Loads proxy configuration from the same environment variables as curl when no proxy is explicitly set.
     */
    private static function getProxy(?string $proxy, array $url, ?string $noProxy): ?array
    {
        if (null === $proxy) {
            // Ignore HTTP_PROXY except on the CLI to work around httpoxy set of vulnerabilities
            $proxy = $_SERVER['http_proxy'] ?? (\in_array(\PHP_SAPI, ['cli', 'phpdbg'], true) ? $_SERVER['HTTP_PROXY'] ?? null : null) ?? $_SERVER['all_proxy'] ?? $_SERVER['ALL_PROXY'] ?? null;

            if ('https:' === $url['scheme']) {
                $proxy = $_SERVER['https_proxy'] ?? $_SERVER['HTTPS_PROXY'] ?? $proxy;
            }
        }

        if (null === $proxy) {
            return null;
        }

        $proxy = (parse_url($proxy) ?: []) + ['scheme' => 'http'];

        if (!isset($proxy['host'])) {
            throw new TransportException('Invalid HTTP proxy: host is missing.');
        }

        if ('http' === $proxy['scheme']) {
            $proxyUrl = 'tcp://'.$proxy['host'].':'.($proxy['port'] ?? '80');
        } elseif ('https' === $proxy['scheme']) {
            $proxyUrl = 'ssl://'.$proxy['host'].':'.($proxy['port'] ?? '443');
        } else {
            throw new TransportException(sprintf('Unsupported proxy scheme "%s": "http" or "https" expected.', $proxy['scheme']));
        }

        $noProxy ??= $_SERVER['no_proxy'] ?? $_SERVER['NO_PROXY'] ?? '';
        $noProxy = $noProxy ? preg_split('/[\s,]+/', $noProxy) : [];

        return [
            'url' => $proxyUrl,
            'auth' => isset($proxy['user']) ? 'Basic '.base64_encode(rawurldecode($proxy['user']).':'.rawurldecode($proxy['pass'] ?? '')) : null,
            'no_proxy' => $noProxy,
        ];
    }

    private static function shouldBuffer(array $headers): bool
    {
        if (null === $contentType = $headers['content-type'][0] ?? null) {
            return false;
        }

        if (false !== $i = strpos($contentType, ';')) {
            $contentType = substr($contentType, 0, $i);
        }

        return $contentType && preg_match('#^(?:text/|application/(?:.+\+)?(?:json|xml)$)#i', $contentType);
    }
}
