<?php
/**
 * An interface that can be applied if a message object is capable of being
 * executed.
 */
interface MessageExecutable
{
    /**
     * Execute the method. No result is returned. This should throw an
     * exception if there are problems, rather than use user_error which
     * cannot be caught (and bypasses error handling in the message engine).
     *
     * @param MessageFrame		The message frame, which provides access to the
     *							headers.
     * @param Map $config		The interface configuration that applied.
     * @return void
     */
    public function execute(&$msgFrame, &$config);
}
