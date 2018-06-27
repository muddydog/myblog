<?php
/**
 * See help: php {__DIR__}/example.php help
 * Run by: php {__DIR__}/example.php xiaoming
 */
require_once dirname(__FILE__).'/main.php';

class ExampleMaintenance extends Maintenance
{
    public function __construct() {
        parent::__construct();
        $this->addDescription( 'This is an example script.' );
        $this->addOption( 'name', 'the name of someone' , true, true);
    }
    
    public function execute() {
        $name = trim( $this->getOption( 'name', '' ) );
        echo 'hello '.$name.'!';
    }
}

$maintClass = 'ExampleMaintenance';
require_once DO_MAINTENANCE;
