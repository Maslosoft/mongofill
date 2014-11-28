<?php

use Mongofill\Protocol;
use Mongofill\Socket;

define('MONGOFILL_USE_APC', function_exists('apc_store'));

/**
 * A connection manager for PHP and MongoDB.
 */
class MongoClient
{
    const VERSION = '1.3.0-mongofill';
    const DEFAULT_HOST = 'localhost';
    const DEFAULT_PORT = 27017;
    const DEFAULT_DATABASE = 'admin';
    const RP_PRIMARY   = 'primary';
    const RP_PRIMARY_PREFERRED = 'primaryPreferred';
    const RP_SECONDARY = 'secondary';
    const RP_SECONDARY_PREFERRED = 'secondaryPreferred';
    const RP_NEAREST   = 'nearest';
    const RP_DEFAULT_ACCEPTABLE_LATENCY_MS = 15;

    const DEFAULT_CONNECT_TIMEOUT_MS = 60000;
    const REPL_SET_CACHE_LIFETIME = 15; // in seconds

    /**
     * @var boolean
     */
    public $connected = false;

    /**
     * @var string
     */
    public $boolean = false;

    /**
     * @var string
     */
    public $status;

    /**
     * @var boolean
     */
    public $persistent;

    /**
     * @var array
     */
    private $options;

    /**
     * @var string
     */
    private $username;

    /**
     * @var string
     */
    private $password;

    /**
     * @var string
     */
    private $database;

    /**
     * @var string
     */
    private $uri;

    /**
     * @var array<string>
     */
    private $hosts = [];

    /**
     * @var array<Protocol>
     */
    public $protocols = [];

    /**
     * @var array<Socket>
     */
    private $sockets = [];

    /**
     * @var array
     */
    private $databases = [];

    /**
     * @var string
     */
    private $replSet;

    /**
     * @var array
     */
    private $replSetStatus = [];

    /**
     * @var array
     */
    private $replSetConf = [];

    /**
     * @var array
     */
    private $readPreference = ['type' => self::RP_PRIMARY];

    /**
     * @var int
     */
    private $connectTimeoutMS;

    /**
     * Creates a new database connection object
     *
     * @param string $server - The server name. server should have the form:
     *      mongodb://[username:password@]host1[:port1][,host2[:port2:],...]/db
     * @param array $options - An array of options for the connection.
     * @throws MongoConnectionException
     */
    public function __construct($server = 'mongodb://localhost:27017', array $options = ['connect' => true])
    {
        if (!$server || strpos($server, 'mongodb://') != 0) {
            throw new MongoConnectionException('malformed uri: ' . $server);
        }

        $this->uri = $server;

        $uri = substr($server, 10);

        $serverPart = '';
        $nsPart = null;
        $optionsPart = '';

        $idx = strrpos($uri, '/');

        if ($idx === false) {
            if (strpos($uri, '?') !== false) {
                throw new MongoConnectionException('malformed uri: ' . $server);
            }

            $serverPart = $uri;
            $nsPart = null;
            $optionsPart = '';
        } else {
            $serverPart = substr($uri, 0, $idx);
            $nsPart = substr($uri, $idx + 1);

            $idx = strrpos($nsPart, '?');

            if ($idx !== false) {
                $optionsPart = substr($nsPart, $idx + 1);
                $nsPart = substr($nsPart, 0, $idx);
            } else {
                $optionsPart = '';
            }
        }

        // username,password,hosts
        $idx = strrpos($serverPart, '@');

        if ($idx !== false) {
            $authPart = substr($serverPart, 0, $idx);
            $serverPart = substr($serverPart, $idx + 1);

            $idx = strrpos($authPart, ':');

            if ($idx === false) {
                $this->username = urldecode($authPart);
                $this->password = '';
            } else {
                $this->username = urldecode(substr($authPart, 0, $idx));
                $this->password = urldecode(substr($authPart, $idx + 1));
            }
        }

        if (strlen($serverPart) === 0) {
            throw new MongoConnectionException('malformed uri: ' . $server);
        }

        foreach (explode(',', $serverPart) as $host_str) {
            if (strpos($host_str, ':') !== false) {
                list($host, $port) = explode(':', $host_str);
            } else {
                $host = $host_str;
                $port = self::DEFAULT_PORT;
            }
            if (preg_match('/\A[a-zA-Z0-9_.\-]+\z/', $host)) {
                $port = preg_match('/\A[0-9]+\z/', $port) ? $port : self::DEFAULT_PORT;
                $this->hosts["$host:$port"] = ['host' => $host, 'port' => $port];
            }
        }

        if ($nsPart != null && strlen($nsPart) != 0) {// database
            $this->database = $nsPart;
        }

        $uri_options = [];
        $split_options = preg_split('/[&;]+/', $optionsPart);

        foreach ($split_options as $part) {
            $idx = strrpos($part, '=');

            if ($idx !== false) {
                $key = substr($part, 0, $idx);
                $value = substr($part, $idx + 1);

                $uri_options[$key] = $value;
            }
        }

        if (!$options) {
            $options = ['connect' => true];
        }

        $this->options = array_replace($uri_options, $options);

        // handle legacy settings
        if (array_key_exists('timeout', $this->options) && !array_key_exists('connectTimeoutMS', $this->options)) {
            $this->options['connectTimeoutMS'] = $this->options['timeout'];
            unset($this->options['timeout']);
        }
        if (array_key_exists('wtimeout', $this->options) && !array_key_exists('wtimeoutms', $this->options)) {
            $this->options['wtimeoutms'] = $this->options['wtimeout'];
            unset($this->options['wtimeout']);
        }

        if (isset($this->options['connectTimeoutMS'])) {
            $this->connectTimeoutMS = $this->options['connectTimeoutMS'];
        } else {
            $this->connectTimeoutMS = self::DEFAULT_CONNECT_TIMEOUT_MS;
        }

        if (array_key_exists('username', $this->options)) {
            $this->username = $this->options['username'];
        }

        if (array_key_exists('password', $this->options)) {
            $this->password = $this->options['password'];
        }

        if (array_key_exists('db', $this->options)) {
            $this->database = $this->options['db'];
        }

        if ($this->database == null && $this->username != null) {
            $this->database = self::DEFAULT_DATABASE;
        }

        if (isset($this->options['replicaSet'])) {
            $this->replSet = $this->options['replicaSet'];
        }
        if (isset($this->options['readPreference'])) {
            $this->setReadPreference($this->options['readPreference'], $this->options['readPreferenceTags']);
        }

        if (!isset($this->options['connect']) || $this->options['connect'] === true) {
            $this->connect();
        }
    }

    /**
     * Gets a database
     *
     * @param string $dbname - The database name.
     *
     * @return MongoDB - Returns a new db object.
     */
    public function __get($dbname)
    {
        return $this->selectDB($dbname);
    }

    /**
     * Connects to a database server or replica set
     *
     * @return bool - If the connection was successful.
     * @throws MongoConnectionException
     */
    public function connect()
    {
        if ($this->protocols) {
            return true;
        }

        $latest_error = null;
        foreach ($this->hosts as $host_key => $host_info) {
            try {
                $this->connectToHost($host_info['host'], $host_info['port']);
            } catch (MongoConnectionException $e) {
                // We can tolerate connection failures as long as at least 1 succeeds
                $latest_error = $e;
                continue;
            }

            // We were able to connect, so update host status
            $this->hosts[$host_key]['health'] = 1; // assume healthy since we connected
            $this->hosts[$host_key]['state'] = 0; // Default to unknown

            if ($this->replSet) {
                $repl_set_info = $this->getReplSetInfo($host_key);

                // This will fail to get info if server is not using a replica set, but that's fine
                if ($repl_set_info['ok'] != 1 || !$repl_set_info['retval']['conf'] || !$repl_set_info['retval']['status']) {
                    // If we're trying to use a replica set, this is fatal
                    $msg = "Unable to get replica set config & status for host $host_key";
                    throw new MongoConnectionException($msg);
                } else {
                    $this->replSetConf = $repl_set_info['retval']['conf'];
                    $this->replSetStatus = $repl_set_info['retval']['status'];
                    foreach ($this->replSetStatus['members'] as $member) {
                        if ($member['stateStr'] === 'ARBITER') {
                            continue;
                        }
                        // If this member is the one we are already connected to, save the status
                        // information under the original host:port combo, which might be different
                        // than what is listed in the replica set config
                        if (isset($member['self']) && $member['self'] === true) {
                            $this->hosts[$host_key]['health'] = $member['health'];
                            $this->hosts[$host_key]['state'] = $member['state'];
                        }
                        list($host, $port) = explode(':', $member['name']);
                        $this->hosts[$member['name']] = [
                            'host' => $host,
                            'port' => $port,
                            'health' => $member['health'],
                            'state' => $member['state'],
                        ];
                    }
                }
            }

            break;
        }

        if (!$this->protocols) {
            $msg = "Could not connect to any of " . count($this->hosts) .
                " hosts. Latest error: " . ($latest_error ? $latest_error->getMessage() : '');
            throw new MongoConnectionException($msg);
        }

        $this->connected = true;

        return true;
    }

    /**
     * Establish a connection to specified host if not already connected to it
     * @param $host
     * @param $port
     */
    private function connectToHost($host, $port)
    {
        $host_key = "$host:$port";
        if (!isset($this->protocols[$host_key])) {
            if (!isset($this->sockets[$host_key])) {
                $this->sockets[$host_key] = new Socket($host, $port, $this->connectTimeoutMS);
                $this->sockets[$host_key]->connect();
            }
            $this->protocols[$host_key] = new Protocol($this->sockets[$host_key]);

            if ($this->database != null) {
                $db = $this->selectDB($this->database);

                if ($this->username != null) {
                    return $db->authenticate($this->username, $this->password, [
                        'protocol' => $this->protocols[$host_key],
                    ]);
                }
            }
        }
    }

    /**
     * Get an array with replica set config and status for a given host.
     * This will be cached in APC (if available) for up to REPL_SET_CACHE_LIFETIME
     * seconds, so servers are not spammed with getReplSetStatus calls, which appear
     * to really slow down a MongoDB server when they happen frequently.
     * @param $host_key
     * @return array
     */
    private function getReplSetInfo($host_key)
    {
        // Use one request to get both replica set config and status
        $cmd = [
            '$eval' => 'return {conf: rs.conf(), status: rs.status()};',
            'nolock' => true, // ensure this command does not globally lock the DB
        ];
        $cache_key = "mongofill:replSetInfo:$host_key";
        $result = MONGOFILL_USE_APC ? apc_fetch($cache_key) : false;
        if (!$result) {
            // We must use a raw opQuery here because MongoDB::command cannot be used
            // until the replica set info has been initialized
            $result = $this->protocols[$host_key]->opQuery(
                'local.$cmd',
                $cmd,
                0, -1, 0,
                MongoCursor::$timeout
            )['result'][0];
            if (MONGOFILL_USE_APC) {
                apc_store($cache_key, $result, self::REPL_SET_CACHE_LIFETIME);
            }
        }
        return $result;
    }

    /**
     * Closes this connection
     *
     * @param boolean|string $connection - If connection is not given, or
     *   FALSE then connection that would be selected for writes would be
     *   closed. In a single-node configuration, that is then the whole
     *   connection, but if you are connected to a replica set, close() will
     *   only close the connection to the primary server.
     *
     * @return bool - Returns if the connection was successfully closed.
     */
    public function close($connection = null)
    {
        foreach ($this->sockets as $socket) {
            $socket->disconnect();
        }
        $this->protocols = [];

        //TODO: implement $connection handling
    }

    public function _getWriteProtocol()
    {
        $this->connect();
        if (!$this->replSet) {
            return reset($this->protocols);
        }
        foreach ($this->replSetStatus['members'] as $member) {
            if ($member['stateStr'] === 'PRIMARY') {
                $host_key = $member['name'];
                list($host, $port) = explode(':', $host_key);
                $this->connectToHost($host, $port);
                return $this->protocols[$host_key];
            }
        }
        throw new MongoConnectionException("No PRIMARY found in replica set");
    }

    public function _getReadProtocol(array $readPreference)
    {
        $this->connect();
        if (!$this->replSet) {
            return reset($this->protocols);
        }

        // Statically cache which protocol is chosen for a given read preference (request association)
        $cache_key = json_encode($readPreference);
        static $cache = [];
        if (isset($cache[$cache_key])) {
            return $cache[$cache_key];
        }

        switch ($readPreference['type']) {
            case self::RP_PRIMARY:
                return $cache[$cache_key] = $this->_getWriteProtocol();

            case self::RP_PRIMARY_PREFERRED:
                try {
                    return $cache[$cache_key] = $this->_getWriteProtocol();
                } catch (MongoConnectionException $e) {
                    // Fall through to reading from secondary
                }

            case self::RP_SECONDARY:
                return $cache[$cache_key] = $this->getNearestHostProtocol(['SECONDARY'], $readPreference);

            case self::RP_SECONDARY_PREFERRED:
                try {
                    return $cache[$cache_key] = $this->getNearestHostProtocol(['SECONDARY'], $readPreference);
                } catch (MongoConnectionException $e) {
                    return $cache[$cache_key] = $this->_getWriteProtocol();
                }

            case self::RP_NEAREST:
                return $cache[$cache_key] = $this->getNearestHostProtocol(['PRIMARY', 'SECONDARY'], $readPreference);

            default:
                throw new Exception("Invalid read preference ({$readPreference['type']}");
        }
    }

    /**
     * Get a Protocol for the nearest candidate server matching the given types
     * and read preference. This implements Member Selection as described here:
     * http://docs.mongodb.org/manual/core/read-preference-mechanics/#replica-set-read-preference-behavior-member-selection
     *
     * @param array $allowedServerTypes
     * @param array $readPreference
     * @return Protocol
     * @throws MongoConnectionException
     */
    private function getNearestHostProtocol(array $allowedServerTypes, array $readPreference)
    {
        $candidates = [];
        $tagsets = isset($readPreference['tagsets']) ? $readPreference['tagsets'] : [[]];
        foreach ($tagsets as $tagset) {
            foreach ($this->replSetStatus['members'] as $key => $member) {
                $tags = $this->replSetConf['members'][$key]['tags'] ?: [];
                if (in_array($member['stateStr'], $allowedServerTypes) && array_intersect($tagset, $tags) === $tagset) {
                    $candidates[] = $member;
                }
            }
            if ($candidates) {
                break;
            }
        }
        if (!$candidates) {
            $msg = "No " . implode(' or ', $allowedServerTypes) . " servers available";
            if (isset($readPreference['tagsets'])) {
                $msg .= " matching tagsets " . json_encode($readPreference['tagsets']);
            }
            throw new MongoConnectionException($msg);
        }

        // Connect and ping all candidate servers
        $min_ping = INF;
        foreach ($candidates as $member) {
            $host_key = $member['name'];
            list($host, $port) = explode(':', $host_key);
            $this->connectToHost($host, $port);
            if (!isset($this->hosts[$host_key]['ping'])) {
                $this->pingHost($host, $port);
            }
            if ($this->hosts[$host_key]['ping'] < $min_ping) {
                $min_ping = $this->hosts[$host_key]['ping'];
            }
        }

        // Filter candidates to only those within the "nearest group" (default 15ms)
        $candidates = array_values(array_filter($candidates, function ($member) use ($min_ping) {
            $host_key = $member['name'];
            return $this->hosts[$host_key]['ping'] - $min_ping < self::RP_DEFAULT_ACCEPTABLE_LATENCY_MS;
        }));

        // Pick a random host from remaining candidates
        $the_chosen_one = $candidates[mt_rand(0, count($candidates) - 1)];
        return $this->protocols[$the_chosen_one['name']];
    }

    /**
     * @return string - The name of the database to authenticate
     */
    public function _getAuthenticationDatabase()
    {
        return $this->database;
    }

    /**
     * @return string - The username for authentication
     */
    public function _getAuthenticationUsername()
    {
        return $this->username;
    }

    /**
     * @return string - The password for authentication
     */
    public function _getAuthenticationPassword()
    {
        return $this->password;
    }

    /**
     * @param string $name - The option name.
     *
     * @return string - The option value
     */
    public function _getOption($name)
    {
        if (array_key_exists($name, $this->options)) {
            return $this->options[$name];
        }

        return null;
    }

    /**
     * Gets a database
     *
     * @param string $name - The database name.
     *
     * @return MongoDB - Returns a new database object.
     */
    public function selectDB($name)
    {
        if (!isset($this->databases[$name])) {
            $this->databases[$name] = new MongoDB($this, $name);
        }

        return $this->databases[$name];
    }

    /**
     * Gets a database collection
     *
     * @param string $db - The database name.
     * @param string $collection - The collection name.
     *
     * @return MongoCollection - Returns a new collection object.
     */
    public function selectCollection($db, $collection)
    {
        return $this->selectDB($db)->selectCollection($collection);
    }

    /**
     * Drops a database [deprecated]
     *
     * @param mixed $db - The database to drop. Can be a MongoDB object or
     *   the name of the database.
     *
     * @return array - Returns the database response.
     */
    public function dropDB($db)
    {
        throw new Exception('Not Implemented');
    }

    /**
     * Return info about all open connections
     *
     * @return array - An array of open connections.
     */
    public static function getConnections()
    {
        throw new Exception('Not Implemented');
    }

    /**
     * Updates status for all associated hosts
     *
     * @return array - Returns an array of information about the hosts in
     *   the set. Includes each host's hostname, its health (1 is healthy),
     *   its state (1 is primary, 2 is secondary, 0 is anything else), the
     *   amount of time it took to ping the server, and when the last ping
     *   occurred.
     * @throws Exception
     */
    public function getHosts()
    {
        $this->connect();
        // Ensure pings are up-to-date
        foreach ($this->hosts as $host) {
            $this->pingHost($host['host'], $host['port']);
        }
        return $this->hosts;
    }

    private function pingHost($host, $port)
    {
        $host_key = "$host:$port";
        $this->connectToHost($host, $port);
        $start_time = microtime(true);
        $this->protocols[$host_key]->opQuery(
            'admin.$cmd',
            ['ping' => 1],
            0, -1, 0,
            MongoCursor::$timeout
        );
        $this->hosts[$host_key]['ping'] = round((microtime(true) - $start_time) * 1000);
        $this->hosts[$host_key]['lastPing'] = time();
    }

    /**
     * Get the read preference for this connection
     *
     * @return array -
     */
    public function getReadPreference()
    {
        return $this->readPreference;
    }

    /**
     * Kills a specific cursor on the server
     *
     * @param string $serverHash - The server hash that has the cursor.
     *   This can be obtained through MongoCursor::info.
     * @param int|mongoint64 $id - The ID of the cursor to kill. You can
     *   either supply an int containing the 64 bit cursor ID, or an object
     *   of the MongoInt64 class. The latter is necessary on 32 bit platforms
     *   (and Windows).
     *
     * @return bool - Returns TRUE if the method attempted to kill a
     *   cursor, and FALSE if there was something wrong with the arguments
     *   (such as a wrong server_hash). The return status does not reflect
     *   where the cursor was actually killed as the server does not provide
     *   that information.
     */
    public function killCursor($serverHash, $id)
    {
        // since we currently support just single server connection,
        // the $serverHash arg is ignored

        if ($id instanceof MongoInt64) {
            $id = $id->value;
        } elseif (!is_numeric($id)) {
            return false;
        }

        $this->protocols[$serverHash]->opKillCursors([ (int)$id ], [], MongoCursor::$timeout);

        return true;
    }

    /**
     * Lists all of the databases available.
     *
     * @return array - Returns an associative array containing three
     *   fields. The first field is databases, which in turn contains an
     *   array. Each element of the array is an associative array
     *   corresponding to a database, giving th database's name, size, and if
     *   it's empty. The other two fields are totalSize (in bytes) and ok,
     *   which is 1 if this method ran successfully.
     */
    public function listDBs()
    {
        $cmd = [
            'listDatabases' => 1
        ];

        $result = $this->selectDB(self::DEFAULT_DATABASE)->command($cmd);

        return $result;
    }

    /**
     * Set the read preference for this connection
     *
     * @param string $readPreference -
     * @param array $tags -
     *
     * @return bool -
     */
    public function setReadPreference($readPreference, array $tags = null)
    {
        if ($new_preference = self::_validateReadPreference($readPreference, $tags)) {
            $this->readPreference = $new_preference;
        }
        return (bool)$new_preference;
    }

    public static function _validateReadPreference($readPreference, array $tags = null)
    {
        $new_preference = [];
        if (strcasecmp($readPreference, self::RP_PRIMARY) === 0) {
            if (!empty($tags)) {
                trigger_error("You can't use read preference tags with a read preference of PRIMARY", E_USER_WARNING);
                return false;
            }
            $new_preference['type'] = self::RP_PRIMARY;
        } elseif (strcasecmp($readPreference, self::RP_PRIMARY_PREFERRED) === 0) {
            $new_preference['type'] = self::RP_PRIMARY_PREFERRED;
        } elseif (strcasecmp($readPreference, self::RP_SECONDARY) === 0) {
            $new_preference['type'] = self::RP_SECONDARY;
        } elseif (strcasecmp($readPreference, self::RP_SECONDARY_PREFERRED) === 0) {
            $new_preference['type'] = self::RP_SECONDARY_PREFERRED;
        } elseif (strcasecmp($readPreference, self::RP_NEAREST) === 0) {
            $new_preference['type'] = self::RP_NEAREST;
        } else {
            trigger_error("The value '$readPreference' is not valid as read preference type", E_USER_WARNING);
            return false;
        }

        if ($tags) {
            // Also supports string format (dc:east,use:reporting), convert to arrays
            foreach ($tags as $i => $tagset) {
                if (is_string($tagset)) {
                    $array = [];
                    // Empty string can be used to allow no tag matching in the case where
                    // tagsets specified earlier in the array do not match any servers
                    $tagset = $tagset ? explode(',', $tagset) : [];
                    foreach ($tagset as $key_value_pair) {
                        list($key, $value) = explode(':', $key_value_pair);
                        $key = trim($key);
                        $value = trim($value);
                        if ($key === '' || $value === '') {
                            $msg = "Invalid tagset \"$key_value_pair\". Must contain non-empty key and value.";
                            trigger_error($msg, E_USER_WARNING);
                            return false;
                        }
                        $array[$key] = $value;
                    }
                    $tags[$i] = $array;
                }
            }
            $new_preference['tagsets'] = $tags;
        }

        return $new_preference;
    }


    /**
     * String representation of this connection
     *
     * @return string - Returns hostname and port for this connection.
     */
    public function __toString()
    {
        $first_host = reset($this->hosts);
        return $first_host['host'] . ':' . $first_host['port'];
    }
}
