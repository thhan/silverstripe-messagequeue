<?php

class MessageQueueTest extends SapphireTest
{
    protected static $fixture_file = 'messagequeue/tests/MessageQueueTest.yml';
    protected $extraDataObjects = array(
        'MessageQueueTest_DataObject'
    );

    /**
     * @TODO
     * *** Test rule matching:
     *   - test multiple interfaces
     *   - test lists of queue names in an interface
     *   - test patterned queue names in an interface
     *   - test passing over a patterned rule for another
     * *** Test message sending:
     *   - test sending plain message over non SQL interface
     *   - test different encodings
     *   - test for send error
     * *** Test message receipt:
     *   - test drop in rule execution
     *   - test delivery via MethodInvocationMessage and non-SQL interface
     *   - test delivery of non-MethodInvocationMessage
     *   - test send and delivery of plain text message
     *   - test callback on delivery error
     */

    /**
     * Test MethodInvocationMessage class, independent from queueing. Test using
     * static method.
     */
    public function testMethodInvocationStatic()
    {
        // test the initial values
        $inv = new MethodInvocationMessage("MessageQueueTest", "doStaticMethod", "p1", 2);
        $frame = new MessageFrame();
        $frame->body = $inv;
        $conf = array();
        $inv->execute($frame, $conf);
        $this->assertTrue(self::$testP1 == "p1", "Static method test executed, P1 test");
        $this->assertTrue(self::$testP2 == 2, "Static method test executed, P2 test");
    }

    /**
     * Test MethodInvocationMessage class, independent from queueing. Test using
     * object method.
     */
    public function testMethodInvocationObject()
    {
        $inv = new MethodInvocationMessage(new MessageQueue_Object("p1"), "doNonDOMethod", "_suffix");
        $frame = new MessageFrame();
        $frame->body = $inv;
        $conf = array();
        $inv->execute($frame, $conf);
        $this->assertTrue(MessageQueue_Object::$testP1 == "p1_suffix", "Non-DO method invocation correctly set its static output");
    }

    /**
     * Test MethodInvocationMessage class, independent from queueing. Test using
     * data object method.
     */
    public function testMethodInvocationDataObject()
    {
        $obj = new MessageQueueTest_DataObject();
        $obj->prop1 = "p1";
        $obj->prop2 = 2;
        $obj->write();
        $id = $obj->ID;

        $inv = new MethodInvocationMessage($obj, "doDataObjectMethod", "_suffix");
        $frame = new MessageFrame();
        $frame->body = $inv;
        $conf = array();
        $inv->execute($frame, $conf);

        $obj2 = MessageQueueTest_DataObject::get()->byID($id);
        $this->assertTrue($obj2->result == "p12_suffix", "DO method invocation correctly set its static output");
    }

    private function getQueueSizeSimpleDB($queue)
    {
        $ds = SimpleDBMQ::get()->filter(array("QueueName"=>$queue));
        if (!$ds) {
            return 0;
        }
        return ($ds->Count());
    }

    /**
     * Test a message send using the default configuration (uses SimpleDBMQ, clears queue on
     * PHP shutdown. Note we need to disable force_immediate_delivery, otherwise the message
     * is not in the queue.
     */
    public function testMessageSendDefaultConfig()
    {
        $old = MessageQueue::get_force_immediate_delivery();
        MessageQueue::set_force_immediate_delivery(false);

        // set test-environment for message queue, store the current
        // testqueue message-queue to reset it after the test is done.
        $interfaces = MessageQueue::get_interfaces();
        MessageQueue::clear_all_interfaces();
        MessageQueue::add_interface("testqueue", array(
            "queues" => "testqueue",
            "implementation" => "SimpleDBMQ",
            "encoding" => "php_serialize",
            "send" => array(
                "onShutdown" => "none"
            ),
            "delivery" => array(
                "onerror" => array(
                    "log",
                    "requeue"
                )
            )
        ));

        $this->assertTrue($this->getQueueSizeSimpleDB("testqueue") == 0, "Queue is empty before we put anything in it");

        MessageQueue::send("testqueue", new MethodInvocationMessage("MessageQueueTest", "doStaticMethod", "p1", 2));

        // Check the message is queue in the database.
        $this->assertTrue($this->getQueueSizeSimpleDB("testqueue") == 1, "Queue has an item after we add to it");

        // reset testqueue to the original state
        MessageQueue::set_all_interfaces($interfaces);

        MessageQueue::set_force_immediate_delivery($old);
    }

    /**
     * Test use of the SimpleDB queue, and with explicitly received message.
     * This tests the manual receive, and that the sent message comes back to us
     * just the way it was sent.
     */
    public function testMessageSimpleDBExplicitReceive()
    {
        // save and disable force immediate, we are testing whether stuff gets queued.
        $old = MessageQueue::get_force_immediate_delivery();
        MessageQueue::set_force_immediate_delivery(false);

        MessageQueue::add_interface("default", array(
            "queues" => "/.*/",
            "implementation" => "SimpleDBMQ",
            "encoding" => "php_serialize",
            "send" => array(
                "onShutdown" => "none"
            ),
            "delivery" => array(
                "onerror" => array(
                    "log",
                    "requeue"
                )
            )
        ));

        $this->assertTrue($this->getQueueSizeSimpleDB("testqueue") == 0, "Queue is empty before we put anything in it");
        MessageQueue::send("testqueue", new MethodInvocationMessage("MessageQueueTest", "doStaticMethod", "p1", 2));


        // check message in queue
        $this->assertTrue($this->getQueueSizeSimpleDB("testqueue") == 1, "Queue has an item after we add to it");

        // get message
        $msgs = MessageQueue::get_messages("testqueue");
        $this->assertTrue($msgs != null, "Got a set");
        $this->assertTrue($msgs->Count() == 1, "Got one message");
        $msg = $msgs->First();
        $this->assertTrue($msg instanceof MessageFrame, "Message is a frame");
        $this->assertTrue($msg->body instanceof MethodInvocationMessage, "Got a method invocation message");
        $this->assertTrue($msg->body->objectOrClass == "MessageQueueTest" &&
                          $msg->body->method == "doStaticMethod", "Got the original message");

        MessageQueue::set_force_immediate_delivery($old);
    }

    /**
     * This tests a couple of error handling cases. We have 2 queues, testmainqueue
     * and testerrorqueue. We queue a message that will fail on delivery, and
     * another after it which will execute successfully. Error handling is
     * to requeue all errors on testerrorqueue. So we expect that mainqueue is
     * empty after consumption, with the first error going to the error queue,
     * and the second message deliverying successfully. (i.e. error doesn't block
     * messages behind it.
     */
    public function testRequeueOnError()
    {
        // Configure simple db queuing, two queues.
        MessageQueue::add_interface("default", array(
            "queues" => array("testmainqueue", "testerrorqueue"),
            "implementation" => "SimpleDBMQ",
            "encoding" => "php_serialize",
            "send" => array(
                "onShutdown" => "none"
            ),
            "delivery" => array(
                "onerror" => array(
                    "log",
                    "requeue" => "testerrorqueue"
                )
            )
        ));

        $this->assertTrue($this->getQueueSizeSimpleDB("testmainqueue") == 0, "Main queue is empty before we put anything in it");
        MessageQueue::send("testmainqueue", new MethodInvocationMessage("MessageQueueTest", "doStaticMethodWithError", "p1", 2));
        MessageQueue::send("testmainqueue", new MethodInvocationMessage("MessageQueueTest", "doStaticMethod", "p1", 2));
        $this->assertTrue($this->getQueueSizeSimpleDB("testmainqueue") == 2, "Main queue has two items after we add to it");
        $this->assertTrue($this->getQueueSizeSimpleDB("testerrorqueue") == 0, "Error queue is empty before we put anything in it");

        self::$testP1 = null;

        // clear the queue, causing the message to execute and fail
        MessageQueue::consume("testmainqueue");

        // Check there is nothing in testmainqueue, and now something in testerrorqueue
        $this->assertTrue($this->getQueueSizeSimpleDB("testmainqueue") == 0, "Main queue is cleared after consumption");
        $this->assertTrue($this->getQueueSizeSimpleDB("testerrorqueue") == 1, "Error queue magically has one message in it");
        $this->assertTrue(self::$testP1 == "p1", "Message with no error has been run");
    }

    /**
     * Static method that throws an exception on delivery if called in a method.
     */
    public static function doStaticMethodWithError()
    {
        // We pass an empty string so it doesn't show up in the TestRunner output
        throw new Exception("");
    }

    private static $testP1 = null;
    private static $testP2 = null;
    public static function doStaticMethod($p1 = null, $p2 = null)
    {
        //user_error("barf");
        self::$testP1 = $p1;
        self::$testP2 = $p2;
    }

    public function testDropOnError()
    {
        MessageQueue::add_interface("default", array(
            "queues" => array("testmainqueue"),
            "implementation" => "SimpleDBMQ",
            "encoding" => "php_serialize",
            "send" => array(
                "onShutdown" => "none"
            ),
            "delivery" => array(
                "onerror" => array(
                    "drop"
                )
            )
        ));

        $this->assertTrue($this->getQueueSizeSimpleDB("testmainqueue") == 0, "Main queue is empty before we put anything in it");
        MessageQueue::send("testmainqueue", new MethodInvocationMessage("MessageQueueTest", "doStaticMethodWithError", "p1", 2));

        $this->assertTrue($this->getQueueSizeSimpleDB("testmainqueue") == 1, "Main queue has an item after we add to it");

        // clear the queue, causing the message to execute and fail
        MessageQueue::consume("testmainqueue");

        // Check there is nothing in testmainqueue, and now something in testerrorqueue
        $this->assertTrue($this->getQueueSizeSimpleDB("testmainqueue") == 0, "Main queue is cleared after consumption");
    }

    /**
     * Test that when a message is delivered by callback, the message disappears
     * off the queue, and the message we get is as we expect.
     */
    public function testCallbackDelivery()
    {
        MessageQueue::add_interface("default", array(
            "queues" => array("testmainqueue"),
            "implementation" => "SimpleDBMQ",
            "encoding" => "php_serialize",
            "send" => array(
                "onShutdown" => "none"
            ),
            "delivery" => array(
                "callback" => array("MessageQueueTest", "messageCallback"),
                "onerror" => array(
                    "requeue"
                )
            )
        ));

        $this->assertTrue($this->getQueueSizeSimpleDB("testmainqueue") == 0, "Main queue is empty before we put anything in it");
        MessageQueue::send("testmainqueue", new MethodInvocationMessage("MessageQueueTest", "doStaticMethod", "p1", 2));
        $this->assertTrue($this->getQueueSizeSimpleDB("testmainqueue") == 1, "Main queue has an item after we add to it");

        self::$message_frame = null;

        // clear the queue, causing the callback to be executed, which will leave the message in self::$message_frame
        MessageQueue::consume("testmainqueue");

        // Check there is nothing in testmainqueue, and now something in testerrorqueue
        $this->assertTrue($this->getQueueSizeSimpleDB("testmainqueue") == 0, "Main queue is cleared after consumption");

        $this->assertTrue(self::$message_frame != null, "Message has been captured");
        $this->assertTrue(self::$message_frame->body != null, "Message has body");
        $this->assertTrue(self::$message_frame->body instanceof MethodInvocationMessage, "Message is the same type of object we sent");
    }

    /**
     * Our callback function simply stores the message frame it gets so
     * the test can examine it.
     */
    private static $message_frame = null;
    public static function messageCallback($msgframe, $conf)
    {
        self::$message_frame = $msgframe;
    }

    /**
     * Send a message that is already a frame. Ensure what we get back
     * is what we sent in the frame.
     */
    public function testFrameSend()
    {
        MessageQueue::add_interface("default", array(
            "queues" => array("testmainqueue"),
            "implementation" => "SimpleDBMQ",
            "encoding" => "raw", // no encoding
            "send" => array(
                "onShutdown" => "none"
            ),
            "delivery" => array(
                "callback" => array("MessageQueueTest", "messageCallback"),
                "onerror" => array(
                    "requeue"
                )
            )
        ));

        $testData = "Nigel mouse";
        $this->assertTrue($this->getQueueSizeSimpleDB("testmainqueue") == 0, "Main queue is empty before we put anything in it");
        $frame = new MessageFrame($testData);
        MessageQueue::send("testmainqueue", $frame);
        $this->assertTrue($this->getQueueSizeSimpleDB("testmainqueue") == 1, "Main queue has an item after we add to it");

        // Get messages, and make sure we get it back.
        $msgs = MessageQueue::get_messages("testmainqueue");
        $this->assertTrue($msgs != null, "Got a set");
        $this->assertTrue($msgs->Count() == 1, "Got one message");
        $msg = $msgs->First();
        $this->assertTrue($msg instanceof MessageFrame, "Message is a frame");
        $this->assertTrue($msg->body == $testData);
    }

    /**
     * Test consume_all_queues using SimpleDB. We use a counter static method
     * and count the number of times its called, for messages on multiple
     * calls.
     */
    public function testMultipleConsume()
    {
        MessageQueue::add_interface("default", array(
            "queues" => array("testmainqueue1", "testmainqueue2", "testmainqueue3"),
            "implementation" => "SimpleDBMQ",
            "encoding" => "php_serialize",
            "send" => array(
                "onShutdown" => "none"
            ),
            "delivery" => array(
                "onerror" => array(
                    "requeue"
                )
            )
        ));

        $this->assertTrue($this->getQueueSizeSimpleDB("testmainqueue1") == 0, "Queue 1 is empty before we put anything in it");
        $this->assertTrue($this->getQueueSizeSimpleDB("testmainqueue2") == 0, "Queue 2 is empty before we put anything in it");
        $this->assertTrue($this->getQueueSizeSimpleDB("testmainqueue3") == 0, "Queue 3 is empty before we put anything in it");
        self::$countedCalls = 0;

        MessageQueue::send("testmainqueue1", new MethodInvocationMessage("MessageQueueTest", "doCountCalls"));
        MessageQueue::send("testmainqueue2", new MethodInvocationMessage("MessageQueueTest", "doCountCalls"));
        MessageQueue::send("testmainqueue3", new MethodInvocationMessage("MessageQueueTest", "doCountCalls"));

        $this->assertTrue(MessageQueue::consume_all_queues("default") == 3, "Consumed messages off 3 queues");
        $this->assertTrue(self::$countedCalls == 3, "3 messages were delivered");
    }

    public function testInterSS()
    {
        $simq = new SimpleInterSSMQ();
        $frame = new MessageFrame();
        $frame->body = "hello";
        $conf = array("implementation_options" => array("remoteServer" => "http://localhost/test_receiver"));
        $dummy = $simq->encode("test", $frame, $conf);
    }

    public static $countedCalls = 0;

    public static function doCountCalls()
    {
        self::$countedCalls++;
    }

    public static $saved_interfaces = null;

    /**
     * Get the existing configuration
     * @return void
     */
    public function setUpOnce()
    {
        self::$saved_interfaces = MessageQueue::get_interfaces();

        // Clear all interface definitions. Individual tests will provide their own.
        foreach (self::$saved_interfaces as $name => $def) {
            MessageQueue::remove_interface($name);
        }

        parent::setUpOnce();
    }

    // At the start of each test, ensure the default configuration is in place. Many of the tests
    // expect this.
    public function setUp()
    {
        // clear whatever is there
        foreach (MessageQueue::get_interfaces() as $name => $def) {
            MessageQueue::remove_interface($name);
        }

        // add the default ones.
        foreach (self::$saved_interfaces as $name => $def) {
            MessageQueue::add_interface($name, $def);
        }

        parent::setUp();
    }

    /**
     * After executing the message queue tests, restore the original queue interfaces.
     * @return void
     */
    public function tearDownOnce()
    {
        // Remove any queue definitions that are set up by tests. Currently only 'default' is used.
        MessageQueue::remove_interface("default");

        // Restore each interface.
        foreach (self::$saved_interfaces as $name => $def) {
            MessageQueue::add_interface($name, $def);
        }

        parent::tearDownOnce();
    }
}
