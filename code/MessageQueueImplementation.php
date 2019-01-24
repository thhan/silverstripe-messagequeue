<?php
/**
 * Interface to classes that provide an implementation for sending and receiving
 * messages.
 */
interface MessageQueueImplementation
{
    /**
     * Send a message on a queue.
     * @param String $queue		Queue name, as interpreted by the the MQ implementor.
     * @param <type> $msgframe	The message frame containing body and header. The header
     *							is subject to interpretation by the queue implementor.
     *							The message body should already be in an encoded
     *							form acceptable to the MQ implementation. For
     *							Stomp, this is a string, so generally the message
     *							should be encoded in some string-based format.
     *							Specific implementor classes may not require this, however.
     * @param <type> $interfaceConfig	The interface configuration for the queue.
     */
    public function send($queue, $msgframe, $interfaceConfig);

    /**
     * Receive one or more messages from a queue.
     * Notes:
     *   - the implementor class is responsible for ensuring that message retrieval
     *	   is atomic, and specifically that if the MessageQueue::consume() is
     *	   called simultaneously by multiple processes, each message is only
     *     processed once.
     * @param String $queue
     * @param Map $interfaceConfig	The interface configuration for the queue.
     * @param Map $options
     * @return DataObjectSet		Returns a set of MessageFrame objects. The headers are MQ implementation
     *								dependent. The body is still in its encoded form.
     */
    public function receive($queue, $interfaceConfig, $options);
}
