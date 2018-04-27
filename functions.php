<?php
/**
 * Copyright (c) 2018. AIT
 */

namespace Other\PmBundle;

/**
 * Dumps information about a variable into your console output.
 *
 * @param mixed $expression The variable you want to export.
 * @param mixed $_          [optional]
 */
function console_log($expression, $_ = null)
{
    ob_start();
    var_dump(...func_get_args());
    file_put_contents('php://stderr', ob_get_clean() . PHP_EOL, FILE_APPEND);
}

/**
 * Checks that PCNTL is actually enabled in this installation.
 *
 * @return bool
 */
function pcntl_installed()
{
    return function_exists('pcntl_signal');
}

/**
 * Checks that all required pcntl functions are available, so not fatal errors would be cause in runtime
 *
 * @return bool
 */
function pcntl_enabled()
{
    $requiredFunctions = ['pcntl_signal', 'pcntl_signal_dispatch', 'pcntl_waitpid'];
    $disabledFunctions = explode(',', (string) ini_get('disable_functions'));
    $disabledFunctions = array_map(function ($item) {
        return trim($item);
    }, $disabledFunctions);

    foreach ($requiredFunctions as $function) {
        if (in_array($function, $disabledFunctions)) {
            return false;
        }
    }

    return true;
}
