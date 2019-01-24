<?php
/**
 * Controller class for remote systems to call to receive messages. Expects to be called via POST
 */
class SimpleInterSSMQ_Accept extends Controller
{
    /**
     * Determines if this controller is accessible. Turned off by default. To allow a connections it needs to be
     * explicitly enabled. If disabled, any access to this control results in a 404.
     */
    public static $enabled = false;

    public static function setEnabled($enabled)
    {
        self::$enabled = $enabled;
    }

    public function index()
    {
        if (!self::$enabled) {
            return $this->httpError(404, "There is nothing here");
        }

        $request = $this->getRequest();
        if (!$request->isPOST()) {
            return $this->badRequest();
        }

        // @todo Security checks before we blindly accept a message
        // @todo includes a configuration test if http and/or https are accepted.

        // grab the message data
        $raw = $request->getBody();

        try {
            $inst = new SimpleInterSSMQ();
            $inst->processRawMessage($raw);

            $this->getResponse()->setStatusCode(200);
            $this->getResponse()->addHeader('Content-Type', "text/plain");
            return "ok";
        } catch (Exception $e) {
            throw $e;
        }
    }

    protected function permissionFailure()
    {
        // return a 401
        $this->getResponse()->setStatusCode(401);
        $this->getResponse()->addHeader('WWW-Authenticate', 'Basic realm="API Access"');
        return "You don't have access to this item through the API.";
    }

    protected function badRequest()
    {
        // return a 404
        $this->getResponse()->setStatusCode(400);
        return "Bad request";
    }
}
