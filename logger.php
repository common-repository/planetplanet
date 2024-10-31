<?php

namespace ReneSeindal\PlanetPlanet;

class BaseLogger {
    protected $debug = FALSE;
    protected $silent = FALSE;

    function __construct($debug = false, $silent = false) {
        $this->debug = $debug;
        $this->silent = $silent;
    }

    function set_debug($debug) {
        $this->debug = $debug;
    }
    function is_debug() {
        return $this->debug;
    }

    function set_silent($silent) {
        $this->silent = $silent;
    }
    function is_silent() {
        return $this->silent;
    }

    // Override for output
    function output($msg) {
    }

    protected function format_string($fmt, $args, $prefix) {
        return ($prefix ? $prefix  : '') . vsprintf(rtrim($fmt), $args?$args:[]);
    }

    protected function format_object($object, $args) {
        $prefix = '';
        if ($args && $args[0])
            $prefix = $args[0] . ' = ';

        return $prefix . print_r($object, TRUE);
    }

    protected function format($fmt, $args, $prefix = NULL) {
        if (is_string($fmt))
            return $this->format_string($fmt, $args, $prefix);
        else
            return $this->format_object($fmt, $args);
    }

    // Debug output
    function debug($fmt, ... $args) {
        if ($this->debug)
            $this->output($this->format($fmt, $args, 'DEBUG '));
    }

    // Progress messages
    function message($fmt, ... $args) {
        if ($this->debug or !$this->silent)
            $this->output($this->format($fmt, $args));
    }

    // Errors and important messages
    function error($fmt, ... $args) {
        $this->output($this->format($fmt, $args, 'ERROR: '));
    }
}

class CLILogger extends BaseLogger {
    function output($msg) {
        error_log( $msg );
    }
}

class FileLogger extends BaseLogger {
    protected $file;

    function __construct($file, $debug = false, $silent = false) {
        parent::__construct($debug, $silent);
        $this->file = $file;
    }

    function output($msg) {
        file_put_contents($this->file, $msg . PHP_EOL, FILE_APPEND);
    }
}

class MailLogger extends BaseLogger {
    protected $email;
    protected $subject;
    protected $msgs;

    function __construct($email, $subject = __CLASS__, $debug = false, $silent = false) {
        parent::__construct($debug, $silent);
        $this->email = $email;
        $this->subject = $subject;
        $this->msgs = [];
    }

    function __destruct() {
        if ($this->msgs) {
            wp_mail($this->email, $this->subject, join(PHP_EOL, $this->msgs));
        };
    }

    function output($msg) {
        $this->msgs[] = $msg;
    }
}


class PolyLogger extends BaseLogger {
    protected $loggers;

    function __construct($debug = false, $silent = false) {
        parent::__construct($debug, $silent);
        $this->loggers = [];
    }

    function add($logger) {
        if (is_a($logger, __NAMESPACE__ . '\BaseLogger'))
            $this->loggers[] = $logger;
    }

    private function dispatch($method, $fmt, $args) {
        array_unshift($args, $fmt);
        foreach ($this->loggers as $logger)
            call_user_func_array([$logger, $method], $args);
    }

    // Debug output
    function debug($fmt, ... $args) {
        $this->dispatch('debug', $fmt, $args);
    }

    // Progress messages
    function message($fmt, ... $args) {
        $this->dispatch('message', $fmt, $args);
   }

    // Errors and important messages
    function error($fmt, ... $args) {
        $this->dispatch('error', $fmt, $args);
    }
}
