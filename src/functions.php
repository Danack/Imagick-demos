<?php

declare(strict_types = 1);


function purgeExceptionMessage(\Throwable $exception)
{
    $rawMessage = $exception->getMessage();

    $purgeAfterPhrases = [
        'with params'
    ];

    $message = $rawMessage;

    foreach ($purgeAfterPhrases as $purgeAfterPhrase) {
        $matchPosition = strpos($message, $purgeAfterPhrase);
        if ($matchPosition !== false) {
            $message = substr($message, 0, $matchPosition + strlen($purgeAfterPhrase));
            $message .= '**PURGED**';
        }
    }

    return $message;
}

function getTextForException(\Throwable $exception)
{
    $currentException = $exception;
    $text = '';

    do {
        $text .= sprintf(
            "Exception type:\n  %s\n\nMessage:\n  %s \n\nStack trace:\n%s\n",
            get_class($currentException),
            purgeExceptionMessage($currentException),
            formatLinesWithCount(getExceptionStackAsArray($currentException))
        );

        $currentException = $currentException->getPrevious();
    } while ($currentException !== null);

    return $text;
}

/**
 * Format an array of strings to have a count at the start
 * e.g. $lines = ['foo', 'bar'], output is:
 *
 * #0 foo
 * #1 bar
 */
function formatLinesWithCount(array $lines): string
{
    $output = '';
    $count = 0;

    foreach ($lines as $line) {
        $output .= '  #' . $count . ' '. $line . "\n";
        $count += 1;
    }

    return $output;
}


/**
 * @param Throwable $exception
 * @return string[]
 */
function getExceptionStackAsArray(\Throwable $exception)
{
    $lines = [];
    foreach ($exception->getTrace() as $trace) {
        $lines[] = formatTraceLine($trace);
    }

    return $lines;
}


function formatTraceLine(array $trace)
{

    $location = '??';
    $function = 'unknown';

    if (isset($trace["file"]) && isset($trace["line"])) {
        $location = $trace["file"]. ':' . $trace["line"];
    }
    else if (isset($trace["file"])) {
        $location = $trace["file"] . ':??';
    }
//    else {
//        var_dump($trace);
//        exit(0);
//    }

    $baseDir = realpath(__DIR__ . '/../');
    if ($baseDir === false) {
        throw new \Exception("Couldn't find parent directory from " . __DIR__);
    }

    $location = str_replace($baseDir, '', $location);

    if (isset($trace["class"]) && isset($trace["type"]) && isset($trace["function"])) {
        $function = $trace["class"] . $trace["type"] . $trace["function"];
    }
    else if (isset($trace["class"]) && isset($trace["function"])) {
        $function = $trace["class"] . '_' . $trace["function"];
    }
    else if (isset($trace["function"])) {
        $function = $trace["function"];
    }
    else {
        $function = "Function is weird: " . json_encode(var_export($trace, true));
    }

    return sprintf(
        "%s %s",
        $location,
        $function
    );
}


/**
 * Self-contained monitoring system for system signals
 * returns true if a 'graceful exit' like signal is received.
 *
 * We don't listen for SIGKILL as that needs to be an immediate exit,
 * which PHP already provides.
 * @return bool
 */
function checkSignalsForExit()
{
    static $initialised = false;
    static $needToExit = false;
    static $fnSignalHandler = null;

    if ($initialised === false) {
        $fnSignalHandler = function ($signalNumber) use (&$needToExit) {
            $needToExit = true;
        };
        pcntl_signal(SIGINT, $fnSignalHandler, false);
        pcntl_signal(SIGQUIT, $fnSignalHandler, false);
        pcntl_signal(SIGTERM, $fnSignalHandler, false);
        pcntl_signal(SIGHUP, $fnSignalHandler, false);
        pcntl_signal(SIGUSR1, $fnSignalHandler, false);
        $initialised = true;
    }

    pcntl_signal_dispatch();

    return $needToExit;
}


/**
 * Repeatedly calls a callable until it's time to stop
 *
 * @param callable $callable - the thing to run
 * @param int $secondsBetweenRuns - the minimum time between runs
 * @param int $sleepTime - the time to sleep between runs
 * @param int $maxRunTime - the max time to run for, before returning
 */
function continuallyExecuteCallable($callable, int $secondsBetweenRuns, int $sleepTime, int $maxRunTime)
{
    $startTime = microtime(true);
    $lastRuntime = 0;
    $finished = false;

    echo "starting continuallyExecuteCallable \n";
    while ($finished === false) {
        $shouldRunThisLoop = false;
        if ($secondsBetweenRuns === 0) {
            $shouldRunThisLoop = true;
        }
        else if ((microtime(true) - $lastRuntime) > $secondsBetweenRuns) {
            $shouldRunThisLoop = true;
        }

        if ($shouldRunThisLoop === true) {
            $callable();
            $lastRuntime = microtime(true);
        }

        if (checkSignalsForExit()) {
            break;
        }

        if ($sleepTime > 0) {
            sleep($sleepTime);
        }

        if ((microtime(true) - $startTime) > $maxRunTime) {
            echo "Reach maxRunTime - finished = true\n";
            $finished = true;
        }
    }

    echo "Finishing continuallyExecuteCallable\n";
}


function saneErrorHandler($errorNumber, $errorMessage, $errorFile, $errorLine): bool
{
    if (error_reporting() === 0) {
        // Error reporting has been silenced
        if ($errorNumber !== E_USER_DEPRECATED) {
            // Check it isn't this value, as this is used by twig, with error suppression. :-/
            return true;
        }
    }
    if ($errorNumber === E_DEPRECATED) {
        return false;
    }
    if ($errorNumber === E_CORE_ERROR || $errorNumber === E_ERROR) {
        // For these two types, PHP is shutting down anyway. Return false
        // to allow shutdown to continue
        return false;
    }
    $message = "Error: [$errorNumber] $errorMessage in file $errorFile on line $errorLine.";
    throw new \Exception($message);
}

/**
 * Decode JSON with actual error detection
 */
function json_decode_safe(?string $json)
{
    if ($json === null) {
        throw new \ImagickDemo\Exception\JsonException("Error decoding JSON: cannot decode null.");
    }

    $data = json_decode($json, true);

    if (json_last_error() === JSON_ERROR_NONE) {
        return $data;
    }

    $parser = new \Seld\JsonLint\JsonParser();
    $parsingException = $parser->lint($json);

    if ($parsingException !== null) {
        throw $parsingException;
    }

    if ($data === null) {
        throw new \ImagickDemo\Exception\JsonException("Error decoding JSON: null returned.");
    }

    throw new \ImagickDemo\Exception\JsonException("Error decoding JSON: " . json_last_error_msg());
}


/**
 * @param mixed $data
 * @param int $options
 * @return string
 * @throws Exception
 */
function json_encode_safe($data, $options = 0): string
{
    $result = json_encode($data, $options);

    if ($result === false) {
        throw new \ImagickDemo\Exception\JsonException("Failed to encode data as json: " . json_last_error_msg());
    }

    return $result;
}


function getExceptionText(\Throwable $exception): string
{
    $text = "";
    do {
        $text .= get_class($exception) . ":" . $exception->getMessage() . "\n\n";
        $text .= $exception->getTraceAsString();

        $exception = $exception->getPrevious();
    } while ($exception !== null);

    return $text;
}


function getExceptionInfoAsArray(\Throwable $exception)
{
    $data = [
        'status' => 'error',
        'message' => $exception->getMessage(),
    ];

    $previousExceptions = [];

    do {
        $exceptionInfo = [
            'type' => get_class($exception),
            'message' => $exception->getMessage(),
            'trace' => getExceptionStackAsArray($exception),
        ];

        $previousExceptions[] = $exceptionInfo;
    } while (($exception = $exception->getPrevious()) !== null);

    $data['details'] = $previousExceptions;

    return $data;
}


function peak_memory($real_usage = false)
{
    return number_format(memory_get_peak_usage($real_usage));
}


/**
 * @param $value
 *
 * @return array{string, null}|array{null, mixed}
 */
function convertToValue($value)
{
    if (is_scalar($value) === true) {
        return [
            null,
            $value
        ];
    }
    if ($value === null) {
        return [
            null,
            null
        ];
    }

    $callable = [$value, 'toArray'];
    if (is_object($value) === true && is_callable($callable)) {
        return [
            null,
            $callable()
        ];
    }
    if (is_object($value) === true) {
        if ($value instanceof \DateTime) {
            // Format as Atom time with microseconds
            return [
                null,
                $value->format("Y-m-d\TH:i:s.uP")
            ];
        }
    }

    if (is_array($value) === true) {
        $values = [];
        foreach ($value as $key => $entry) {
            $values[$key] = convertToValue($entry);
        }

        return [
            null,
            $values
        ];
    }

    if (is_object($value) === true) {
        return [
            sprintf(
                "Unsupported type [%s] of class [%s] for toArray.",
                gettype($value),
                get_class($value)
            ),
            null
        ];
    }

    return [
        sprintf(
            "Unsupported type [%s] for toArray.",
            gettype($value)
        ),
        null
    ];
}