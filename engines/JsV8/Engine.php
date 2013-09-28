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

	/**
	 * Creates a new module object within this engine
	 */
	protected function newModule( $text, $chunkName ) {
		return new Scribunto_JsV8Module( $this, $text, $chunkName );
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
}

class Scribunto_JsV8Module extends ScribuntoModuleBase {
	/**
	 * Validates the script and returns a Status object containing the syntax
	 * errors for the given code.
	 *
	 * @return Status
	 */
	public function validate() {
		return Status::newGood(); // XXX
	}
	/**
	 * Invoke the function with the specified name.
	 *
	 * @return string
	 */
	public function invoke( $name, $frame ) {
		// XXX
		throw $this->engine->newException( 'scribunto-common-nosuchfunction' );
	}
}
