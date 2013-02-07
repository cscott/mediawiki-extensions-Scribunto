<?php

class Scribunto_LuaTitleLibrary extends Scribunto_LuaLibraryBase {
	// Note these caches are naturally limited to
	// $wgExpensiveParserFunctionLimit + 1 actual Title objects because any
	// addition besides the one for the current page calls
	// incrementExpensiveFunctionCount()
	private $titleCache = array();
	private $idCache = array();

	function register( $pureLua = false ) {
		$lib = array(
			'newTitle' => array( $this, 'newTitle' ),
			'makeTitle' => array( $this, 'makeTitle' ),
			'getUrl' => array( $this, 'getUrl' ),
		);
		$this->getEngine()->registerInterface( 'mw.title.lua', $lib, array(
			'thisTitle' => $this->returnTitleToLua( $this->getTitle() ),
		) );
	}

	private function checkNamespace( $name, $argIdx, &$arg, $default = null ) {
		global $wgContLang;

		if ( $arg === null && $default !== null ) {
			$arg = $default;
		} elseif ( is_numeric( $arg ) ) {
			$arg = (int)$arg;
			if ( !MWNamespace::exists( $arg ) ) {
				throw new Scribunto_LuaError(
					"bad argument #$argIdx to '$name' (unrecognized namespace number '$arg')"
				);
			}
		} elseif ( is_string( $arg ) ) {
			$ns = $wgContLang->getNsIndex( $arg );
			if ( $ns === false ) {
				throw new Scribunto_LuaError(
					"bad argument #$argIdx to '$name' (unrecognized namespace name '$arg')"
				);
			}
			$arg = $ns;
		} else {
			$this->checkType( $name, $argIdx, $arg, 'namespace number or name' );
		}
	}

	/**
	 * Extract information from a Title object for return to Lua
	 *
	 * This also records a link to this title in the current ParserOutput
	 * and caches the title for repeated lookups. The caller should call
	 * incrementExpensiveFunctionCount() if necessary.
	 *
	 * @param $title Title Title to return
	 * @return array Lua data
	 */
	private function returnTitleToLua( Title $title ) {
		if ( !$title ) {
			return array( null );
		}

		// Cache it
		$this->titleCache[$title->getPrefixedDBkey()] = $title;
		$this->idCache[$title->getArticleID()] = $title;

		// Record a link
		$this->getParser()->getOutput()->addLink( $title );

		return array(
			'isLocal' => (bool)$title->isLocal(),
			'isRedirect' => (bool)$title->isRedirect(),
			'interwiki' => $title->getInterwiki(),
			'namespace' => $title->getNamespace(),
			'nsText' => $title->getNsText(),
			'text' => $title->getText(),
			'id' => $title->getArticleID(),
			'fragment' => $title->getFragment(),
			'contentModel' => $title->getContentModel(),
			'thePartialUrl' => $title->getPartialURL(),
		);
	}

	/**
	 * Handler for title.new
	 *
	 * Calls Title::newFromID or Title::newFromTitle as appropriate for the
	 * arguments, and may call incrementExpensiveFunctionCount() if the title
	 * is not already cached.
	 *
	 * @param $text_or_id string|int Title or page_id to fetch
	 * @param $defaultNamespace string|int Namespace name or number to use if $text_or_id doesn't override
	 * @return array Lua data
	 */
	function newTitle( $text_or_id, $defaultNamespace = null ) {
		$type = $this->getLuaType( $text_or_id );
		if ( $type === 'number' ) {
			if ( array_key_exists( $text_or_id, $this->idCache ) ) {
				$title = $this->idCache[$text_or_id];
			} else {
				$this->incrementExpensiveFunctionCount();
				$title = Title::newFromID( $text_or_id );
				$this->idCache[$text_or_id] = $title;
			}
		} elseif ( $type === 'string' ) {
			$this->checkNamespace( 'title.new', 2, $defaultNamespace, NS_MAIN );

			// Note this just fills in the given fields, it doesn't fetch from
			// the page table.
			$title = Title::newFromText( $text_or_id, $defaultNamespace );
			if ( !$title ) {
				return array( null );
			}
			if ( isset( $this->titleCache[$title->getPrefixedDBkey()] ) ) {
				// Use the cached version, because that has already been loaded from the database
				$title = $this->titleCache[$title->getPrefixedDBkey()];
			} else {
				$this->incrementExpensiveFunctionCount();
			}
		} else {
			// This will always fail
			$this->checkType( 'title.new', 1, $text_or_id, 'number or string' );
		}

		return array( $this->returnTitleToLua( $title ) );
	}

	/**
	 * Handler for title.makeTitle
	 *
	 * Calls Title::makeTitleSafe, and may call
	 * incrementExpensiveFunctionCount() if the title is not already cached.
	 *
	 * @param $ns string|int Namespace
	 * @param $text string Title text
	 * @param $fragment string URI fragment
	 * @param $interwiki string Interwiki code
	 * @return array Lua data
	 */
	function makeTitle( $ns, $text, $fragment = null, $interwiki = null ) {
		$this->checkNamespace( 'makeTitle', 1, $ns );
		$this->checkType( 'makeTitle', 2, $text, 'string' );
		$this->checkTypeOptional( 'makeTitle', 3, $fragment, 'string', '' );
		$this->checkTypeOptional( 'makeTitle', 4, $interwiki, 'string', '' );

		// Note this just fills in the given fields, it doesn't fetch from the
		// page table.
		$title = Title::makeTitleSafe( $ns, $text, $fragment, $interwiki );
		if ( !$title ) {
			return array( null );
		}
		if ( isset( $this->titleCache[$title->getPrefixedDBkey()] ) ) {
			// Use the cached version, because that has already been loaded from the database
			$title = $this->titleCache[$title->getPrefixedDBkey()];
		} else {
			$this->incrementExpensiveFunctionCount();
		}

		return array( $this->returnTitleToLua( $title ) );
	}

	// May call the following Title methods:
	// getFullUrl, getLocalUrl, getCanonicalUrl
	function getUrl( $text, $which, $query = null, $proto = null ) {
		static $protoMap = array(
			'http' => PROTO_HTTP,
			'https' => PROTO_HTTPS,
			'relative' => PROTO_RELATIVE,
			'canonical' => PROTO_CANONICAL,
		);

		$this->checkType( 'getUrl', 1, $text, 'string' );
		$this->checkType( 'getUrl', 2, $which, 'string' );
		if ( !in_array( $which, array( 'fullUrl', 'localUrl', 'canonicalUrl' ), true ) ) {
			$this->checkType( 'getUrl', 2, $which, "'fullUrl', 'localUrl', or 'canonicalUrl'" );
		}
		$func = "get" . ucfirst( $which );

		$args = array( $query, false );
		if ( !is_string( $query ) && !is_array( $query ) ) {
			$this->checkTypeOptional( $which, 1, $query, 'table or string', '' );
		}
		if ( $which === 'fullUrl' ) {
			$this->checkTypeOptional( $which, 2, $proto, 'string', 'relative' );
			if ( !isset( $protoMap[$proto] ) ) {
				$this->checkType( $which, 2, $proto, "'http', 'https', 'relative', or 'canonical'" );
			}
			$args[] = $protoMap[$proto];
		}

		$title = Title::newFromText( $text );
		if ( !$title ) {
			return array( null );
		}
		return array( call_user_func_array( array( $title, $func ), $args ) );
	}
}
