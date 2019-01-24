<?php

class MessageQueueTest_DataObject extends DataObject implements TestOnly
{
    private static $db = array(
        "prop1" => "Varchar",
        "prop2" => "Int",
        "result" => "Varchar"
    );

    public function doDataObjectMethod($p1 = null)
    {
        $this->result = $this->prop1 . $this->prop2 . $p1;
        $this->write();
    }
}
