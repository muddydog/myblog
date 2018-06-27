<?php
define( 'DO_MAINTENANCE', __DIR__.'/do.php' );

$maintClass = false;

abstract class Maintenance {
    // Const for getStdin()
    const STDIN_ALL = 'all';

    // This is the desired params
    protected $mParamsConfig = [];

    // Array of mapping short parameters to long ones
    protected $mShortParamsMap = [];

    // Array of desired args
    protected $mArgList = [];

    // This is the list of options that were actually passed
    protected $mOptions = [];

    // This is the list of arguments that were actually passed
    protected $mArgs = [];

    // Name of the script currently running
    protected $mSelf;

    // Special vars for params that are always used
    protected $mQuiet = false;

    // A description of the script, children should change this via addDescription()
    protected $mDescription = '';

    // Have we already loaded our user input?
    protected $mInputLoaded = false;

    /**
     * Batch size. If a script supports this, they should set
     * a default with setBatchSize()
     *
     * @var int
     */
    protected $mBatchSize = null;

    // Generic options added by addDefaultParams()
    private $mGenericParameters = [];
    // Generic options which might or not be supported by the script
    private $mDependantParameters = [];

    /** @var float UNIX timestamp */
    private $lastReplicationWait = 0.0;

    /**
     * Used when creating separate schema files.
     * @var resource
     */
    public $fileHandle;

    /**
     * Used to read the options in the order they were passed.
     * Useful for option chaining (Ex. dumpBackup.php). It will
     * be an empty array if the options are passed in through
     * loadParamsAndArgs( $self, $opts, $args ).
     *
     * This is an array of arrays where
     * 0 => the option and 1 => parameter value.
     *
     * @var array
     */
    public $orderedOptions = [];

    /**
     * settings.
     */
    public $settings;

    /**
     * Default constructor. Children should call this *first* if implementing
     * their own constructors
     */
    public function __construct() {
        global $IP;
        $IP = strval( getenv( 'ROOT_PATH' ) ) !== ''
            ? getenv( 'ROOT_PATH' )
            : realpath( __DIR__ . '/../../' );

        $this->addDefaultParams();
        register_shutdown_function( [ $this, 'outputChanneled' ], false );
    }

    /**
     * Should we execute the maintenance script, or just allow it to be included
     * as a standalone class? It checks that the call stack only includes this
     * function and "requires" (meaning was called from the file scope)
     *
     * @return bool
     */
    public static function shouldExecute() {
        global $inCommandLineMode;

        if ( !function_exists( 'debug_backtrace' ) ) {
            // If someone has a better idea...
            return $inCommandLineMode;
        }

        $bt = debug_backtrace();
        $count = count( $bt );
        if ( $count < 2 ) {
            return false; // sanity
        }
        if ( $bt[0]['class'] !== 'Maintenance' || $bt[0]['function'] !== 'shouldExecute' ) {
            return false; // last call should be to this function
        }
        $includeFuncs = [ 'require_once', 'require', 'include', 'include_once' ];
        for ( $i = 1; $i < $count; $i++ ) {
            if ( !in_array( $bt[$i]['function'], $includeFuncs ) ) {
                return false; // previous calls should all be "requires"
            }
        }

        return true;
    }

    /**
     * Do the actual work. All child classes will need to implement this
     */
    abstract public function execute();

    /**
     * Add a parameter to the script. Will be displayed on --help
     * with the associated description
     *
     * @param string $name The name of the param (help, version, etc)
     * @param string $description The description of the param to show on --help
     * @param bool $required Is the param required?
     * @param bool $withArg Is an argument required with this option?
     * @param string|bool $shortName Character to use as short name
     * @param bool $multiOccurrence Can this option be passed multiple times?
     */
    protected function addOption( $name, $description, $required = false,
        $withArg = false, $shortName = false, $multiOccurrence = false
    ) {
        $this->mParamsConfig[$name] = [
            'desc' => $description,
            'require' => $required,
            'withArg' => $withArg,
            'shortName' => $shortName,
            'multiOccurrence' => $multiOccurrence
        ];

        if ( $shortName !== false ) {
            $this->mShortParamsMap[$shortName] = $name;
        }
    }

    /**
     * Checks to see if a particular param exists.
     * @param string $name The name of the param
     * @return bool
     */
    protected function hasOption( $name ) {
        return isset( $this->mOptions[$name] );
    }

    /**
     * Get an option, or return the default.
     *
     * If the option was added to support multiple occurrences,
     * this will return an array.
     *
     * @param string $name The name of the param
     * @param mixed $default Anything you want, default null
     * @return mixed
     */
    protected function getOption( $name, $default = null ) {
        if ( $this->hasOption( $name ) ) {
            return $this->mOptions[$name];
        } else {
            // Set it so we don't have to provide the default again
            $this->mOptions[$name] = $default;

            return $this->mOptions[$name];
        }
    }

    /**
     * Add some args that are needed
     * @param string $arg Name of the arg, like 'start'
     * @param string $description Short description of the arg
     * @param bool $required Is this required?
     */
    protected function addArg( $arg, $description, $required = true ) {
        $this->mArgList[] = [
            'name' => $arg,
            'desc' => $description,
            'require' => $required
        ];
    }

    /**
     * Remove an option.  Useful for removing options that won't be used in your script.
     * @param string $name The option to remove.
     */
    protected function deleteOption( $name ) {
        unset( $this->mParamsConfig[$name] );
    }

    /**
     * Set the description text.
     * @param string $text The text of the description
     */
    protected function addDescription( $text ) {
        $this->mDescription = $text;
    }

    /**
     * Does a given argument exist?
     * @param int $argId The integer value (from zero) for the arg
     * @return bool
     */
    protected function hasArg( $argId = 0 ) {
        return isset( $this->mArgs[$argId] );
    }

    /**
     * Get an argument.
     * @param int $argId The integer value (from zero) for the arg
     * @param mixed $default The default if it doesn't exist
     * @return mixed
     */
    protected function getArg( $argId = 0, $default = null ) {
        return $this->hasArg( $argId ) ? $this->mArgs[$argId] : $default;
    }

    /**
     * Set the batch size.
     * @param int $s The number of operations to do in a batch
     */
    protected function setBatchSize( $s = 0 ) {
        $this->mBatchSize = $s;

        // If we support $mBatchSize, show the option.
        // Used to be in addDefaultParams, but in order for that to
        // work, subclasses would have to call this function in the constructor
        // before they called parent::__construct which is just weird
        // (and really wasn't done).
        if ( $this->mBatchSize ) {
            $this->addOption( 'batch-size', 'Run this many operations ' .
                'per batch, default: ' . $this->mBatchSize, false, true );
            if ( isset( $this->mParamsConfig['batch-size'] ) ) {
                // This seems a little ugly...
                $this->mDependantParameters['batch-size'] = $this->mParamsConfig['batch-size'];
            }
        }
    }

    /**
     * Get the script's name
     * @return string
     */
    public function getName() {
        return $this->mSelf;
    }

    /**
     * Return input from stdin.
     * @param int $len The number of bytes to read. If null, just return the handle.
     *   Maintenance::STDIN_ALL returns the full length
     * @return mixed
     */
    protected function getStdin( $len = null ) {
        if ( $len == self::STDIN_ALL ) {
            return file_get_contents( 'php://stdin' );
        }
        $f = fopen( 'php://stdin', 'rt' );
        if ( !$len ) {
            return $f;
        }
        $input = fgets( $f, $len );
        fclose( $f );

        return rtrim( $input );
    }

    /**
     * @return bool
     */
    public function isQuiet() {
        return $this->mQuiet;
    }

    /**
     * Throw some output to the user. Scripts can call this with no fears,
     * as we handle all --quiet stuff here
     * @param string $out The text to show to the user
     * @param mixed $channel Unique identifier for the channel. See function outputChanneled.
     */
    protected function output( $out, $channel = null ) {
        if ( $this->mQuiet ) {
            return;
        }
        if ( $channel === null ) {
            $this->cleanupChanneled();
            print $out;
        } else {
            $out = preg_replace( '/\n\z/', '', $out );
            $this->outputChanneled( $out, $channel );
        }
    }

    /**
     * Throw an error to the user. Doesn't respect --quiet, so don't use
     * this for non-error output
     * @param string $err The error to display
     * @param int $die If > 0, go ahead and die out using this int as the code
     */
    protected function error( $err, $die = 0 ) {
        $this->outputChanneled( false );
        exec('echo -e "\033[1;31m'.$err.'\033[0m"', $output);
        print implode(PHP_EOL, $output) . PHP_EOL;
        $die = intval( $die );
        if ( $die > 0 ) {
            die( $die );
        }
    }

    private $atLineStart = true;
    private $lastChannel = null;

    /**
     * Clean up channeled output.  Output a newline if necessary.
     */
    public function cleanupChanneled() {
        if ( !$this->atLineStart ) {
            print PHP_EOL;
            $this->atLineStart = true;
        }
    }

    /**
     * Message outputter with channeled message support. Messages on the
     * same channel are concatenated, but any intervening messages in another
     * channel start a new line.
     * @param string $msg The message without trailing newline
     * @param string $channel Channel identifier or null for no
     *     channel. Channel comparison uses ===.
     */
    public function outputChanneled( $msg, $channel = null ) {
        if ( $msg === false ) {
            $this->cleanupChanneled();

            return;
        }

        // End the current line if necessary
        if ( !$this->atLineStart && $channel !== $this->lastChannel ) {
            print PHP_EOL;
        }

        print $msg;

        $this->atLineStart = false;
        if ( $channel === null ) {
            // For unchanneled messages, output trailing newline immediately
            print PHP_EOL;
            $this->atLineStart = true;
        }
        $this->lastChannel = $channel;
    }

    /**
     * Add the default parameters to the scripts
     */
    protected function addDefaultParams() {
        # Generic (non script dependant) options:

        $this->addOption( 'help', 'Display this help message', false, false, 'h' );
        $this->addOption( 'quiet', 'Whether to supress non-error output', false, false, 'q' );
        $this->addOption( 'conf', 'Directory of server_conf.php, if not default', false, true );
        $this->addOption( 'globals', 'Output globals at the end of processing for debugging' );
        $this->addOption(
            'memory-limit',
            'Set a specific memory limit for the script, '
                . '"max" for no limit or "default" to avoid changing it'
        );

        # Save generic options to display them separately in help
        $this->mGenericParameters = $this->mParamsConfig;

        # Script dependant options:

        # Save additional script dependant options to display
        #  them separately in help
        $this->mDependantParameters = array_diff_key( $this->mParamsConfig, $this->mGenericParameters );
    }

    /**
     * get config var from config file.
     * @param string $key The variable name in config file. Use '/' to fetch var recursively.
     * @param mixed $default Default value to be return when the key is not exists.
     */
    public function getConfig($key, $default) {
        if (strpos($key, '/') !== false) {
            $slices = explode('/', trim($key, '/'));
            $key = array_shift($slices);
            reset($slices);
        } else {
            $slices = array();
        }

        if (empty($this->settings) || !isset($this->settings[$key])) {
            return $default;
        }
        $config = $this->settings[$key];
        while (null !== ($subkey = array_shift($slices))) {
            if (!isset($config[$subkey])) {
                return $default;
            }
            $config = $config[$subkey];
        }
        return $config;
    }


    /**
     * Run a child maintenance script. Pass all of the current arguments
     * to it.
     * @param string $maintClass A name of a child maintenance class
     * @param string $classFile Full path of where the child is
     * @return Maintenance
     */
    public function runChild( $maintClass, $classFile = null ) {
        // Make sure the class is loaded first
        if ( !class_exists( $maintClass ) ) {
            if ( $classFile ) {
                require_once $classFile;
            }
            if ( !class_exists( $maintClass ) ) {
                $this->error( "Cannot spawn child: $maintClass" );
            }
        }

        /**
         * @var $child Maintenance
         */
        $child = new $maintClass();
        $child->loadParamsAndArgs( $this->mSelf, $this->mOptions, $this->mArgs );
        return $child;
    }

    /**
     * Do some sanity checking and basic setup
     */
    public function setup() {
        global $IP, $inCommandLineMode;

        # Abort if called from a web server
        if ( isset( $_SERVER ) && isset( $_SERVER['REQUEST_METHOD'] ) ) {
            $this->error( 'This script must be run from the command line', true );
        }

        if ( $IP === null ) {
            $this->error( "\$IP not set, aborting!\n" .
                '(Did you forget to call parent::__construct() in your maintenance script?)', 1 );
        }

        # Make sure we can handle script parameters
        if ( !defined( 'HPHP_VERSION' ) && !ini_get( 'register_argc_argv' ) ) {
            $this->error( 'Cannot get command line arguments, register_argc_argv is set to false', true );
        }

        // Send PHP warnings and errors to stderr instead of stdout.
        // This aids in diagnosing problems, while keeping messages
        // out of redirected output.
        if ( ini_get( 'display_errors' ) ) {
            ini_set( 'display_errors', 'stderr' );
        }

        $this->loadParamsAndArgs();
        $this->maybeHelp();

        # Set the memory limit
        # Note we need to set it again later in cache LocalSettings changed it
        $this->adjustMemoryLimit();

        # Set max execution time to 0 (no limit). PHP.net says that
        # "When running PHP from the command line the default setting is 0."
        # But sometimes this doesn't seem to be the case.
        ini_set( 'max_execution_time', 0 );

        $inCommandLineMode = true;

        # Turn off output buffering if it's on
        while ( ob_get_level() > 0 ) {
            ob_end_flush();
        }

        $this->validateParamsAndArgs();
    }

    /**
     * Normally we disable the memory_limit when running admin scripts.
     * Some scripts may wish to actually set a limit, however, to avoid
     * blowing up unexpectedly. We also support a --memory-limit option,
     * to allow sysadmins to explicitly set one if they'd prefer to override
     * defaults (or for people using Suhosin which yells at you for trying
     * to disable the limits)
     * @return string
     */
    public function memoryLimit() {
        $limit = $this->getOption( 'memory-limit', 'max' );
        $limit = trim( $limit, "\" '" ); // trim quotes in case someone misunderstood
        return $limit;
    }

    /**
     * Adjusts PHP's memory limit to better suit our needs, if needed.
     */
    protected function adjustMemoryLimit() {
        $limit = $this->memoryLimit();
        if ( $limit == 'max' ) {
            $limit = -1; // no memory limit
        }
        if ( $limit != 'default' ) {
            ini_set( 'memory_limit', $limit );
        }
    }

    /**
     * Clear all params and arguments.
     */
    public function clearParamsAndArgs() {
        $this->mOptions = [];
        $this->mArgs = [];
        $this->mInputLoaded = false;
    }

    /**
     * Load params and arguments from a given array
     * of command-line arguments
     *
     * @param array $argv
     */
    public function loadWithArgv( $argv ) {
        $options = [];
        $args = [];
        $this->orderedOptions = [];

        # Parse arguments
        for ( $arg = reset( $argv ); $arg !== false; $arg = next( $argv ) ) {
            if ( $arg == '--' ) {
                # End of options, remainder should be considered arguments
                $arg = next( $argv );
                while ( $arg !== false ) {
                    $args[] = $arg;
                    $arg = next( $argv );
                }
                break;
            } elseif ( substr( $arg, 0, 2 ) == '--' ) {
                # Long options
                $option = substr( $arg, 2 );
                if ( isset( $this->mParamsConfig[$option] ) && $this->mParamsConfig[$option]['withArg'] ) {
                    $param = next( $argv );
                    if ( $param === false ) {
                        $this->error( "\nERROR: $option parameter needs a value after it\n" );
                        $this->maybeHelp( true );
                    }

                    $this->setParam( $options, $option, $param );
                } else {
                    $bits = explode( '=', $option, 2 );
                    if ( count( $bits ) > 1 ) {
                        $option = $bits[0];
                        $param = $bits[1];
                    } else {
                        $param = 1;
                    }

                    $this->setParam( $options, $option, $param );
                }
            } elseif ( $arg == '-' ) {
                # Lonely "-", often used to indicate stdin or stdout.
                $args[] = $arg;
            } elseif ( substr( $arg, 0, 1 ) == '-' ) {
                # Short options
                $argLength = strlen( $arg );
                for ( $p = 1; $p < $argLength; $p++ ) {
                    $option = $arg[$p];
                    if ( !isset( $this->mParamsConfig[$option] ) && isset( $this->mShortParamsMap[$option] ) ) {
                        $option = $this->mShortParamsMap[$option];
                    }

                    if ( isset( $this->mParamsConfig[$option]['withArg'] ) && $this->mParamsConfig[$option]['withArg'] ) {
                        $param = next( $argv );
                        if ( $param === false ) {
                            $this->error( "\nERROR: $option parameter needs a value after it\n" );
                            $this->maybeHelp( true );
                        }
                        $this->setParam( $options, $option, $param );
                    } else {
                        $this->setParam( $options, $option, 1 );
                    }
                }
            } else {
                $args[] = $arg;
            }
        }

        $this->mOptions = $options;
        $this->mArgs = $args;
        $this->loadSpecialVars();
        $this->mInputLoaded = true;
    }

    /**
     * Helper function used solely by loadParamsAndArgs
     * to prevent code duplication
     *
     * This sets the param in the options array based on
     * whether or not it can be specified multiple times.
     *
     * @param array $options
     * @param string $option
     * @param mixed $value
     */
    private function setParam( &$options, $option, $value ) {
        $this->orderedOptions[] = [ $option, $value ];

        if ( isset( $this->mParamsConfig[$option] ) ) {
            $multi = $this->mParamsConfig[$option]['multiOccurrence'];
        } else {
            $multi = false;
        }
        $exists = array_key_exists( $option, $options );
        if ( $multi && $exists ) {
            $options[$option][] = $value;
        } elseif ( $multi ) {
            $options[$option] = [ $value ];
        } elseif ( !$exists ) {
            $options[$option] = $value;
        } else {
            $this->error( "\nERROR: $option parameter given twice\n" );
            $this->maybeHelp( true );
        }
    }

    /**
     * Process command line arguments
     * $mOptions becomes an array with keys set to the option names
     * $mArgs becomes a zero-based array containing the non-option arguments
     *
     * @param string $self The name of the script, if any
     * @param array $opts An array of options, in form of key=>value
     * @param array $args An array of command line arguments
     */
    public function loadParamsAndArgs( $self = null, $opts = null, $args = null ) {
        # If we were given opts or args, set those and return early
        if ( $self ) {
            $this->mSelf = $self;
            $this->mInputLoaded = true;
        }
        if ( $opts ) {
            $this->mOptions = $opts;
            $this->mInputLoaded = true;
        }
        if ( $args ) {
            $this->mArgs = $args;
            $this->mInputLoaded = true;
        }

        # If we've already loaded input (either by user values or from $argv)
        # skip on loading it again. The array_shift() will corrupt values if
        # it's run again and again
        if ( $this->mInputLoaded ) {
            $this->loadSpecialVars();

            return;
        }

        global $argv;
        $this->mSelf = $argv[0];
        $this->loadWithArgv( array_slice( $argv, 1 ) );
    }

    /**
     * Run some validation checks on the params, etc
     */
    protected function validateParamsAndArgs() {
        $die = false;
        # Check to make sure we've got all the required options
        foreach ( $this->mParamsConfig as $opt => $info ) {
            if ( $info['require'] && !$this->hasOption( $opt ) ) {
                $this->error( "Param $opt required!" );
                $die = true;
            }
        }
        # Check arg list too
        foreach ( $this->mArgList as $k => $info ) {
            if ( $info['require'] && !$this->hasArg( $k ) ) {
                $this->error( 'Argument <' . $info['name'] . '> required!' );
                $die = true;
            }
        }

        if ( $die ) {
            $this->maybeHelp( true );
        }
    }

    /**
     * Handle the special variables that are global to all scripts
     */
    protected function loadSpecialVars() {
        if ( $this->hasOption( 'quiet' ) ) {
            $this->mQuiet = true;
        }
        if ( $this->hasOption( 'batch-size' ) ) {
            $this->mBatchSize = intval( $this->getOption( 'batch-size' ) );
        }
    }

    /**
     * Maybe show the help.
     * @param bool $force Whether to force the help to show, default false
     */
    protected function maybeHelp( $force = false ) {
        if ( !$force && !$this->hasOption( 'help' ) ) {
            return;
        }

        $screenWidth = 80; // TODO: Calculate this!
        $tab = "    ";
        $descWidth = $screenWidth - ( 2 * strlen( $tab ) );

        ksort( $this->mParamsConfig );
        $this->mQuiet = false;

        // Description ...
        if ( $this->mDescription ) {
            $this->output( PHP_EOL . wordwrap( $this->mDescription, $screenWidth ) . PHP_EOL );
        }
        $output = "\nUsage: php " . basename( $this->mSelf );

        // ... append parameters ...
        if ( $this->mParamsConfig ) {
            $output .= " [--" . implode( array_keys( $this->mParamsConfig ), "|--" ) . "]";
        }

        // ... and append arguments.
        if ( $this->mArgList ) {
            $output .= ' ';
            foreach ( $this->mArgList as $k => $arg ) {
                if ( $arg['require'] ) {
                    $output .= '<' . $arg['name'] . '>';
                } else {
                    $output .= '[' . $arg['name'] . ']';
                }
                if ( $k < count( $this->mArgList ) - 1 ) {
                    $output .= ' ';
                }
            }
        }
        $this->output( "$output\n\n" );

        # TODO abstract some repetitive code below

        // Generic parameters
        $this->output( "Generic maintenance parameters:\n" );
        foreach ( $this->mGenericParameters as $par => $info ) {
            if ( $info['shortName'] !== false ) {
                $par .= " (-{$info['shortName']})";
            }
            $this->output(
                wordwrap( "$tab--$par: " . $info['desc'], $descWidth,
                    "\n$tab$tab" ) . PHP_EOL
            );
        }
        $this->output( PHP_EOL );

        $scriptDependantParams = $this->mDependantParameters;
        if ( count( $scriptDependantParams ) > 0 ) {
            $this->output( "Script dependant parameters:\n" );
            // Parameters description
            foreach ( $scriptDependantParams as $par => $info ) {
                if ( $info['shortName'] !== false ) {
                    $par .= " (-{$info['shortName']})";
                }
                $this->output(
                    wordwrap( "$tab--$par: " . $info['desc'], $descWidth,
                        "\n$tab$tab" ) . PHP_EOL
                );
            }
            $this->output( PHP_EOL );
        }

        // Script specific parameters not defined on construction by
        // Maintenance::addDefaultParams()
        $scriptSpecificParams = array_diff_key(
            # all script parameters:
            $this->mParamsConfig,
            # remove the Maintenance default parameters:
            $this->mGenericParameters,
            $this->mDependantParameters
        );
        if ( count( $scriptSpecificParams ) > 0 ) {
            $this->output( "Script specific parameters:\n" );
            // Parameters description
            foreach ( $scriptSpecificParams as $par => $info ) {
                if ( $info['shortName'] !== false ) {
                    $par .= " (-{$info['shortName']})";
                }
                $this->output(
                    wordwrap( "$tab--$par: " . $info['desc'], $descWidth,
                        "\n$tab$tab" ) . PHP_EOL
                );
            }
            $this->output( PHP_EOL );
        }

        // Print arguments
        if ( count( $this->mArgList ) > 0 ) {
            $this->output( "Arguments:\n" );
            // Arguments description
            foreach ( $this->mArgList as $info ) {
                $openChar = $info['require'] ? '<' : '[';
                $closeChar = $info['require'] ? '>' : ']';
                $this->output(
                    wordwrap( "$tab$openChar" . $info['name'] . "$closeChar: " .
                        $info['desc'], $descWidth, "\n$tab$tab" ) . PHP_EOL
                );
            }
            $this->output( PHP_EOL );
        }

        die( 1 );
    }

    /**
     * Handle some last-minute setup here.
     */
    public function finalSetup() {
        global $inCommandLineMode;

        # Turn off output buffering again, it might have been turned on in the settings files
        if ( ob_get_level() ) {
            ob_end_flush();
        }
        # Same with these
        $inCommandLineMode = true;
        $this->afterFinalSetup();

        @set_time_limit( 0 );

        $this->adjustMemoryLimit();
    }

    /**
     * Execute a callback function at the end of initialisation
     */
    protected function afterFinalSetup() {
        if ( defined( 'CMDLINE_CALLBACK' ) ) {
            call_user_func( CMDLINE_CALLBACK );
        }
    }

    /**
     * Potentially debug globals. Originally a feature only
     * for refreshLinks
     */
    public function globals() {
        if ( $this->hasOption( 'globals' ) ) {
            print_r( $GLOBALS );
        }
    }

    public function checkIncludePath($configPath='') {
        $new_path = get_include_path();
        if ($configPath && strpos($new_path, rtrim($configPath, '/')) === false) {
            $new_path .= PATH_SEPARATOR . rtrim($configPath, '/');
        }
        // My prefer directory storing external required sdks or libarys.
        if (strpos($new_path, '/home/q/php') === false) {
            $new_path .= PATH_SEPARATOR . '/home/q/php';
        }
        set_include_path($new_path);
    }

    /**
     * Generic setup for most installs. Returns the location of LocalSettings
     * @return string
     */
    public function loadConfigFile() {
        if (!empty($this->settings)) {
            return false;
        }

        global $inCommandLineMode, $IP;
        if ( isset( $this->mOptions['conf'] ) ) {
            $configPath = $this->mOptions['conf'];
        } else {
            // Default directory storing configure files , up to your framework.
            $configPath = "$IP/config/iapi";
        }
        $this->checkIncludePath(realpath($configPath));
        $inCommandLineMode = true;

        include('server_conf.php');
        $this->settings = get_defined_vars();
        if (empty($this->settings)) {
            $this->error( "A local configure file server_conf.php must exist and be readable in the source directory.\n" .
                "Use --conf to specify it.", true );
        }
        return true;
    }

    /**
     * Get the maintenance directory.
     * @return string
     */
    protected function getDir() {
        return __DIR__;
    }
}

/**
 * Fake maintenance wrapper, mostly used for the web installer/updater
 */
class FakeMaintenance extends Maintenance {
    protected $mSelf = "FakeMaintenanceScript";

    public function execute() {
        return;
    }
}
