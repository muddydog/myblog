<?php
if (php_sapi_name() != 'cli') {
    echo 'deny';
    exit(1);
}

if ( !Maintenance::shouldExecute() ) {
    return;
}

if ( !$maintClass || !class_exists( $maintClass ) ) {
    echo "\$maintClass is not set or is set to a non-existent class.\n";
    exit( 1 );
}

// Get an object to start us off
/** @var Maintenance $maintenance */
$maintenance = new $maintClass();

// Basic sanity checks and such
$maintenance->setup();

// load config file.
$maintenance->loadConfigFile();

// We used to call this variable $self, but it was moved
// to $maintenance->mSelf. Keep that here for b/c
$self = $maintenance->getName();

// require our constants predefined
require_once "$IP/src/script/defines.php";

// Load autoloaders.
require_once "$IP/src/script/auto_load.php";
// ...require other auto loader file.

$maintenance->finalSetup();

// Do the work
$maintenance->execute();

// Potentially debug globals
$maintenance->globals();
