<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

use core_cache\configurable_cache_interface;
use core_cache\definition;
use core_cache\key_aware_cache_interface;
use core_cache\lockable_cache_interface;
use core_cache\searchable_cache_interface;
use core_cache\store;
use core\clock;
use core\di;

/**
 * Redis Cache Store
 *
 * To allow separation of definitions in Moodle and faster purging, each cache
 * is implemented as a Redis hash.  That is a trade-off between having functionality of TTL
 * and being able to manage many caches in a single redis instance.  Given the recommendation
 * not to use TTL if at all possible and the benefits of having many stores in Redis using the
 * hash configuration, the hash implementation has been used.
 *
 * @package   cachestore_redis
 * @copyright   2013 Adam Durana
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cachestore_redis extends store implements
    key_aware_cache_interface,
    configurable_cache_interface,
    searchable_cache_interface,
    lockable_cache_interface
{
    /**
     * Compressor: none.
     */
    const COMPRESSOR_NONE = 0;

    /**
     * Compressor: PHP GZip.
     */
    const COMPRESSOR_PHP_GZIP = 1;

    /**
     * Compressor: PHP Zstandard.
     */
    const COMPRESSOR_PHP_ZSTD = 2;

    /**
     * @var string Suffix used on key name (for hash) to store the TTL sorted list
     */
    const TTL_SUFFIX = '_ttl';

    /**
     * @var int Number of items to delete from cache in one batch when expiring old TTL data.
     */
    const TTL_EXPIRE_BATCH = 10000;

    /** @var int The number of seconds to wait for a connection or response from the Redis server. */
    const CONNECTION_TIMEOUT = 3;

    /**
     * Name of this store.
     *
     * @var string
     */
    protected $name;

    /**
     * The definition hash, used for hash key
     *
     * @var string
     */
    protected $hash;

    /**
     * Flag for readiness!
     *
     * @var boolean
     */
    protected $isready = false;

    /**
     * Cache definition for this store.
     *
     * @var definition
     */
    protected $definition = null;

    /**
     * Connection to Redis for this store.
     *
     * @var Redis|RedisCluster
     */
    protected $redis;

    /**
     * Serializer for this store.
     *
     * @var int
     */
    protected $serializer = Redis::SERIALIZER_PHP;

    /**
     * Compressor for this store.
     *
     * @var int
     */
    protected $compressor = self::COMPRESSOR_NONE;


    /**
     * The number of seconds to wait for a connection or response from the Redis server.
     *
     * @var int
     */
    protected $connectiontimeout = self::CONNECTION_TIMEOUT;

    /**
     * Bytes read or written by last call to set()/get() or set_many()/get_many().
     *
     * @var int
     */
    protected $lastiobytes = 0;

    /** @var int Maximum number of seconds to wait for a lock before giving up. */
    protected $lockwait = 60;

    /** @var int Timeout before lock is automatically released (in case of crashes) */
    protected $locktimeout = 600;

    /** @var ?array Array of current locks, or null if we haven't registered shutdown function */
    protected $currentlocks = null;

    /** @var clock */
    private readonly clock $clock;

    /**
     * Determines if the requirements for this type of store are met.
     *
     * @return bool
     */
    public static function are_requirements_met() {
        return class_exists('Redis');
    }

    /**
     * Determines if this type of store supports a given mode.
     *
     * @param int $mode
     * @return bool
     */
    public static function is_supported_mode($mode) {
        return ($mode === self::MODE_APPLICATION || $mode === self::MODE_SESSION);
    }

    /**
     * Get the features of this type of cache store.
     *
     * @param array $configuration
     * @return int
     */
    public static function get_supported_features(array $configuration = array()) {
        // Although this plugin now supports TTL I did not add SUPPORTS_NATIVE_TTL here, because
        // doing so would cause Moodle to stop adding a 'TTL wrapper' to data items which enforces
        // the precise specified TTL. Unless the scheduled task is set to run rather frequently,
        // this could cause change in behaviour. Maybe later this should be reconsidered...
        return self::SUPPORTS_DATA_GUARANTEE + self::DEREFERENCES_OBJECTS + self::IS_SEARCHABLE;
    }

    /**
     * Get the supported modes of this type of cache store.
     *
     * @param array $configuration
     * @return int
     */
    public static function get_supported_modes(array $configuration = array()) {
        return self::MODE_APPLICATION + self::MODE_SESSION;
    }

    /**
     * Constructs an instance of this type of store.
     *
     * @param string $name
     * @param array $configuration
     */
    public function __construct(
        $name,
        array $configuration = [],
    ) {
        $this->name = $name;

        if (!array_key_exists('server', $configuration) || empty($configuration['server'])) {
            return;
        }
        if (array_key_exists('serializer', $configuration)) {
            $this->serializer = (int)$configuration['serializer'];
        }
        if (array_key_exists('compressor', $configuration)) {
            $this->compressor = (int)$configuration['compressor'];
        }
        if (array_key_exists('connectiontimeout', $configuration)) {
            $this->connectiontimeout = (int)$configuration['connectiontimeout'];
        }
        if (array_key_exists('lockwait', $configuration)) {
            $this->lockwait = (int)$configuration['lockwait'];
        }
        if (array_key_exists('locktimeout', $configuration)) {
            $this->locktimeout = (int)$configuration['locktimeout'];
        }
        $this->redis = $this->new_redis($configuration);
        $this->clock = di::get(clock::class);
    }

    /**
     * Create a new Redis or RedisCluster instance and connect to the server.
     *
     * @param array $configuration The redis instance configuration.
     * @return Redis|RedisCluster|null
     */
    protected function new_redis(array $configuration): Redis|RedisCluster|null {
        $encrypt = (bool) ($configuration['encryption'] ?? false);
        $clustermode = (bool) ($configuration['clustermode'] ?? false);
        $password = !empty($configuration['password']) ? $configuration['password'] : '';

        // Set Redis server(s).
        $servers = explode("\n", $configuration['server']);
        $trimmedservers = [];
        foreach ($servers as $server) {
            $server = strtolower(trim($server));
            if (!empty($server)) {
                if ($server[0] === '/' || str_starts_with($server, 'unix://')) {
                    $port = 0;
                    $trimmedservers[] = $server;
                } else {
                    $port = 6379; // No Unix socket so set default port.
                    if (strpos($server, ':')) { // Check for custom port.
                        list($server, $port) = explode(':', $server);
                    }
                    if (!$clustermode && $encrypt) {
                        $server = 'tls://' . $server;
                    }
                    $trimmedservers[] = $server.':'.$port;
                }

                // We only need the first record for the single redis.
                if (!$clustermode) {
                    // Handle the case when the server is not a Unix domain socket.
                    if ($port !== 0) {
                        // We only need the first record for the single redis.
                        $serverchunks = explode(':', $trimmedservers[0]);
                        // Get the last chunk as the port.
                        $port = array_pop($serverchunks);
                        // Combine the rest of the chunks back into a string as the server.
                        $server = implode(':', $serverchunks);
                    }
                    break;
                }
            }
        }

        // TLS/SSL Configuration.
        $exceptionclass = $clustermode ? 'RedisClusterException' : 'RedisException';
        $opts = [];
        if ($encrypt) {
            $opts = empty($configuration['cafile']) ?
                ['verify_peer' => false, 'verify_peer_name' => false] :
                ['cafile' => $configuration['cafile']];

            // For a single (non-cluster) Redis, the TLS/SSL config must be added to the 'stream' key.
            if (!$clustermode) {
                $opts['stream'] = $opts;
            }
        }
        // Connect to redis.
        $redis = null;
        try {
            // Create a $redis object of a RedisCluster or Redis class.
            $phpredisversion = phpversion('redis');
            if ($clustermode) {
                if (version_compare($phpredisversion, '6.0.0', '>=')) {
                    // Named parameters are fully supported starting from version 6.0.0.
                    $redis = new RedisCluster(
                        name: null,
                        seeds: $trimmedservers,
                        timeout: $this->connectiontimeout, // Timeout.
                        read_timeout: $this->connectiontimeout, // Read timeout.
                        persistent: true,
                        auth: $password,
                        context: !empty($opts) ? $opts : null,
                    );
                } else {
                    $redis = new RedisCluster(
                        null,
                        $trimmedservers,
                        $this->connectiontimeout,
                        $this->connectiontimeout,
                        true, $password,
                        !empty($opts) ? $opts : null,
                    );
                }
            } else {
                $redis = new Redis();
                if (version_compare($phpredisversion, '6.0.0', '>=')) {
                    // Named parameters are fully supported starting from version 6.0.0.
                    $redis->connect(
                        host: $server,
                        port: $port,
                        timeout: $this->connectiontimeout, // Timeout.
                        retry_interval: 100, // Retry interval.
                        read_timeout: $this->connectiontimeout, // Read timeout.
                        context: $opts,
                    );
                } else {
                    $redis->connect(
                        $server, $port,
                        $this->connectiontimeout,
                        null,
                        100,
                        $this->connectiontimeout,
                        $opts,
                    );
                }

                if (!empty($password)) {
                    $redis->auth($password);
                }
            }

            // In case of a TLS connection,
            // if phpredis client does not communicate immediately with the server the connection hangs.
            // See https://github.com/phpredis/phpredis/issues/2332.
            if ($encrypt && !$redis->ping('Ping')) {
                throw new $exceptionclass("Ping failed");
            }

            // If using compressor, serialisation will be done at cachestore level, not php-redis.
            if ($this->compressor === self::COMPRESSOR_NONE) {
                $redis->setOption(Redis::OPT_SERIALIZER, $this->serializer);
            }

            // Set the prefix.
            $prefix = !empty($configuration['prefix']) ? $configuration['prefix'] : '';
            if (!empty($prefix)) {
                $redis->setOption(Redis::OPT_PREFIX, $prefix);
            }
            $this->isready = true;
        } catch (RedisException | RedisClusterException $e) {
            $server = $clustermode ? implode(',', $trimmedservers) : $server.':'.$port;
            debugging("Failed to connect to Redis at {$server}, the error returned was: {$e->getMessage()}");
            $this->isready = false;
        }

        return $redis;
    }

    /**
     * See if we can ping Redis server
     *
     * @param RedisCluster|Redis $redis
     * @return bool
     */
    protected function ping(RedisCluster|Redis $redis): bool {
        try {
            if ($redis->ping() === false) {
                return false;
            }
        } catch (Exception $e) {
            return false;
        }
        return true;
    }

    /**
     * Get the name of the store.
     *
     * @return string
     */
    public function my_name() {
        return $this->name;
    }

    /**
     * Initialize the store.
     *
     * @param definition $definition
     * @return bool
     */
    public function initialise(definition $definition) {
        $this->definition = $definition;
        $this->hash       = $definition->generate_definition_hash();
        return true;
    }

    /**
     * Determine if the store is initialized.
     *
     * @return bool
     */
    public function is_initialised() {
        return ($this->definition !== null);
    }

    /**
     * Determine if the store is ready for use.
     *
     * @return bool
     */
    public function is_ready() {
        return $this->isready;
    }

    /**
     * Get the value associated with a given key.
     *
     * @param string $key The key to get the value of.
     * @return mixed The value of the key, or false if there is no value associated with the key.
     */
    public function get($key) {
        $value = $this->redis->hGet($this->hash, $key);

        if ($this->compressor == self::COMPRESSOR_NONE) {
            return $value;
        }

        // When using compression, values are always strings, so strlen will work.
        $this->lastiobytes = strlen($value);

        return $this->uncompress($value);
    }

    /**
     * Get the values associated with a list of keys.
     *
     * @param array $keys The keys to get the values of.
     * @return array An array of the values of the given keys.
     */
    public function get_many($keys) {
        $values = $this->redis->hMGet($this->hash, $keys) ?: [];

        if ($this->compressor == self::COMPRESSOR_NONE) {
            return $values;
        }

        $this->lastiobytes = 0;
        foreach ($values as &$value) {
            $this->lastiobytes += strlen($value);
            $value = $this->uncompress($value);
        }

        return $values;
    }

    /**
     * Gets the number of bytes read from or written to cache as a result of the last action.
     *
     * If compression is not enabled, this function always returns IO_BYTES_NOT_SUPPORTED. The reason is that
     * when compression is not enabled, data sent to the cache is not serialized, and we would
     * need to serialize it to compute the size, which would have a significant performance cost.
     *
     * @return int Bytes read or written
     * @since Moodle 4.0
     */
    public function get_last_io_bytes(): int {
        if ($this->compressor != self::COMPRESSOR_NONE) {
            return $this->lastiobytes;
        } else {
            // Not supported unless compression is on.
            return parent::get_last_io_bytes();
        }
    }

    /**
     * Set the value of a key.
     *
     * @param string $key The key to set the value of.
     * @param mixed $value The value.
     * @return bool True if the operation succeeded, false otherwise.
     */
    public function set($key, $value) {
        if ($this->compressor != self::COMPRESSOR_NONE) {
            $value = $this->compress($value);
            $this->lastiobytes = strlen($value);
        }

        if ($this->redis->hSet($this->hash, $key, $value) === false) {
            return false;
        }
        if ($this->definition->get_ttl()) {
            // When TTL is enabled, we also store the key name in a list sorted by the current time.
            $this->redis->zAdd($this->hash . self::TTL_SUFFIX, [], self::get_time(), $key);
            // The return value to the zAdd function never indicates whether the operation succeeded
            // (it returns zero when there was no error if the item is already in the list) so we
            // ignore it.
        }
        return true;
    }

    /**
     * Set the values of many keys.
     *
     * @param array $keyvaluearray An array of key/value pairs. Each item in the array is an associative array
     *      with two keys, 'key' and 'value'.
     * @return int The number of key/value pairs successfuly set.
     */
    public function set_many(array $keyvaluearray) {
        $pairs = [];
        $usettl = false;
        if ($this->definition->get_ttl()) {
            $usettl = true;
            $ttlparams = [];
            $now = self::get_time();
        }

        $this->lastiobytes = 0;
        foreach ($keyvaluearray as $pair) {
            $key = $pair['key'];
            if ($this->compressor != self::COMPRESSOR_NONE) {
                $pairs[$key] = $this->compress($pair['value']);
                $this->lastiobytes += strlen($pairs[$key]);
            } else {
                $pairs[$key] = $pair['value'];
            }
            if ($usettl) {
                // When TTL is enabled, we also store the key names in a list sorted by the current
                // time.
                $ttlparams[] = $now;
                $ttlparams[] = $key;
            }
        }
        if ($usettl && count($ttlparams) > 0) {
            // Store all the key values with current time.
            $this->redis->zAdd($this->hash . self::TTL_SUFFIX, [], ...$ttlparams);
            // The return value to the zAdd function never indicates whether the operation succeeded
            // (it returns zero when there was no error if the item is already in the list) so we
            // ignore it.
        }
        if ($this->redis->hMSet($this->hash, $pairs)) {
            return count($pairs);
        }
        return 0;
    }

    /**
     * Delete the given key.
     *
     * @param string $key The key to delete.
     * @return bool True if the delete operation succeeds, false otherwise.
     */
    public function delete($key) {
        $ok = true;
        if (!$this->redis->hDel($this->hash, $key)) {
            $ok = false;
        }
        if ($this->definition->get_ttl()) {
            // When TTL is enabled, also remove the key from the TTL list.
            $this->redis->zRem($this->hash . self::TTL_SUFFIX, $key);
        }
        return $ok;
    }

    /**
     * Delete many keys.
     *
     * @param array $keys The keys to delete.
     * @return int The number of keys successfully deleted.
     */
    public function delete_many(array $keys) {
        // If there are no keys to delete, do nothing.
        if (!$keys) {
            return 0;
        }
        $count = $this->redis->hDel($this->hash, ...$keys);
        if ($this->definition->get_ttl()) {
            // When TTL is enabled, also remove the keys from the TTL list.
            $this->redis->zRem($this->hash . self::TTL_SUFFIX, ...$keys);
        }
        return $count;
    }

    /**
     * Purges all keys from the store.
     *
     * @return bool
     */
    public function purge() {
        if ($this->definition->get_ttl()) {
            // Purge the TTL list as well.
            $this->redis->del($this->hash . self::TTL_SUFFIX);
            // According to documentation, there is no error return for the 'del' command (it
            // only returns the number of keys deleted, which could be 0 or 1 in this case) so we
            // do not need to check the return value.
        }
        return ($this->redis->del($this->hash) !== false);
    }

    /**
     * Cleans up after an instance of the store.
     */
    public function instance_deleted() {
        $this->redis->close();
        unset($this->redis);
    }

    /**
     * Determines if the store has a given key.
     *
     * @see key_aware_cache_interface
     * @param string $key The key to check for.
     * @return bool True if the key exists, false if it does not.
     */
    public function has($key) {
        return !empty($this->redis->hExists($this->hash, $key));
    }

    /**
     * Determines if the store has any of the keys in a list.
     *
     * @see key_aware_cache_interface
     * @param array $keys The keys to check for.
     * @return bool True if any of the keys are found, false none of the keys are found.
     */
    public function has_any(array $keys) {
        foreach ($keys as $key) {
            if ($this->has($key)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Determines if the store has all of the keys in a list.
     *
     * @see key_aware_cache_interface
     * @param array $keys The keys to check for.
     * @return bool True if all of the keys are found, false otherwise.
     */
    public function has_all(array $keys) {
        foreach ($keys as $key) {
            if (!$this->has($key)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Tries to acquire a lock with a given name.
     *
     * @see lockable_cache_interface
     * @param string $key Name of the lock to acquire.
     * @param string $ownerid Information to identify owner of lock if acquired.
     * @return bool True if the lock was acquired, false if it was not.
     */
    public function acquire_lock($key, $ownerid) {
        $timelimit = $this->clock->time() + $this->lockwait;
        $startlocktime = $this->clock->time();

        do {
            // Lock already exists, wait 1 second then retry.
            $haslock = $this->redis->set($key, $ownerid, ['nx', 'ex' => $this->locktimeout]);
            if (!$haslock) {
                if ($this->clock->time() < $startlocktime + 5) {
                    // We want a random delay to stagger the polling load. Ideally, this delay should be a fraction
                    // of the average response time. If it is too small we will poll too much and if it is too
                    // large we will waste time waiting for no reason. 100ms is the default starting point.
                    $delay = rand(100, 110);
                } else {
                    // If we don't get a lock within 5 seconds then there must be a very long-lived process holding the lock
                    // so throttle back to just polling roughly once a second.
                    $delay = rand(1000, 1100);
                }

                usleep($delay * 1000);
                continue;
            }

            // If we haven't got it already, better register a shutdown function.
            if ($this->currentlocks === null) {
                core_shutdown_manager::register_function([$this, 'shutdown_release_locks']);
                $this->currentlocks = [];
            }

            $this->currentlocks[$key] = $ownerid;

            return true;
        } while ($this->clock->time() < $timelimit);

        return false;
    }

    /**
     * Releases any locks when the system shuts down, in case there is a crash or somebody forgets
     * to use 'try-finally'.
     *
     * Do not call this function manually (except from unit test).
     */
    public function shutdown_release_locks() {
        foreach ($this->currentlocks as $key => $ownerid) {
            debugging('Automatically releasing Redis cache lock: ' . $key . ' (' . $ownerid .
                    ') - did somebody forget to call release_lock()?', DEBUG_DEVELOPER);
            $this->release_lock($key, $ownerid);
        }
    }

    /**
     * Checks a lock with a given name and owner information.
     *
     * @see lockable_cache_interface
     * @param string $key Name of the lock to check.
     * @param string $ownerid Owner information to check existing lock against.
     * @return mixed True if the lock exists and the owner information matches, null if the lock does not
     *      exist, and false otherwise.
     */
    public function check_lock_state($key, $ownerid) {
        $result = $this->redis->get($key);
        if ($result === (string)$ownerid) {
            return true;
        }
        if ($result === false) {
            return null;
        }
        return false;
    }

    /**
     * Finds all of the keys being used by this cache store instance.
     *
     * @return array of all keys in the hash as a numbered array.
     */
    public function find_all() {
        return $this->redis->hKeys($this->hash);
    }

    /**
     * Finds all of the keys whose keys start with the given prefix.
     *
     * @param string $prefix
     *
     * @return array List of keys that match this prefix.
     */
    public function find_by_prefix($prefix) {
        $return = [];
        foreach ($this->find_all() as $key) {
            if (strpos($key, $prefix) === 0) {
                $return[] = $key;
            }
        }
        return $return;
    }

    /**
     * Releases a given lock if the owner information matches.
     *
     * @see lockable_cache_interface
     * @param string $key Name of the lock to release.
     * @param string $ownerid Owner information to use.
     * @return bool True if the lock is released, false if it is not.
     */
    public function release_lock($key, $ownerid) {
        if ($this->check_lock_state($key, $ownerid)) {
            unset($this->currentlocks[$key]);
            return ($this->redis->del($key) !== false);
        }
        return false;
    }

    /**
     * Runs TTL expiry process for this cache.
     *
     * This is not part of the standard cache API and is intended for use by the scheduled task
     * \cachestore_redis\ttl.
     *
     * @return array Various keys with information about how the expiry went
     */
    public function expire_ttl(): array {
        $ttl = $this->definition->get_ttl();
        if (!$ttl) {
            throw new \coding_exception('Cache definition ' . $this->definition->get_id() . ' does not use TTL');
        }
        $limit = self::get_time() - $ttl;
        $count = 0;
        $batches = 0;
        $timebefore = microtime(true);
        $memorybefore = $this->store_total_size();
        do {
            $keys = $this->redis->zRangeByScore($this->hash . self::TTL_SUFFIX, 0, $limit,
                    ['limit' => [0, self::TTL_EXPIRE_BATCH]]);
            $this->delete_many($keys);
            $count += count($keys);
            $batches++;
        } while (count($keys) === self::TTL_EXPIRE_BATCH);
        $memoryafter = $this->store_total_size();
        $timeafter = microtime(true);

        $result = ['keys' => $count, 'batches' => $batches, 'time' => $timeafter - $timebefore];
        if ($memorybefore !== null) {
            $result['memory'] = $memorybefore - $memoryafter;
        }
        return $result;
    }

    /**
     * Gets the current time for TTL functionality. This wrapper makes it easier to unit-test
     * the TTL behaviour.
     *
     * @return int Current time
     */
    protected static function get_time(): int {
        global $CFG;
        if (PHPUNIT_TEST && !empty($CFG->phpunit_cachestore_redis_time)) {
            return $CFG->phpunit_cachestore_redis_time;
        }
        return time();
    }

    /**
     * Sets the current time (within unit test) for TTL functionality.
     *
     * This setting is stored in $CFG so will be automatically reset if you use resetAfterTest.
     *
     * @param int $time Current time (set 0 to start using real time).
     */
    public static function set_phpunit_time(int $time = 0): void {
        global $CFG;
        if (!PHPUNIT_TEST) {
            throw new \coding_exception('Function only available during unit test');
        }
        if ($time) {
            $CFG->phpunit_cachestore_redis_time = $time;
        } else {
            unset($CFG->phpunit_cachestore_redis_time);
        }
    }

    /**
     * Estimates the stored size, taking into account whether compression is turned on.
     *
     * @param mixed $key Key name
     * @param mixed $value Value
     * @return int Approximate stored size
     */
    public function estimate_stored_size($key, $value): int {
        if ($this->compressor == self::COMPRESSOR_NONE) {
            // If uncompressed, use default estimate.
            return parent::estimate_stored_size($key, $value);
        } else {
            // If compressed, compress value.
            return strlen($this->serialize($key)) + strlen($this->compress($value));
        }
    }

    /**
     * Gets Redis reported memory usage.
     *
     * @return int|null Memory used by Redis or null if we don't know
     */
    public function store_total_size(): ?int {
        try {
            $details = $this->redis->info('MEMORY');
        } catch (RedisException $e) {
            return null;
        }
        if (empty($details['used_memory'])) {
            return null;
        } else {
            return (int)$details['used_memory'];
        }
    }

    /**
     * Creates a configuration array from given 'add instance' form data.
     *
     * @see configurable_cache_interface
     *
     * @param stdClass $data
     * @return array
     */
    public static function config_get_configuration_array($data) {
        return array(
            'server' => $data->server,
            'prefix' => $data->prefix,
            'password' => $data->password,
            'serializer' => $data->serializer,
            'compressor' => $data->compressor,
            'connectiontimeout' => $data->connectiontimeout,
            'encryption' => $data->encryption,
            'cafile' => $data->cafile,
            'clustermode' => $data->clustermode,
        );
    }

    /**
     * Sets form data from a configuration array.
     *
     * @see configurable_cache_interface
     * @param moodleform $editform
     * @param array $config
     */
    public static function config_set_edit_form_data(moodleform $editform, array $config) {
        $data = array();
        $data['server'] = $config['server'];
        $data['prefix'] = !empty($config['prefix']) ? $config['prefix'] : '';
        $data['password'] = !empty($config['password']) ? $config['password'] : '';
        if (!empty($config['serializer'])) {
            $data['serializer'] = $config['serializer'];
        }
        if (!empty($config['compressor'])) {
            $data['compressor'] = $config['compressor'];
        }
        if (!empty($config['connectiontimeout'])) {
            $data['connectiontimeout'] = $config['connectiontimeout'];
        }
        if (!empty($config['encryption'])) {
            $data['encryption'] = $config['encryption'];
        }
        if (!empty($config['cafile'])) {
            $data['cafile'] = $config['cafile'];
        }
        if (!empty($config['clustermode'])) {
            $data['clustermode'] = $config['clustermode'];
        }
        $editform->set_data($data);
    }


    /**
     * Creates an instance of the store for testing.
     *
     * @param definition $definition
     * @return mixed An instance of the store, or false if an instance cannot be created.
     */
    public static function initialise_test_instance(definition $definition) {
        if (!self::are_requirements_met()) {
            return false;
        }
        $config = get_config('cachestore_redis');
        if (empty($config->test_server)) {
            return false;
        }
        $configuration = array('server' => $config->test_server);
        if (!empty($config->test_serializer)) {
            $configuration['serializer'] = $config->test_serializer;
        }
        if (!empty($config->test_password)) {
            $configuration['password'] = $config->test_password;
        }
        if (!empty($config->test_encryption)) {
            $configuration['encryption'] = $config->test_encryption;
        }
        if (!empty($config->test_cafile)) {
            $configuration['cafile'] = $config->test_cafile;
        }
        if (!empty($config->test_clustermode)) {
            $configuration['clustermode'] = $config->test_clustermode;
        }
        // Make it possible to test TTL performance by hacking a copy of the cache definition.
        if (!empty($config->test_ttl)) {
            $definition = clone $definition;
            $property = (new ReflectionClass($definition))->getProperty('ttl');
            $property->setValue($definition, 999);
        }
        $cache = new cachestore_redis('Redis test', $configuration);
        $cache->initialise($definition);

        return $cache;
    }

    /**
     * Return configuration to use when unit testing.
     *
     * @return array
     */
    public static function unit_test_configuration() {
        global $DB;

        if (!self::are_requirements_met() || !self::ready_to_be_used_for_testing()) {
            throw new moodle_exception('TEST_CACHESTORE_REDIS_TESTSERVERS not configured, unable to create test configuration');
        }

        return ['server' => TEST_CACHESTORE_REDIS_TESTSERVERS,
                'prefix' => $DB->get_prefix(),
                'encryption' => defined('TEST_CACHESTORE_REDIS_ENCRYPT') && TEST_CACHESTORE_REDIS_ENCRYPT,
        ];
    }

    /**
     * Returns true if this cache store instance is both suitable for testing, and ready for testing.
     *
     * When TEST_CACHESTORE_REDIS_TESTSERVERS is set, then we are ready to be use d for testing.
     *
     * @return bool
     */
    public static function ready_to_be_used_for_testing() {
        return defined('TEST_CACHESTORE_REDIS_TESTSERVERS');
    }

    /**
     * Gets an array of options to use as the serialiser.
     * @return array
     */
    public static function config_get_serializer_options() {
        $options = array(
            Redis::SERIALIZER_PHP => get_string('serializer_php', 'cachestore_redis')
        );

        if (defined('Redis::SERIALIZER_IGBINARY')) {
            $options[Redis::SERIALIZER_IGBINARY] = get_string('serializer_igbinary', 'cachestore_redis');
        }
        return $options;
    }

    /**
     * Gets an array of options to use as the compressor.
     *
     * @return array
     */
    public static function config_get_compressor_options() {
        $arr = [
            self::COMPRESSOR_NONE     => get_string('compressor_none', 'cachestore_redis'),
            self::COMPRESSOR_PHP_GZIP => get_string('compressor_php_gzip', 'cachestore_redis'),
        ];

        // Check if the Zstandard PHP extension is installed.
        if (extension_loaded('zstd')) {
            $arr[self::COMPRESSOR_PHP_ZSTD] = get_string('compressor_php_zstd', 'cachestore_redis');
        }

        return $arr;
    }

    /**
     * Compress the given value, serializing it first.
     *
     * @param mixed $value
     * @return string
     */
    private function compress($value) {
        $value = $this->serialize($value);

        switch ($this->compressor) {
            case self::COMPRESSOR_NONE:
                return $value;

            case self::COMPRESSOR_PHP_GZIP:
                return gzencode($value);

            case self::COMPRESSOR_PHP_ZSTD:
                return zstd_compress($value);

            default:
                debugging("Invalid compressor: {$this->compressor}");
                return $value;
        }
    }

    /**
     * Uncompresses (deflates) the data, unserialising it afterwards.
     *
     * @param string $value
     * @return mixed
     */
    private function uncompress($value) {
        if ($value === false) {
            return false;
        }

        switch ($this->compressor) {
            case self::COMPRESSOR_NONE:
                break;
            case self::COMPRESSOR_PHP_GZIP:
                $value = gzdecode($value);
                break;
            case self::COMPRESSOR_PHP_ZSTD:
                $value = zstd_uncompress($value);
                break;
            default:
                debugging("Invalid compressor: {$this->compressor}");
        }

        return $this->unserialize($value);
    }

    /**
     * Serializes the data according to the configured serializer.
     *
     * @param mixed $value
     * @return string
     */
    private function serialize($value) {
        switch ($this->serializer) {
            case Redis::SERIALIZER_NONE:
                return $value;
            case Redis::SERIALIZER_PHP:
                return serialize($value);
            case defined('Redis::SERIALIZER_IGBINARY') && Redis::SERIALIZER_IGBINARY:
                return igbinary_serialize($value);
            default:
                debugging("Invalid serializer: {$this->serializer}");
                return $value;
        }
    }

    /**
     * Unserializes the data according to the configured serializer
     *
     * @param string $value
     * @return mixed
     */
    private function unserialize($value) {
        switch ($this->serializer) {
            case Redis::SERIALIZER_NONE:
                return $value;
            case Redis::SERIALIZER_PHP:
                return unserialize($value);
            case defined('Redis::SERIALIZER_IGBINARY') && Redis::SERIALIZER_IGBINARY:
                return igbinary_unserialize($value);
            default:
                debugging("Invalid serializer: {$this->serializer}");
                return $value;
        }
    }
}
