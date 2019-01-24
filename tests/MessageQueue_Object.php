<?php

class MessageQueue_Object extends Object implements TestOnly
{
    public $prop1 = null;
    public static $testP1 = null;

    public function __construct($p1 = null)
    {
        $this->prop1 = $p1;
    }

    public function doNonDOMethod($p1 = null)
    {
        self::$testP1 = $this->prop1 . $p1;
    }
}
