<?php

class Scribunto_JsV8Engine extends ScribuntoEngineBase {
	/**
	 * Libraries to load. See also the 'ScribuntoExternalLibraries' hook.
	 * @var array Maps module names to PHP classes
	 */
	protected static $libraryClasses = array(
		'mw.site' => 'Scribunto_JsSiteLibrary',
		'mw.uri' => 'Scribunto_JsUriLibrary',
		'mw.language' => 'Scribunto_JsLanguageLibrary',
		'mw.message' => 'Scribunto_JsMessageLibrary',
		'mw.title' => 'Scribunto_JsTitleLibrary',
		'mw.text' => 'Scribunto_JsTextLibrary',
	);

	/**
	 * Paths for modules that may be loaded from Lua. See also the
	 * 'ScribuntoExternalLibraryPaths' hook.
	 * @var array Paths
	 */
	protected static $libraryPaths = array(
		'.',
	);

	/**
	 * Get the language for GeSHi syntax highlighter.
	 */
	function getGeSHiLanguage() {
		return 'javascript';
	}

	/**
	 * Get the language for Ace code editor.
	 */
	function getCodeEditorLanguage() {
		return 'javascript';
	}

	function newInterpreter() {
		return new Scribunto_JsV8Interpreter( $this, $this->options );
	}

	protected $loaded = false;
	protected $interpreter;

	/**
	 * Get the current interpreter object
	 * @return Scribunto_LuaInterpreter
	 */
	public function getInterpreter() {
		$this->load();
		return $this->interpreter;
	}

	/**
	 * Initialise the interpreter and the base environment
	 */
	public function load() {
		if( $this->loaded ) {
			return;
		}
		$this->loaded = true;

		try {
			$this->interpreter = $this->newInterpreter();
			// XXX load JS library here.
		} catch ( Exception $ex ) {
			$this->loaded = false;
			$this->interpreter = null;
			throw $ex;
		}
	}

	/**
	 * Creates a new module object within this engine
	 */
	protected function newModule( $text, $chunkName ) {
		return new Scribunto_JsV8Module( $this, $text, $chunkName );
	}

	public function newJsError( $message, $params = array() ) {
		return new Scribunto_JsError( $message, $this->getDefaultExceptionParams() + $params );
	}

	/**
	 * Run an interactive console request
	 *
	 * @param $params Associative array. Options are:
	 *    - title: The title object for the module being debugged
	 *    - content: The text content of the module
	 *    - prevQuestions: An array of previous "questions" used to establish the state
	 *    - question: The current "question", a string script
	 *
	 * @return array containing:
	 *    - print: The resulting print buffer
	 *    - return: The resulting return value
	 */
	function runConsole( $params ) {
		return false;
	}

	public function getSoftwareInfo( &$software ) {
		$versions = array(
			'V8Js' => phpversion( "V8Js" ),
			'v8' => V8Js::V8_VERSION,
		);
		$software['[http://www.php.net/manual/en/book.v8js.php V8Js]'] = $versions['V8Js'];
		$software['[https://code.google.com/p/v8/ v8]'] = str_replace( 'Lua ', '', $versions['v8'] );
	}

	public function getLimitReport() {
		return "V8Js: XXX FIXME XXX\n";
	}

	protected $currentFrames = array();
	/**
	 * Execute a module function chunk
	 */
	public function executeFunctionChunk( $exports, $name, $frame ) {
		$oldFrames = $this->currentFrames;
		$this->currentFrames = array(
			'current' => $frame,
			'parent' => isset( $frame->parent ) ? $frame->parent : null,
		);
		try {
			$result = $this->getInterpreter()->callFunction( $exports, $name );
		} catch ( Exception $ex ) {
			$this->currentFrames = $oldFrames;
			throw $ex;
		}
		$this->currentFrames = $oldFrames;
		return $result;
	}
}

class Scribunto_JsV8Interpreter {
	var $engine, $v8;

	function __construct( $engine, $options ) {
		if ( !extension_loaded( 'V8Js' ) ) {
			throw new Scribunto_JsV8InterpreterNotFoundError(
				'The V8Js extension is not present; this engine cannot be used.'
			);
		}
		$this->engine = $engine;
		$this->v8 = new V8Js();
		$this->cpuLimit = $options[ 'cpuLimit' ];
		$this->memoryLimit = $options[ 'memoryLimit' ];
	}

	public function loadString( $text, $chunkName ) {
		// time and memory limits only work with v8js >= 0.1.4 (Apr 10, 2013)
		$timeLimit = 0; $memoryLimit = 0;
		try {
			return $this->v8->executeString( $text, $chunkName, V8Js::FLAG_NONE , $this->cpuLimit, $this->memoryLimit );
		} catch ( V8JsTimeLimitException $e ) {
			throw $this->engine->newException( 'scribunto-common-timeout' );
		} catch ( V8JsMemoryLimitException $e ) {
			throw $this->engine->newException( 'scribunto-common-oom' );
		} catch ( V8JsScriptException $e ) {
			$message = $e->getMessage();
			if ( preg_match( '/^(.*?):(\d+): (.*)$/', $message, $m ) ) {
				$message = $m[3];
			}
			throw $this->engine->newJsError( $message, array(
				'module' => $e->getJsFileName(),
				'line' => $e->getJsLineNumber(),
			) );
		}
	}

	public function callFunction( $func /*, ... */ ) {
		$args = func_get_args();
		$exports = array_shift( $args );
		$name = array_shift( $args );
		$func = get_object_vars( $exports )[ $name ];
		$this->v8->func = $func;
		$this->v8->args = $args;
		// use loadString() in order to get memory/cpu limits applied and
		// handle thrown exceptions
		return $this->loadString( "PHP.func.apply(null, PHP.args)", $name );
	}

}

class Scribunto_JsError extends ScribuntoException {
	var $jsMessage;

	function __construct( $message, $options = array() ) {
		$this->jsMessage = $message;
		$options = $options + array( 'args' => array( $message ) );
		if ( isset( $options['module'] ) && isset( $options['line'] ) ) {
			$msg = 'scribunto-js-error-location';
		} else {
			$msg = 'scribunto-js-error';
		}

		parent::__construct( $msg, $options );
	}

	function getJsMessage() {
		return $this->jsMessage;
	}
}

class Scribunto_JsV8InterpreterNotFoundError extends MWException {}

class Scribunto_JsV8Module extends ScribuntoModuleBase {

	/**
	 * Validates the script and returns a Status object containing the syntax
	 * errors for the given code.
	 *
	 * @return Status
	 */
	public function validate() {
		// Use a wrapper which doesn't execute the code.
		$wrapped = "function(exports){".$this->code."}";
		try {
			$this->engine->getInterpreter()->loadString( $wrapped, $this->chunkName );
		} catch ( ScribuntoException $e ) {
			return $e->toStatus();
		}
		return Status::newGood();
	}

	/**
	 * Execute the module function and return the export table.
	 */
	public function execute() {
		// Wrapper returns the exports table.
		$wrapped = "(function(exports){".$this->code.";return exports;})({})";
		$exports = $this->engine->getInterpreter()->loadString( $wrapped, $this->chunkName );
		return $exports;
	}

	/**
	 * Invoke the function with the specified name.
	 *
	 * @return string
	 */
	public function invoke( $name, $frame ) {
		$exports = $this->execute();
		if ( !property_exists( $exports, $name ) ) {
			throw $this->engine->newException( 'scribunto-common-nosuchfunction' );
		}
		$result = $this->engine->executeFunctionChunk( $exports, $name, $frame );

		return strval( $result );
	}
}
