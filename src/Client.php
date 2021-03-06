<?php

namespace Phediverse\MastodonRest;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Request;
use Phediverse\MastodonRest\Resource\Account;
use Phediverse\MastodonRest\Resource\BaseResource;
use Phediverse\MastodonRest\Resource\Instance;

class Client
{
    const API_BASE = '/api/v1/';
    const RESOURCE_CLASSES = [Instance::class, Account::class]; // used for more secure PHP deserialization

    public static function build(string $instanceHostname, string $accessToken)
    {
        if (substr($instanceHostname, 0, 4) !== 'http') {
            $instanceHostname = 'https://' . $instanceHostname;
        }

        return new static(new \GuzzleHttp\Client([
            'base_uri' => $instanceHostname . static::API_BASE,
            'headers' => ['Authorization' => 'Bearer ' . $accessToken]
        ]));
    }

    protected $http;
    protected $instanceHostname;
    protected $cache;

    /**
     * @param ClientInterface $http
     * @param bool $useCache
     */
    public function __construct(ClientInterface $http, bool $useCache = true)
    {
        $this->http = $http;
        $this->instanceHostname = parse_url($http->getConfig('base_uri'), PHP_URL_HOST);
        $this->cache = $useCache ? [] : null;
    }

    /////////////////////////
    ///                   ///
    ///     RESOURCES     ///
    ///                   ///
    /////////////////////////

    /**
     * @param string $serialized PHP or JSON serialized data
     * @param string|null $uri if supplied, adds to the cache at that location; should generally a relative URI
     * @return BaseResource the original resource, as an object, with this client attached
     */
    public function deserialize(string $serialized, string $uri = null)
    {
        switch ($serialized[0] ?? '') {
            case 'C': // PHP serialization
                $resource = unserialize($serialized, static::RESOURCE_CLASSES);
                if (!($resource instanceof BaseResource)) {
                    throw new \InvalidArgumentException("Serialized data is not a resource");
                }
                $resource->setClient($this);
                break;
            case '{': // JSON serialization
                $decoded = json_decode($serialized, JSON_OBJECT_AS_ARRAY);
                if (!is_subclass_of($class = ($decoded['resourceType'] ?? 'INVALID'), BaseResource::class)) {
                    throw new \InvalidArgumentException("Serialized data is not a resource");
                }

                /** @noinspection PhpUndefinedMethodInspection */ // too much dynamic magic for PhpStorm to handle
                $resource = $class::fromData($decoded, $this);
                break;
            default:
                throw new \InvalidArgumentException("Unknown serialization format");
        }

        if ($uri && is_array($this->cache)) { // add to cache if we have a cache and a URI is provided
            if (!isset($class)) {
                $class = get_class($resource);
            }

            if (!isset($this->cache[$class])) {
                $this->cache[$class] = [];
            }
            $this->cache[$class][$uri] = $resource;
        }

        return $resource;
    }

    // Instances

    public function getInstanceHostname() : string
    {
        return $this->instanceHostname;
    }

    public function getInstance(string $host = null, bool $useCache = true) : Instance
    {
        if ($host !== null) {
            $host = (substr($host, 0, 4) !== 'http' ? ('https://' . $host) : $host) . static::API_BASE;
        }

        return $this->get(($host ?: '') . 'instance', Instance::class, $useCache, ['headers' => []]);
    }

    // Accounts

    public function getAccountId() : int
    {
        return $this->getAccount()->getId();
    }

    public function getAccount(int $id = null, bool $useCache = true) : Account
    {
        return $this->get('accounts/' . ($id ?: 'verify_credentials'), Account::class, $useCache);
    }

    /////////////////////////
    ///                   ///
    ///   CLIENT TOOLS    ///
    ///                   ///
    /////////////////////////

    // cache methods

    /**
     * Clears the internal object cache.
     *
     * @param string|null $class If null, clear everything. Otherwise, clear only entries for a specific class.
     * @param string|null $uri If non-null AND $class is set, clear only the specified key in the class.
     * @return $this
     */
    public function clearCache(string $class = null, string $uri = null)
    {
        if ($this->cache) {
            if (!$class) {
                $this->cache = [];
            } elseif (!$uri) {
                $this->cache[$class] = [];
            } else {
                unset($this->cache[$class][$uri]);
            }
        }

        return $this;
    }

    /**
     * Returns the resource cache of this client.
     *
     * @param bool $resolve whether to wait until all items in the cache have their promises resolved before returning
     * @return array
     */
    public function getCacheContents(bool $resolve = true)
    {
        if (!is_array($this->cache)) {
            throw new \InvalidArgumentException("Cache is turned off for this client");
        }

        if ($resolve) {
            foreach ($this->cache as &$class) {
                foreach ($class as &$entry) {
                    if (method_exists($entry, 'resolve')) {
                        $entry->resolve();
                    }
                }
            }
        }

        return $this->cache;
    }

    // under-the-hood methods

    protected function get(string $uri, string $class, bool $useCache = true, array $httpOptions = [])
    {
        if ($useCache && is_array($this->cache) && isset($this->cache[$class][$uri])) {
            return $this->cache[$class][$uri];
        }

        /** @noinspection PhpUndefinedMethodInspection */ // too much dynamic magic for PhpStorm to handle
        $item = $class::fromPromise($this->http->sendAsync(new Request('GET', $uri, $httpOptions)));

        if ($useCache && is_array($this->cache)) {
            if (!isset($this->cache[$class])) {
                $this->cache[$class] = [];
            }
            $this->cache[$class][$uri] = $item;
        }

        return $item;
    }
}
