<?php

class SimpleInterSSMQ implements MessageQueueImplementation
{
    // Constructor needed for singleton()
    public function __construct()
    {
    }

    /**
     * Sends a message. Encodes the message frame and sends it using CURL.
     * @throws Exception
     * @param  $queue
     * @param  $msgframe
     * @param  $interfaceConfig
     * @return void
     */
    public function send($queue, $msgframe, $interfaceConfig)
    {
        $this->init($interfaceConfig);

        $url = $this->remoteURL;

        $ch = curl_init($url);

        $raw = $this->encode($queue, $msgframe, $interfaceConfig);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $raw);

        if (isset($config["basicAuth"])) {
            $ba = $config["basicAuth"];
            if (!isset($ba["username"]) || !isset($ba["password"])) {
                throw new Exception("SimpleInterSSMQ basic auth is set, but missing username or password");
            }
            curl_setopt($ch, CURLOPT_USERPWD, $ba["username"] . ":" . $ba["password"]);
        }

        $headers  =  array( "Content-type: text/plain" );
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $output = curl_exec($ch);

        curl_close($ch);

        if ($output != "ok") {
            throw new Exception("Failed to deliver to remote server, " . $output);
        }
    }

    protected $conf = null;
    protected $remoteServer = null;

    public function encode($queue, $msgframe, $interfaceConfig)
    {
        return serialize(array("queue" => $queue, "msgframe" => $msgframe));
    }

    /**
     * Convert raw message into an array with "queue" and "msgframe" properties.
     * @param  $raw
     * @return void
     */
    public function decode($raw)
    {
        return unserialize($raw);
    }

    /**
     * Set up for interacting with Stomp, icnluding creating the connection. Configuration
     * info is taken from the interface configuration.
     * @param <type> $config
     * @return void
     */
    protected function init($config)
    {
        if ($this->conf) {
            return;
        }
        if (!isset($config["implementation_options"])) {
            throw new Exception("SimpleInterSSMQ requires implmenentation options");
        }
        $this->conf = $config["implementation_options"];

        if (!isset($this->conf["remoteServer"])) {
            throw new Exception("SimpleInterSSMQ requires a remoteServer");
        }
        $this->remoteURL = $this->conf["remoteServer"];
    }

    /**
     * @TODO: This really needs to use transactions to ensure that only one reader will get each message. Might need
     *	to implement a lock or something for MySQL MyISAM :-(
     * @param String $queue
     * @param <type> $interfaceConfig
     * @param <type> $options
     * @return <type>
     */
    public function receive($queue, $interfaceConfig, $options)
    {
        return new ArrayList();
/*		$result = new DataObjectSet();
        $limit = ($options && isset($options["limit"])) ? $options["limit"] : null;

        $conn = DB::getConn();

        // OK, start a transaction, or if we are in MySQL, create a lock on the SimpleDBMQ table.
        if ($conn instanceof MySQLDatabase) $res = $conn->query('lock table SimpleDBMQ write');
        else if (method_exists($conn, 'startTransaction')) $conn->startTransaction();

        try {
            $msgs = DataObject::get("SimpleDBMQ", $queue ? ('"QueueName"=\'' . $queue . '\'') : "", null, null, $limit ? array("limit" => $limit, "start" => 0) : null);
            if (!$msgs) return $result;

            foreach ($msgs as $do) {
                $result->push(new MessageFrame($do->Message, unserialize($do->Header), $do->QueueName));
                $do->delete();
                $do->flushCache();
            }

            // Commit transaction, or in MySQL just release the lock
            if ($conn instanceof MySQLDatabase) $res = $conn->query('unlock tables');
            else if (method_exists($conn, 'endTransaction')) $conn->endTransaction();
        }
        catch (Exception $e) {
            // Rollback, or in MySQL just release the lock
            if ($conn instanceof MySQLDatabase) $res = $conn->query('unlock tables');
            else if (method_exists($conn, 'transactionRollback')) $conn->transactionRollback();

            throw $e;
        }

        return $result;
        */
    }

    /**
     * Given a message received by a remote system, unencode the message and deliver it.
     * @param  $queue
     * @param  $message
     * @return void
     */
    public function processRawMessage($raw)
    {
        $cooked = $this->decode($raw);
        $queue = $cooked["queue"];
        $msgframe = $cooked["msgframe"];
        $conf = MessageQueue::get_queue_config($queue);

        MessageQueue::decode_message($msgframe, $conf);
        MessageQueue::deliver_message($msgframe, $conf);
    }
}
