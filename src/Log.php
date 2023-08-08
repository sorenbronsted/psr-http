<?php

namespace bronsted;

use Psr\Log\LoggerInterface;
use Throwable;

class Log
{
    private static LoggerInterface $instance;

    public static function setInstance(LoggerInterface $instance)
    {
        self::$instance = $instance;
    }

    public static function __callStatic(string $name, array $args)
    {
        if ($args[0] instanceof Throwable) {
            $th = $args[0];
            self::$instance->$name('{message} {code} {file}:{line}', [
                'message' => $th->getMessage(),
                'code' => $th->getCode(),
                'file' => $th->getFile(),
                'line' => $th->getLine()
            ]);
            foreach ($th->getTrace() as $trace) {
                $trace = (object)$trace;
                self::$instance->$name('{function} {file}:{line}', [
                    'function' => $trace->function,
                    'file' => ($trace->file ?? ''),
                    'line' => ($trace->line ?? '')
                ]);
            }
        } else {
            self::$instance->$name($args[0], $args[1] ?? []);
        }
    }
}
