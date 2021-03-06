<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * @package    local_queue
 * @copyright  2017 onwards Catalyst IT {@link http://www.catalyst-eu.net/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Ionut Marchis <ionut.marchis@catalyst-eu.net>
 */

namespace local_queue;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot.'/local/queue/lib.php');

class QueueManager {
    /**
     * Queue system enabled.
     */
    private $enabled;

    /**
     * Maximum number of workers allowed.
     */
    private $maxworkers;
    /**
     * Time to wait in between cycles.
     */
    private $wait;
    /**
     * Execution - number of queue items handled.
     */
    private $executed = 0;
    /**
     * Internal - workers ~ open pipes.
     */
    private $workers = [];
    /**
     * Queue name to do work on.
     */
    private $queue;

    /**
     * @var \local_queue\interfaces\QueueService queue service objects.
     */
    private $queueservice;

    public function __construct($queue) {
        $this->queue = $queue;
    }

    /**
     * Load the plugin configuration for any changes.
     */
    public function load_configuration() {
        refresh_configuration();
        $defaults = local_queue_configuration();
        $this->maxworkers = $defaults['pipesnumber'];
        $this->wait = $defaults['waittime'];
        $this->queueservice = $defaults['mainqueueservice'];
        $this->enabled = $defaults['enabled'];
    }

    /**
     * Get the results of this worker execution and update the associated queue item accordingly.
     * @param \local_queue\interfaces\QueueWorker $worker
     * @param string $color color string of the mechanics output.
     */
    public function results(\local_queue\interfaces\QueueWorker $worker, $color) {
        $result = [];
        $result['pid'] = $worker->pid;
        $result['hash'] = $worker->hash;
        $report = $worker->get_report();
        $result['item'] = $worker->get_item();
        $worker->finish();
        unset($worker);
        $result['action'] = $report['action'];
        $result['outputfile'] = $report['output'];
        $result['errorfile'] = $report['error'];
        $result['failed'] = $report['failed'];
        unset($report);
        QueueLogger::systemlog(" ---> [".$result['pid']."] Worker '".$result['hash']."' Start", $color);
        QueueLogger::systemlog(' ---> ITEM: '.$result['item']->id, $color);
        QueueLogger::systemlog(' ---> PAYLOAD: '.$result['item']->payload, $color);
        QueueLogger::systemlog(' ---> ACTION: '.$result['action'], $color);
        $outputsize = filesize($result['outputfile']);
        if ($outputsize > 0) {
            QueueLogger::systemlog(' ---> OUTPUT: ', $color);
            QueueLogger::read($result['outputfile'], $result['hash']);
        }
        if ($result['failed'] && filesize($result['errorfile']) > 0) {
            QueueLogger::systemlog(" ---> ERROR: ", $color);
            QueueLogger::read($result['errorfile'], $result['hash']);
        }
        QueueLogger::systemlog(" ---> [".$result['pid']."] Worker '".$result['hash']."' End", $color);
        $this->executed++;
        QueueLogger::systemlog(" ... Executed: $this->executed", QueueLogger::BLUE);
        unset($color);
        $service = $this->queueservice;
        $service::{$result['action']}($result['item']);
        unset($service);
        unset($result);
        gc_collect_cycles();
        gc_disable();
    }

    /**
     * Loads the plugin configuration and pauses (for performance reasons) in betweet cycles or before checking the schedule.
     */
    public function work() {
        $color = QueueLogger::YELLOW;
        QueueLogger::systemlog(" ... Work started ... ", $color);
        QueueLogger::systemlog(" ... Loading configuration ... ", $color);
        $this->load_configuration();
        if ($this->enabled && !$this->queueing()) {
            QueueLogger::systemlog(" ... Queue cycle ended ... ", $color);
        } else if (!$this->enabled) {
            QueueLogger::systemlog(" ... The Queue is not enabled ... ", $color);
            QueueLogger::systemlog(' ... Pausing work for '.($this->wait / 1000000).' second(s) ... ', $color);
            usleep($this->wait);
        } else {
            QueueLogger::systemlog(' ... Pausing work for '.($this->wait / 1000000).' second(s) ... ', $color);
            usleep($this->wait);
        }
        QueueLogger::systemlog(' ... Resuming work... ', $color);
        unset($color);
        gc_collect_cycles();
        gc_disable();
        $this->schedule();
    }

    /**
     * Consumes items from the queue and routes the next step (foreground or background work) based upon the workload status.
     */
    public function schedule() {
        if ($this->used() > 0) {
            $this->process();
        }
        $color = QueueLogger::PURPLE;
        QueueLogger::systemlog(' ... Checking schedule ... ', $color);
        QueueLogger::systemlog(' ... Total Workers: '.$this->maxworkers.' ... ', $color);
        QueueLogger::systemlog(' ... Busy Workers: '.$this->used().' ... ', $color);
        $slots = $this->maxworkers - $this->used();
        QueueLogger::systemlog(" ... Free Workers: $slots ... ", $color);
        unset($color);
        gc_collect_cycles();
        gc_disable();
        $maintenance = !$this->enabled || CLI_MAINTENANCE || moodle_needs_upgrading();
        if (!$maintenance && $slots > 0) {
            $service = $this->queueservice;
            $items = $service::consume($slots, $this->queue);
            unset($service);
            unset($slots);
            foreach ($items as $item) {
                $this->new_worker($item);
                unset($item);
            }
            unset($items);
        }
        gc_collect_cycles();
        gc_disable();
    }

    /**
     * Handle the current backgrounded workers if they're finished, or skip.
     */
    public function process() {
        $color = QueueLogger::GREEN;
        QueueLogger::systemlog(' ... Processing started ... ', $color);
        foreach ($this->workers as $worker) {
            $pid = $worker->pid;
            if ($worker->running()) {
                QueueLogger::systemlog(" --- [$pid] Worker '$worker->hash' still running --- ", $color);
                continue;
            } else {
                unset($this->workers[$worker->hash]);
                $this->results($worker, $color);
                unset($worker);
            }
            unset($pid);
        }
        QueueLogger::systemlog(' ... Processing ended ... ', $color);
        unset($color);
        gc_collect_cycles();
        gc_disable();
    }

    /**
     * Hard Break - terminate currently running workers signal, stop and report.
     * @param string $error The signal specific error message.
     * @param int $signal The received signal code.
     */
    public function hard_break($error, $signal) {
        $color = QueueLogger::LRED;
        $active = $this->used();
        $prefix = '["'. $this->queue.'" queue manager]';
        QueueLogger::log($prefix. ' Hard break started.', PHP_EOL, date("Y-m-d H:i:s"));
        QueueLogger::log($prefix. ' Active Workers found: '. $active, PHP_EOL, date("Y-m-d H:i:s"));
        $start = microtime(1);
        $killed = $finished = $stopped = 0;
        foreach ($this->workers as $worker) {
            $pid = $worker->pid;
            if ($worker->running()) {
                posix_kill($pid, $signal);
                usleep(10000);
                if ($worker->running()) {
                    $worker->trigger_error($error);
                    posix_kill($pid, SIGKILL);
                    $killed++;
                } else {
                    $stopped++;
                }
            } else {
                $finished++;
            }
            unset($this->workers[$worker->hash]);
            $this->results($worker, $color);
            unset($worker);
            unset($pid);
        }
        $difftime = @microtime_diff($start, microtime());
        QueueLogger::log($prefix. ' Hard break execution took '. $difftime. ' seconds.', PHP_EOL, date("Y-m-d H:i:s"));
        $status = $finished. ' finished / '. $stopped. ' stopped / '. $killed. ' killed. (total '. $active. ')';
        QueueLogger::log($prefix. ' Workers Exit Status: '. $status, PHP_EOL, date("Y-m-d H:i:s"));
        QueueLogger::log($prefix. ' Hard break ended. ', PHP_EOL, date("Y-m-d H:i:s"));
        unset($start);
        unset($color);
        unset($prefix);
        unset($killed);
        unset($active);
        unset($stopped);
        unset($finished);
        unset($difftime);
        unset($status);
        gc_collect_cycles();
        gc_disable();
    }

    /**
     * Check if there are any running workers.
     */
    public function queueing() {
        return $this->used() > 0;
    }

    /**
     * Get the total number of running workers.
     */
    public function used() {
        return count($this->workers);
    }

    /**
     * Creates and starts a new worker for the queue item and adds it to the foreground.
     * @param \stdClass $item - queue item object.
     */
    public function new_worker($item) {
        $workerclass = $item->worker;
        if (class_exists($workerclass)) {
            $worker = new $workerclass();
            unset($workerclass);
            $worker->set_item($item);
            unset($item);
            $worker->begin();
            $this->workers[$worker->hash] = $worker;
            unset($worker);
        }
    }

    /**
     * Signals received dispatcher - Handle the kill or other special signals.
     * @param int $signal The received signal code.
     */
    public function dispatch($signal) {
        $kills = [
            SIGHUP => 'SIGHUP',
            SIGINT => 'SIGINT',
            SIGILL => 'SIGILL',
            SIGABRT => 'SIGABRT',
            SIGTERM => 'SIGTERM',
            SIGQUIT => 'SIGQUIT',
            SIGALRM => 'SIGALRM',
            SIGTSTP => 'SIGTSTP'
        ];
        $special = [
            SIGUSR1 => 'SIGUSR1',
            SIGUSR2 => 'SIGUSR2'
        ];
        QueueLogger::log('');
        $prefix = '["'. $this->queue.'" queue manager]';
        if (isset($kills[$signal])) {
            $message = "$prefix TERMINATION signal '$kills[$signal]' received ... Hard break triggered.";
            QueueLogger::log($message, PHP_EOL, date("Y-m-d H:i:s"));
            $this->hard_break($message, $signal);
            exit;
        } else if (isset($special[$signal])) {
            $message = "$prefix SPECIAL signal '$special[$signal]' received ... Unhandled ... ";
            QueueLogger::log($message, PHP_EOL, date("Y-m-d H:i:s"));
        } else {
            $message = "$prefix Caught OTHER signal '$signal' ... Ignoring it ... ";
            QueueLogger::log($message, PHP_EOL, date("Y-m-d H:i:s"));
        }
    }
}