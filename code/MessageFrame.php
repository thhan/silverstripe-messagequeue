<?php
/**
 * Message frame is what is passed to/from the message queue implementation classes.
 * The header is interpreted by the implementation class. The body is the message
 * itself.
 */
class MessageFrame extends ViewableData
{
    public $header = null;
    public $body = null;

    /**
     * Name of queue that message was received from.
     * @var String queue
     */
    public $queue = null;

    public function __construct($body = null, $header = null, $queue = null)
    {
        parent::__construct();
        $this->body = $body;
        if ($header && !is_array($header)) {
            throw new Exception("Message frame expects header to be an array");
        }
        $this->header = $header;
        $this->queue = $queue;
    }
}
