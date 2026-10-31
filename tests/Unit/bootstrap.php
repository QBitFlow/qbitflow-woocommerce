<?php

// The plugin's files refuse to run outside WordPress unless ABSPATH is defined.
define('ABSPATH', sys_get_temp_dir() . '/');

// The unit suite needs no WordPress: the plugin's pure classes and the bundled (prefixed) SDK.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/vendor-prefixed/autoload.php';
