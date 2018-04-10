<?php
/**
 * Copyright (c) 2018. AIT
 */

namespace PMB\PMBundle;

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
