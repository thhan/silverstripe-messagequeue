<?php
/**
 * A simple controller that can be used to consume messages.
 */
class MessageQueue_Process extends Controller
{
    public function index()
    {
        $req = $this->getRequest()->requestVars();
        $queue = ($req && isset($req["queue"])) ? $req["queue"] : null;
        $limit = ($req && isset($req["limit"])) ? $req["limit"] : null;

        // Work out what processes need to be run. Tries 'actions' and 'action', which are synonyms.
        if ($req && isset($req["actions"])) {
            $actions = $req["actions"];
        } elseif ($req && isset($req["action"])) {
            $actions = $req["action"];
        } else {
            $actions = "all";
        }

        $retrigger = ($req && isset($req["retrigger"])) ? $req["retrigger"] : "";

        $actions = explode(",", $actions);
        $flush = false;
        $consume = false;
        foreach ($actions as $a) {
            if ($a == "flush" || $a == "all") {
                $flush = true;
            }
            if ($a == "consume" || $a == "all") {
                $consume = true;
            }
        }

        if ($flush) {
            MessageQueue::flush($queue);
        }

        if ($consume) {
            $count = MessageQueue::consume($queue, $limit ? array("limit" => $limit) : null);
            if (!$count) {
                return $this->httpError(404, 'No messages');
            }
        }

        if ($retrigger == "yes") {
            // @todo This assumes the queue is simpleDBMQ. Not performant on long queue.
            // @todo Generalise counting.
            $count = DB::query("select count(*) from \"SimpleDBMQ\" where \"QueueName\"='{$queue}'")->value();
            if ($count > 0) {
                MessageQueue::consume_in_subprocess($queue);
            }
        }

        return 'True';
    }
}
