<?php


class %BOOTCODE%_Less_Cache{

	public static $cache_dir = false;		// directory less.php can use for storing data
	public static $import_dirs = array();
	public static $error;

    const cache_version = '1511';
	protected static $clean_cache = true;


	public static function Get( $less_files, $parser_options = array() ){

		//check $cache_dir
		if( empty(self::$cache_dir) ){
			throw new Exception('cache_dir not set');
			return false;
		}

		self::$cache_dir = str_replace('\\','/',self::$cache_dir);
		self::$cache_dir = rtrim(self::$cache_dir,'/').'/';

		if( !is_dir(self::$cache_dir) ){
			throw new Exception('cache_dir does not exist');
			return false;
		}


		// generate name for compiled css file
		$less_files = (array)$less_files;
		$hash = md5(json_encode($less_files));
 		$list_file = self::$cache_dir.'lessphp_'.$hash.'.list';


 		// check cached content
		$compiled_file = false;
		$less_cache = false;
 		if( file_exists($list_file) ){

			//get info about the list file
			$compiled_name = self::CompiledName( $hash, $list_file );
			$compiled_file = self::$cache_dir.$compiled_name;


			//check modified time of all included files
			if( file_exists($compiled_file) ){

				$list = explode("\n",file_get_contents($list_file));
				$list_updated = filemtime($list_file);

				foreach($list as $file ){
					if( !file_exists($file) || filemtime($file) > $list_updated ){
						$compiled_file = false;
						break;
					}
				}


				// return relative path if we don't need to regenerate
				if( $compiled_file ){

					//touch the files to extend the cache
					touch($list_file);
					touch($compiled_file);

					return $compiled_name;
				}
			}

		}

		$compiled = self::Cache( $less_files, $parser_options );
		if( !$compiled ){
			return false;
		}


		//save the cache
		$cache = implode("\n",$less_files);
		file_put_contents( $list_file, $cache );


		//save the css
		$compiled_name = self::CompiledName( $hash, $list_file );
		file_put_contents( self::$cache_dir.$compiled_name, $compiled );


		//clean up
		self::CleanCache();

		return $compiled_name;

	}

	public static function Cache( &$less_files, $parser_options = array() ){

		//prepare the processor
		if( !class_exists('%BOOTCODE%_Less_Parser') ){
			include_once('Less.php');
		}


		$parser = new %BOOTCODE%_Less_Parser($parser_options);
		$parser->SetCacheDir( self::$cache_dir );
		$parser->SetImportDirs( self::$import_dirs );


		// combine files
 		try{
			foreach($less_files as $file_path => $uri_or_less ){

				//treat as less markup if there are newline characters
				if( strpos($uri_or_less,"\n") !== false ){
					$parser->Parse( $uri_or_less );
					continue;
				}

				$parser->ParseFile( $file_path, $uri_or_less );
			}

			$compiled = $parser->getCss();

		}catch(Exception $e){
			self::$error = $e;
			return false;
		}

		$less_files = $parser->allParsedFiles();

		return $compiled;
	}


	public static function CompiledName( $hash, $list_file ){

		$etag = base_convert( self::cache_version, 10, 36 ) . base_convert( filesize($list_file), 10, 36 );

		return 'lessphp_'.$hash.'_'.$etag.'.css';
	}


	public static function CleanCache(){
		static $clean = false;

		if( $clean ){
			return;
		}

		$files = scandir(self::$cache_dir);
		if( $files ){
			$check_time = time() - 604800;
			foreach($files as $file){
				if( strpos($file,'lessphp_') !== 0 ){
					continue;
				}
				$full_path = self::$cache_dir.'/'.$file;
				if( filemtime($full_path) > $check_time ){
					continue;
				}
				unlink($full_path);
			}
		}

		$clean = true;
	}

}

class %BOOTCODE%_Less_Parser extends %BOOTCODE%_Less_Cache{


	private $input;		// LeSS input string
	private $input_len;	// input string length
	private $pos;		// current index in `input`
	private $memo;		// temporarily holds `i`, when backtracking


	/**
	 * @var string
	 */
	private $path;

	/**
	 * @var string
	 */
	private $filename;

	public static $instance;

	/**
	 *
	 */
	const version = '1.5.1b2';
	const less_version = '1.5.1';

	/**
	 * @var %BOOTCODE%_Less_Environment
	 */
	private $env;
	private $rules = array();

	private static $imports = array();

	public static $has_extends = false;

	public $cache_method = false; //'serialize' or 'php';


	/**
	 * @param Environment|null $env
	 */
	public function __construct( $env = null ){


		// Top parser on an import tree must be sure there is one "env"
		// which will then be passed around by reference.
		if( $env instanceof %BOOTCODE%_Less_Environment ){
			$this->env = $env;
		}else{
			$this->env = new %BOOTCODE%_Less_Environment( $env );
			self::$imports = array();
			self::$import_dirs = array();
		}

		$this->pos = 0;
	}



	/**
	 * Get the current css buffer
	 *
	 * @return string
	 */
	public function getCss(){

		$precision = ini_get('precision');
		@ini_set('precision',16);
		$locale = setlocale(LC_NUMERIC, 0);
		setlocale(LC_NUMERIC, "C");


 		$root = new %BOOTCODE%_Less_Tree_Ruleset(array(), $this->rules );
		$root->root = true;
		$root->firstRoot = true;


		//$importVisitor = new %BOOTCODE%_Less_importVisitor();
		//$importVisitor->run($root);

		//obj($root);

		self::$has_extends = false;
		$evaldRoot = $root->compile($this->env);

		$joinSelector = new %BOOTCODE%_Less_joinSelectorVisitor();
		$joinSelector->run($evaldRoot);


		if( self::$has_extends ){
			$extendsVisitor = new %BOOTCODE%_Less_processExtendsVisitor();
			$extendsVisitor->run($evaldRoot);
		}

		$toCSSVisitor = new %BOOTCODE%_Less_toCSSVisitor( $this->env );
		$toCSSVisitor->run($evaldRoot);

		$css = $evaldRoot->toCSS($this->env);

		if( %BOOTCODE%_Less_Environment::$compress ){
			$css = preg_replace('/(^(\s)+)|((\s)+$)/', '', $css);
		}

		//reset php settings
		@ini_set('precision',$precision);
		setlocale(LC_NUMERIC, $locale);

		return $css;
	}


	/**
	 * Parse a Less string into css
	 *
	 * @param string $str The string to convert
	 * @param bool $returnRoot Indicates whether the return value should be a css string a root node
	 * @return %BOOTCODE%_Less_Tree_Ruleset|%BOOTCODE%_Less_Parser
	 */
	public function parse($str){
		$this->input = $str;
		$this->_parse();
	}


	/**
	 * Parse a Less string from a given file
	 *
	 * @throws %BOOTCODE%_Less_ParserException
	 * @param $filename The file to parse
	 * @param $uri_root The url of the file
	 * @param bool $returnRoot Indicates whether the return value should be a css string a root node
	 * @return %BOOTCODE%_Less_Tree_Ruleset|%BOOTCODE%_Less_Parser
	 */
	public function parseFile( $filename, $uri_root = '', $returnRoot = false){

		if( !file_exists($filename) ){
			throw new %BOOTCODE%_Less_ParserException(sprintf('File `%s` not found.', $filename));
		}

		$previousFileInfo = $this->env->currentFileInfo;
		$this->SetFileInfo($filename, $uri_root);

		$previousImportDirs = self::$import_dirs;
		self::AddParsedFile($filename);

		$return = null;
		if( $returnRoot ){
			$rules = $this->GetRules( $filename );
			$return = new %BOOTCODE%_Less_Tree_Ruleset(array(), $rules );
		}else{
			$this->_parse( $filename );
		}

		if( $previousFileInfo ){
			$this->env->currentFileInfo = $previousFileInfo;
		}
		self::$import_dirs = $previousImportDirs;

		return $return;
	}


	public function SetFileInfo( $filename, $uri_root = ''){

		$this->path = pathinfo($filename, PATHINFO_DIRNAME);
		$this->filename = %BOOTCODE%_Less_Environment::normalizePath($filename);

		$dirname = preg_replace('/[^\/\\\\]*$/','',$this->filename);

		$currentFileInfo = array();
		$currentFileInfo['currentDirectory'] = $dirname;
		$currentFileInfo['filename'] = $filename;
		$currentFileInfo['rootpath'] = $dirname;
		$currentFileInfo['entryPath'] = $dirname;

		if( empty($uri_root) ){
			$currentFileInfo['uri_root'] = $uri_root;
		}else{
			$currentFileInfo['uri_root'] = rtrim($uri_root,'/').'/';
		}


		//inherit reference
		if( isset($this->env->currentFileInfo['reference']) && $this->env->currentFileInfo['reference'] ){
			$currentFileInfo['reference'] = true;
		}

		$this->env->currentFileInfo = $currentFileInfo;

		self::$import_dirs = array_merge( array( $dirname => $currentFileInfo['uri_root'] ), self::$import_dirs );
	}

	public function SetCacheDir( $dir ){

		if( !is_dir($dir) ){
			throw new %BOOTCODE%_Less_ParserException('Less.php cache directory doesn\'t exist: '.$dir);
		}elseif( !is_writable($dir) ){
			throw new %BOOTCODE%_Less_ParserException('Less.php cache directory isn\'t writable: '.$dir);
		}else{
			$dir = str_replace('\\','/',$dir);
			self::$cache_dir = rtrim($dir,'/').'/';
			return true;
		}
	}

	public function SetImportDirs( $dirs ){
		foreach($dirs as $path => $uri_root){

			$path = str_replace('\\','/',$path);
			$uri_root = str_replace('\\','/',$uri_root);

			if( !empty($path) ){
				$path = rtrim($path,'/').'/';
			}
			if( !empty($uri_root) ){
				$uri_root = rtrim($uri_root,'/').'/';
			}
			self::$import_dirs[$path] = $uri_root;
		}
	}

	private function _parse( $file_path = false ){
		$this->rules = array_merge($this->rules, $this->GetRules( $file_path ));
	}


	/**
	 * Return the results of parsePrimary for $file_path
	 * Use cache and save cached results if possible
	 *
	 */
	private function GetRules( $file_path ){

		$cache_file = false;
		if( $file_path ){
			if( $this->cache_method ){
				$cache_file = $this->CacheFile( $file_path );

				if( $cache_file && file_exists($cache_file) ){
					switch($this->cache_method){

						// Using serialize
						// Faster but uses more memory
						case 'serialize':
							$cache = unserialize(file_get_contents($cache_file));
							if( $cache ){
								touch($cache_file);
								return $cache;
							}
						break;


						// Using generated php code
						case 'php':
						return include($cache_file);
					}
				}
			}

			$this->input = file_get_contents( $file_path );
		}

		$this->pos = 0;
		$this->input = preg_replace('/\r\n/', "\n", $this->input);

		// Remove potential UTF Byte Order Mark
		$this->input = preg_replace('/\\G\xEF\xBB\xBF/', '', $this->input);
		$this->input_len = strlen($this->input);

		$rules = $this->parsePrimary();


		// free up a little memory
		unset($this->input, $this->pos);


		//save the cache
		if( $cache_file && $this->cache_method ){

			switch($this->cache_method){
				case 'serialize':
					file_put_contents( $cache_file, serialize($rules) );
				break;
				case 'php':
					file_put_contents( $cache_file, '<?php return '.var_export($rules,true).'; ?>' );
				break;
			}

			if( self::$clean_cache ){
				self::CleanCache();
			}

		}

		return $rules;
	}


	public static function ReleaseMemory(){
		if( function_exists('gc_collect_cycles') ){
			gc_collect_cycles();
		}
	}

	public function CacheFile( $file_path ){

		if( $file_path && self::$cache_dir ){

			$env = get_object_vars($this->env);
			unset($env['frames']);

			$parts = array();
			$parts[] = $file_path;
			$parts[] = filesize( $file_path );
			$parts[] = filemtime( $file_path );
			$parts[] = $env;
			$parts[] = self::cache_version;
			$parts[] = $this->cache_method;
			return self::$cache_dir.'lessphp_'.base_convert( sha1(json_encode($parts) ), 16, 36).'.lesscache';
		}
	}


	static function AddParsedFile($file){
		self::$imports[] = $file;
	}

	static function AllParsedFiles(){
		return self::$imports;
	}

	static function FileParsed($file){
		return in_array($file,self::$imports);
	}


	function save() {
		$this->memo = $this->pos;
	}

	private function restore() {
		$this->pos = $this->memo;
	}


	private function isWhitespace($offset = 0) {
		return ctype_space($this->input[ $this->pos + $offset]);
	}

	/**
	 * Parse from a token, regexp or string, and move forward if match
	 *
	 * @param string $tok
	 * @return null|bool|object
	 */
	private function match($toks){

		// The match is confirmed, add the match length to `this::pos`,
		// and consume any extra white-space characters (' ' || '\n')
		// which come after that. The reason for this is that LeSS's
		// grammar is mostly white-space insensitive.
		//

		foreach($toks as $tok){

			$char = $tok[0];

			if( $char === '/' ){
				$match = $this->MatchReg($tok);

				if( $match ){
					return count($match) === 1 ? $match[0] : $match;
				}

			}elseif( $char === '#' ){
				$match = $this->MatchChar($tok[1]);

			}else{
				// Non-terminal, match using a function call
				$match = $this->$tok();

			}

			if( $match ){
				return $match;
			}
		}
	}

	private function MatchFuncs($toks){

		foreach($toks as $tok){
			$match = $this->$tok();
			if( $match ){
				return $match;
			}
		}

	}

	// Match a single character in the input,
	private function MatchChar($tok){
		if( ($this->pos < $this->input_len) && ($this->input[$this->pos] === $tok) ){
			$this->skipWhitespace(1);
			return $tok;
		}
	}

	// Match a regexp from the current start point
	private function MatchReg($tok){

		if( preg_match($tok, $this->input, $match, 0, $this->pos) ){
			$this->skipWhitespace(strlen($match[0]));
			return $match;
		}
	}


	/**
	 * Same as match(), but don't change the state of the parser,
	 * just return the match.
	 *
	 * @param $tok
	 * @param int $offset
	 * @return bool
	 */
	public function PeekReg($tok){
		return preg_match($tok, $this->input, $match, 0, $this->pos);
	}

	public function PeekChar($tok){
		return ($this->input[$this->pos] === $tok );
		//return ($this->pos < $this->input_len) && ($this->input[$this->pos] === $tok );
	}


	public function skipWhitespace($length){

		$this->pos += $length;

		for(; $this->pos < $this->input_len; $this->pos++ ){
			$c = $this->input[$this->pos];

			if( ($c !== "\n") && ($c !== "\r") && ($c !== "\t") && ($c !== ' ') ){
				break;
			}
		}
	}


	public function expect($tok, $msg = NULL) {
		$result = $this->match( array($tok) );
		if (!$result) {
			throw new %BOOTCODE%_Less_ParserException( $msg	? "Expected '" . $tok . "' got '" . $this->input[$this->pos] . "'" : $msg );
		} else {
			return $result;
		}
	}

	public function expectChar($tok, $msg = null ){
		$result = $this->MatchChar($tok);
		if( !$result ){
			throw new %BOOTCODE%_Less_ParserException( $msg ? "Expected '" . $tok . "' got '" . $this->input[$this->pos] . "'" : $msg );
		}else{
			return $result;
		}
	}

	//
	// Here in, the parsing rules/functions
	//
	// The basic structure of the syntax tree generated is as follows:
	//
	//   Ruleset ->  Rule -> Value -> Expression -> Entity
	//
	// Here's some LESS code:
	//
	//	.class {
	//	  color: #fff;
	//	  border: 1px solid #000;
	//	  width: @w + 4px;
	//	  > .child {...}
	//	}
	//
	// And here's what the parse tree might look like:
	//
	//	 Ruleset (Selector '.class', [
	//		 Rule ("color",  Value ([Expression [Color #fff]]))
	//		 Rule ("border", Value ([Expression [Dimension 1px][Keyword "solid"][Color #000]]))
	//		 Rule ("width",  Value ([Expression [Operation "+" [Variable "@w"][Dimension 4px]]]))
	//		 Ruleset (Selector [Element '>', '.child'], [...])
	//	 ])
	//
	//  In general, most rules will try to parse a token with the `$()` function, and if the return
	//  value is truly, will return a new node, of the relevant type. Sometimes, we need to check
	//  first, before parsing, that's when we use `peek()`.
	//

	//
	// The `primary` rule is the *entry* and *exit* point of the parser.
	// The rules here can appear at any level of the parse tree.
	//
	// The recursive nature of the grammar is an interplay between the `block`
	// rule, which represents `{ ... }`, the `ruleset` rule, and this `primary` rule,
	// as represented by this simplified grammar:
	//
	//	 primary  →  (ruleset | rule)+
	//	 ruleset  →  selector+ block
	//	 block	→  '{' primary '}'
	//
	// Only at one point is the primary rule not called from the
	// block rule: at the root level.
	//
	private function parsePrimary(){
		$root = array();

		while( true ){

			if( $this->pos >= $this->input_len ){
				break;
			}

			$node = $this->MatchFuncs( array('parseExtendRule', 'parseMixinDefinition', 'parseRule', 'parseRuleset', 'parseMixinCall', 'parseComment', 'parseDirective'));


			if( is_array($node) ){
				$root = array_merge($root,$node);
			}elseif( $node ){
				$root[] = $node;
			}elseif( !$this->MatchReg('/\\G[\s\n;]+/') ){
				break;
			}

		}

		return $root;
	}



	// We create a Comment node for CSS comments `/* */`,
	// but keep the LeSS comments `//` silent, by just skipping
	// over them.
	private function parseComment(){

		if( $this->input[$this->pos] !== '/' ){
			return;
		}

		if( $this->input[$this->pos+1] === '/' ){
			$match = $this->MatchReg('/\\G\/\/.*/');
			return new %BOOTCODE%_Less_Tree_Comment($match[0], true, $this->pos, $this->env->currentFileInfo);
		}

		//$comment = $this->MatchReg('/\\G\/\*(?:[^*]|\*+[^\/*])*\*+\/\n?/');
		$comment = $this->MatchReg('/\\G\/\*(?s).*?\*+\/\n?/');//not the same as less.js to prevent fatal errors
		if( $comment ){
			return new %BOOTCODE%_Less_Tree_Comment($comment[0], false, $this->pos, $this->env->currentFileInfo);
		}
	}

	private function parseComments(){
		$comments = array();

		while( true ){
			$comment = $this->parseComment();
			if( !$comment ){
				break;
			}

			$comments[] = $comment;
		}

		return $comments;
	}



	//
	// A string, which supports escaping " and '
	//
	//	 "milky way" 'he\'s the one!'
	//
	private function parseEntitiesQuoted() {
		$j = 0;
		$e = false;
		$index = $this->pos;

		if ($this->PeekChar('~')) {
			$j++;
			$e = true; // Escaped strings
		}

		$char = $this->input[$this->pos+$j];
		if( $char != '"' && $char !== "'" ){
			return;
		}

		if ($e) {
			$this->MatchChar('~');
		}
		$str = $this->MatchReg('/\\G"((?:[^"\\\\\r\n]|\\\\.)*)"|\'((?:[^\'\\\\\r\n]|\\\\.)*)\'/');
		if( $str ){
			$result = $str[0][0] == '"' ? $str[1] : $str[2];
			return new %BOOTCODE%_Less_Tree_Quoted($str[0], $result, $e, $index, $this->env->currentFileInfo );
		}
		return;
	}

	//
	// A catch-all word, such as:
	//
	//	 black border-collapse
	//
	private function parseEntitiesKeyword(){

		$k = $this->MatchReg('/\\G[_A-Za-z-][_A-Za-z0-9-]*/');
		if( $k ){
			$k = $k[0];
			$color = %BOOTCODE%_Less_Tree_Color::fromKeyword($k);
			if( $color ){
				return $color;
			}
			return new %BOOTCODE%_Less_Tree_Keyword($k);
		}
	}

	//
	// A function call
	//
	//	 rgb(255, 0, 255)
	//
	// We also try to catch IE's `alpha()`, but let the `alpha` parser
	// deal with the details.
	//
	// The arguments are parsed with the `entities.arguments` parser.
	//
	private function parseEntitiesCall(){
		$index = $this->pos;

		if( !preg_match('/\\G([\w-]+|%|progid:[\w\.]+)\(/', $this->input, $name,0,$this->pos) ){
			return;
		}
		$name = $name[1];
		$nameLC = strtolower($name);

		if ($nameLC === 'url') {
			return null;
		}

		$this->pos += strlen($name);

		if( $nameLC === 'alpha' ){
			$alpha_ret = $this->parseAlpha();
			if( $alpha_ret ){
				return $alpha_ret;
			}
		}

		$this->MatchChar('('); // Parse the '(' and consume whitespace.

		$args = $this->parseEntitiesArguments();

		if( !$this->MatchChar(')') ){
			return;
		}

		if ($name) {
			return new %BOOTCODE%_Less_Tree_Call($name, $args, $index, $this->env->currentFileInfo );
		}
	}

	/**
	 * Parse a list of arguments
	 *
	 * @return array
	 */
	private function parseEntitiesArguments(){

		$args = array();
		while( true ){
			$arg = $this->MatchFuncs( array('parseEntitiesAssignment','parseExpression') );
			if( !$arg ){
				break;
			}

			$args[] = $arg;
			if( !$this->MatchChar(',') ){
				break;
			}
		}
		return $args;
	}

	private function parseEntitiesLiteral(){
		return $this->MatchFuncs( array('parseEntitiesDimension','parseEntitiesColor','parseEntitiesQuoted','parseUnicodeDescriptor') );
	}

	// Assignments are argument entities for calls.
	// They are present in ie filter properties as shown below.
	//
	//	 filter: progid:DXImageTransform.Microsoft.Alpha( *opacity=50* )
	//
	private function parseEntitiesAssignment() {

		$key = $this->MatchReg('/\\G\w+(?=\s?=)/');
		if( !$key ){
			return;
		}

		if( !$this->MatchChar('=') ){
			return;
		}

		$value = $this->parseEntity();
		if( $value ){
			return new %BOOTCODE%_Less_Tree_Assignment($key[0], $value);
		}
	}

	//
	// Parse url() tokens
	//
	// We use a specific rule for urls, because they don't really behave like
	// standard function calls. The difference is that the argument doesn't have
	// to be enclosed within a string, so it can't be parsed as an Expression.
	//
	private function parseEntitiesUrl(){


		if( $this->input[$this->pos] !== 'u' || !$this->matchReg('/\\Gurl\(/') ){
			return;
		}

		$value = $this->match( array('parseEntitiesQuoted','parseEntitiesVariable','/\\G(?:(?:\\\\[\(\)\'"])|[^\(\)\'"])+/') );
		if( !$value ){
			$value = '';
		}


		$this->expectChar(')');


		return new %BOOTCODE%_Less_Tree_Url((isset($value->value) || $value instanceof %BOOTCODE%_Less_Tree_Variable)
							? $value : new %BOOTCODE%_Less_Tree_Anonymous($value), $this->env->currentFileInfo );
	}


	//
	// A Variable entity, such as `@fink`, in
	//
	//	 width: @fink + 2px
	//
	// We use a different parser for variable definitions,
	// see `parsers.variable`.
	//
	private function parseEntitiesVariable(){
		$index = $this->pos;
		if ($this->PeekChar('@') && ($name = $this->MatchReg('/\\G@@?[\w-]+/'))) {
			return new %BOOTCODE%_Less_Tree_Variable($name[0], $index, $this->env->currentFileInfo);
		}
	}


	// A variable entity useing the protective {} e.g. @{var}
	private function parseEntitiesVariableCurly() {
		$index = $this->pos;

		if( $this->input_len > ($this->pos+1) && $this->input[$this->pos] === '@' && ($curly = $this->MatchReg('/\\G@\{([\w-]+)\}/')) ){
			return new %BOOTCODE%_Less_Tree_Variable('@'.$curly[1], $index, $this->env->currentFileInfo);
		}
	}

	//
	// A Hexadecimal color
	//
	//	 #4F3C2F
	//
	// `rgb` and `hsl` colors are parsed through the `entities.call` parser.
	//
	private function parseEntitiesColor(){
		if ($this->PeekChar('#') && ($rgb = $this->MatchReg('/\\G#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})/'))) {
			return new %BOOTCODE%_Less_Tree_Color($rgb[1]);
		}
	}

	//
	// A Dimension, that is, a number and a unit
	//
	//	 0.5em 95%
	//
	private function parseEntitiesDimension(){

		$c = @ord($this->input[$this->pos]);

		//Is the first char of the dimension 0-9, '.', '+' or '-'
		if (($c > 57 || $c < 43) || $c === 47 || $c == 44){
			return;
		}

		$value = $this->MatchReg('/\\G([+-]?\d*\.?\d+)(%|[a-z]+)?/');
		if( $value ){
			return new %BOOTCODE%_Less_Tree_Dimension($value[1], isset($value[2]) ? $value[2] : null);
		}
	}


	//
	// A unicode descriptor, as is used in unicode-range
	//
	// U+0?? or U+00A1-00A9
	//
	function parseUnicodeDescriptor() {
		$ud = $this->MatchReg('/\\G(U\+[0-9a-fA-F?]+)(\-[0-9a-fA-F?]+)?/');
		if( $ud ){
			return new %BOOTCODE%_Less_Tree_UnicodeDescriptor($ud[0]);
		}
	}


	//
	// JavaScript code to be evaluated
	//
	//	 `window.location.href`
	//
	private function parseEntitiesJavascript(){
		$e = false;
		$j = $this->pos;
		if( $this->input[$j] === '~' ){
			$j++;
			$e = true;
		}
		if( $this->input[$j] !== '`' ){
			return;
		}
		if( $e ){
			$this->MatchChar('~');
		}
		$str = $this->MatchReg('/\\G`([^`]*)`/');
		if( $str ){
			return new %BOOTCODE%_Less_Tree_Javascript($str[1], $this->pos, $e);
		}
	}


	//
	// The variable part of a variable definition. Used in the `rule` parser
	//
	//	 @fink:
	//
	private function parseVariable(){
		if ($this->PeekChar('@') && ($name = $this->MatchReg('/\\G(@[\w-]+)\s*:/'))) {
			return $name[1];
		}
	}

	//
	// extend syntax - used to extend selectors
	//
	function parseExtend($isRule = false){

		$index = $this->pos;
		$extendList = array();


		if( !$this->MatchReg( $isRule ? '/\\G&:extend\(/' : '/\\G:extend\(/' ) ){ return; }

		do{
			$option = null;
			$elements = array();
			while( true ){
				$option = $this->MatchReg('/\\G(all)(?=\s*(\)|,))/');
				if( $option ){ break; }
				$e = $this->parseElement();
				if( !$e ){ break; }
				$elements[] = $e;
			}

			if( $option ){
				$option = $option[1];
			}

			$extendList[] = new %BOOTCODE%_Less_Tree_Extend( new %BOOTCODE%_Less_Tree_Selector($elements), $option, $index );

		}while( $this->MatchChar(",") );

		$this->expect('/\\G\)/');

		if( $isRule ){
			$this->expect('/\\G;/');
		}

		return $extendList;
	}

	function parseExtendRule(){
		return $this->parseExtend(true);
	}


	//
	// A Mixin call, with an optional argument list
	//
	//	 #mixins > .square(#fff);
	//	 .rounded(4px, black);
	//	 .button;
	//
	// The `while` loop is there because mixins can be
	// namespaced, but we only support the child and descendant
	// selector for now.
	//
	private function parseMixinCall(){
		$elements = array();
		$index = $this->pos;
		$important = false;
		$args = null;
		$c = null;

		$char = $this->input[$this->pos];
		if( $char !== '.' && $char !== '#' ){
			return;
		}

		$this->save(); // stop us absorbing part of an invalid selector

		while( true ){
			$e = $this->MatchReg('/\\G[#.](?:[\w-]|\\\\(?:[A-Fa-f0-9]{1,6} ?|[^A-Fa-f0-9]))+/');
			if( !$e ){
				break;
			}
			$elements[] = new %BOOTCODE%_Less_Tree_Element($c, $e[0], $this->pos, $this->env->currentFileInfo);
			$c = $this->MatchChar('>');
		}

		if( $this->MatchChar('(') ){
			$returned = $this->parseMixinArgs(true);
			$args = $returned['args'];
			$this->expectChar(')');
		}

		if( !$args ){
			$args = array();
		}

		if( $this->parseImportant() ){
			$important = true;
		}

		if( $elements && ($this->MatchChar(';') || $this->PeekChar('}')) ){
			return new %BOOTCODE%_Less_Tree_MixinCall($elements, $args, $index, $this->env->currentFileInfo, $important);
		}

		$this->restore();
	}


	private function parseMixinArgs( $isCall ){
		$expressions = array();
		$argsSemiColon = array();
		$isSemiColonSeperated = null;
		$argsComma = array();
		$expressionContainsNamed = null;
		$name = null;
		$nameLoop = null;
		$returner = array('args'=>null, 'variadic'=> false);

		while( true ){
			if( $isCall ){
				$arg = $this->parseExpression();
			} else {
				$this->parseComments();
				if( $this->input[ $this->pos ] === '.' && $this->MatchReg('/\\G\.{3}/') ){
					$returner['variadic'] = true;
					if( $this->MatchChar(";") && !$isSemiColonSeperated ){
						$isSemiColonSeperated = true;
					}

					if( $isSemiColonSeperated ){
						$argsSemiColon[] = array('variadic'=>true);
					}else{
						$argsComma[] = array('variadic'=>true);
					}
					break;
				}
				$arg = $this->MatchFuncs( array('parseEntitiesVariable','parseEntitiesLiteral','parseEntitiesKeyword') );
			}


			if( !$arg ){
				break;
			}


			$nameLoop = null;
			if( $arg instanceof %BOOTCODE%_Less_Tree_Expression ){
				$arg->throwAwayComments();
			}
			$value = $arg;
			$val = null;

			if( $isCall ){
				// Variable
				if( count($arg->value) == 1 ){
					$val = $arg->value[0];
				}
			} else {
				$val = $arg;
			}


			if( $val && $val instanceof %BOOTCODE%_Less_Tree_Variable ){

				if( $this->MatchChar(':') ){
					if( $expressions ){
						if( $isSemiColonSeperated ){
							throw new %BOOTCODE%_Less_ParserException('Cannot mix ; and , as delimiter types');
						}
						$expressionContainsNamed = true;
					}
					$value = $this->expect('parseExpression');
					$nameLoop = ($name = $val->name);
				}elseif( !$isCall && $this->MatchReg('/\\G\.{3}/') ){
					$returner['variadic'] = true;
					if( $this->MatchChar(";") && !$isSemiColonSeperated ){
						$isSemiColonSeperated = true;
					}
					if( $isSemiColonSeperated ){
						$argsSemiColon[] = array('name'=> $arg->name, 'variadic' => true);
					}else{
						$argsComma[] = array('name'=> $arg->name, 'variadic' => true);
					}
					break;
				}elseif( !$isCall ){
					$name = $nameLoop = $val->name;
					$value = null;
				}
			}

			if( $value ){
				$expressions[] = $value;
			}

			$argsComma[] = array('name'=>$nameLoop, 'value'=>$value );

			if( $this->MatchChar(',') ){
				continue;
			}

			if( $this->MatchChar(';') || $isSemiColonSeperated ){

				if( $expressionContainsNamed ){
					throw new %BOOTCODE%_Less_ParserException('Cannot mix ; and , as delimiter types');
				}

				$isSemiColonSeperated = true;

				if( count($expressions) > 1 ){
					$value = new %BOOTCODE%_Less_Tree_Value($expressions);
				}
				$argsSemiColon[] = array('name'=>$name, 'value'=>$value );

				$name = null;
				$expressions = array();
				$expressionContainsNamed = false;
			}
		}

		$returner['args'] = ($isSemiColonSeperated ? $argsSemiColon : $argsComma);
		return $returner;
	}


	//
	// A Mixin definition, with a list of parameters
	//
	//	 .rounded (@radius: 2px, @color) {
	//		...
	//	 }
	//
	// Until we have a finer grained state-machine, we have to
	// do a look-ahead, to make sure we don't have a mixin call.
	// See the `rule` function for more information.
	//
	// We start by matching `.rounded (`, and then proceed on to
	// the argument list, which has optional default values.
	// We store the parameters in `params`, with a `value` key,
	// if there is a value, such as in the case of `@radius`.
	//
	// Once we've got our params list, and a closing `)`, we parse
	// the `{...}` block.
	//
	private function parseMixinDefinition(){
		$params = array();
		$variadic = false;
		$cond = null;

		$char = $this->input[$this->pos];
		if( ($char !== '.' && $char !== '#') || ($char === '{' && $this->Peek('/\\G[^{]*\}/')) ){
			return;
		}

		$this->save();

		$match = $this->MatchReg('/\\G([#.](?:[\w-]|\\\(?:[A-Fa-f0-9]{1,6} ?|[^A-Fa-f0-9]))+)\s*\(/');
		if( $match ){
			$name = $match[1];

			$argInfo = $this->parseMixinArgs( false );
			$params = $argInfo['args'];
			$variadic = $argInfo['variadic'];


			// .mixincall("@{a}");
			// looks a bit like a mixin definition.. so we have to be nice and restore
			if( !$this->MatchChar(')') ){
				//furthest = i;
				$this->restore();
			}

			$this->parseComments();

			if ($this->MatchReg('/\\Gwhen/')) { // Guard
				$cond = $this->expect('parseConditions', 'Expected conditions');
			}

			$ruleset = $this->parseBlock();

			if( is_array($ruleset) ){
				return new %BOOTCODE%_Less_Tree_MixinDefinition($name, $params, $ruleset, $cond, $variadic);
			} else {
				$this->restore();
			}
		}
	}

	//
	// Entities are the smallest recognized token,
	// and can be found inside a rule's value.
	//
	private function parseEntity(){

		return $this->MatchFuncs( array('parseEntitiesLiteral','parseEntitiesVariable','parseEntitiesUrl','parseEntitiesCall','parseEntitiesKeyword','parseEntitiesJavascript','parseComment') );
	}

	//
	// A Rule terminator. Note that we use `peek()` to check for '}',
	// because the `block` rule will be expecting it, but we still need to make sure
	// it's there, if ';' was ommitted.
	//
	private function parseEnd()
	{
		return ($end = $this->MatchChar(';') ) ? $end : $this->PeekChar('}');
	}

	//
	// IE's alpha function
	//
	//	 alpha(opacity=88)
	//
	private function parseAlpha(){

		if ( ! $this->MatchReg('/\\G\(opacity=/i')) {
			return;
		}

		$value = $this->MatchReg('/\\G[0-9]+/');
		if( $value ){
			$value = $value[0];
		}else{
			$value = $this->parseEntitiesVariable();
			if( !$value ){
				return;
			}
		}

		$this->expectChar(')');
		return new %BOOTCODE%_Less_Tree_Alpha($value);
	}


	//
	// A Selector Element
	//
	//	 div
	//	 + h1
	//	 #socks
	//	 input[type="text"]
	//
	// Elements are the building blocks for Selectors,
	// they are made out of a `Combinator` (see combinator rule),
	// and an element name, such as a tag a class, or `*`.
	//
	private function parseElement(){
		$c = $this->parseCombinator();

		$e = $this->match( array('/\\G(?:\d+\.\d+|\d+)%/', '/\\G(?:[.#]?|:*)(?:[\w-]|[^\x00-\x9f]|\\\\(?:[A-Fa-f0-9]{1,6} ?|[^A-Fa-f0-9]))+/',
			'#*', '#&', 'parseAttribute', '/\\G\([^()@]+\)/', '/\\G[\.#](?=@)/', 'parseEntitiesVariableCurly') );

		if( !$e ){
			if( $this->MatchChar('(') ){
				if( ($v = $this->parseSelector()) && $this->MatchChar(')') ){
					$e = new %BOOTCODE%_Less_Tree_Paren($v);
				}
			}
		}

		if ($e) {
			return new %BOOTCODE%_Less_Tree_Element($c, $e, $this->pos, $this->env->currentFileInfo);
		}
	}

	//
	// Combinators combine elements together, in a Selector.
	//
	// Because our parser isn't white-space sensitive, special care
	// has to be taken, when parsing the descendant combinator, ` `,
	// as it's an empty space. We have to check the previous character
	// in the input, to see if it's a ` ` character.
	//
	private function parseCombinator(){
		$c = $this->input[$this->pos];
		if ($c === '>' || $c === '+' || $c === '~' || $c === '|') {

			$this->skipWhitespace(1);

			return new %BOOTCODE%_Less_Tree_Combinator($c);
		}elseif( $this->pos > 0 && $this->isWhitespace(-1) ){
			return new %BOOTCODE%_Less_Tree_Combinator(' ');
		} else {
			return new %BOOTCODE%_Less_Tree_Combinator();
		}
	}

	//
	// A CSS selector (see selector below)
	// with less extensions e.g. the ability to extend and guard
	//
	private function parseLessSelector(){
		return $this->parseSelector(true);
	}

	//
	// A CSS Selector
	//
	//	 .class > div + h1
	//	 li a:hover
	//
	// Selectors are made out of one or more Elements, see above.
	//
	private function parseSelector( $isLess = false ){
		$elements = array();
		$extendList = array();
		$condition = null;
		$when = false;
		$extend = false;

		while( ($isLess && ($extend = $this->parseExtend())) || ($isLess && ($when = $this->MatchReg('/\\Gwhen/') )) || ($e = $this->parseElement()) ){
			if( $when ){
				$condition = $this->expect('parseConditions', 'expected condition');
			}elseif( $condition ){
				//error("CSS guard can only be used at the end of selector");
			}elseif( $extend ){
				$extendList = array_merge($extendList,$extend);
			}else{
				//if( count($extendList) ){
					//error("Extend can only be used at the end of selector");
				//}
				$c = $this->input[ $this->pos ];
				$elements[] = $e;
				$e = null;
			}

			if( $c === '{' || $c === '}' || $c === ';' || $c === ',' || $c === ')') { break; }
		}

		if( $elements ){
			return new %BOOTCODE%_Less_Tree_Selector( $elements, $extendList, $condition, $this->pos, $this->env->currentFileInfo);
		}
		if( $extendList ) { throw new %BOOTCODE%_Less_ParserException('Extend must be used to extend a selector, it cannot be used on its own'); }
	}

	private function parseTag(){
		return ( $tag = $this->MatchReg('/\\G[A-Za-z][A-Za-z-]*[0-9]?/') ) ? $tag : $this->MatchChar('*');
	}

	private function parseAttribute(){

		$val = null;
		$op = null;

		if( !$this->MatchChar('[') ){
			return;
		}

		$key = $this->parseEntitiesVariableCurly();
		if( !$key ){
			$key = $this->expect('/\\G(?:[_A-Za-z0-9-\*]*\|)?(?:[_A-Za-z0-9-]|\\\\.)+/');
		}

		$op = $this->MatchReg('/\\G[|~*$^]?=/');
		if( $op ){
			$val = $this->match( array('parseEntitiesQuoted','/\\G[0-9]+%/','/\\G[\w-]+/','parseEntitiesVariableCurly') );
		}

		$this->expectChar(']');

		return new %BOOTCODE%_Less_Tree_Attribute($key, $op[0], $val);
	}

	//
	// The `block` rule is used by `ruleset` and `mixin.definition`.
	// It's a wrapper around the `primary` rule, with added `{}`.
	//
	private function parseBlock(){
		if ($this->MatchChar('{') && (is_array($content = $this->parsePrimary())) && $this->MatchChar('}')) {
			return $content;
		}
	}

	//
	// div, .class, body > p {...}
	//
	private function parseRuleset(){
		$selectors = array();
		$start = $this->pos;

		while( true ){
			$s = $this->parseLessSelector();
			if( !$s ){
				break;
			}
			$selectors[] = $s;
			$this->parseComments();
			if( !$this->MatchChar(',') ){
				break;
			}
			if( $s->condition ){
				//error("Guards are only currently allowed on a single selector.");
			}
			$this->parseComments();
		}


		if( $selectors && (is_array($rules = $this->parseBlock())) ){
			return new %BOOTCODE%_Less_Tree_Ruleset($selectors, $rules, $this->env->strictImports);
		} else {
			// Backtrack
			$this->pos = $start;
		}
	}


	private function parseRule( $tryAnonymous = null ){
		$merge = false;
		$start = $this->pos;
		$this->save();

		if( isset($this->input[$this->pos]) ){
			$c = $this->input[$this->pos];

			if( $c === '.' || $c === '#' || $c === '&' ){
				return;
			}
		}

		if( $name = $this->MatchFuncs( array('parseVariable','parseRuleProperty')) ){


			// prefer to try to parse first if its a variable or we are compressing
			// but always fallback on the other one
			if( !$tryAnonymous && $name[0] === '@' ){
				$value = $this->MatchFuncs( array('parseValue','parseAnonymousValue'));
			}else{
				$value = $this->MatchFuncs( array('parseAnonymousValue','parseValue'));
			}

			$important = $this->parseImportant();

			if( substr($name,-1) === '+' ){
				$merge = true;
				$name = substr($name, 0, -1 );
			}

			if( $value && $this->parseEnd() ){
				return new %BOOTCODE%_Less_Tree_Rule($name, $value, $important[0], $merge, $start, $this->env->currentFileInfo);
			}else{
				$this->restore();
				if( $value && !$tryAnonymous ){
					return $this->parseRule(true);
				}
			}
		}
	}

	function parseAnonymousValue(){

		if( preg_match('/\\G([^@+\/\'"*`(;{}-]*);/',$this->input, $match, 0, $this->pos) ){
			$this->pos += strlen($match[1]);
			return new %BOOTCODE%_Less_Tree_Anonymous($match[1]);
		}
	}

	//
	// An @import directive
	//
	//	 @import "lib";
	//
	// Depending on our environment, importing is done differently:
	// In the browser, it's an XHR request, in Node, it would be a
	// file-system operation. The function used for importing is
	// stored in `import`, which we pass to the Import constructor.
	//
	private function parseImport(){
		$index = $this->pos;

		$this->save();

		$dir = $this->MatchReg('/\\G@import?\s+/');

		$options = array();
		if( $dir ){
			$options = $this->parseImportOptions();
			if( !$options ){
				$options = array();
			}
		}

		if( $dir && ($path = $this->MatchFuncs( array('parseEntitiesQuoted','parseEntitiesUrl'))) ){
			$features = $this->parseMediaFeatures();
			if( $this->MatchChar(';') ){
				if( $features ){
					$features = new %BOOTCODE%_Less_Tree_Value($features);
				}

				return new %BOOTCODE%_Less_Tree_Import($path, $features, $options, $this->pos, $this->env->currentFileInfo );
			}
		}

		$this->restore();
	}

	private function parseImportOptions(){

		$options = array();

		// list of options, surrounded by parens
		if( !$this->MatchChar('(') ){ return null; }
		do{
			$optionName = $this->parseImportOption();
			if( $optionName ){
				$value = true;
				switch( $optionName ){
					case "css":
						$optionName = "less";
						$value = false;
					break;
					case "once":
						$optionName = "multiple";
						$value = false;
					break;
				}
				$options[$optionName] = $value;
				if( !$this->MatchChar(',') ){ break; }
			}
		}while( $optionName );
		$this->expectChar(')');
		return $options;
	}

	private function parseImportOption(){
		$opt = $this->MatchReg('/\\G(less|css|multiple|once|inline|reference)/');
		if( $opt ){
			return $opt[1];
		}
	}

	private function parseMediaFeature() {
		$nodes = array();

		do{
			$e = $this->MatchFuncs(array('parseEntitiesKeyword','parseEntitiesVariable'));
			if( $e ){
				$nodes[] = $e;
			} elseif ($this->MatchChar('(')) {
				$p = $this->parseProperty();
				$e = $this->parseValue();
				if ($this->MatchChar(')')) {
					if ($p && $e) {
						$nodes[] = new %BOOTCODE%_Less_Tree_Paren(new %BOOTCODE%_Less_Tree_Rule($p, $e, null, null, $this->pos, $this->env->currentFileInfo, true));
					} elseif ($e) {
						$nodes[] = new %BOOTCODE%_Less_Tree_Paren($e);
					} else {
						return null;
					}
				} else
					return null;
			}
		} while ($e);

		if ($nodes) {
			return new %BOOTCODE%_Less_Tree_Expression($nodes);
		}
	}

	private function parseMediaFeatures() {
		$features = array();

		do{
			$e = $this->parseMediaFeature();
			if( $e ){
				$features[] = $e;
				if (!$this->MatchChar(',')) break;
			}else{
				$e = $this->parseEntitiesVariable();
				if( $e ){
					$features[] = $e;
					if (!$this->MatchChar(',')) break;
				}
			}
		} while ($e);

		return $features ? $features : null;
	}

	private function parseMedia() {
		if ($this->MatchReg('/\\G@media/')) {
			$features = $this->parseMediaFeatures();

			if ($rules = $this->parseBlock()) {
				return new %BOOTCODE%_Less_Tree_Media($rules, $features, $this->pos, $this->env->currentFileInfo);
			}
		}
	}

	//
	// A CSS Directive
	//
	//	 @charset "utf-8";
	//
	private function parseDirective(){
		$hasBlock = false;
		$hasIdentifier = false;
		$hasExpression = false;

		if (! $this->PeekChar('@')) {
			return;
		}

		$value = $this->MatchFuncs(array('parseImport','parseMedia'));
		if( $value ){
			return $value;
		}

		$this->save();

		$name = $this->MatchReg('/\\G@[a-z-]+/');

		if( !$name ) return;
		$name = $name[0];

		$nonVendorSpecificName = $name;
		$pos = strpos($name,'-', 2);
		if( $name[1] == '-' && $pos > 0 ){
			$nonVendorSpecificName = "@" . substr($name, $pos + 1);
		}

		switch($nonVendorSpecificName) {
			case "@font-face":
				$hasBlock = true;
				break;
			case "@viewport":
			case "@top-left":
			case "@top-left-corner":
			case "@top-center":
			case "@top-right":
			case "@top-right-corner":
			case "@bottom-left":
			case "@bottom-left-corner":
			case "@bottom-center":
			case "@bottom-right":
			case "@bottom-right-corner":
			case "@left-top":
			case "@left-middle":
			case "@left-bottom":
			case "@right-top":
			case "@right-middle":
			case "@right-bottom":
				$hasBlock = true;
				break;
			case "@host":
			case "@page":
			case "@document":
			case "@supports":
			case "@keyframes":
				$hasBlock = true;
				$hasIdentifier = true;
				break;
			case "@namespace":
				$hasExpression = true;
				break;
		}

		if( $hasIdentifier ){
			$identifier = $this->MatchReg('/\\G[^{]+/');
			if( $identifier ){
				$name .= " " .trim($identifier[0]);
			}
		}


		if( $hasBlock ){

			if ($rules = $this->parseBlock()) {
				return new %BOOTCODE%_Less_Tree_Directive($name, $rules, $this->pos, $this->env->currentFileInfo);
			}
		}else{
			$value = $hasExpression ? $this->parseExpression() : $this->parseEntity();
			if( $value && $this->MatchChar(';') ){
				return new %BOOTCODE%_Less_Tree_Directive($name, $value, $this->pos, $this->env->currentFileInfo);
			}
		}

		$this->restore();
	}


	//
	// A Value is a comma-delimited list of Expressions
	//
	//	 font-family: Baskerville, Georgia, serif;
	//
	// In a Rule, a Value represents everything after the `:`,
	// and before the `;`.
	//
	private function parseValue(){
		$expressions = array();

		do{
			$e = $this->parseExpression();
			if( $e ){
				$expressions[] = $e;
				if (! $this->MatchChar(',')) {
					break;
				}
			}
		}while($e);

		if( $expressions ){
			return new %BOOTCODE%_Less_Tree_Value($expressions);
		}
	}

	private function parseImportant (){
		if ($this->PeekChar('!')) {
			return $this->MatchReg('/\\G! *important/');
		}
	}

	private function parseSub (){

		if( $this->MatchChar('(') ){
			if( $a = $this->parseAddition() ){
				$e = new %BOOTCODE%_Less_Tree_Expression( array($a) );
				$this->expectChar(')');
				$e->parens = true;
				return $e;
			}
		}
	}

	function parseMultiplication(){
		$operation = false;
		$m = $this->parseOperand();
		if( $m ){
			$isSpaced = $this->isWhitespace( -1 );
			while( true ){

				if( $this->PeekReg('/\\G\/[*\/]/') ){
					break;
				}

				$op = $this->MatchChar('/');
				if( !$op ){
					$op = $this->MatchChar('*');
					if( !$op ){
						break;
					}
				}

				$a = $this->parseOperand();

				if(!$a) { break; }

				$m->parensInOp = true;
				$a->parensInOp = true;
				$operation = new %BOOTCODE%_Less_Tree_Operation( $op, array( $operation ? $operation : $m, $a ), $isSpaced );
				$isSpaced = $this->isWhitespace( -1 );
			}
			return ($operation ? $operation : $m);
		}
	}

	private function parseAddition (){
		$operation = false;
		$m = $this->parseMultiplication();
		if( $m ){
			$isSpaced = $this->isWhitespace( -1 );

			while( true ){
				$op = $this->MatchReg('/\\G[-+]\s+/');
				if( $op ){
					$op = $op[0];
				}elseif( !$isSpaced ){
					$op = $this->match(array('#+','#-'));
				}
				if( !$op ){
					break;
				}

				$a = $this->parseMultiplication();
				if( !$a ){
					break;
				}

				$m->parensInOp = true;
				$a->parensInOp = true;
				$operation = new %BOOTCODE%_Less_Tree_Operation($op, array($operation ? $operation : $m, $a), $isSpaced);
				$isSpaced = $this->isWhitespace( -1 );
			}
			return $operation ? $operation : $m;
		}
	}

	private function parseConditions() {
		$index = $this->pos;
		$condition = null;
		$a = $this->parseCondition();
		if( $a ){
			while( $this->PeekReg('/\\G,\s*(not\s*)?\(/') && $this->MatchChar(',') ){
				$b = $this->parseCondition();
				if( !$b ){
					break;
				}

				$condition = new %BOOTCODE%_Less_Tree_Condition('or', $condition ? $condition : $a, $b, $index);
			}
			return $condition ? $condition : $a;
		}
	}

	private function parseCondition() {
		$index = $this->pos;
		$negate = false;


		if ($this->MatchReg('/\\Gnot/')) $negate = true;
		$this->expectChar('(');
		$a = $this->MatchFuncs(array('parseAddition','parseEntitiesKeyword','parseEntitiesQuoted'));

		if( $a ){
			$op = $this->MatchReg('/\\G(?:>=|<=|=<|[<=>])/');
			if( $op ){
				$b = $this->MatchFuncs(array('parseAddition','parseEntitiesKeyword','parseEntitiesQuoted'));
				if( $b ){
					$c = new %BOOTCODE%_Less_Tree_Condition($op[0], $a, $b, $index, $negate);
				} else {
					throw new %BOOTCODE%_Less_ParserException('Unexpected expression');
				}
			} else {
				$c = new %BOOTCODE%_Less_Tree_Condition('=', $a, new %BOOTCODE%_Less_Tree_Keyword('true'), $index, $negate);
			}
			$this->expectChar(')');
			return $this->MatchReg('/\\Gand/') ? new %BOOTCODE%_Less_Tree_Condition('and', $c, $this->parseCondition()) : $c;
		}
	}

	//
	// An operand is anything that can be part of an operation,
	// such as a Color, or a Variable
	//
	private function parseOperand (){

		$negate = false;
		$offset = $this->pos+1;
		if( $offset >= $this->input_len ){
			return;
		}
		$char = $this->input[$offset];
		if( $char === '@' || $char === '(' ){
			$negate = $this->MatchChar('-');
		}

		$o = $this->MatchFuncs(array('parseSub','parseEntitiesDimension','parseEntitiesColor','parseEntitiesVariable','parseEntitiesCall'));

		if( $negate ){
			$o->parensInOp = true;
			$o = new %BOOTCODE%_Less_Tree_Negative($o);
		}

		return $o;
	}

	//
	// Expressions either represent mathematical operations,
	// or white-space delimited Entities.
	//
	//	 1px solid black
	//	 @var * 2
	//
	private function parseExpression (){
		$entities = array();

		do{
			$e = $this->MatchFuncs(array('parseAddition','parseEntity'));
			if( $e ){
				$entities[] = $e;
				// operations do not allow keyword "/" dimension (e.g. small/20px) so we support that here
				if( !$this->PeekReg('/\\G\/[\/*]/') ){
					$delim = $this->MatchChar('/');
					if( $delim ){
						$entities[] = new %BOOTCODE%_Less_Tree_Anonymous($delim);
					}
				}
			}
		}while($e);

		if( $entities ){
			return new %BOOTCODE%_Less_Tree_Expression($entities);
		}
	}

	private function parseProperty (){
		$name = $this->MatchReg('/\\G(\*?-?[_a-zA-Z0-9-]+)\s*:/');
		if( $name ){
			return $name[1];
		}
	}

	private function parseRuleProperty(){
		$name = $this->MatchReg('/\\G(\*?-?[_a-zA-Z0-9-]+)\s*(\+?)\s*:/');
		if( $name ){
			return $name[1] . (isset($name[2]) ? $name[2] : '');
		}
	}

	/**
	 * Some versions of php have trouble with method_exists($a,$b) if $a is not an object
	 *
	 */
	public static function is_method($a,$b){
		return is_object($a) && method_exists($a,$b);
	}

	/**
	 *
	 * Round 1.499999 to 1 instead of 2
	 *
	 */
	public static function round($i, $precision = 0){

		$precision = pow(10,$precision);
		$i = $i*$precision;

		$ceil = ceil($i);
		$floor = floor($i);
		if( ($ceil - $i) <= ($i - $floor) ){
			return $ceil/$precision;
		}else{
			return $floor/$precision;
		}
	}

}




//less.js : lib/less/colors.js

class %BOOTCODE%_Less_Colors {

	public static $colors;

	private static function all() {
		if (self::$colors)
			return self::$colors;

		self::$colors = array(
			'aliceblue'=>'#f0f8ff',
			'antiquewhite'=>'#faebd7',
			'aqua'=>'#00ffff',
			'aquamarine'=>'#7fffd4',
			'azure'=>'#f0ffff',
			'beige'=>'#f5f5dc',
			'bisque'=>'#ffe4c4',
			'black'=>'#000000',
			'blanchedalmond'=>'#ffebcd',
			'blue'=>'#0000ff',
			'blueviolet'=>'#8a2be2',
			'brown'=>'#a52a2a',
			'burlywood'=>'#deb887',
			'cadetblue'=>'#5f9ea0',
			'chartreuse'=>'#7fff00',
			'chocolate'=>'#d2691e',
			'coral'=>'#ff7f50',
			'cornflowerblue'=>'#6495ed',
			'cornsilk'=>'#fff8dc',
			'crimson'=>'#dc143c',
			'cyan'=>'#00ffff',
			'darkblue'=>'#00008b',
			'darkcyan'=>'#008b8b',
			'darkgoldenrod'=>'#b8860b',
			'darkgray'=>'#a9a9a9',
			'darkgrey'=>'#a9a9a9',
			'darkgreen'=>'#006400',
			'darkkhaki'=>'#bdb76b',
			'darkmagenta'=>'#8b008b',
			'darkolivegreen'=>'#556b2f',
			'darkorange'=>'#ff8c00',
			'darkorchid'=>'#9932cc',
			'darkred'=>'#8b0000',
			'darksalmon'=>'#e9967a',
			'darkseagreen'=>'#8fbc8f',
			'darkslateblue'=>'#483d8b',
			'darkslategray'=>'#2f4f4f',
			'darkslategrey'=>'#2f4f4f',
			'darkturquoise'=>'#00ced1',
			'darkviolet'=>'#9400d3',
			'deeppink'=>'#ff1493',
			'deepskyblue'=>'#00bfff',
			'dimgray'=>'#696969',
			'dimgrey'=>'#696969',
			'dodgerblue'=>'#1e90ff',
			'firebrick'=>'#b22222',
			'floralwhite'=>'#fffaf0',
			'forestgreen'=>'#228b22',
			'fuchsia'=>'#ff00ff',
			'gainsboro'=>'#dcdcdc',
			'ghostwhite'=>'#f8f8ff',
			'gold'=>'#ffd700',
			'goldenrod'=>'#daa520',
			'gray'=>'#808080',
			'grey'=>'#808080',
			'green'=>'#008000',
			'greenyellow'=>'#adff2f',
			'honeydew'=>'#f0fff0',
			'hotpink'=>'#ff69b4',
			'indianred'=>'#cd5c5c',
			'indigo'=>'#4b0082',
			'ivory'=>'#fffff0',
			'khaki'=>'#f0e68c',
			'lavender'=>'#e6e6fa',
			'lavenderblush'=>'#fff0f5',
			'lawngreen'=>'#7cfc00',
			'lemonchiffon'=>'#fffacd',
			'lightblue'=>'#add8e6',
			'lightcoral'=>'#f08080',
			'lightcyan'=>'#e0ffff',
			'lightgoldenrodyellow'=>'#fafad2',
			'lightgray'=>'#d3d3d3',
			'lightgrey'=>'#d3d3d3',
			'lightgreen'=>'#90ee90',
			'lightpink'=>'#ffb6c1',
			'lightsalmon'=>'#ffa07a',
			'lightseagreen'=>'#20b2aa',
			'lightskyblue'=>'#87cefa',
			'lightslategray'=>'#778899',
			'lightslategrey'=>'#778899',
			'lightsteelblue'=>'#b0c4de',
			'lightyellow'=>'#ffffe0',
			'lime'=>'#00ff00',
			'limegreen'=>'#32cd32',
			'linen'=>'#faf0e6',
			'magenta'=>'#ff00ff',
			'maroon'=>'#800000',
			'mediumaquamarine'=>'#66cdaa',
			'mediumblue'=>'#0000cd',
			'mediumorchid'=>'#ba55d3',
			'mediumpurple'=>'#9370d8',
			'mediumseagreen'=>'#3cb371',
			'mediumslateblue'=>'#7b68ee',
			'mediumspringgreen'=>'#00fa9a',
			'mediumturquoise'=>'#48d1cc',
			'mediumvioletred'=>'#c71585',
			'midnightblue'=>'#191970',
			'mintcream'=>'#f5fffa',
			'mistyrose'=>'#ffe4e1',
			'moccasin'=>'#ffe4b5',
			'navajowhite'=>'#ffdead',
			'navy'=>'#000080',
			'oldlace'=>'#fdf5e6',
			'olive'=>'#808000',
			'olivedrab'=>'#6b8e23',
			'orange'=>'#ffa500',
			'orangered'=>'#ff4500',
			'orchid'=>'#da70d6',
			'palegoldenrod'=>'#eee8aa',
			'palegreen'=>'#98fb98',
			'paleturquoise'=>'#afeeee',
			'palevioletred'=>'#d87093',
			'papayawhip'=>'#ffefd5',
			'peachpuff'=>'#ffdab9',
			'peru'=>'#cd853f',
			'pink'=>'#ffc0cb',
			'plum'=>'#dda0dd',
			'powderblue'=>'#b0e0e6',
			'purple'=>'#800080',
			'red'=>'#ff0000',
			'rosybrown'=>'#bc8f8f',
			'royalblue'=>'#4169e1',
			'saddlebrown'=>'#8b4513',
			'salmon'=>'#fa8072',
			'sandybrown'=>'#f4a460',
			'seagreen'=>'#2e8b57',
			'seashell'=>'#fff5ee',
			'sienna'=>'#a0522d',
			'silver'=>'#c0c0c0',
			'skyblue'=>'#87ceeb',
			'slateblue'=>'#6a5acd',
			'slategray'=>'#708090',
			'slategrey'=>'#708090',
			'snow'=>'#fffafa',
			'springgreen'=>'#00ff7f',
			'steelblue'=>'#4682b4',
			'tan'=>'#d2b48c',
			'teal'=>'#008080',
			'thistle'=>'#d8bfd8',
			'tomato'=>'#ff6347',
			'turquoise'=>'#40e0d0',
			'violet'=>'#ee82ee',
			'wheat'=>'#f5deb3',
			'white'=>'#ffffff',
			'whitesmoke'=>'#f5f5f5',
			'yellow'=>'#ffff00',
			'yellowgreen'=>'#9acd32'
		);
		return self::$colors;
	}

	public static function hasOwnProperty($color) {
		$colors = self::all();
		return isset($colors[$color]);
	}


	public static function color($color) {
		$colors = self::all();
		return $colors[$color];
	}

}


//less.js : lib/less/functions.js


class %BOOTCODE%_Less_Environment{

	public $paths = array();			// option - unmodified - paths to search for imports on
	static $files = array();			// list of files that have been imported, used for import-once
	public $relativeUrls;				// option - whether to adjust URL's to be relative
	public $rootpath;					// option - rootpath to append to URL's
	public $strictImports = null;		// option -
	public $insecure;					// option - whether to allow imports from insecure ssl hosts
	public static $compress = false;	// option - whether to compress
	public $processImports;				// option - whether to process imports. if false then imports will not be imported
	public $javascriptEnabled;			// option - whether JavaScript is enabled. if undefined, defaults to true
	public $useFileCache;				// browser only - whether to use the per file session cache
	public $currentFileInfo;			// information about the current file - for error reporting and importing and making urls relative etc.

	/**
	 * @var array
	 */
	public $frames = array();


	/**
	 * @var bool
	 */
	public $debug = false;


	/**
	 * @var array
	 */
	public $mediaBlocks = array();

	/**
	 * @var array
	 */
	public $mediaPath = array();

	public $selectors = array();

	public $charset;

	public $parensStack = array();

	public $strictMath = false;

	public $strictUnits = false;

	public $tabLevel = 0;

	public $lastRule = false;

	public $importMultiple = false;

	//public $type = 'Environment';


	public static $comma_space;
	public static $colon_space;
	public static $firstSelector;

	public function __construct( $options = null ){
		$this->frames = array();


		if( isset($options['compress']) ){
			self::$compress = (bool)$options['compress'];
		}
		if( isset($options['strictUnits']) ){
			$this->strictUnits = (bool)$options['strictUnits'];
		}


		if( self::$compress ){
			self::$comma_space = ',';
			self::$colon_space = ':';
		}else{
			self::$comma_space = ', ';
			self::$colon_space = ': ';
		}


	}


	//may want to just use the __clone()?
	public function copyEvalEnv($frames = array() ){

		$evalCopyProperties = array(
			//'silent',      // whether to swallow errors and warnings
			//'verbose',     // whether to log more activity
			//'yuicompress', // whether to compress with the outside tool yui compressor
			//'ieCompat',    // whether to enforce IE compatibility (IE8 data-uri)
			'strictMath',  // whether math has to be within parenthesis
			'strictUnits', // whether units need to evaluate correctly
			//'cleancss',    // whether to compress with clean-css
			//'sourceMap',   // whether to output a source map
			//'importMultiple'// whether we are currently importing multiple copies
			);

		$new_env = new %BOOTCODE%_Less_Environment();
		foreach($evalCopyProperties as $property){
			$new_env->$property = $this->$property;
		}
		$new_env->frames = $frames;
		return $new_env;
	}

	public function inParenthesis(){
		$this->parensStack[] = true;
	}

	public function outOfParenthesis() {
		array_pop($this->parensStack);
	}

	public function isMathOn() {
		return $this->strictMath ? !!$this->parensStack : true;
	}

	public static function isPathRelative($path){
		return !preg_match('/^(?:[a-z-]+:|\/)/',$path);
	}


	/**
	 * Canonicalize a path by resolving references to '/./', '/../'
	 * Does not remove leading "../"
	 * @param string path or url
	 * @return string Canonicalized path
	 *
	 */
	static function normalizePath($path){

    	$segments = explode('/',$path);
    	$segments = array_reverse($segments);

    	$path = array();
    	$path_len = 0;

		while( $segments ){
			$segment = array_pop($segments);
			switch( $segment ) {

				case '.':
				break;

				case '..':
					if( !$path_len || ( $path[$path_len-1] === '..') ){
						$path[] = $segment;
						$path_len++;
					}else{
						array_pop($path);
						$path_len--;
					}
				break;

				default:
					$path[] = $segment;
					$path_len++;
				break;
			}
		}

		return implode('/',$path);
	}


	/**
	 * @return bool
	 */
	public function getDebug(){
		return $this->debug;
	}

	/**
	 * @param $debug
	 * @return void
	 */
	public function setDebug($debug){
		$this->debug = $debug;
	}

	public function unshiftFrame($frame){
		array_unshift($this->frames, $frame);
	}

	public function shiftFrame(){
		return array_shift($this->frames);
	}

	public function addFrame($frame){
		$this->frames[] = $frame;
	}

	public function addFrames(array $frames){
		$this->frames = array_merge($this->frames, $frames);
	}
}



class %BOOTCODE%_Less_Functions{

	function __construct($env, $currentFileInfo = null ){
		$this->env = $env;
		$this->currentFileInfo = $currentFileInfo;
	}


	//tree.operate()
	static public function operate ($env, $op, $a, $b){
		switch ($op) {
			case '+': return $a + $b;
			case '-': return $a - $b;
			case '*': return $a * $b;
			case '/': return $a / $b;
		}
	}

	static public function clamp($val){
		return min(1, max(0, $val));
	}

	static public function number($n){

		if ($n instanceof %BOOTCODE%_Less_Tree_Dimension) {
			return floatval( $n->unit->is('%') ? $n->value / 100 : $n->value);
		} else if (is_numeric($n)) {
			return $n;
		} else {
			throw new %BOOTCODE%_Less_CompilerException("color functions take numbers as parameters");
		}
	}

	static public function scaled($n, $size = 256 ){
		if( $n instanceof %BOOTCODE%_Less_Tree_Dimension && $n->unit->is('%') ){
			return (float)$n->value * $size / 100;
		} else {
			return %BOOTCODE%_Less_Functions::number($n);
		}
	}

	public function rgb ($r, $g, $b){
		return $this->rgba($r, $g, $b, 1.0);
	}

	public function rgba($r, $g, $b, $a){
		$rgb = array($r, $g, $b);
		$rgb = array_map(array('%BOOTCODE%_Less_Functions','scaled'),$rgb);

		$a = self::number($a);
		return new %BOOTCODE%_Less_Tree_Color($rgb, $a);
	}

	public function hsl($h, $s, $l){
		return $this->hsla($h, $s, $l, 1.0);
	}

	public function hsla($h, $s, $l, $a){

		$h = fmod(self::number($h), 360) / 360; // Classic % operator will change float to int
		$s = self::clamp(self::number($s));
		$l = self::clamp(self::number($l));
		$a = self::clamp(self::number($a));

		$m2 = $l <= 0.5 ? $l * ($s + 1) : $l + $s - $l * $s;

		$m1 = $l * 2 - $m2;

		return $this->rgba( self::hsla_hue($h + 1/3, $m1, $m2) * 255,
							self::hsla_hue($h, $m1, $m2) * 255,
							self::hsla_hue($h - 1/3, $m1, $m2) * 255,
							$a);
	}

	function hsla_hue($h, $m1, $m2){
		$h = $h < 0 ? $h + 1 : ($h > 1 ? $h - 1 : $h);
		if	  ($h * 6 < 1) return $m1 + ($m2 - $m1) * $h * 6;
		else if ($h * 2 < 1) return $m2;
		else if ($h * 3 < 2) return $m1 + ($m2 - $m1) * (2/3 - $h) * 6;
		else				 return $m1;
	}

	public function hsv($h, $s, $v) {
		return $this->hsva($h, $s, $v, 1.0);
	}

	public function hsva($h, $s, $v, $a) {
		$h = ((%BOOTCODE%_Less_Functions::number($h) % 360) / 360 ) * 360;
		$s = %BOOTCODE%_Less_Functions::number($s);
		$v = %BOOTCODE%_Less_Functions::number($v);
		$a = %BOOTCODE%_Less_Functions::number($a);

		$i = floor(($h / 60) % 6);
		$f = ($h / 60) - $i;

		$vs = array( $v,
				  $v * (1 - $s),
				  $v * (1 - $f * $s),
				  $v * (1 - (1 - $f) * $s));

		$perm = array(array(0, 3, 1),
					array(2, 0, 1),
					array(1, 0, 3),
					array(1, 2, 0),
					array(3, 1, 0),
					array(0, 1, 2));

		return $this->rgba($vs[$perm[$i][0]] * 255,
						 $vs[$perm[$i][1]] * 255,
						 $vs[$perm[$i][2]] * 255,
						 $a);
	}

	public function hue($color){
		$c = $color->toHSL();
		return new %BOOTCODE%_Less_Tree_Dimension(%BOOTCODE%_Less_Parser::round($c['h']));
	}

	public function saturation($color){
		$c = $color->toHSL();
		return new %BOOTCODE%_Less_Tree_Dimension(%BOOTCODE%_Less_Parser::round($c['s'] * 100), '%');
	}

	public function lightness($color){
		$c = $color->toHSL();
		return new %BOOTCODE%_Less_Tree_Dimension(%BOOTCODE%_Less_Parser::round($c['l'] * 100), '%');
	}

	public function hsvhue( $color ){
		$hsv = $color->toHSV();
		return new %BOOTCODE%_Less_Tree_Dimension( %BOOTCODE%_Less_Parser::round($hsv['h']) );
	}


	public function hsvsaturation( $color ){
		$hsv = $color->toHSV();
		return new %BOOTCODE%_Less_Tree_Dimension( %BOOTCODE%_Less_Parser::round($hsv['s'] * 100), '%' );
	}

	public function hsvvalue( $color ){
		$hsv = $color->toHSV();
		return new %BOOTCODE%_Less_Tree_Dimension( %BOOTCODE%_Less_Parser::round($hsv['v'] * 100), '%' );
	}

	public function red($color) {
		return new %BOOTCODE%_Less_Tree_Dimension( $color->rgb[0] );
	}

	public function green($color) {
		return new %BOOTCODE%_Less_Tree_Dimension( $color->rgb[1] );
	}

	public function blue($color) {
		return new %BOOTCODE%_Less_Tree_Dimension( $color->rgb[2] );
	}

	public function alpha($color){
		$c = $color->toHSL();
		return new %BOOTCODE%_Less_Tree_Dimension($c['a']);
	}

	public function luma ($color) {
		return new %BOOTCODE%_Less_Tree_Dimension(%BOOTCODE%_Less_Parser::round( $color->luma() * $color->alpha * 100), '%');
	}

	public function saturate($color, $amount = null){
		// filter: saturate(3.2);
		// should be kept as is, so check for color
		if( !property_exists($color,'rgb') ){
			return null;
		}
		$hsl = $color->toHSL();

		$hsl['s'] += $amount->value / 100;
		$hsl['s'] = self::clamp($hsl['s']);

		return $this->hsla($hsl['h'], $hsl['s'], $hsl['l'], $hsl['a']);
	}

	public function desaturate($color, $amount){
		$hsl = $color->toHSL();

		$hsl['s'] -= $amount->value / 100;
		$hsl['s'] = self::clamp($hsl['s']);

		return $this->hsla($hsl['h'], $hsl['s'], $hsl['l'], $hsl['a']);
	}



	public function lighten($color, $amount){
		$hsl = $color->toHSL();

		$hsl['l'] += $amount->value / 100;
		$hsl['l'] = self::clamp($hsl['l']);

		return $this->hsla($hsl['h'], $hsl['s'], $hsl['l'], $hsl['a']);
	}

	public function darken($color, $amount){

		if( $color instanceof %BOOTCODE%_Less_Tree_Color ){
			$hsl = $color->toHSL();
			$hsl['l'] -= $amount->value / 100;
			$hsl['l'] = self::clamp($hsl['l']);

			return $this->hsla($hsl['h'], $hsl['s'], $hsl['l'], $hsl['a']);
		}

		%BOOTCODE%_Less_Functions::Expected('color',$color);
	}

	public function fadein($color, $amount){
		$hsl = $color->toHSL();
		$hsl['a'] += $amount->value / 100;
		$hsl['a'] = self::clamp($hsl['a']);
		return $this->hsla($hsl['h'], $hsl['s'], $hsl['l'], $hsl['a']);
	}

	public function fadeout($color, $amount){
		$hsl = $color->toHSL();
		$hsl['a'] -= $amount->value / 100;
		$hsl['a'] = self::clamp($hsl['a']);
		return $this->hsla($hsl['h'], $hsl['s'], $hsl['l'], $hsl['a']);
	}

	public function fade($color, $amount){
		$hsl = $color->toHSL();

		$hsl['a'] = $amount->value / 100;
		$hsl['a'] = self::clamp($hsl['a']);
		return $this->hsla($hsl['h'], $hsl['s'], $hsl['l'], $hsl['a']);
	}



	public function spin($color, $amount){
		$hsl = $color->toHSL();
		$hue = fmod($hsl['h'] + $amount->value, 360);

		$hsl['h'] = $hue < 0 ? 360 + $hue : $hue;

		return $this->hsla($hsl['h'], $hsl['s'], $hsl['l'], $hsl['a']);
	}

	//
	// Copyright (c) 2006-2009 Hampton Catlin, Nathan Weizenbaum, and Chris Eppstein
	// http://sass-lang.com
	//
	public function mix($color1, $color2, $weight = null){
		if (!$weight) {
			$weight = new %BOOTCODE%_Less_Tree_Dimension('50', '%');
		}

		$p = $weight->value / 100.0;
		$w = $p * 2 - 1;
		$hsl1 = $color1->toHSL();
		$hsl2 = $color2->toHSL();
		$a = $hsl1['a'] - $hsl2['a'];

		$w1 = (((($w * $a) == -1) ? $w : ($w + $a) / (1 + $w * $a)) + 1) / 2;
		$w2 = 1 - $w1;

		$rgb = array($color1->rgb[0] * $w1 + $color2->rgb[0] * $w2,
					 $color1->rgb[1] * $w1 + $color2->rgb[1] * $w2,
					 $color1->rgb[2] * $w1 + $color2->rgb[2] * $w2);

		$alpha = $color1->alpha * $p + $color2->alpha * (1 - $p);

		return new %BOOTCODE%_Less_Tree_Color($rgb, $alpha);
	}

	public function greyscale($color){
		return $this->desaturate($color, new %BOOTCODE%_Less_Tree_Dimension(100));
	}


	public function contrast( $color, $dark = false, $light = false, $threshold = false) {
		// filter: contrast(3.2);
		// should be kept as is, so check for color
		if( !property_exists($color,'rgb') ){
			return null;
		}
		if( $light === false ){
			$light = $this->rgba(255, 255, 255, 1.0);
		}
		if( $dark === false ){
			$dark = $this->rgba(0, 0, 0, 1.0);
		}
		//Figure out which is actually light and dark!
		if( $dark->luma() > $light->luma() ){
			$t = $light;
			$light = $dark;
			$dark = $t;
		}
		if( $threshold === false ){
			$threshold = 0.43;
		} else {
			$threshold = %BOOTCODE%_Less_Functions::number($threshold);
		}

		if( ($color->luma() * $color->alpha) < $threshold ){
			return $light;
		} else {
			return $dark;
		}
	}

	public function e ($str){
		return new %BOOTCODE%_Less_Tree_Anonymous($str instanceof %BOOTCODE%_Less_Tree_JavaScript ? $str->evaluated : $str);
	}

	public function escape ($str){

		$revert = array('%21'=>'!', '%2A'=>'*', '%27'=>"'",'%3F'=>'?','%26'=>'&','%2C'=>',','%2F'=>'/','%40'=>'@','%2B'=>'+','%24'=>'$');

		return new %BOOTCODE%_Less_Tree_Anonymous(strtr(rawurlencode($str->value), $revert));
	}


	public function _percent(){
		$quoted = func_get_arg(0);

		$args = func_get_args();
		array_shift($args);
		$str = $quoted->value;

		foreach($args as $arg){
			if( preg_match('/%[sda]/i',$str, $token) ){
				$token = $token[0];
				$value = stristr($token, 's') ? $arg->value : $arg->toCSS();
				$value = preg_match('/[A-Z]$/', $token) ? urlencode($value) : $value;
				$str = preg_replace('/%[sda]/i',$value, $str, 1);
			}
		}
		$str = str_replace('%%', '%', $str);

		return new %BOOTCODE%_Less_Tree_Quoted('"' . $str . '"', $str);
	}

	public function unit($val, $unit = null ){
		if( !($val instanceof %BOOTCODE%_Less_Tree_Dimension) ){
			throw new %BOOTCODE%_Less_CompilerException('The first argument to unit must be a number' . ($val instanceof %BOOTCODE%_Less_Tree_Operation ? '. Have you forgotten parenthesis?' : '.') );
		}
		return new %BOOTCODE%_Less_Tree_Dimension($val->value, $unit ? $unit->toCSS() : "");
	}

	public function convert($val, $unit){
		return $val->convertTo($unit->value);
	}

	public function round($n, $f = false) {

		$fraction = 0;
		if( $f !== false ){
			$fraction = $f->value;
		}

		return $this->_math('%BOOTCODE%_Less_Parser::round',null, $n, $fraction);
	}

	public function pi(){
		return new %BOOTCODE%_Less_Tree_Dimension(M_PI);
	}

	public function mod($a, $b) {
		return new %BOOTCODE%_Less_Tree_Dimension( $a->value % $b->value, $a->unit);
	}



	public function pow($x, $y) {
		if( is_numeric($x) && is_numeric($y) ){
			$x = new %BOOTCODE%_Less_Tree_Dimension($x);
			$y = new %BOOTCODE%_Less_Tree_Dimension($y);
		}elseif( !($x instanceof %BOOTCODE%_Less_Tree_Dimension) || !($y instanceof %BOOTCODE%_Less_Tree_Dimension) ){
			throw new %BOOTCODE%_Less_CompilerException('Arguments must be numbers');
		}

		return new %BOOTCODE%_Less_Tree_Dimension( pow($x->value, $y->value), $x->unit );
	}

	// var mathFunctions = [{name:"ce ...
	public function ceil( $n ){		return $this->_math('ceil', null, $n); }
	public function floor( $n ){	return $this->_math('floor', null, $n); }
	public function sqrt( $n ){		return $this->_math('sqrt', null, $n); }
	public function abs( $n ){		return $this->_math('abs', null, $n); }

	public function tan( $n ){		return $this->_math('tan', '', $n);	}
	public function sin( $n ){		return $this->_math('sin', '', $n);	}
	public function cos( $n ){		return $this->_math('cos', '', $n);	}

	public function atan( $n ){		return $this->_math('atan', 'rad', $n);	}
	public function asin( $n ){		return $this->_math('asin', 'rad', $n);	}
	public function acos( $n ){		return $this->_math('acos', 'rad', $n);	}

	private function _math() {
		$args = func_get_args();
		$fn = array_shift($args);
		$unit = array_shift($args);

		if ($args[0] instanceof %BOOTCODE%_Less_Tree_Dimension) {

			if( $unit === null ){
				$unit = $args[0]->unit;
			}else{
				$args[0] = $args[0]->unify();
			}
			$args[0] = (float)$args[0]->value;
			return new %BOOTCODE%_Less_Tree_Dimension( call_user_func_array($fn, $args), $unit);
		} else if (is_numeric($args[0])) {
			return call_user_func_array($fn,$args);
		} else {
			throw new %BOOTCODE%_Less_CompilerException("math functions take numbers as parameters");
		}
	}

	function _minmax( $isMin, $args ){

		switch( count($args) ){
			case 0: throw new %BOOTCODE%_Less_CompilerException( 'one or more arguments required');
			case 1: return $args[0];
		}

		$order = array();	// elems only contains original argument values.
		$values = array();	// key is the unit.toString() for unified tree.Dimension values,
							// value is the index into the order array.

		for( $i = 0; $i < count($args); $i++ ){
			$current = $args[$i];
			if( !($current instanceof %BOOTCODE%_Less_Tree_Dimension) ){
				$order[] = $current;
				continue;
			}
			$currentUnified = $current->unify();
			$unit = $currentUnified->unit->toString();

			if( !isset($values[$unit]) ){
				$values[$unit] = count($order);
				$order[] = $current;
				continue;
			}

			$j = $values[$unit];
			$referenceUnified = $order[$j]->unify();
			if( ($isMin && $currentUnified->value < $referenceUnified->value) || (!$isMin && $currentUnified->value > $referenceUnified->value) ){
				$order[$j] = $current;
			}
		}
		if( count($order) == 1 ){
			return $order[0];
		}

		foreach($order as $k => $a){
			$order[$k] = $a->toCSS( $this->env );
		}

		$args = implode( %BOOTCODE%_Less_Environment::$comma_space, $order);

		return new %BOOTCODE%_Less_Tree_Anonymous( ($isMin ? 'min' : 'max') . '(' . $args . ')');
	}

	public function min(){
		return $this->_minmax(true, func_get_args() );
	}

	public function max(){
		return $this->_minmax(false, func_get_args() );
	}

	public function argb($color) {
		return new %BOOTCODE%_Less_Tree_Anonymous($color->toARGB());
	}

	public function percentage($n) {
		return new %BOOTCODE%_Less_Tree_Dimension($n->value * 100, '%');
	}

	public function color($n) {

		if( $n instanceof %BOOTCODE%_Less_Tree_Quoted ){
			$colorCandidate = $n->value;
			$returnColor = %BOOTCODE%_Less_Tree_Color::fromKeyword($colorCandidate);
			if( $returnColor ){
				return $returnColor;
			}
			if( preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})/',$colorCandidate) ){
				return new %BOOTCODE%_Less_Tree_Color(substr($colorCandidate, 1));
			}
			throw new %BOOTCODE%_Less_CompilerException("argument must be a color keyword or 3/6 digit hex e.g. #FFF");
		} else {
			throw new %BOOTCODE%_Less_CompilerException("argument must be a string");
		}
	}


	public function iscolor($n) {
		return $this->_isa($n, '%BOOTCODE%_Less_Tree_Color');
	}

	public function isnumber($n) {
		return $this->_isa($n, '%BOOTCODE%_Less_Tree_Dimension');
	}

	public function isstring($n) {
		return $this->_isa($n, '%BOOTCODE%_Less_Tree_Quoted');
	}

	public function iskeyword($n) {
		return $this->_isa($n, '%BOOTCODE%_Less_Tree_Keyword');
	}

	public function isurl($n) {
		return $this->_isa($n, '%BOOTCODE%_Less_Tree_Url');
	}

	public function ispixel($n) {
		return $this->isunit($n, 'px');
	}

	public function ispercentage($n) {
		return $this->isunit($n, '%');
	}

	public function isem($n) {
		return $this->isunit($n, 'em');
	}

	public function isunit( $n, $unit ){
		return ($n instanceof %BOOTCODE%_Less_Tree_Dimension) && $n->unit->is( ( property_exists($unit,'value') ? $unit->value : $unit) ) ? new %BOOTCODE%_Less_Tree_Keyword('true') : new %BOOTCODE%_Less_Tree_Keyword('false');
	}

	private function _isa($n, $type) {
		return is_a($n, $type) ? new %BOOTCODE%_Less_Tree_Keyword('true') : new %BOOTCODE%_Less_Tree_Keyword('false');
	}

	/* Blending modes */

	public function multiply($color1, $color2) {
		$r = $color1->rgb[0] * $color2->rgb[0] / 255;
		$g = $color1->rgb[1] * $color2->rgb[1] / 255;
		$b = $color1->rgb[2] * $color2->rgb[2] / 255;
		return $this->rgb($r, $g, $b);
	}

	public function screen($color1, $color2) {
		$r = 255 - (255 - $color1->rgb[0]) * (255 - $color2->rgb[0]) / 255;
		$g = 255 - (255 - $color1->rgb[1]) * (255 - $color2->rgb[1]) / 255;
		$b = 255 - (255 - $color1->rgb[2]) * (255 - $color2->rgb[2]) / 255;
		return $this->rgb($r, $g, $b);
	}

	public function overlay($color1, $color2) {
		$r = $color1->rgb[0] < 128 ? 2 * $color1->rgb[0] * $color2->rgb[0] / 255 : 255 - 2 * (255 - $color1->rgb[0]) * (255 - $color2->rgb[0]) / 255;
		$g = $color1->rgb[1] < 128 ? 2 * $color1->rgb[1] * $color2->rgb[1] / 255 : 255 - 2 * (255 - $color1->rgb[1]) * (255 - $color2->rgb[1]) / 255;
		$b = $color1->rgb[2] < 128 ? 2 * $color1->rgb[2] * $color2->rgb[2] / 255 : 255 - 2 * (255 - $color1->rgb[2]) * (255 - $color2->rgb[2]) / 255;
		return $this->rgb($r, $g, $b);
	}

	public function softlight($color1, $color2) {
		$t = $color2->rgb[0] * $color1->rgb[0] / 255;
		$r = $t + $color1->rgb[0] * (255 - (255 - $color1->rgb[0]) * (255 - $color2->rgb[0]) / 255 - $t) / 255;
		$t = $color2->rgb[1] * $color1->rgb[1] / 255;
		$g = $t + $color1->rgb[1] * (255 - (255 - $color1->rgb[1]) * (255 - $color2->rgb[1]) / 255 - $t) / 255;
		$t = $color2->rgb[2] * $color1->rgb[2] / 255;
		$b = $t + $color1->rgb[2] * (255 - (255 - $color1->rgb[2]) * (255 - $color2->rgb[2]) / 255 - $t) / 255;
		return $this->rgb($r, $g, $b);
	}

	public function hardlight($color1, $color2) {
		$r = $color2->rgb[0] < 128 ? 2 * $color2->rgb[0] * $color1->rgb[0] / 255 : 255 - 2 * (255 - $color2->rgb[0]) * (255 - $color1->rgb[0]) / 255;
		$g = $color2->rgb[1] < 128 ? 2 * $color2->rgb[1] * $color1->rgb[1] / 255 : 255 - 2 * (255 - $color2->rgb[1]) * (255 - $color1->rgb[1]) / 255;
		$b = $color2->rgb[2] < 128 ? 2 * $color2->rgb[2] * $color1->rgb[2] / 255 : 255 - 2 * (255 - $color2->rgb[2]) * (255 - $color1->rgb[2]) / 255;
		return $this->rgb($r, $g, $b);
	}

	public function difference($color1, $color2) {
		$r = abs($color1->rgb[0] - $color2->rgb[0]);
		$g = abs($color1->rgb[1] - $color2->rgb[1]);
		$b = abs($color1->rgb[2] - $color2->rgb[2]);
		return $this->rgb($r, $g, $b);
	}

	public function exclusion($color1, $color2) {
		$r = $color1->rgb[0] + $color2->rgb[0] * (255 - $color1->rgb[0] - $color1->rgb[0]) / 255;
		$g = $color1->rgb[1] + $color2->rgb[1] * (255 - $color1->rgb[1] - $color1->rgb[1]) / 255;
		$b = $color1->rgb[2] + $color2->rgb[2] * (255 - $color1->rgb[2] - $color1->rgb[2]) / 255;
		return $this->rgb($r, $g, $b);
	}

	public function average($color1, $color2) {
		$r = ($color1->rgb[0] + $color2->rgb[0]) / 2;
		$g = ($color1->rgb[1] + $color2->rgb[1]) / 2;
		$b = ($color1->rgb[2] + $color2->rgb[2]) / 2;
		return $this->rgb($r, $g, $b);
	}

	public function negation($color1, $color2) {
		$r = 255 - abs(255 - $color2->rgb[0] - $color1->rgb[0]);
		$g = 255 - abs(255 - $color2->rgb[1] - $color1->rgb[1]);
		$b = 255 - abs(255 - $color2->rgb[2] - $color1->rgb[2]);
		return $this->rgb($r, $g, $b);
	}

	public function tint($color, $amount) {
		return $this->mix( $this->rgb(255,255,255), $color, $amount);
	}

	public function shade($color, $amount) {
		return $this->mix($this->rgb(0, 0, 0), $color, $amount);
	}

	public function extract($values, $index ){
		$index = (int)$index->value - 1; // (1-based index)
		// handle non-array values as an array of length 1
		// return 'undefined' if index is invalid
		if( property_exists($values,'value') && is_array($values->value) ){
			if( isset($values->value[$index]) ){
				return $values->value[$index];
			}
			return null;

		}elseif( (int)$index === 0 ){
			return $values;
		}

		return null;
	}

	function length($values){
		$n = (property_exists($values,'value') && is_array($values->value)) ? count($values->value) : 1;
		return new %BOOTCODE%_Less_Tree_Dimension($n);
	}

	function datauri($mimetypeNode, $filePathNode = null ) {

		$filePath = ( $filePathNode ? $filePathNode->value : null );
		$mimetype = $mimetypeNode->value;
		$useBase64 = false;

		$args = 2;
		if( !$filePath ){
			$filePath = $mimetype;
			$args = 1;
		}

		$filePath = str_replace('\\','/',$filePath);
		if( %BOOTCODE%_Less_Environment::isPathRelative($filePath) ){

			if( $this->env->relativeUrls ){
				$temp = $this->env->currentFileInfo['currentDirectory'];
			} else {
				$temp = $this->env->currentFileInfo['entryPath'];
			}

			if( !empty($temp) ){
				$filePath = %BOOTCODE%_Less_Environment::normalizePath(rtrim($temp,'/').'/'.$filePath);
			}

		}


		// detect the mimetype if not given
		if( $args < 2 ){

			/* incomplete
			$mime = require('mime');
			mimetype = mime.lookup(path);

			// use base 64 unless it's an ASCII or UTF-8 format
			var charset = mime.charsets.lookup(mimetype);
			useBase64 = ['US-ASCII', 'UTF-8'].indexOf(charset) < 0;
			if (useBase64) mimetype += ';base64';
			*/

			$mimetype = %BOOTCODE%_Less_Mime::lookup($filePath);

			$charset = %BOOTCODE%_Less_Mime::charsets_lookup($mimetype);
			$useBase64 = !in_array($charset,array('US-ASCII', 'UTF-8'));
			if( $useBase64 ){ $mimetype .= ';base64'; }

		}else{
			$useBase64 = preg_match('/;base64$/',$mimetype);
		}


		if( file_exists($filePath) ){
			$buf = @file_get_contents($filePath);
		}else{
			$buf = false;
		}


		// IE8 cannot handle a data-uri larger than 32KB. If this is exceeded
		// and the --ieCompat flag is enabled, return a normal url() instead.
		$DATA_URI_MAX_KB = 32;
		$fileSizeInKB = round( strlen($buf) / 1024 );
		if( $fileSizeInKB >= $DATA_URI_MAX_KB ){
			$url = new %BOOTCODE%_Less_Tree_Url( ($filePathNode ? $filePathNode : $mimetypeNode), $this->currentFileInfo);
			return $url->compile($this);
		}

		if( $buf ){
			$buf = $useBase64 ? base64_encode($buf) : rawurlencode($buf);
			$filePath = "'data:" . $mimetype . ',' . $buf . "'";
		}

		return new %BOOTCODE%_Less_Tree_Url( new %BOOTCODE%_Less_Tree_Anonymous($filePath) );
	}

	//svg-gradient
	function svggradient( $direction ){

		$throw_message = 'svg-gradient expects direction, start_color [start_position], [color position,]..., end_color [end_position]';
		$arguments = func_get_args();

		if( count($arguments) < 3 ){
			throw new %BOOTCODE%_Less_CompilerException( $throw_message );
		}

		$stops = array_slice($arguments,1);
		$gradientType = 'linear';
		$rectangleDimension = 'x="0" y="0" width="1" height="1"';
		$useBase64 = true;
		$renderEnv = new %BOOTCODE%_Less_Environment();
		$directionValue = $direction->toCSS($renderEnv);


		switch( $directionValue ){
			case "to bottom":
				$gradientDirectionSvg = 'x1="0%" y1="0%" x2="0%" y2="100%"';
				break;
			case "to right":
				$gradientDirectionSvg = 'x1="0%" y1="0%" x2="100%" y2="0%"';
				break;
			case "to bottom right":
				$gradientDirectionSvg = 'x1="0%" y1="0%" x2="100%" y2="100%"';
				break;
			case "to top right":
				$gradientDirectionSvg = 'x1="0%" y1="100%" x2="100%" y2="0%"';
				break;
			case "ellipse":
			case "ellipse at center":
				$gradientType = "radial";
				$gradientDirectionSvg = 'cx="50%" cy="50%" r="75%"';
				$rectangleDimension = 'x="-50" y="-50" width="101" height="101"';
				break;
			default:
				throw new %BOOTCODE%_Less_CompilerException( "svg-gradient direction must be 'to bottom', 'to right', 'to bottom right', 'to top right' or 'ellipse at center'" );
		}

		$returner = '<?xml version="1.0" ?>' .
			'<svg xmlns="http://www.w3.org/2000/svg" version="1.1" width="100%" height="100%" viewBox="0 0 1 1" preserveAspectRatio="none">' .
			'<' . $gradientType . 'Gradient id="gradient" gradientUnits="userSpaceOnUse" ' . $gradientDirectionSvg . '>';

		for( $i = 0; $i < count($stops); $i++ ){
			if( is_object($stops[$i]) && property_exists($stops[$i],'value') ){
				$color = $stops[$i]->value[0];
				$position = $stops[$i]->value[1];
			}else{
				$color = $stops[$i];
				$position = null;
			}

			if( !($color instanceof %BOOTCODE%_Less_Tree_Color) || (!(($i === 0 || $i+1 === count($stops)) && $position === null) && !($position instanceof %BOOTCODE%_Less_Tree_Dimension)) ){
				throw new %BOOTCODE%_Less_CompilerException( $throw_message );
			}
			if( $position ){
				$positionValue = $position->toCSS($renderEnv);
			}elseif( $i === 0 ){
				$positionValue = '0%';
			}else{
				$positionValue = '100%';
			}
			$alpha = $color->alpha;
			$returner .= '<stop offset="' . $positionValue . '" stop-color="' . $color->toRGB() . '"' . ($alpha < 1 ? ' stop-opacity="' . $alpha . '"' : '') . '/>';
		}

		$returner .= '</' . $gradientType . 'Gradient><rect ' . $rectangleDimension . ' fill="url(#gradient)" /></svg>';


		if( $useBase64 ){
			// only works in node, needs interface to what is supported in environment
			try{
				$returner = base64_encode($returner);
			}catch(Exception $e){
				$useBase64 = false;
			}
		}

		$returner = "'data:image/svg+xml" . ($useBase64 ? ";base64" : "") . "," . $returner . "'";
		return new %BOOTCODE%_Less_Tree_URL( new %BOOTCODE%_Less_Tree_Anonymous( $returner ) );
	}


	private static function Expected( $type, $arg ){

		$debug = debug_backtrace();
		array_shift($debug);
		$last = array_shift($debug);
		$last = array_intersect_key($last,array('function'=>'','class'=>'','line'=>''));

		$message = 'Object of type '.get_class($arg).' passed to darken function. Expecting `Color`. '.$arg->toCSS().'. '.print_r($last,true);
		throw new %BOOTCODE%_Less_CompilerException($message);

	}

}


//less.js : lib/less/functions.js

class %BOOTCODE%_Less_Mime{

	// this map is intentionally incomplete
	// if you want more, install 'mime' dep
	static $_types = array(
	        '.htm' => 'text/html',
	        '.html'=> 'text/html',
	        '.gif' => 'image/gif',
	        '.jpg' => 'image/jpeg',
	        '.jpeg'=> 'image/jpeg',
	        '.png' => 'image/png'
	        );

	static function lookup( $filepath ){
		$parts = explode('.',$filepath);
		$ext = '.'.strtolower(array_pop($parts));

		if( !isset(self::$_types[$ext]) ){
			return null;
		}
		return self::$_types[$ext];
	}

	static function charsets_lookup( $type = false ){
		// assumes all text types are UTF-8
		return $type && preg_match('/^text\//',$type) ? 'UTF-8' : '';
	}
}

class %BOOTCODE%_Less_Tree{

	public function toCSS($env = null){
		$strs = array();
		$this->genCSS($env, $strs );
		return implode('',$strs);
	}

	public static function OutputAdd( &$strs, $chunk, $fileInfo = null, $index = null ){
		$strs[] = $chunk;
	}


	public static function outputRuleset($env, &$strs, $rules ){

		$ruleCnt = count($rules);
		$env->tabLevel++;


		// Compressed
		if( %BOOTCODE%_Less_Environment::$compress ){
			self::OutputAdd( $strs, '{' );
			for( $i = 0; $i < $ruleCnt; $i++ ){
				$rules[$i]->genCSS( $env, $strs );
			}
			self::OutputAdd( $strs, '}' );
			$env->tabLevel--;
			return;
		}


		// Non-compressed
		$tabSetStr = "\n".str_repeat( '  ' , $env->tabLevel-1 );
		$tabRuleStr = $tabSetStr.'  ';

		self::OutputAdd( $strs, " {" );
		for($i = 0; $i < $ruleCnt; $i++ ){
			self::OutputAdd( $strs, $tabRuleStr );
			$rules[$i]->genCSS( $env, $strs );
		}
		$env->tabLevel--;
		self::OutputAdd( $strs, $tabSetStr.'}' );

	}

	public function accept($visitor){}

	/**
	 * Requires php 5.3+
	 */
	public static function __set_state($args){

		$class = get_called_class();
		$obj = new $class(null,null,null,null);
		foreach($args as $key => $val){
			$obj->$key = $val;
		}
		return $obj;
	}

}


class %BOOTCODE%_Less_Tree_Alpha extends %BOOTCODE%_Less_Tree{
	public $value;
	public $type = 'Alpha';

	public function __construct($val){
		$this->value = $val;
	}

	//function accept( $visitor ){
	//	$this->value = $visitor->visit( $this->value );
	//}

	public function compile($env){

		if( !is_string($this->value) ){ return new %BOOTCODE%_Less_Tree_Alpha( $this->value->compile($env) ); }

		return $this;
	}

	public function genCSS( $env, &$strs ){

		self::OutputAdd( $strs, "alpha(opacity=" );

		if( is_string($this->value) ){
			self::OutputAdd( $strs, $this->value );
		}else{
			$this->value->genCSS($env, $strs);
		}

		self::OutputAdd( $strs, ')' );
	}

	public function toCSS($env = null){
		return "alpha(opacity=" . (is_string($this->value) ? $this->value : $this->value->toCSS()) . ")";
	}


}


class %BOOTCODE%_Less_Tree_Anonymous extends %BOOTCODE%_Less_Tree{
	public $value;
	public $quote;
	public $type = 'Anonymous';

	public function __construct($value, $index = null, $currentFileInfo = null, $mapLines = null ){
		$this->value = is_object($value) ? $value->value : $value;
		$this->index = $index;
		$this->mapLines = $mapLines;
		$this->currentFileInfo = $currentFileInfo;
	}

	public function compile($env){
		return $this;
	}

	function compare($x){
		if( !is_object($x) ){
			return -1;
		}

		$left = $this->toCSS();
		$right = $x->toCSS();

		if( $left === $right ){
			return 0;
		}

		return $left < $right ? -1 : 1;
	}

	public function genCSS( $env, &$strs ){
		self::OutputAdd( $strs, $this->value, $this->currentFileInfo, $this->index, $this->mapLines );
	}

	public function toCSS($env = null){
		return $this->value;
	}

}



class %BOOTCODE%_Less_Tree_Assignment extends %BOOTCODE%_Less_Tree{

	public $key;
	public $value;
	public $type = 'Assignment';

	function __construct($key, $val) {
		$this->key = $key;
		$this->value = $val;
	}

	function accept( $visitor ){
		$this->value = $visitor->visitObj( $this->value );
	}


	public function compile($env) {
		if( %BOOTCODE%_Less_Parser::is_method($this->value,'compile') ){
			return new %BOOTCODE%_Less_Tree_Assignment( $this->key, $this->value->compile($env));
		}
		return $this;
	}

	public function genCSS( $env, &$strs ){
		self::OutputAdd( $strs, $this->key . '=' );
		if( is_string($this->value) ){
			self::OutputAdd( $strs, $this->value );
		}else{
			$this->value->genCSS( $env, $strs );
		}
	}

	public function toCss($env = null){
		return $this->key . '=' . (is_string($this->value) ? $this->value : $this->value->toCSS());
	}
}



class %BOOTCODE%_Less_Tree_Attribute extends %BOOTCODE%_Less_Tree{

	public $key;
	public $op;
	public $value;
	public $type = 'Attribute';

	function __construct($key, $op, $value){
		$this->key = $key;
		$this->op = $op;
		$this->value = $value;
	}

	function compile($env){

		return new %BOOTCODE%_Less_Tree_Attribute(
			is_object($this->key) ? $this->key->compile($env) : $this->key ,
			$this->op,
			is_object($this->value) ? $this->value->compile($env) : $this->value);
	}

	function genCSS( $env, &$strs ){
		self::OutputAdd( $strs, $this->toCSS($env) );
	}

	function toCSS($env = null){
		$value = $this->key;

		if( $this->op ){
			$value .= $this->op;
			$value .= (is_object($this->value) ? $this->value->toCSS($env) : $this->value);
		}

		return '[' . $value . ']';
	}
}


//
// A function call node.
//

class %BOOTCODE%_Less_Tree_Call extends %BOOTCODE%_Less_Tree{
    public $value;

    var $name;
    var $args;
    var $index;
    var $currentFileInfo;
    public $type = 'Call';

	public function __construct($name, $args, $index, $currentFileInfo = null ){
		$this->name = $name;
		$this->args = $args;
		$this->index = $index;
		$this->currentFileInfo = $currentFileInfo;
	}

	function accept( $visitor ){
		$this->args = $visitor->visitArray( $this->args );
	}

    //
    // When evaluating a function call,
    // we either find the function in `tree.functions` [1],
    // in which case we call it, passing the  evaluated arguments,
    // or we simply print it out as it appeared originally [2].
    //
    // The *functions.js* file contains the built-in functions.
    //
    // The reason why we evaluate the arguments, is in the case where
    // we try to pass a variable to a function, like: `saturate(@color)`.
    // The function should receive the value, not the variable.
    //
    public function compile($env){
		$args = array();
		foreach($this->args as $a){
			$args[] = $a->compile($env);
		}

		$name = $this->name;
		switch($name){
			case '%':
			$name = '_percent';
			break;

			case 'data-uri':
			$name = 'datauri';
			break;

			case 'svg-gradient':
			$name = 'svggradient';
			break;
		}


		if( is_callable( array('%BOOTCODE%_Less_Functions',$name) ) ){ // 1.
			try {
				$func = new %BOOTCODE%_Less_Functions($env, $this->currentFileInfo);
				$result = call_user_func_array( array($func,$name),$args);
				if( $result != null ){
					return $result;
				}

			} catch (Exception $e) {
				throw %BOOTCODE%_Less_CompilerException('error evaluating function `' . $this->name . '` '.$e->getMessage().' index: '. $this->index);
			}

		}

		return new %BOOTCODE%_Less_Tree_Call( $this->name, $args, $this->index, $this->currentFileInfo );
    }

	public function genCSS( $env, &$strs ){

		self::OutputAdd( $strs, $this->name . '(', $this->currentFileInfo, $this->index );
		$args_len = count($this->args);
		for($i = 0; $i < $args_len; $i++ ){
			$this->args[$i]->genCSS($env, $strs );
			if( $i + 1 < $args_len ){
				self::OutputAdd( $strs, ', ' );
			}
		}

		self::OutputAdd( $strs, ')' );
	}

    public function toCSS($env = null){
        return $this->compile($env)->toCSS();
    }

}



class %BOOTCODE%_Less_Tree_Color extends %BOOTCODE%_Less_Tree{
	var $rgb;
	var $alpha;
	public $type = 'Color';

	public function __construct($rgb, $a = 1){
		$this->rgb = array();
		if( is_array($rgb) ){
			$this->rgb = $rgb;
		}else if( strlen($rgb) == 6 ){
			foreach(str_split($rgb, 2) as $c){
				$this->rgb[] = hexdec($c);
			}
		}else{
			foreach(str_split($rgb, 1) as $c){
				$this->rgb[] = hexdec($c.$c);
			}
		}
		$this->alpha = is_numeric($a) ? $a : 1;
	}

    public function compile($env = null){
        return $this;
    }

	public function luma(){
		return (0.2126 * $this->rgb[0] / 255) + (0.7152 * $this->rgb[1] / 255) + (0.0722 * $this->rgb[2] / 255);
	}

	public function genCSS( $env, &$strs ){
		self::OutputAdd( $strs, $this->toCSS($env) );
	}

    public function toCSS($env = null, $doNotCompress = false ){
		$compress = %BOOTCODE%_Less_Environment::$compress && !$doNotCompress;


	    //
	    // If we have some transparency, the only way to represent it
	    // is via `rgba`. Otherwise, we use the hex representation,
	    // which has better compatibility with older browsers.
	    // Values are capped between `0` and `255`, rounded and zero-padded.
	    //
    	if( $this->alpha < 1.0 ){
            if( $this->alpha === 0 && isset($this->isTransparentKeyword) && $this->isTransparentKeyword ){
                return 'transparent';
            }


			$values = array_map('round', $this->rgb);
			$values[] = $this->alpha;

			$glue = ($compress ? ',' : ', ');
			return "rgba(" . implode($glue, $values) . ")";
		}else{

			$color = $this->toRGB();

			if( $compress ){

				// Convert color to short format
				if( $color[1] === $color[2] && $color[3] === $color[4] && $color[5] === $color[6]) {
					$color = '#'.$color[1] . $color[3] . $color[5];
				}
			}

			return $color;
		}
    }

    //
    // Operations have to be done per-channel, if not,
    // channels will spill onto each other. Once we have
    // our result, in the form of an integer triplet,
    // we create a new Color node to hold the result.
    //
    public function operate($env, $op, $other) {
        $result = array();

        if (! ($other instanceof %BOOTCODE%_Less_Tree_Color)) {
            $other = $other->toColor();
        }

        for ($c = 0; $c < 3; $c++) {
            $result[$c] = %BOOTCODE%_Less_Functions::operate($env, $op, $this->rgb[$c], $other->rgb[$c]);
        }
        return new %BOOTCODE%_Less_Tree_Color($result, $this->alpha + $other->alpha);
    }

    public function toRGB(){
		$color = '';
		foreach($this->rgb as $i){
			$i = %BOOTCODE%_Less_Parser::round($i);
			$i = ($i > 255 ? 255 : ($i < 0 ? 0 : $i));
			$i = dechex($i);
			$color .= str_pad($i, 2, '0', STR_PAD_LEFT);
		}
		return '#'.$color;
    }

	public function toHSL(){
		$r = $this->rgb[0] / 255;
		$g = $this->rgb[1] / 255;
		$b = $this->rgb[2] / 255;
		$a = $this->alpha;

		$max = max($r, $g, $b);
		$min = min($r, $g, $b);
		$l = ($max + $min) / 2;
		$d = $max - $min;

		if( $max === $min ){
			$h = $s = 0;
		} else {
			$s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);

			switch ($max) {
				case $r: $h = ($g - $b) / $d + ($g < $b ? 6 : 0); break;
				case $g: $h = ($b - $r) / $d + 2;                 break;
				case $b: $h = ($r - $g) / $d + 4;                 break;
			}
			$h /= 6;
		}
		return array('h' => $h * 360, 's' => $s, 'l' => $l, 'a' => $a );
	}

	//Adapted from http://mjijackson.com/2008/02/rgb-to-hsl-and-rgb-to-hsv-color-model-conversion-algorithms-in-javascript
	function toHSV() {
		$r = $this->rgb[0] / 255;
		$g = $this->rgb[1] / 255;
		$b = $this->rgb[2] / 255;
		$a = $this->alpha;

		$max = max($r, $g, $b);
		$min = min($r, $g, $b);

		$v = $max;

		$d = $max - $min;
		if ($max === 0) {
			$s = 0;
		} else {
			$s = $d / $max;
		}

		if ($max === $min) {
			$h = 0;
		} else {
			switch($max){
				case $r: $h = ($g - $b) / $d + ($g < $b ? 6 : 0); break;
				case $g: $h = ($b - $r) / $d + 2; break;
				case $b: $h = ($r - $g) / $d + 4; break;
			}
			$h /= 6;
		}
		return array('h'=> $h * 360, 's'=> $s, 'v'=> $v, 'a' => $a );
	}

	public function toARGB(){
		$argb = array_merge( (array) %BOOTCODE%_Less_Parser::round($this->alpha * 255), $this->rgb);

		$temp = '';
		foreach($argb as $i){
			$i = %BOOTCODE%_Less_Parser::round($i);
			$i = dechex($i > 255 ? 255 : ($i < 0 ? 0 : $i));
			$temp .= str_pad($i, 2, '0', STR_PAD_LEFT);
		}
		return '#' . $temp;
	}

    public function compare($x){

		if( !property_exists( $x, 'rgb' ) ){
			return -1;
		}


        return ($x->rgb[0] === $this->rgb[0] &&
            $x->rgb[1] === $this->rgb[1] &&
            $x->rgb[2] === $this->rgb[2] &&
            $x->alpha === $this->alpha) ? 0 : -1;
    }


	public static function fromKeyword( $keyword ){

		if( %BOOTCODE%_Less_Colors::hasOwnProperty($keyword) ){
			// detect named color
			return new %BOOTCODE%_Less_Tree_Color(substr(%BOOTCODE%_Less_Colors::color($keyword), 1));
		}

		if( $keyword === 'transparent' ){
			$transparent = new %BOOTCODE%_Less_Tree_Color( array(0, 0, 0), 0);
			$transparent->isTransparentKeyword = true;
			return $transparent;
		}
	}

}



class %BOOTCODE%_Less_Tree_Combinator extends %BOOTCODE%_Less_Tree{

	public $value;
	public $type = 'Combinator';

	public function __construct($value = null) {
		if( $value == ' ' ){
			$this->value = ' ';
		}else {
			$this->value = trim($value);
		}
	}

	static $_outputMap = array(
		''  => '',
		' ' => ' ',
		':' => ' :',
		'+' => ' + ',
		'~' => ' ~ ',
		'>' => ' > ',
		'|' => '|'
	);

	static $_outputMapCompressed = array(
		''  => '',
		' ' => ' ',
		':' => ' :',
		'+' => '+',
		'~' => '~',
		'>' => '>',
		'|' => '|'
	);

	function genCSS($env, &$strs ){
		if( %BOOTCODE%_Less_Environment::$compress ){
			self::OutputAdd( $strs, self::$_outputMapCompressed[$this->value] );
		}else{
			self::OutputAdd( $strs, self::$_outputMap[$this->value] );
		}
	}

}


class %BOOTCODE%_Less_Tree_Comment extends %BOOTCODE%_Less_Tree{

	public $type = 'Comment';

	public function __construct($value, $silent, $index = null, $currentFileInfo = null ){
		$this->value = $value;
		$this->silent = !! $silent;
		$this->currentFileInfo = $currentFileInfo;
	}

	public function genCSS( $env, &$strs ){
		//if( $this->debugInfo ){
			//self::OutputAdd( $strs, tree.debugInfo($env, $this), $this->currentFileInfo, $this->index);
		//}
		self::OutputAdd( $strs, trim($this->value) );//TODO shouldn't need to trim, we shouldn't grab the \n
	}

	public function toCSS($env = null){
		return %BOOTCODE%_Less_Environment::$compress ? '' : $this->value;
	}

	public function isSilent( $env ){
		$isReference = ($this->currentFileInfo && isset($this->currentFileInfo['reference']) && (!isset($this->isReferenced) || !$this->isReferenced) );
		$isCompressed = %BOOTCODE%_Less_Environment::$compress && !preg_match('/^\/\*!/', $this->value);
		return $this->silent || $isReference || $isCompressed;
	}

	public function compile(){
		return $this;
	}

	public function markReferenced(){
		$this->isReferenced = true;
	}

}


class %BOOTCODE%_Less_Tree_Condition extends %BOOTCODE%_Less_Tree{

	public $op;
	public $lvalue;
	public $rvalue;
	public $index;
	public $negate;
	public $type = 'Condition';

	public function __construct($op, $l, $r, $i = 0, $negate = false) {
		$this->op = trim($op);
		$this->lvalue = $l;
		$this->rvalue = $r;
		$this->index = $i;
		$this->negate = $negate;
	}

	public function accept($visitor){
		$this->lvalue = $visitor->visitObj( $this->lvalue );
		$this->rvalue = $visitor->visitObj( $this->rvalue );
	}

    public function compile($env) {
		$a = $this->lvalue->compile($env);
		$b = $this->rvalue->compile($env);

		$i = $this->index;

		switch( $this->op ){
			case 'and':
				$result = $a && $b;
			break;

			case 'or':
				$result = $a || $b;
			break;

			default:
				if( %BOOTCODE%_Less_Parser::is_method($a, 'compare') ){
					$result = $a->compare($b);
				}elseif( %BOOTCODE%_Less_Parser::is_method($b, 'compare') ){
					$result = $b->compare($a);
				}else{
					throw new %BOOTCODE%_Less_CompilerException('Unable to perform comparison', $this->index);
				}

				switch ($result) {
					case -1:
					$result = $this->op === '<' || $this->op === '=<' || $this->op === '<=';
					break;

					case  0:
					$result = $this->op === '=' || $this->op === '>=' || $this->op === '=<' || $this->op === '<=';
					break;

					case  1:
					$result = $this->op === '>' || $this->op === '>=';
					break;
				}
			break;
		}

		return $this->negate ? !$result : $result;
    }

}



class %BOOTCODE%_Less_Tree_Dimension extends %BOOTCODE%_Less_Tree{

	public $type = 'Dimension';

    public function __construct($value, $unit = false){
        $this->value = floatval($value);

		if( $unit && ($unit instanceof %BOOTCODE%_Less_Tree_Unit) ){
			$this->unit = $unit;
		}elseif( $unit ){
			$this->unit = new %BOOTCODE%_Less_Tree_Unit( array($unit) );
		}else{
			$this->unit = new %BOOTCODE%_Less_Tree_Unit( );
		}
    }

	function accept( $visitor ){
		$this->unit = $visitor->visitObj( $this->unit );
	}

    public function compile($env = null) {
        return $this;
    }

    public function toColor() {
        return new %BOOTCODE%_Less_Tree_Color(array($this->value, $this->value, $this->value));
    }

	public function genCSS( $env, &$strs ){

		if( ($env && $env->strictUnits) && !$this->unit->isSingular() ){
			throw new %BOOTCODE%_Less_CompilerException("Multiple units in dimension. Correct the units or use the unit function. Bad unit: ".$this->unit->toString());
		}

		$value = $this->value;
		$strValue = (string)$value;

		if( $value !== 0 && $value < 0.000001 && $value > -0.000001 ){
			// would be output 1e-6 etc.
			$strValue = number_format($strValue,10);
			$strValue = preg_replace('/\.?0+$/','', $strValue);
		}

		if( %BOOTCODE%_Less_Environment::$compress ){
			// Zero values doesn't need a unit
			if( $value === 0 && $this->unit->isLength() ){
				self::OutputAdd( $strs, $strValue );
				return $strValue;
			}

			// Float values doesn't need a leading zero
			if( $value > 0 && $value < 1 && $strValue[0] === '0' ){
				$strValue = substr($strValue,1);
			}
		}

		self::OutputAdd( $strs, $strValue );
		$this->unit->genCSS($env, $strs);
	}

    public function __toString(){
        return $this->toCSS();
    }

    // In an operation between two Dimensions,
    // we default to the first Dimension's unit,
    // so `1px + 2em` will yield `3px`.
    public function operate($env, $op, $other){

		$value = %BOOTCODE%_Less_Functions::operate($env, $op, $this->value, $other->value);
		$unit = clone $this->unit;

		if( $op === '+' || $op === '-' ){

			if( !$unit->numerator && !$unit->denominator ){
				$unit->numerator = $other->unit->numerator;
				$unit->denominator = $other->unit->denominator;
			}elseif( !$other->unit->numerator && !$other->unit->denominator ){
				// do nothing
			}else{
				$other = $other->convertTo( $this->unit->usedUnits());

				if( $env->strictUnits && $other->unit->toString() !== $unit->toCSS() ){
					throw new %BOOTCODE%_Less_CompilerException("Incompatible units. Change the units or use the unit function. Bad units: '".$unit->toString() . "' and ".$other->unit->toString()+"'.");
				}

				$value = %BOOTCODE%_Less_Functions::operate($env, $op, $this->value, $other->value);
			}
		}elseif( $op === '*' ){
			$unit->numerator = array_merge($unit->numerator, $other->unit->numerator);
			$unit->denominator = array_merge($unit->denominator, $other->unit->denominator);
			sort($unit->numerator);
			sort($unit->denominator);
			$unit->cancel();
		}elseif( $op === '/' ){
			$unit->numerator = array_merge($unit->numerator, $other->unit->denominator);
			$unit->denominator = array_merge($unit->denominator, $other->unit->numerator);
			sort($unit->numerator);
			sort($unit->denominator);
			$unit->cancel();
		}
		return new %BOOTCODE%_Less_Tree_Dimension( $value, $unit);
    }

	public function compare($other) {
		if ($other instanceof %BOOTCODE%_Less_Tree_Dimension) {

			$a = $this->unify();
			$b = $other->unify();
			$aValue = $a->value;
			$bValue = $b->value;

			if ($bValue > $aValue) {
				return -1;
			} elseif ($bValue < $aValue) {
				return 1;
			} else {
				if( !$b->unit->isEmpty() && $a->unit->compare($b->unit) !== 0) {
					return -1;
				}
				return 0;
			}
		} else {
			return -1;
		}
	}

	function unify() {
		return $this->convertTo(array('length'=> 'm', 'duration'=> 's', 'angle' => 'rad' ));
	}

    function convertTo($conversions) {
		$value = $this->value;
		$unit = clone $this->unit;

		if( is_string($conversions) ){
			$derivedConversions = array();
			foreach( %BOOTCODE%_Less_Tree_UnitConversions::$groups as $i ){
				if( isset(%BOOTCODE%_Less_Tree_UnitConversions::${$i}[$conversions]) ){
					$derivedConversions = array( $i => $conversions);
				}
			}
			$conversions = $derivedConversions;
		}


		foreach($conversions as $groupName => $targetUnit){
			$group = %BOOTCODE%_Less_Tree_UnitConversions::${$groupName};

			//numerator
			$len = count($unit->numerator);
			for($i=0; $i < $len; $i++ ){
				$atomicUnit = $unit->numerator[$i];
				if( !isset($group[$atomicUnit]) ){
					continue;
				}

				$value = $value * ($group[$atomicUnit] / $group[$targetUnit]);

				$unit->numerator[$i] = $targetUnit;
			}

			//denominator
			$len = count($unit->denominator);
			for($i=0; $i < $len; $i++ ){
				$atomicUnit = $unit->denominator[$i];
				if( !isset($group[$atomicUnit]) ){
					continue;
				}

				$value = $value / ($group[$atomicUnit] / $group[$targetUnit]);

				$unit->denominator[$i] = $targetUnit;
			}
		}

		$unit->cancel();

		return new %BOOTCODE%_Less_Tree_Dimension( $value, $unit);
    }
}



class %BOOTCODE%_Less_Tree_Unit extends %BOOTCODE%_Less_Tree{

	var $numerator = array();
	var $denominator = array();
	public $type = 'Unit';

	function __construct($numerator = array(), $denominator = array(), $backupUnit = null ){
		$this->numerator = $numerator;
		$this->denominator = $denominator;
		$this->backupUnit = $backupUnit;
	}

	function __clone(){
	}

	function genCSS( $env, &$strs ){

		if( $this->numerator ){
			self::OutputAdd( $strs, $this->numerator[0] );
		}elseif( $this->denominator ){
			self::OutputAdd( $strs, $this->denominator[0] );
		}elseif( (!$env || !$env->strictUnits) && $this->backupUnit ){
			self::OutputAdd( $strs, $this->backupUnit );
			return ;
		}
	}

	function toString(){
		$returnStr = implode('*',$this->numerator);
		foreach($this->denominator as $d){
			$returnStr .= '/'.$d;
		}
		return $returnStr;
	}

	function compare($other) {
		return $this->is( $other->toString() ) ? 0 : -1;
	}

	function is($unitString){
		return $this->toString() === $unitString;
	}

	function isLength(){
		$css = $this->toCSS();
		return !!preg_match('/px|em|%|in|cm|mm|pc|pt|ex/',$css);
	}

	function isAngle() {
		return isset( %BOOTCODE%_Less_Tree_UnitConversions::$angle[$this->toCSS()] );
	}

	function isEmpty(){
		return !$this->numerator && !$this->denominator;
	}

	function isSingular() {
		return count($this->numerator) <= 1 && !$this->denominator;
	}


	function usedUnits(){
		$result = array();

		foreach(%BOOTCODE%_Less_Tree_UnitConversions::$groups as $groupName){
			$group = %BOOTCODE%_Less_Tree_UnitConversions::${$groupName};

			foreach($this->numerator as $atomicUnit){
				if( isset($group[$atomicUnit]) && !isset($result[$groupName]) ){
					$result[$groupName] = $atomicUnit;
				}
			}

			foreach($this->denominator as $atomicUnit){
				if( isset($group[$atomicUnit]) && !isset($result[$groupName]) ){
					$result[$groupName] = $atomicUnit;
				}
			}
		}

		return $result;
	}

	function cancel(){
		$counter = array();
		$backup = null;

		foreach($this->numerator as $atomicUnit){
			if( !$backup ){
				$backup = $atomicUnit;
			}
			$counter[$atomicUnit] = ( isset($counter[$atomicUnit]) ? $counter[$atomicUnit] : 0) + 1;
		}

		foreach($this->denominator as $atomicUnit){
			if( !$backup ){
				$backup = $atomicUnit;
			}
			$counter[$atomicUnit] = ( isset($counter[$atomicUnit]) ? $counter[$atomicUnit] : 0) - 1;
		}

		$this->numerator = array();
		$this->denominator = array();

		foreach($counter as $atomicUnit => $count){
			if( $count > 0 ){
				for( $i = 0; $i < $count; $i++ ){
					$this->numerator[] = $atomicUnit;
				}
			}elseif( $count < 0 ){
				for( $i = 0; $i < -$count; $i++ ){
					$this->denominator[] = $atomicUnit;
				}
			}
		}

		if( !$this->numerator && !$this->denominator && $backup ){
			$this->backupUnit = $backup;
		}

		sort($this->numerator);
		sort($this->denominator);
	}


}




class %BOOTCODE%_Less_Tree_UnitConversions{

	static $groups = array('length','duration','angle');

	static $length = array(
		'm'=> 1,
		'cm'=> 0.01,
		'mm'=> 0.001,
		'in'=> 0.0254,
		'pt'=> 0.000352778, // 0.0254 / 72,
		'pc'=> 0.004233333, //0.0254 / 72 * 12
		);

	static $duration = array(
		's'=> 1,
		'ms'=> 0.001
		);

	static $angle = array(
		'rad' => 0.1591549430919,	// 1/(2*M_PI),
		'deg' => 0.002777778, 		// 1/360,
		'grad'=> 0.0025,			// 1/400,
		'turn'=> 1
		);

}

class %BOOTCODE%_Less_Tree_Directive extends %BOOTCODE%_Less_Tree{

	public $name;
	public $value;
	public $rules;
	public $index;
	public $type = 'Directive';

	public function __construct($name, $value = null, $index = null, $currentFileInfo = null ){
		$this->name = $name;
		if (is_array($value)) {
			$rule = new %BOOTCODE%_Less_Tree_Ruleset(array(), $value);
			$rule->allowImports = true;
			$this->rules = array($rule);
		} else {
			$this->value = $value;
		}
		$this->currentFileInfo = $currentFileInfo;
	}


	function accept( $visitor ){
		if( $this->rules ){
			$this->rules = $visitor->visitArray( $this->rules );
		}
		if( $this->value ){
			$this->value = $visitor->visitObj( $this->value );
		}
	}

	function genCSS( $env, &$strs ){

		self::OutputAdd( $strs, $this->name, $this->currentFileInfo, $this->index );

		if( $this->rules ){
			%BOOTCODE%_Less_Tree::outputRuleset( $env, $strs, $this->rules);
		}else{
			self::OutputAdd( $strs, ' ' );
			$this->value->genCSS( $env, $strs );
			self::OutputAdd( $strs, ';' );
		}
	}

	public function compile($env){
		$evaldDirective = $this;
		if( $this->rules ){
			$env->unshiftFrame($this);
			$evaldDirective = new %BOOTCODE%_Less_Tree_Directive( $this->name, null, $this->index, $this->currentFileInfo );
			$evaldDirective->rules = array( $this->rules[0]->compile($env) );
			$evaldDirective->rules[0]->root = true;
			$env->shiftFrame();
		}
		return $evaldDirective;
	}

	// TODO: Not sure if this is right...
	public function variable($name){
		return $this->rules[0]->variable($name);
	}

	public function find($selector){
		return $this->rules[0]->find($selector, $this);
	}

	//rulesets: function () { return tree.Ruleset.prototype.rulesets.apply(this.rules[0]); },

	public function markReferenced(){
		$this->isReferenced = true;
		if( $this->rules ){
			$rules = $this->rules[0]->rules;
			for( $i = 0; $i < count($rules); $i++ ){
				if( %BOOTCODE%_Less_Parser::is_method( $rules[$i], 'markReferenced') ){
					$rules[$i]->markReferenced();
				}
			}
		}
	}

}


//less.js : lib/less/tree/element.js

class %BOOTCODE%_Less_Tree_Element extends %BOOTCODE%_Less_Tree{

	public $combinator;
	public $value = '';
	public $index;
	public $type = 'Element';

	public function __construct($combinator, $value, $index = null, $currentFileInfo = null ){
		if( ! ($combinator instanceof %BOOTCODE%_Less_Tree_Combinator)) {
			$combinator = new %BOOTCODE%_Less_Tree_Combinator($combinator);
		}

		if( $value ){
			$this->value = $value;
		}

		$this->combinator = $combinator;
		$this->index = $index;
		$this->currentFileInfo = $currentFileInfo;
	}

	function accept( $visitor ){
		$this->combinator = $visitor->visitObj( $this->combinator );
		if( is_object($this->value) ){ //object or string
			$this->value = $visitor->visitObj( $this->value );
		}
	}

	public function compile($env) {
		return new %BOOTCODE%_Less_Tree_Element($this->combinator,
			is_string($this->value) ? $this->value : $this->value->compile($env),
			$this->index,
			$this->currentFileInfo
		);
	}

	public function genCSS( $env, &$strs ){
		self::OutputAdd( $strs, $this->toCSS($env), $this->currentFileInfo, $this->index );
	}

	public function toCSS( $env = null ){

		$value = $this->value;
		if( !is_string($value) ){
			$value = $value->toCSS($env);
		}

		if( $value === '' && $this->combinator->value[0] === '&' ){
			return '';
		}
		return $this->combinator->toCSS($env) . $value;
	}

}


class %BOOTCODE%_Less_Tree_Expression extends %BOOTCODE%_Less_Tree{

	public $value = array();
	public $parens = false;
	public $parensInOp = false;
	public $type = 'Expression';

	public function __construct($value) {
		$this->value = $value;
	}

	function accept( $visitor ){
		$this->value = $visitor->visitArray( $this->value );
	}

	public function compile($env) {

		$inParenthesis = $this->parens && !$this->parensInOp;
		$doubleParen = false;
		if( $inParenthesis ) {
			$env->inParenthesis();
		}

		if( $this->value ){

			$count = count($this->value);

			if( $count > 1 ){

				$ret = array();
				foreach($this->value as $e){
					$ret[] = $e->compile($env);
				}
				$returnValue = new %BOOTCODE%_Less_Tree_Expression($ret);

			}elseif( $count === 1 ){

				if( !isset($this->value[0]) ){
					$this->value = array_slice($this->value,0);
				}

				if( ($this->value[0] instanceof %BOOTCODE%_Less_Tree_Expression) && $this->value[0]->parens && !$this->value[0]->parensInOp ){
					$doubleParen = true;
				}

				$returnValue = $this->value[0]->compile($env);
			}

		} else {
			$returnValue = $this;
		}
		if( $inParenthesis ){
			$env->outOfParenthesis();
		}
		if( $this->parens && $this->parensInOp && !$env->isMathOn() && !$doubleParen ){
			$returnValue = new %BOOTCODE%_Less_Tree_Paren($returnValue);
		}
		return $returnValue;
	}

	function genCSS( $env, &$strs ){
		$val_len = count($this->value);
		for( $i = 0; $i < $val_len; $i++ ){
			$this->value[$i]->genCSS( $env, $strs );
			if( $i + 1 < $val_len ){
				self::OutputAdd( $strs, ' ' );
			}
		}
	}

	function throwAwayComments() {

		if( is_array($this->value) ){
			$new_value = array();
			foreach($this->value as $v){
				if( $v instanceof %BOOTCODE%_Less_Tree_Comment ){
					continue;
				}
				$new_value[] = $v;
			}
			$this->value = $new_value;
		}
	}
}



class %BOOTCODE%_Less_Tree_Extend extends %BOOTCODE%_Less_Tree{

	public $selector;
	public $option;
	public $index;
	public $selfSelectors = array();
	public $allowBefore;
	public $allowAfter;
	public $firstExtendOnThisSelectorPath;
	public $type = 'Extend';


	public $object_id;
	public $parent_ids = array();

	function __construct($selector, $option, $index){
		static $i = 0;
		$this->selector = $selector;
		$this->option = $option;
		$this->index = $index;

		switch($option){
			case "all":
				$this->allowBefore = true;
				$this->allowAfter = true;
			break;
			default:
				$this->allowBefore = false;
				$this->allowAfter = false;
			break;
		}

		$this->object_id = $i++;
		$this->parent_ids = array($this->object_id);
	}

	function accept( $visitor ){
		$this->selector = $visitor->visitObj( $this->selector );
	}

	function compile( $env ){
		%BOOTCODE%_Less_Parser::$has_extends = true;
		return new %BOOTCODE%_Less_Tree_Extend( $this->selector->compile($env), $this->option, $this->index);
	}

	function findSelfSelectors( $selectors ){
		$selfElements = array();


		for( $i = 0, $selectors_len = count($selectors); $i < $selectors_len; $i++ ){
			$selectorElements = $selectors[$i]->elements;
			// duplicate the logic in genCSS function inside the selector node.
			// future TODO - move both logics into the selector joiner visitor
			if( $i && $selectorElements && $selectorElements[0]->combinator->value === "") {
				$selectorElements[0]->combinator->value = ' ';
			}
			$selfElements = array_merge( $selfElements, $selectors[$i]->elements );
		}

		$this->selfSelectors = array(new %BOOTCODE%_Less_Tree_Selector($selfElements));
	}

}



//
// CSS @import node
//
// The general strategy here is that we don't want to wait
// for the parsing to be completed, before we start importing
// the file. That's because in the context of a browser,
// most of the time will be spent waiting for the server to respond.
//
// On creation, we push the import path to our import queue, though
// `import,push`, we also pass it a callback, which it'll call once
// the file has been fetched, and parsed.
//
class %BOOTCODE%_Less_Tree_Import extends %BOOTCODE%_Less_Tree{

	public $options;
	public $index;
	public $path;
	public $features;
	public $currentFileInfo;
	public $css;
	public $skip;
	public $root;
	public $type = 'Import';

	function __construct($path, $features, $options, $index, $currentFileInfo = null ){
		$this->options = $options;
		$this->index = $index;
		$this->path = $path;
		$this->features = $features;
		$this->currentFileInfo = $currentFileInfo;

		if( is_array($options) ){
			$this->options += array('inline'=>false);

			if( isset($this->options['less']) || $this->options['inline'] ){
				$this->css = !isset($this->options['less']) || !$this->options['less'] || $this->options['inline'];
			} else {
				$pathValue = $this->getPath();
				if( $pathValue && preg_match('/css([\?;].*)?$/',$pathValue) ){
					$this->css = true;
				}
			}
		}
	}

//
// The actual import node doesn't return anything, when converted to CSS.
// The reason is that it's used at the evaluation stage, so that the rules
// it imports can be treated like any other rules.
//
// In `eval`, we make sure all Import nodes get evaluated, recursively, so
// we end up with a flat structure, which can easily be imported in the parent
// ruleset.
//

	function accept($visitor){

		if( $this->features ){
			$this->features = $visitor->visitObj($this->features);
		}
		$this->path = $visitor->visitObj($this->path);

		if( !$this->options['inline'] && $this->root ){
			$this->root = $visitor->visit($this->root);
		}
	}

	function genCSS( $env, &$strs ){
		if( $this->css ){

			self::OutputAdd( $strs, '@import ', $this->currentFileInfo, $this->index );

			$this->path->genCSS( $env, $strs );
			if( $this->features ){
				self::OutputAdd( $strs, ' ' );
				$this->features->genCSS( $env, $strs );
			}
			self::OutputAdd( $strs, ';' );
		}
	}

	function toCSS($env = null){
		$features = $this->features ? ' ' . $this->features->toCSS($env) : '';

		if ($this->css) {
			return "@import " . $this->path->toCSS() . $features . ";\n";
		} else {
			return "";
		}
	}

	function getPath(){
		if ($this->path instanceof %BOOTCODE%_Less_Tree_Quoted) {
			$path = $this->path->value;
			return ( isset($this->css) || preg_match('/(\.[a-z]*$)|([\?;].*)$/',$path)) ? $path : $path . '.less';
		} else if ($this->path instanceof %BOOTCODE%_Less_Tree_URL) {
			return $this->path->value->value;
		}
		return null;
	}

	function compileForImport( $env ){
		return new %BOOTCODE%_Less_Tree_Import( $this->path->compile($env), $this->features, $this->options, $this->index, $this->currentFileInfo);
	}

	function compilePath($env) {
		$path = $this->path->compile($env);
		$rootpath = '';
		if( $this->currentFileInfo && $this->currentFileInfo['rootpath'] ){
			$rootpath = $this->currentFileInfo['rootpath'];
		}


		if( !($path instanceof %BOOTCODE%_Less_Tree_URL) ){
			if( $rootpath ){
				$pathValue = $path->value;
				// Add the base path if the import is relative
				if( $pathValue && %BOOTCODE%_Less_Environment::isPathRelative($pathValue) ){
					$path->value = $this->currentFileInfo['uri_root'].$pathValue;
				}
			}
			$path->value = %BOOTCODE%_Less_Environment::normalizePath($path->value);
		}

		return $path;
	}

	function compile($env) {

		$evald = $this->compileForImport($env);
		$uri = $full_path = false;

		//get path & uri
		$evald_path = $evald->getPath();
		if( $evald_path && %BOOTCODE%_Less_Environment::isPathRelative($evald_path) ){
			foreach(%BOOTCODE%_Less_Parser::$import_dirs as $rootpath => $rooturi){
				$temp = $rootpath.$evald_path;
				if( file_exists($temp) ){
					$full_path = %BOOTCODE%_Less_Environment::normalizePath($temp);
					$uri = %BOOTCODE%_Less_Environment::normalizePath(dirname($rooturi.$evald_path));
					break;
				}
			}
		}

		if( !$full_path ){
			$uri = $evald_path;
			$full_path = $evald_path;
		}

		//import once
		$realpath = realpath($full_path);


		if( $realpath && %BOOTCODE%_Less_Parser::FileParsed($realpath) ){
			if( isset($this->currentFileInfo['reference']) ){
				$evald->skip = true;
			}elseif( !isset($evald->options['multiple']) && !$env->importMultiple ){
				$evald->skip = true;
			}
		}

		$features = ( $evald->features ? $evald->features->compile($env) : null );

		if( $evald->skip ){
			return array();
		}


		if( $this->options['inline'] ){
			//todo needs to reference css file not import
			//$contents = new %BOOTCODE%_Less_Tree_Anonymous($this->root, 0, array('filename'=>$this->importedFilename), true );

			%BOOTCODE%_Less_Parser::AddParsedFile($full_path);
			$contents = new %BOOTCODE%_Less_Tree_Anonymous( file_get_contents($full_path), 0, array(), true );

			if( $this->features ){
				return new %BOOTCODE%_Less_Tree_Media( array($contents), $this->features->value );
			}

			return array( $contents );

		}elseif( $evald->css ){
			$temp = $this->compilePath( $env);
			return new %BOOTCODE%_Less_Tree_Import( $this->compilePath( $env), $features, $this->options, $this->index);
		}


		// options
		$import_env = clone $env;
		if( (isset($this->options['reference']) && $this->options['reference']) || isset($this->currentFileInfo['reference']) ){
			$import_env->currentFileInfo['reference'] = true;
		}

		if( (isset($this->options['multiple']) && $this->options['multiple']) ){
			$import_env->importMultiple = true;
		}

		$parser = new %BOOTCODE%_Less_Parser($import_env);
		$evald->root = $parser->parseFile($full_path, $uri, true);


		$ruleset = new %BOOTCODE%_Less_Tree_Ruleset(array(), $evald->root->rules );
		$ruleset->evalImports($import_env);

		return $this->features ? new %BOOTCODE%_Less_Tree_Media($ruleset->rules, $this->features->value) : $ruleset->rules;
	}
}



class %BOOTCODE%_Less_Tree_Javascript extends %BOOTCODE%_Less_Tree{

	public $type = 'Javascript';

	public function __construct($string, $index, $escaped){
		$this->escaped = $escaped;
		$this->expression = $string;
		$this->index = $index;
	}

	public function compile($env){
		return $this;
	}

	function genCSS( $env, &$strs ){
		self::OutputAdd( $strs, '/* Sorry, can not do JavaScript evaluation in PHP... :( */' );
	}

	public function toCSS($env = null){
		return %BOOTCODE%_Less_Environment::$compress ? '' : '/* Sorry, can not do JavaScript evaluation in PHP... :( */';
	}
}



class %BOOTCODE%_Less_Tree_Keyword extends %BOOTCODE%_Less_Tree{

	public $type = 'Keyword';

	public function __construct($value){
		$this->value = $value;
	}

	public function compile($env){
		return $this;
	}

	public function genCSS( $env, &$strs ){
		self::OutputAdd( $strs, $this->value );
	}

	public function compare($other) {
		if ($other instanceof %BOOTCODE%_Less_Tree_Keyword) {
			return $other->value === $this->value ? 0 : 1;
		} else {
			return -1;
		}
	}
}


class %BOOTCODE%_Less_Tree_Media extends %BOOTCODE%_Less_Tree{

	public $features;
	public $ruleset;
	public $type = 'Media';

	public function __construct($value = array(), $features = array(), $index = null, $currentFileInfo = null ){

		$this->index = $index;
		$this->currentFileInfo = $currentFileInfo;

		$selectors = $this->emptySelectors();

		$this->features = new %BOOTCODE%_Less_Tree_Value($features);

		$this->rules = array(new %BOOTCODE%_Less_Tree_Ruleset($selectors, $value));
		$this->rules[0]->allowImports = true;
	}

	function accept( $visitor ){
		$this->features = $visitor->visitObj($this->features);
		$this->rules = $visitor->visitArray($this->rules);
	}

	function genCSS( $env, &$strs ){

		self::OutputAdd( $strs, '@media ', $this->currentFileInfo, $this->index );
		$this->features->genCSS( $env, $strs );
		%BOOTCODE%_Less_Tree::outputRuleset( $env, $strs, $this->rules);

	}

	public function compile($env) {

		$media = new %BOOTCODE%_Less_Tree_Media(array(), array(), $this->index, $this->currentFileInfo );

		$strictMathBypass = false;
		if( $env->strictMath === false) {
			$strictMathBypass = true;
			$env->strictMath = true;
		}
		try {
			$media->features = $this->features->compile($env);
		}catch(Exception $e){}

		if( $strictMathBypass ){
			$env->strictMath = false;
		}

		$env->mediaPath[] = $media;
		$env->mediaBlocks[] = $media;

		array_unshift($env->frames, $this->rules[0]);
		$media->rules = array($this->rules[0]->compile($env));
		array_shift($env->frames);

		array_pop($env->mediaPath);

		return !$env->mediaPath ? $media->compileTop($env) : $media->compileNested($env);
	}

	public function variable($name) {
		return $this->rules[0]->variable($name);
	}

	public function find($selector) {
		return $this->rules[0]->find($selector, $this);
	}

	public function emptySelectors(){
		$el = new %BOOTCODE%_Less_Tree_Element('','&', $this->index, $this->currentFileInfo );
		return array( new %BOOTCODE%_Less_Tree_Selector(array($el), array(), null, $this->index, $this->currentFileInfo) );
	}

	public function markReferenced(){
		$this->isReferenced = true;
		foreach($this->rules[0]->rules as $rule){
			if( %BOOTCODE%_Less_Parser::is_method($rule,'markReferenced') ){
				$rule->markReferenced();
			}
		}
	}

	// evaltop
	public function compileTop($env) {
		$result = $this;

		if (count($env->mediaBlocks) > 1) {
			$selectors = $this->emptySelectors();
			$result = new %BOOTCODE%_Less_Tree_Ruleset($selectors, $env->mediaBlocks);
			$result->multiMedia = true;
		}

		$env->mediaBlocks = array();
		$env->mediaPath = array();

		return $result;
	}

	public function compileNested($env) {
		$path = array_merge($env->mediaPath, array($this));

		// Extract the media-query conditions separated with `,` (OR).
		foreach ($path as $key => $p) {
			$value = $p->features instanceof %BOOTCODE%_Less_Tree_Value ? $p->features->value : $p->features;
			$path[$key] = is_array($value) ? $value : array($value);
		}

		// Trace all permutations to generate the resulting media-query.
		//
		// (a, b and c) with nested (d, e) ->
		//	a and d
		//	a and e
		//	b and c and d
		//	b and c and e

		$permuted = $this->permute($path);
		$expressions = array();
		foreach($permuted as $path){

			for( $i=0, $len=count($path); $i < $len; $i++){
				$path[$i] = %BOOTCODE%_Less_Parser::is_method($path[$i], 'toCSS') ? $path[$i] : new %BOOTCODE%_Less_Tree_Anonymous($path[$i]);
			}

			for( $i = count($path) - 1; $i > 0; $i-- ){
				array_splice($path, $i, 0, array(new %BOOTCODE%_Less_Tree_Anonymous('and')));
			}

			$expressions[] = new %BOOTCODE%_Less_Tree_Expression($path);
		}
		$this->features = new %BOOTCODE%_Less_Tree_Value($expressions);



		// Fake a tree-node that doesn't output anything.
		return new %BOOTCODE%_Less_Tree_Ruleset(array(), array());
	}

	public function permute($arr) {
		if (!$arr)
			return array();

		if (count($arr) == 1)
			return $arr[0];

		$result = array();
		$rest = $this->permute(array_slice($arr, 1));
		foreach ($rest as $r) {
			foreach ($arr[0] as $a) {
				$result[] = array_merge(
					is_array($a) ? $a : array($a),
					is_array($r) ? $r : array($r)
				);
			}
		}

		return $result;
	}

	function bubbleSelectors($selectors) {
		$this->rules = array(new %BOOTCODE%_Less_Tree_Ruleset( $selectors, array($this->rules[0])));
	}

}



class %BOOTCODE%_Less_Tree_MixinCall extends %BOOTCODE%_Less_Tree{

	public $selector;
	public $arguments;
	public $index;
	public $currentFileInfo;

	public $important;
	public $type = 'MixinCall';

	/**
	 * less.js: tree.mixin.Call
	 *
	 */
	public function __construct($elements, $args, $index, $currentFileInfo, $important = false){
		$this->selector = new %BOOTCODE%_Less_Tree_Selector($elements);
		$this->arguments = $args;
		$this->index = $index;
		$this->currentFileInfo = $currentFileInfo;
		$this->important = $important;
	}

	//function accept($visitor){
	//	$this->selector = $visitor->visit($this->selector);
	//	$this->arguments = $visitor->visit($this->arguments);
	//}


	/**
	 * less.js: tree.mixin.Call.prototype()
	 *
	 */
	public function compile($env){

		$rules = array();
		$match = false;
		$isOneFound = false;

		$args = array();
		foreach($this->arguments as $a){
			$args[] = array('name'=> $a['name'], 'value' => $a['value']->compile($env) );
		}

		foreach($env->frames as $frame){
			$mixins = $frame->find($this->selector, null, $env);

			if( !$mixins ){
				continue;
			}

			$isOneFound = true;
			$mixins_len = count($mixins);
			for( $m = 0; $m < $mixins_len; $m++ ){
				$mixin = $mixins[$m];

				$isRecursive = false;
				foreach($env->frames as $recur_frame){
					if( !($mixin instanceof %BOOTCODE%_Less_Tree_MixinDefinition) ){
						if( (isset($recur_frame->originalRuleset) && $mixin->ruleset_id === $recur_frame->originalRuleset)
							|| ($mixin === $recur_frame) ){
							$isRecursive = true;
							break;
						}
					}
				}
				if( $isRecursive ){
					continue;
				}

				if ($mixin->matchArgs($args, $env)) {

					//if( !($mixin instanceof %BOOTCODE%_Less_Tree_Ruleset || $mixin instanceof %BOOTCODE%_Less_Tree_MixinDefinition) || $mixin->matchCondition($args, $env) ){
					if( !%BOOTCODE%_Less_Parser::is_method($mixin,'matchCondition') || $mixin->matchCondition($args, $env) ){
						try{

							if( !($mixin instanceof %BOOTCODE%_Less_Tree_MixinDefinition) ){
								$mixin = new %BOOTCODE%_Less_Tree_MixinDefinition('', array(), $mixin->rules, null, false);
								$mixin->originalRuleset = $mixins[$m]->originalRuleset;
							}
							//if (this.important) {
							//	isImportant = env.isImportant;
							//	env.isImportant = true;
							//}

							$rules = array_merge($rules, $mixin->compile($env, $args, $this->important)->rules);
							//if (this.important) {
							//	env.isImportant = isImportant;
							//}
						} catch (Exception $e) {
							//throw new %BOOTCODE%_Less_CompilerException($e->getMessage(), $e->index, null, $this->currentFileInfo['filename']);
							throw new %BOOTCODE%_Less_CompilerException($e->getMessage(), null, null, $this->currentFileInfo['filename']);
						}
					}
					$match = true;
				}

			}

			if( $match ){
				if( !$this->currentFileInfo || !isset($this->currentFileInfo['reference']) || !$this->currentFileInfo['reference'] ){
					foreach($rules as $rule){
						if( %BOOTCODE%_Less_Parser::is_method($rule,'markReferenced') ){
							$rule->markReferenced();
						}
					}
				}
				return $rules;
			}
		}


		if( $isOneFound ){

			$message = array();
			if( $args ){
				foreach($args as $a){
					$argValue = '';
					if( $a['name'] ){
						$argValue += $a['name']+':';
					}
					if( is_object($a['value']) ){
						$argValue += $a['value']->toCSS();
					}else{
						$argValue += '???';
					}
					$message[] = $argValue;
				}
			}
			$message = implode(', ',$message);


			throw new %BOOTCODE%_Less_CompilerException('No matching definition was found for `'.
				trim($this->selector->toCSS($env)) . '(' .$message.')',
				$this->index, null, $this->currentFileInfo['filename']);

		}else{
			throw new %BOOTCODE%_Less_CompilerException(trim($this->selector->toCSS($env)) . " is undefined", $this->index, null, $this->currentFileInfo['filename']);
		}
	}
}




class %BOOTCODE%_Less_Tree_MixinDefinition extends %BOOTCODE%_Less_Tree_Ruleset{
	public $name;
	public $selectors;
	public $params;
	public $arity;
	public $rules;
	public $lookups;
	public $required;
	public $frames;
	public $condition;
	public $variadic;
	public $type = 'MixinDefinition';


	// less.js : /lib/less/tree/mixin.js : tree.mixin.Definition
	public function __construct($name, $params, $rules, $condition, $variadic = false){
		$this->name = $name;
		$this->selectors = array(new %BOOTCODE%_Less_Tree_Selector(array( new %BOOTCODE%_Less_Tree_Element(null, $name))));

		$this->params = $params;
		$this->condition = $condition;
		$this->variadic = $variadic;
		$this->arity = count($params);
		$this->rules = $rules;
		$this->lookups = array();

		$this->required = 0;
		if( is_array($params) ){
			foreach( $params as $p ){
				if (! isset($p['name']) || ($p['name'] && !isset($p['value']))) {
					$this->required++;
				}
			}
		}

		$this->frames = array();
		$this->SetRulesetIndex();
	}



	//function accept( $visitor ){
	//	$this->params = $visitor->visit($this->params);
	//	$this->rules = $visitor->visit($this->rules);
	//	$this->condition = $visitor->visit($this->condition);
	//}


	public function toCSS($env = null){
		return '';
	}

	// less.js : /lib/less/tree/mixin.js : tree.mixin.Definition.evalParams
	public function compileParams($env, $mixinEnv, $args = array() , &$evaldArguments = array() ){
		$frame = new %BOOTCODE%_Less_Tree_Ruleset(null, array());
		$varargs;
		$params = $this->params;
		$val;
		$name;
		$isNamedFound;


		$mixinEnv = clone $mixinEnv;
		$mixinEnv->frames = array_merge( array($frame), $mixinEnv->frames);
		//$mixinEnv = $mixinEnv->copyEvalEnv( array_merge( array($frame), $mixinEnv->frames) );

		$args_len = count($args);
		for($i = 0; $i < $args_len; $i++ ){
			$arg = $args[$i];

			if( $arg && $arg['name'] ){
				$name = $arg['name'];
				$isNamedFound = false;

				foreach($params as $j => $param){
					if( !isset($evaldArguments[$j]) && $name === $params[$j]['name']) {
						$evaldArguments[$j] = $arg['value']->compile($env);
						array_unshift($frame->rules, new %BOOTCODE%_Less_Tree_Rule( $name, $arg['value']->compile($env) ) );
						$isNamedFound = true;
						break;
					}
				}
				if ($isNamedFound) {
					array_splice($args, $i, 1);
					$i--;
					$args_len--;
					continue;
				} else {
					throw new %BOOTCODE%_Less_CompilerException("Named argument for " . $this->name .' '.$args[$i]['name'] . ' not found');
				}
			}
		}

		$argIndex = 0;
		foreach($params as $i => $param){

			if ( isset($evaldArguments[$i]) ){ continue; }

			$arg = null;
			if( array_key_exists($argIndex,$args) && $args[$argIndex] ){
				$arg = $args[$argIndex];
			}

			if (isset($param['name']) && $param['name']) {
				$name = $param['name'];

				if( isset($param['variadic']) && $args ){
					$varargs = array();
					for ($j = $argIndex; $j < $args_len; $j++) {
						$varargs[] = $args[$j]['value']->compile($env);
					}
					$expression = new %BOOTCODE%_Less_Tree_Expression($varargs);
					array_unshift($frame->rules, new %BOOTCODE%_Less_Tree_Rule($param['name'], $expression->compile($env)));
				}else{
					$val = ($arg && $arg['value']) ? $arg['value'] : false;

					if ($val) {
						$val = $val->compile($env);
					} else if ( isset($param['value']) ) {
						$val = $param['value']->compile($mixinEnv);
						$frame->resetCache();
					} else {
						throw new %BOOTCODE%_Less_CompilerException("Wrong number of arguments for " . $this->name . " (" . count($args) . ' for ' . $this->arity . ")");
					}

					array_unshift($frame->rules, new %BOOTCODE%_Less_Tree_Rule($param['name'], $val));
					$evaldArguments[$i] = $val;
				}
			}

			if ( isset($param['variadic']) && $args) {
				for ($j = $argIndex; $j < $args_len; $j++) {
					$evaldArguments[$j] = $args[$j]['value']->compile($env);
				}
			}
			$argIndex++;
		}

		asort($evaldArguments);

		return $frame;
	}

	// less.js : /lib/less/tree/mixin.js : tree.mixin.Definition.eval
	public function compile($env, $args = NULL, $important = NULL) {
		$_arguments = array();

		$mixinFrames = array_merge($this->frames, $env->frames);

		$mixinEnv = new %BOOTCODE%_Less_Environment();
		$mixinEnv->addFrames($mixinFrames);

		$frame = $this->compileParams($env, $mixinEnv, $args, $_arguments);



		$ex = new %BOOTCODE%_Less_Tree_Expression($_arguments);
		array_unshift($frame->rules, new %BOOTCODE%_Less_Tree_Rule('@arguments', $ex->compile($env)));


		$rules = array_slice($this->rules,0);

		$ruleset = new %BOOTCODE%_Less_Tree_Ruleset(null, $rules);
		$ruleset->originalRuleset = $this->ruleset_id;


		$ruleSetEnv = $env->copyEvalEnv( array_merge( array($this, $frame), $mixinFrames ) );
		$ruleset = $ruleset->compile( $ruleSetEnv );

		if( $important ){
			$ruleset = $ruleset->makeImportant();
		}
		return $ruleset;
	}


	public function matchCondition($args, $env) {

		if( !$this->condition ){
			return true;
		}

		$frame = $this->compileParams($env, $env->copyEvalEnv(array_merge($this->frames,$env->frames)), $args );

		$compile_env = $env->copyEvalEnv(
			array_merge(
				array($frame)		// the parameter variables
				, $this->frames		// the parent namespace/mixin frames
				, $env->frames		// the current environment frames
			)
		);

		if( !$this->condition->compile($compile_env) ){
			return false;
		}

		return true;
	}

	public function matchArgs($args, $env = NULL){
		$argsLength = count($args);

		if( !$this->variadic ){
			if( $argsLength < $this->required ){
				return false;
			}
			if( $argsLength > count($this->params) ){
				return false;
			}
		}else{
			if( $argsLength < ($this->required - 1)){
				return false;
			}
		}

		$len = min($argsLength, $this->arity);

		for( $i = 0; $i < $len; $i++ ){
			if( !isset($this->params[$i]['name']) && !isset($this->params[$i]['variadic']) ){
				if( $args[$i]['value']->compile($env)->toCSS() != $this->params[$i]['value']->compile($env)->toCSS() ){
					return false;
				}
			}
		}

		return true;
	}

}



class %BOOTCODE%_Less_Tree_Negative extends %BOOTCODE%_Less_Tree{

	public $value;
	public $type = 'Negative';

	function __construct($node){
		$this->value = $node;
	}

	//function accept($visitor) {
	//	$this->value = $visitor->visit($this->value);
	//}

	function genCSS( $env, &$strs ){
		self::OutputAdd( $strs, '-' );
		$this->value->genCSS( $env, $strs );
	}

	function compile($env) {
		if( $env->isMathOn() ){
			$ret = new %BOOTCODE%_Less_Tree_Operation('*', array( new %BOOTCODE%_Less_Tree_Dimension(-1), $this->value ) );
			return $ret->compile($env);
		}
		return new %BOOTCODE%_Less_Tree_Negative( $this->value->compile($env) );
	}
}


class %BOOTCODE%_Less_Tree_Operation extends %BOOTCODE%_Less_Tree{

	public $type = 'Operation';

	public function __construct($op, $operands, $isSpaced = false){
		$this->op = trim($op);
		$this->operands = $operands;
		$this->isSpaced = $isSpaced;
	}

	function accept($visitor) {
		$this->operands = $visitor->visitArray($this->operands);
	}

	public function compile($env){
		$a = $this->operands[0]->compile($env);
		$b = $this->operands[1]->compile($env);


		if( $env->isMathOn() ){

			if( $a instanceof %BOOTCODE%_Less_Tree_Dimension ){

				if( $b instanceof %BOOTCODE%_Less_Tree_Color ){
					if ($this->op === '*' || $this->op === '+') {
						$temp = $b;
						$b = $a;
						$a = $temp;
					} else {
						throw new %BOOTCODE%_Less_CompilerException("Operation on an invalid type");
					}
				}
			}elseif( !($a instanceof %BOOTCODE%_Less_Tree_Color) ){
				throw new %BOOTCODE%_Less_CompilerException("Operation on an invalid type");
			}

			return $a->operate($env,$this->op, $b);
		} else {
			return new %BOOTCODE%_Less_Tree_Operation($this->op, array($a, $b), $this->isSpaced );
		}
	}

	function genCSS( $env, &$strs ){
		$this->operands[0]->genCSS( $env, $strs );
		if( $this->isSpaced ){
			self::OutputAdd( $strs, " " );
		}
		self::OutputAdd( $strs, $this->op );
		if( $this->isSpaced ){
			self::OutputAdd( $strs, ' ' );
		}
		$this->operands[1]->genCSS( $env, $strs );
	}

}


class %BOOTCODE%_Less_Tree_Paren extends %BOOTCODE%_Less_Tree{

	public $value;
	public $type = 'Paren';

	public function __construct($value) {
		$this->value = $value;
	}

	function accept($visitor){
		$this->value = $visitor->visitObj($this->value);
	}

	function genCSS( $env, &$strs ){
		self::OutputAdd( $strs, '(' );
		$this->value->genCSS( $env, $strs );
		self::OutputAdd( $strs, ')' );
	}

	public function compile($env) {
		return new %BOOTCODE%_Less_Tree_Paren($this->value->compile($env));
	}

}



class %BOOTCODE%_Less_Tree_Quoted extends %BOOTCODE%_Less_Tree{
	public $value;
	public $content;
	public $index;
	public $currentFileInfo;
	public $type = 'Quoted';

	public function __construct($str, $content = '', $escaped = false, $index = false, $currentFileInfo = null ){
		$this->escaped = $escaped;
		$this->value = $content;
		$this->quote = $str[0];
		$this->index = $index;
		$this->currentFileInfo = $currentFileInfo;
	}

    public function genCSS( $env, &$strs ){
		if( !$this->escaped ){
			self::OutputAdd( $strs, $this->quote, $this->currentFileInfo, $this->index );
        }
        self::OutputAdd( $strs, $this->value );
        if( !$this->escaped ){
			self::OutputAdd( $strs, $this->quote );
        }
    }

	public function compile($env){

		$value = $this->value;
		if( preg_match_all('/`([^`]+)`/', $this->value, $matches) ){
			foreach($matches as $i => $match){
				$js = new %BOOTCODE%_Less_Tree_JavaScript($matches[1], $this->index, true);
				$js = $js->compile($env)->value;
				$value = str_replace($matches[0][$i], $js, $value);
			}
		}

		if( preg_match_all('/@\{([\w-]+)\}/',$value,$matches) ){
			foreach($matches[1] as $i => $match){
				$v = new %BOOTCODE%_Less_Tree_Variable('@' . $match, $this->index, $this->currentFileInfo );
				$v = $v->compile($env,true);
				$v = ($v instanceof %BOOTCODE%_Less_Tree_Quoted) ? $v->value : $v->toCSS($env);
				$value = str_replace($matches[0][$i], $v, $value);
			}
		}

		return new %BOOTCODE%_Less_Tree_Quoted($this->quote . $value . $this->quote, $value, $this->escaped, $this->index);
	}

	function compare($x) {

		if( !%BOOTCODE%_Less_Parser::is_method($x, 'toCSS') ){
			return -1;
		}

		$left = $this->toCSS();
		$right = $x->toCSS();

		if ($left === $right) {
			return 0;
		}

		return $left < $right ? -1 : 1;
	}
}



class %BOOTCODE%_Less_Tree_Rule extends %BOOTCODE%_Less_Tree{

	public $name;
	public $value;
	public $important;
	public $merge;
	public $index;
	public $inline;
	public $variable;
	public $currentFileInfo;
	public $type = 'Rule';

	public function __construct($name, $value = null, $important = null, $merge = null, $index = null, $currentFileInfo = null,  $inline = false){
		$this->name = $name;
		$this->value = ($value instanceof %BOOTCODE%_Less_Tree_Value) ? $value : new %BOOTCODE%_Less_Tree_Value(array($value));
		$this->important = $important ? ' ' . trim($important) : '';
		$this->merge = $merge;
		$this->index = $index;
		$this->currentFileInfo = $currentFileInfo;
		$this->inline = $inline;
		$this->variable = ($name[0] === '@');
	}

	function accept($visitor) {
		$this->value = $visitor->visitObj( $this->value );
	}

	function genCSS( $env, &$strs ){

		self::OutputAdd( $strs, $this->name . %BOOTCODE%_Less_Environment::$colon_space, $this->currentFileInfo, $this->index);
		try{
			$this->value->genCSS($env, $strs);

		}catch( Exception $e ){
			$e->index = $this->index;
			$e->filename = $this->currentFileInfo['filename'];
			throw e;
		}
		self::OutputAdd( $strs, $this->important . (($this->inline || ($env->lastRule && %BOOTCODE%_Less_Environment::$compress)) ? "" : ";"), $this->currentFileInfo, $this->index);
	}

	public function compile ($env){

		$return = null;
		$strictMathBypass = false;
		if( $this->name === "font" && !$env->strictMath ){
			$strictMathBypass = true;
			$env->strictMath = true;
		}
		try{
			$return = new %BOOTCODE%_Less_Tree_Rule($this->name,
										$this->value->compile($env),
										$this->important,
										$this->merge,
										$this->index,
										$this->currentFileInfo,
										$this->inline);
		}
		catch(Exception $e){}

		if( $strictMathBypass ){
			$env->strictMath = false;
		}

		return $return;
	}

	function makeImportant(){
		return new %BOOTCODE%_Less_Tree_Rule($this->name, $this->value, '!important', $this->merge, $this->index, $this->currentFileInfo, $this->inline);
	}

}



class %BOOTCODE%_Less_Tree_Ruleset extends %BOOTCODE%_Less_Tree{

	protected $lookups;
	public $_variables;
	public $_rulesets;

	public $strictImports;

	public $selectors;
	public $rules;
	public $root;
	public $allowImports;
	public $paths;
	public $firstRoot;
	public $type = 'Ruleset';


	var $ruleset_id;
	var $originalRuleset;


	public function SetRulesetIndex(){
		static $i = 0;
		$this->ruleset_id = $i++;
		$this->originalRuleset = $this->ruleset_id;
	}

	public function __construct($selectors, $rules, $strictImports = null){
		$this->selectors = $selectors;
		$this->rules = $rules;
		$this->lookups = array();
		$this->strictImports = $strictImports;
		$this->SetRulesetIndex();
	}

	function accept( $visitor ){
		if( $this->paths ){
			$paths_len = count($this->paths);
			for($i = 0,$paths_len; $i < $paths_len; $i++ ){
				$this->paths[$i] = $visitor->visitArray($this->paths[$i]);
			}
		}elseif( $this->selectors ){
			$this->selectors = $visitor->visitArray($this->selectors);
		}

		if( $this->rules ){
			$this->rules = $visitor->visitArray($this->rules);
		}
	}

	public function compile($env){

		$selectors = array();
		if( $this->selectors ){
			foreach($this->selectors as $s){
				$selectors[] = $s->compile($env);
			}
		}
		$ruleset = new %BOOTCODE%_Less_Tree_Ruleset($selectors, $this->rules, $this->strictImports);
		$rules = array();

		$ruleset->originalRuleset = $this->ruleset_id;

		$ruleset->root = $this->root;
		$ruleset->firstRoot = $this->firstRoot;
		$ruleset->allowImports = $this->allowImports;

		// push the current ruleset to the frames stack
		$env->unshiftFrame($ruleset);

		// currrent selectors
		array_unshift($env->selectors,$this->selectors);


		// Evaluate imports
		if ($ruleset->root || $ruleset->allowImports || !$ruleset->strictImports) {
			$ruleset->evalImports($env);
		}


		// Store the frames around mixin definitions,
		// so they can be evaluated like closures when the time comes.
		$ruleset_len = count($ruleset->rules);
		for( $i = 0; $i < $ruleset_len; $i++ ){
			if( $ruleset->rules[$i] instanceof %BOOTCODE%_Less_Tree_MixinDefinition ){
				$ruleset->rules[$i]->frames = array_slice($env->frames,0);
			}
		}

		$mediaBlockCount = 0;
		if( $env instanceof %BOOTCODE%_Less_Environment ){
			$mediaBlockCount = count($env->mediaBlocks);
		}

		// Evaluate mixin calls.
		for($i=0; $i < $ruleset_len; $i++){
			$rule = $ruleset->rules[$i];
			if( $rule instanceof %BOOTCODE%_Less_Tree_MixinCall ){
				$rules = $rule->compile($env);

				$temp = array();
				foreach($rules as $r){
					if( ($r instanceof %BOOTCODE%_Less_Tree_Rule) && $r->variable ){
						// do not pollute the scope if the variable is
						// already there. consider returning false here
						// but we need a way to "return" variable from mixins
						if( !$ruleset->variable($r->name) ){
							$temp[] = $r;
						}
					}else{
						$temp[] = $r;
					}
				}
				$temp_count = count($temp)-1;
				array_splice($ruleset->rules, $i, 1, $temp);
				$ruleset_len += $temp_count;
				$i += $temp_count;
				$ruleset->resetCache();
			}
		}


		for( $i=0; $i<$ruleset_len; $i++ ){
			if(! ($ruleset->rules[$i] instanceof %BOOTCODE%_Less_Tree_MixinDefinition) ){
				$ruleset->rules[$i] = $ruleset->rules[$i]->compile($env);
			}
		}


		// Pop the stack
		$env->shiftFrame();
		array_shift($env->selectors);

		if ($mediaBlockCount) {
			$len = count($env->mediaBlocks);
			for($i = $mediaBlockCount; $i < $len; $i++ ){
				$env->mediaBlocks[$i]->bubbleSelectors($selectors);
			}
		}

		return $ruleset;
	}

	function evalImports($env) {

		$rules_len = count($this->rules);
		for($i=0; $i < $rules_len; $i++){
			$rule = $this->rules[$i];

			if( $rule instanceof %BOOTCODE%_Less_Tree_Import ){
				$rules = $rule->compile($env);
				if( is_array($rules) ){
					array_splice($this->rules, $i, 1, $rules);
					$temp_count = count($rules)-1;
					$i += $temp_count;
					$rules_len += $temp_count;
				}else{
					array_splice($this->rules, $i, 1, array($rules));
				}

				$this->resetCache();
			}
		}
	}

	function makeImportant(){

		$important_rules = array();
		foreach($this->rules as $rule){
			if( $rule instanceof %BOOTCODE%_Less_Tree_Rule || $rule instanceof %BOOTCODE%_Less_Tree_Ruleset ){
				$important_rules[] = $rule->makeImportant();
			}else{
				$important_rules[] = $rule;
			}
		}

		return new %BOOTCODE%_Less_Tree_Ruleset($this->selectors, $important_rules, $this->strictImports );
	}

	public function matchArgs($args){
		return !is_array($args) || !$args;
	}

	public function matchCondition( $args, $env ){
		$lastSelector = end($this->selectors);
		if( $lastSelector->condition && !$lastSelector->condition->compile( $env->copyEvalEnv( $env->frames ) ) ){
			return false;
		}
		return true;
	}

	function resetCache() {
		$this->_rulesets = null;
		$this->_variables = null;
		$this->lookups = array();
	}

	public function variables(){

		if( !$this->_variables ){
			$this->_variables = array();
			foreach( $this->rules as $r){
				if ($r instanceof %BOOTCODE%_Less_Tree_Rule && $r->variable === true) {
					$this->_variables[$r->name] = $r;
				}
			}
		}

		return $this->_variables;
	}

	public function variable($name){
		$vars = $this->variables();
		return isset($vars[$name]) ? $vars[$name] : null;
	}

	public function find( $selector, $self = null, $env = null){

		if( !$self ){
			$self = $this;
		}

		$key = $selector->toCSS($env);

		if( !array_key_exists($key, $this->lookups) ){
			$this->lookups[$key] = array();;


			foreach($this->rules as $rule){

				if( $rule == $self ){
					continue;
				}

				if( ($rule instanceof %BOOTCODE%_Less_Tree_Ruleset) || ($rule instanceof %BOOTCODE%_Less_Tree_MixinDefinition) ){

					foreach( $rule->selectors as $ruleSelector ){
						$match = $selector->match($ruleSelector);
						if( $match ){
							if( $selector->elements_len > $match ){
								$this->lookups[$key] = array_merge($this->lookups[$key], $rule->find( new %BOOTCODE%_Less_Tree_Selector(array_slice($selector->elements, $match)), $self, $env));
							} else {
								$this->lookups[$key][] = $rule;
							}
							break;
						}
					}
				}
			}
		}

		return $this->lookups[$key];
	}

	public function genCSS( $env, &$strs ){
		$ruleNodes = array();
		$rulesetNodes = array();
		$firstRuleset = true;

		if( !$this->root ){
			$env->tabLevel++;
		}

		$tabRuleStr = $tabSetStr = '';
		if( !%BOOTCODE%_Less_Environment::$compress && $env->tabLevel ){
			$tabRuleStr = str_repeat( '  ' , $env->tabLevel );
			$tabSetStr = str_repeat( '  ' , $env->tabLevel-1 );
		}

		foreach($this->rules as $rule){

			$class = get_class($rule);
			if( ($class === '%BOOTCODE%_Less_Tree_Media') || ($class === '%BOOTCODE%_Less_Tree_Directive') || ($this->root && $class === '%BOOTCODE%_Less_Tree_Comment') || ($class === '%BOOTCODE%_Less_Tree_Ruleset' && $rule->rules) ){
				$rulesetNodes[] = $rule;
			}else{
				$ruleNodes[] = $rule;
			}
		}

		// If this is the root node, we don't render
		// a selector, or {}.
		if( !$this->root ){

			/*
			debugInfo = tree.debugInfo(env, this, tabSetStr);

			if (debugInfo) {
				output.add(debugInfo);
				output.add(tabSetStr);
			}
			*/

			for( $i = 0,$paths_len = count($this->paths); $i < $paths_len; $i++ ){
				$path = $this->paths[$i];
				%BOOTCODE%_Less_Environment::$firstSelector = true;
				foreach($path as $p){
					$p->genCSS($env, $strs );
					%BOOTCODE%_Less_Environment::$firstSelector = false;
				}
				if( $i + 1 < $paths_len ){
					self::OutputAdd( $strs, %BOOTCODE%_Less_Environment::$compress ? ',' : (",\n" . $tabSetStr) );
				}
			}

			self::OutputAdd( $strs, (%BOOTCODE%_Less_Environment::$compress ? '{' : " {\n") . $tabRuleStr );
		}

		// Compile rules and rulesets
		$ruleNodes_len = count($ruleNodes);
		$rulesetNodes_len = count($rulesetNodes);
		for( $i = 0; $i < $ruleNodes_len; $i++ ){
			$rule = $ruleNodes[$i];

			// @page{ directive ends up with root elements inside it, a mix of rules and rulesets
			// In this instance we do not know whether it is the last property
			if( $i + 1 === $ruleNodes_len && (!$this->root || $rulesetNodes_len === 0 || $this->firstRoot ) ){
				$env->lastRule = true;
			}

			if( is_object($rule) ){
				if( method_exists($rule,'genCSS') ){
					$rule->genCSS( $env, $strs );
				}elseif( property_exists($rule,'value') && $rule->value ){
					self::OutputAdd( $strs, (string)$rule->value );
				}
			}

			if( !$env->lastRule ){
				self::OutputAdd( $strs, %BOOTCODE%_Less_Environment::$compress ? '' : ("\n" . $tabRuleStr) );
			}else{
				$env->lastRule = false;
			}
		}

		if( !$this->root ){
			self::OutputAdd( $strs, (%BOOTCODE%_Less_Environment::$compress ? '}' : "\n" . $tabSetStr . '}'));
			$env->tabLevel--;
		}

		for( $i = 0; $i < $rulesetNodes_len; $i++ ){
			if( $ruleNodes_len && $firstRuleset ){
				self::OutputAdd( $strs, (%BOOTCODE%_Less_Environment::$compress ? "" : "\n") . ($this->root ? $tabRuleStr : $tabSetStr) );
			}
			if( !$firstRuleset ){
				self::OutputAdd( $strs, (%BOOTCODE%_Less_Environment::$compress ? "" : "\n") . ($this->root ? $tabRuleStr : $tabSetStr));
			}
			$firstRuleset = false;
			$rulesetNodes[$i]->genCSS($env, $strs);
		}

		if( !$strs && !%BOOTCODE%_Less_Environment::$compress && $this->firstRoot ){
			self::OutputAdd( $strs, "\n" );
		}

	}

	function markReferenced(){

		foreach($this->selectors as $selector){
			$selector->markReferenced();
		}
	}

	public function joinSelectors( $context, $selectors ){
		$paths = array();
		if( is_array($selectors) ){
			foreach($selectors as $selector) {
				$this->joinSelector( $paths, $context, $selector);
			}
		}
		return $paths;
	}

	public function joinSelector( &$paths, $context, $selector){

		$hasParentSelector = false;

		foreach($selector->elements as $el) {
			if( $el->value === '&') {
				$hasParentSelector = true;
			}
		}

		if( !$hasParentSelector ){
			if( $context ){
				foreach($context as $context_el){
					$paths[] = array_merge($context_el, array($selector) );
				}
			}else {
				$paths[] = array($selector);
			}
			return;
		}


		// The paths are [[Selector]]
		// The first list is a list of comma seperated selectors
		// The inner list is a list of inheritance seperated selectors
		// e.g.
		// .a, .b {
		//   .c {
		//   }
		// }
		// == [[.a] [.c]] [[.b] [.c]]
		//

		// the elements from the current selector so far
		$currentElements = array();
		// the current list of new selectors to add to the path.
		// We will build it up. We initiate it with one empty selector as we "multiply" the new selectors
		// by the parents
		$newSelectors = array(array());


		foreach( $selector->elements as $el){

			// non parent reference elements just get added
			if( $el->value !== '&' ){
				$currentElements[] = $el;
			} else {
				// the new list of selectors to add
				$selectorsMultiplied = array();

				// merge the current list of non parent selector elements
				// on to the current list of selectors to add
				if( $currentElements ){
					$this->mergeElementsOnToSelectors( $currentElements, $newSelectors);
				}

				// loop through our current selectors
				foreach($newSelectors as $sel){

					// if we don't have any parent paths, the & might be in a mixin so that it can be used
					// whether there are parents or not
					if( !$context ){
						// the combinator used on el should now be applied to the next element instead so that
						// it is not lost
						if( $sel ){
							$sel[0]->elements = array_slice($sel[0]->elements,0);
							$sel[0]->elements[] = new %BOOTCODE%_Less_Tree_Element($el->combinator, '', 0, $el->index, $el->currentFileInfo );
						}
						$selectorsMultiplied[] = $sel;
					}else {

						// and the parent selectors
						foreach($context as $parentSel){
							// We need to put the current selectors
							// then join the last selector's elements on to the parents selectors

							// our new selector path
							$newSelectorPath = array();
							// selectors from the parent after the join
							$afterParentJoin = array();
							$newJoinedSelectorEmpty = true;

							//construct the joined selector - if & is the first thing this will be empty,
							// if not newJoinedSelector will be the last set of elements in the selector
							if( $sel ){
								$newSelectorPath = $sel;
								$lastSelector = array_pop($newSelectorPath);
								$newJoinedSelector = $selector->createDerived( array_slice($lastSelector->elements,0) );
								$newJoinedSelectorEmpty = false;
							}
							else {
								$newJoinedSelector = $selector->createDerived(array());
							}

							//put together the parent selectors after the join
							if ( count($parentSel) > 1) {
								$afterParentJoin = array_merge($afterParentJoin, array_slice($parentSel,1) );
							}

							if ( $parentSel ){
								$newJoinedSelectorEmpty = false;

								// join the elements so far with the first part of the parent
								$newJoinedSelector->elements[] = new %BOOTCODE%_Less_Tree_Element( $el->combinator, $parentSel[0]->elements[0]->value, 0, $el->index, $el->currentFileInfo);

								$newJoinedSelector->elements = array_merge( $newJoinedSelector->elements, array_slice($parentSel[0]->elements, 1) );
							}

							if (!$newJoinedSelectorEmpty) {
								// now add the joined selector
								$newSelectorPath[] = $newJoinedSelector;
							}

							// and the rest of the parent
							$newSelectorPath = array_merge($newSelectorPath, $afterParentJoin);

							// add that to our new set of selectors
							$selectorsMultiplied[] = $newSelectorPath;
						}
					}
				}

				// our new selectors has been multiplied, so reset the state
				$newSelectors = $selectorsMultiplied;
				$currentElements = array();
			}
		}

		// if we have any elements left over (e.g. .a& .b == .b)
		// add them on to all the current selectors
		if( $currentElements ){
			$this->mergeElementsOnToSelectors($currentElements, $newSelectors);
		}
		foreach( $newSelectors as $new_sel){
			if( $new_sel ){
				$paths[] = $new_sel;
			}
		}
	}

	function mergeElementsOnToSelectors( $elements, &$selectors){

		if( !$selectors ){
			$selectors[] = array( new %BOOTCODE%_Less_Tree_Selector($elements) );
			return;
		}


		foreach( $selectors as &$sel){

			// if the previous thing in sel is a parent this needs to join on to it
			if( $sel ){
				$last = count($sel)-1;
				$sel[$last] = $sel[$last]->createDerived( array_merge($sel[$last]->elements, $elements) );
			}else{
				$sel[] = new %BOOTCODE%_Less_Tree_Selector( $elements );
			}
		}
	}
}



class %BOOTCODE%_Less_Tree_Selector extends %BOOTCODE%_Less_Tree{

	public $elements;
	public $extendList = array();
	public $_css;
	public $index;
	public $evaldCondition = false;
	public $type = 'Selector';
	public $elements_len = 0;

	public function __construct($elements, $extendList=array() , $condition = null, $index=null, $currentFileInfo=array(), $isReferenced=null ){
		$this->elements = $elements;
		$this->elements_len = count($elements);
		$this->extendList = $extendList;
		$this->condition = $condition;
		$this->currentFileInfo = $currentFileInfo;
		$this->isReferenced = $isReferenced;
		if( !$condition ){
			$this->evaldCondition = true;
		}
	}

	function accept($visitor) {
		$this->elements = $visitor->visitArray($this->elements);
		$this->extendList = $visitor->visitArray($this->extendList);
		if( $this->condition ){
			$this->condition = $visitor->visitObj($this->condition);
		}
	}

	function createDerived( $elements, $extendList = null, $evaldCondition = null ){
		$evaldCondition = $evaldCondition != null ? $evaldCondition : $this->evaldCondition;
		$newSelector = new %BOOTCODE%_Less_Tree_Selector( $elements, ($extendList ? $extendList : $this->extendList), $this->condition, $this->index, $this->currentFileInfo, $this->isReferenced);
		$newSelector->evaldCondition = $evaldCondition;
		return $newSelector;
	}

	public function match($other) {
		global $debug;

		if( !$other ){
			return 0;
		}

		$offset = 0;
		$olen = $other->elements_len;
		if( $olen ){
			if( $other->elements[0]->value === "&" ){
				$offset = 1;
			}
			$olen -= $offset;
		}

		if( $olen === 0 ){
			return 0;
		}

		if( $this->elements_len < $olen ){
			return 0;
		}

		for ($i = 0; $i < $olen; $i ++) {
			if ($this->elements[$i]->value !== $other->elements[$i + $offset]->value) {
				return 0;
			}
		}

		return $olen; // return number of matched selectors
	}

	public function compile($env) {

		$elements = array();
		foreach($this->elements as $el){
			$elements[] = $el->compile($env);
		}

		$extendList = array();
		foreach($this->extendList as $el){
			$extendList[] = $el->compile($el);
		}

		$evaldCondition = false;
		if( $this->condition ){
			$evaldCondition = $this->condition->compile($env);
		}

		return $this->createDerived( $elements, $extendList, $evaldCondition );
	}

	function genCSS( $env, &$strs ){

		if( !%BOOTCODE%_Less_Environment::$firstSelector && $this->elements[0]->combinator->value === "" ){
			self::OutputAdd( $strs, ' ', $this->currentFileInfo, $this->index );
		}
		if( !$this->_css ){
			//TODO caching? speed comparison?
			foreach($this->elements as $element){
				$element->genCSS( $env, $strs );
			}
		}
	}

	function markReferenced(){
		$this->isReferenced = true;
	}

	function getIsReferenced(){
		return !isset($this->currentFileInfo['reference']) || !$this->currentFileInfo['reference'] || $this->isReferenced;
	}

	function getIsOutput(){
		return $this->evaldCondition;
	}

}



class %BOOTCODE%_Less_Tree_UnicodeDescriptor extends %BOOTCODE%_Less_Tree{

	public $type = 'UnicodeDescriptor';

	public function __construct($value){
		$this->value = $value;
	}

	public function genCSS( $env, &$strs ){
		self::OutputAdd( $strs, $this->value );
	}

	public function compile($env){
		return $this;
	}
}




class %BOOTCODE%_Less_Tree_Url extends %BOOTCODE%_Less_Tree{

	public $attrs;
	public $value;
	public $currentFileInfo;
	public $type = 'Url';

	public function __construct($value, $currentFileInfo = null){
		$this->value = $value;
		$this->currentFileInfo = $currentFileInfo;
	}

	function accept( $visitor ){
		$this->value = $visitor->visitObj($this->value);
	}

	function genCSS( $env, &$strs ){
		self::OutputAdd( $strs, 'url(' );
		$this->value->genCSS( $env, $strs );
		self::OutputAdd( $strs, ')' );
	}

	public function compile($ctx){
		$val = $this->value->compile($ctx);

		// Add the base path if the URL is relative
		if( $this->currentFileInfo && is_string($val->value) && %BOOTCODE%_Less_Environment::isPathRelative($val->value) ){
			$rootpath = $this->currentFileInfo['uri_root'];
			if ( !$val->quote ){
				$rootpath = preg_replace('/[\(\)\'"\s]/', '\\$1', $rootpath );
			}
			$val->value = $rootpath . $val->value;
		}

		$val->value = %BOOTCODE%_Less_Environment::normalizePath( $val->value);

		return new %BOOTCODE%_Less_Tree_URL($val, null);
	}

}



class %BOOTCODE%_Less_Tree_Value extends %BOOTCODE%_Less_Tree{

	public $type = 'Value';

	public function __construct($value){
		$this->value = $value;
	}

	function accept($visitor) {
		$this->value = $visitor->visitArray($this->value);
	}

	public function compile($env){

		$ret = array();
		foreach($this->value as $i => $v){
			$ret[] = $v->compile($env);
		}
		if( $i > 0 ){
			return new %BOOTCODE%_Less_Tree_Value($ret);
		}
		return $ret[0];
	}

	function genCSS( $env, &$strs ){
		$len = count($this->value);
		for($i = 0; $i < $len; $i++ ){
			$this->value[$i]->genCSS( $env, $strs);
			if( $i+1 < $len ){
				self::OutputAdd( $strs, %BOOTCODE%_Less_Environment::$comma_space );
			}
		}
	}

}



class %BOOTCODE%_Less_Tree_Variable extends %BOOTCODE%_Less_Tree{

	public $name;
	public $index;
	public $currentFileInfo;
	public $evaluating = false;
	public $type = 'Variable';

    public function __construct($name, $index, $currentFileInfo = null) {
        $this->name = $name;
        $this->index = $index;
		$this->currentFileInfo = $currentFileInfo;
    }

	public function compile($env) {
		$name = $this->name;
		if (strpos($name, '@@') === 0) {
			$v = new %BOOTCODE%_Less_Tree_Variable(substr($name, 1), $this->index + 1);
			$name = '@' . $v->compile($env)->value;
		}

		if ($this->evaluating) {
			throw new %BOOTCODE%_Less_CompilerException("Recursive variable definition for " . $name, $this->index, null, $this->currentFileInfo['filename']);
		}

		$this->evaluating = true;


		foreach($env->frames as $frame){
			if( $v = $frame->variable($name) ){
				$this->evaluating = false;
				return $v->value->compile($env);
			}
		}

		throw new %BOOTCODE%_Less_CompilerException("variable " . $name . " is undefined", $this->index, null);
	}

}



class %BOOTCODE%_Less_extendFinderVisitor extends %BOOTCODE%_Less_visitor{

	public $contexts = array();
	public $allExtendsStack;
	public $foundExtends;

	function __construct(){
		$this->contexts = array();
		$this->allExtendsStack = array(array());
		parent::__construct();
	}

	function run($root){
		$root = $this->visitObj($root);
		$root->allExtends =& $this->allExtendsStack[0];
		return $root;
	}

	function visitRule($ruleNode, &$visitDeeper ){
		$visitDeeper = false;
	}

	function visitMixinDefinition( $mixinDefinitionNode, &$visitDeeper ){
		$visitDeeper = false;
	}

	function visitRuleset($rulesetNode){

		if( $rulesetNode->root ){
			return;
		}

		$allSelectorsExtendList = array();

		// get &:extend(.a); rules which apply to all selectors in this ruleset
		$rules = $rulesetNode->rules;
		$ruleCnt = count($rules);
		for($i = 0; $i < $ruleCnt; $i++ ){
			if( $rules[$i] instanceof %BOOTCODE%_Less_Tree_Extend ){
				$allSelectorsExtendList[] = $rules[$i];
				$rulesetNode->extendOnEveryPath = true;
			}
		}



		// now find every selector and apply the extends that apply to all extends
		// and the ones which apply to an individual extend
		$paths = $rulesetNode->paths;
		$paths_len = count($paths);
		for($i = 0; $i < $paths_len; $i++ ){

			$selectorPath = $paths[$i];
			$selector = end($selectorPath); //$selectorPath[ count($selectorPath)-1];


			$list = array_merge($selector->extendList, $allSelectorsExtendList);

			$extendList = array();
			foreach($list as $allSelectorsExtend){
				$extendList[] = clone $allSelectorsExtend;
			}

			$extendList_len = count($extendList);
			for($j = 0; $j < $extendList_len; $j++ ){
				$this->foundExtends = true;
				$extend = $extendList[$j];
				$extend->findSelfSelectors( $selectorPath );
				$extend->ruleset = $rulesetNode;
				if( $j === 0 ){ $extend->firstExtendOnThisSelectorPath = true; }

				$temp = count($this->allExtendsStack)-1;
				$this->allExtendsStack[ $temp ][] = $extend;
			}
		}

		$this->contexts[] = $rulesetNode->selectors;
	}

	function visitRulesetOut( $rulesetNode ){
		if( !is_object($rulesetNode) || !$rulesetNode->root ){
			array_pop($this->contexts);
		}
	}

	function visitMedia( $mediaNode ){
		$mediaNode->allExtends = array();
		$this->allExtendsStack[] =& $mediaNode->allExtends;
	}

	function visitMediaOut( $mediaNode ){
		array_pop($this->allExtendsStack);
	}

	function visitDirective( $directiveNode ){
		$directiveNode->allExtends = array();
		$this->allExtendsStack[] =& $directiveNode->allExtends;
	}

	function visitDirectiveOut( $directiveNode ){
		array_pop($this->allExtendsStack);
	}
}




/*
class %BOOTCODE%_Less_importVisitor extends %BOOTCODE%_Less_visitor_replacing{

	public $_visitor;
	public $_importer;
	public $importCount;

	function __construct( $importer = null, $evalEnv = null ){
		$this->_visitor = new %BOOTCODE%_Less_visitor($this);
		$this->_importer = $importer;
		if( $evalEnv ){
			$this->env = $evalEnv;
		}else{
			$this->env = new %BOOTCODE%_Less_Environment();
		}
		$this->importCount = 0;
	}


	function run( $root ){
		// process the contents
		$this->_visitor->visitObj($root);

		$this->isFinished = true;

		//if( $this->importCount === 0) {
		//	$this->_finish();
		//}
	}

	function visitImport($importNode, &$visitArgs ){
		$importVisitor = $this;

		$visitArgs['visitDeeper'] = false;

		if( $importNode->css ){
			return $importNode;
		}

		$evaldImportNode = $importNode->compileForImport($this->env);

		if( $evaldImportNode && !$evaldImportNode->css ){
			$importNode = $evaldImportNode;
			$this->importCount++;
		}

		return $importNode;
	}


	function visitRule( $ruleNode, &$visitArgs ){
		$visitArgs['visitDeeper'] = false;
		return $ruleNode;
	}

	function visitDirective($directiveNode, $visitArgs){
		array_unshift($this->env->frames,$directiveNode);
		return $directiveNode;
	}

	function visitDirectiveOut($directiveNode) {
		array_shift($this->env->frames);
	}

	function visitMixinDefinition($mixinDefinitionNode, $visitArgs) {
		array_unshift($this->env->frames,$mixinDefinitionNode);
		return $mixinDefinitionNode;
	}

	function visitMixinDefinitionOut($mixinDefinitionNode) {
		array_shift($this->env->frames);
	}

	function visitRuleset($rulesetNode, $visitArgs) {
		array_unshift($this->env->frames,$rulesetNode);
		return $rulesetNode;
	}

	function visitRulesetOut($rulesetNode) {
		array_shift($this->env->frames);
	}

	function visitMedia($mediaNode, $visitArgs) {
		array_unshift($this->env->frames, $mediaNode->ruleset);
		return $mediaNode;
	}

	function visitMediaOut($mediaNode) {
		array_shift($this->env->frames);
	}

}
*/

class %BOOTCODE%_Less_joinSelectorVisitor extends %BOOTCODE%_Less_visitor{

	public $contexts = array( array() );

	function run( $root ){
		return $this->visitObj($root);
	}

	function visitRule( $ruleNode, &$visitDeeper ){
		$visitDeeper = false;
	}

	function visitMixinDefinition( $mixinDefinitionNode, &$visitDeeper ){
		$visitDeeper = false;
	}

	function visitRuleset( $rulesetNode ){

		$paths = array();

		if( !$rulesetNode->root ){
			$selectors = array();

			if( $rulesetNode->selectors && count($rulesetNode->selectors) ){
				foreach($rulesetNode->selectors as $selector){
					if( $selector->getIsOutput() ){
						$selectors[] = $selector;
					}
				}
			}

			if( !count($selectors) ){
				$rulesetNode->selectors = $selectors = null;
				$rulesetNode->rules = null;
			}else{
				$context = end($this->contexts); //$context = $this->contexts[ count($this->contexts) - 1];
				$paths = $rulesetNode->joinSelectors( $context, $selectors);
			}

			$rulesetNode->paths = $paths;
		}

		$this->contexts[] = $paths; //different from less.js. Placed after joinSelectors() so that $this->contexts will get correct $paths
	}

	function visitRulesetOut( $rulesetNode ){
		array_pop($this->contexts);
	}

	function visitMedia($mediaNode) {
		$context = end($this->contexts); //$context = $this->contexts[ count($this->contexts) - 1];

		if( !count($context) || (is_object($context[0]) && @$context[0]->multiMedia) ){
			$mediaNode->rules[0]->root = true;
		}
	}

}




class %BOOTCODE%_Less_processExtendsVisitor extends %BOOTCODE%_Less_visitor{

	public $allExtendsStack;

	function run( $root ){
		$extendFinder = new %BOOTCODE%_Less_extendFinderVisitor();
		$extendFinder->run( $root );
		if( !$extendFinder->foundExtends) { return $root; }

		$root->allExtends = $this->doExtendChaining( $root->allExtends, $root->allExtends);

		$this->allExtendsStack = array();
		$this->allExtendsStack[] = &$root->allExtends;

		return $this->visitObj( $root );
	}

	function doExtendChaining( $extendsList, $extendsListTarget, $iterationCount = 0){
		//
		// chaining is different from normal extension.. if we extend an extend then we are not just copying, altering and pasting
		// the selector we would do normally, but we are also adding an extend with the same target selector
		// this means this new extend can then go and alter other extends
		//
		// this method deals with all the chaining work - without it, extend is flat and doesn't work on other extend selectors
		// this is also the most expensive.. and a match on one selector can cause an extension of a selector we had already processed if
		// we look at each selector at a time, as is done in visitRuleset

		$extendsToAdd = array();


		//loop through comparing every extend with every target extend.
		// a target extend is the one on the ruleset we are looking at copy/edit/pasting in place
		// e.g. .a:extend(.b) {} and .b:extend(.c) {} then the first extend extends the second one
		// and the second is the target.
		// the seperation into two lists allows us to process a subset of chains with a bigger set, as is the
		// case when processing media queries
		for( $extendIndex = 0, $extendsList_len = count($extendsList); $extendIndex < $extendsList_len; $extendIndex++ ){
			for( $targetExtendIndex = 0; $targetExtendIndex < count($extendsListTarget); $targetExtendIndex++ ){

				$extend = $extendsList[$extendIndex];
				$targetExtend = $extendsListTarget[$targetExtendIndex];

				// look for circular references
				if( in_array($targetExtend->object_id, $extend->parent_ids,true) ){
					continue;
				}

				// find a match in the target extends self selector (the bit before :extend)
				$selectorPath = array( $targetExtend->selfSelectors[0] );
				$matches = $this->findMatch( $extend, $selectorPath);


				if( $matches ){

					// we found a match, so for each self selector..
					foreach($extend->selfSelectors as $selfSelector ){


						// process the extend as usual
						$newSelector = $this->extendSelector( $matches, $selectorPath, $selfSelector);

						// but now we create a new extend from it
						$newExtend = new %BOOTCODE%_Less_Tree_Extend( $targetExtend->selector, $targetExtend->option, 0);
						$newExtend->selfSelectors = $newSelector;

						// add the extend onto the list of extends for that selector
						end($newSelector)->extendList = array($newExtend);
						//$newSelector[ count($newSelector)-1]->extendList = array($newExtend);

						// record that we need to add it.
						$extendsToAdd[] = $newExtend;
						$newExtend->ruleset = $targetExtend->ruleset;

						//remember its parents for circular references
						$newExtend->parent_ids = array_merge($newExtend->parent_ids,$targetExtend->parent_ids,$extend->parent_ids);

						// only process the selector once.. if we have :extend(.a,.b) then multiple
						// extends will look at the same selector path, so when extending
						// we know that any others will be duplicates in terms of what is added to the css
						if( $targetExtend->firstExtendOnThisSelectorPath ){
							$newExtend->firstExtendOnThisSelectorPath = true;
							$targetExtend->ruleset->paths[] = $newSelector;
						}
					}
				}
			}
		}

		if( $extendsToAdd ){
			// try to detect circular references to stop a stack overflow.
			// may no longer be needed.			$this->extendChainCount++;
			if( $iterationCount > 100) {
				$selectorOne = "{unable to calculate}";
				$selectorTwo = "{unable to calculate}";
				try{
					$selectorOne = $extendsToAdd[0]->selfSelectors[0]->toCSS();
					$selectorTwo = $extendsToAdd[0]->selector->toCSS();
				}catch(Exception $e){}
				throw new %BOOTCODE%_Less_ParserException("extend circular reference detected. One of the circular extends is currently:"+$selectorOne+":extend(" + $selectorTwo+")");
			}

			// now process the new extends on the existing rules so that we can handle a extending b extending c ectending d extending e...
			$extendsToAdd = $this->doExtendChaining( $extendsToAdd, $extendsListTarget, $iterationCount+1);
		}

		return array_merge($extendsList, $extendsToAdd);
	}


	function visitRule( $ruleNode, &$visitDeeper ){
		$visitDeeper = false;
	}

	function visitMixinDefinition( $mixinDefinitionNode, &$visitDeeper ){
		$visitDeeper = false;
	}

	function visitSelector( $selectorNode, &$visitDeeper ){
		$visitDeeper = false;
	}

	function visitRuleset($rulesetNode){


		if( $rulesetNode->root ){
			return;
		}

		$allExtends = end($this->allExtendsStack);
		$paths_len = count($rulesetNode->paths);

		// look at each selector path in the ruleset, find any extend matches and then copy, find and replace
		for( $extendIndex = 0, $all_extend_len = count($allExtends); $extendIndex < $all_extend_len; $extendIndex++ ){
			for($pathIndex = 0; $pathIndex < $paths_len; $pathIndex++ ){

				$selectorPath = $rulesetNode->paths[$pathIndex];

				// extending extends happens initially, before the main pass
				if( isset($rulesetNode->extendOnEveryPath) && $rulesetNode->extendOnEveryPath ){ continue; }
				if( end($selectorPath)->extendList ){ continue; }

				$matches = $this->findMatch($allExtends[$extendIndex], $selectorPath);

				if( $matches ){
					foreach($allExtends[$extendIndex]->selfSelectors as $selfSelector ){
						$rulesetNode->paths[] = $this->extendSelector($matches, $selectorPath, $selfSelector);
					}
				}
			}
		}
	}

	function findMatch($extend, $haystackSelectorPath ){

		//
		// look through the haystack selector path to try and find the needle - extend.selector
		// returns an array of selector matches that can then be replaced
		//
		$needleElements = $extend->selector->elements;
		$potentialMatches = array();
		$potentialMatches_len = 0;
		$potentialMatch = null;
		$matches = array();

		// loop through the haystack elements
		for($haystackSelectorIndex = 0, $haystack_path_len = count($haystackSelectorPath); $haystackSelectorIndex < $haystack_path_len; $haystackSelectorIndex++ ){
			$hackstackSelector = $haystackSelectorPath[$haystackSelectorIndex];

			for($hackstackElementIndex = 0, $haystack_elements_len = count($hackstackSelector->elements); $hackstackElementIndex < $haystack_elements_len; $hackstackElementIndex++ ){

				$haystackElement = $hackstackSelector->elements[$hackstackElementIndex];

				// if we allow elements before our match we can add a potential match every time. otherwise only at the first element.
				if( $extend->allowBefore || ($haystackSelectorIndex === 0 && $hackstackElementIndex === 0) ){
					$potentialMatches[] = array('pathIndex'=> $haystackSelectorIndex, 'index'=> $hackstackElementIndex, 'matched'=> 0, 'initialCombinator'=> $haystackElement->combinator);
					$potentialMatches_len++;
				}

				for($i = 0; $i < $potentialMatches_len; $i++ ){
					$potentialMatch = &$potentialMatches[$i];

					// selectors add " " onto the first element. When we use & it joins the selectors together, but if we don't
					// then each selector in haystackSelectorPath has a space before it added in the toCSS phase. so we need to work out
					// what the resulting combinator will be
					$targetCombinator = $haystackElement->combinator->value;
					if( $targetCombinator === '' && $hackstackElementIndex === 0 ){
						$targetCombinator = ' ';
					}

					// if we don't match, null our match to indicate failure
					if( !$this->isElementValuesEqual( $needleElements[$potentialMatch['matched'] ]->value, $haystackElement->value) ||
						($potentialMatch['matched'] > 0 && $needleElements[ $potentialMatch['matched'] ]->combinator->value !== $targetCombinator) ){
						$potentialMatch = null;
					} else {
						$potentialMatch['matched']++;
					}

					// if we are still valid and have finished, test whether we have elements after and whether these are allowed
					if( $potentialMatch ){

						$potentialMatch['finished'] = ($potentialMatch['matched'] === $extend->selector->elements_len );

						if( $potentialMatch['finished'] &&
							(!$extend->allowAfter && ($hackstackElementIndex+1 < $haystack_elements_len || $haystackSelectorIndex+1 < $haystack_path_len)) ){
							$potentialMatch = null;
						}
					}
					// if null we remove, if not, we are still valid, so either push as a valid match or continue
					if( $potentialMatch ){
						if( $potentialMatch['finished'] ){
							$potentialMatch['length'] = $extend->selector->elements_len;
							$potentialMatch['endPathIndex'] = $haystackSelectorIndex;
							$potentialMatch['endPathElementIndex'] = $hackstackElementIndex + 1; // index after end of match
							$potentialMatches = array(); // we don't allow matches to overlap, so start matching again
							$potentialMatches_len = 0;
							$matches[] = $potentialMatch;
						}
					} else {
						array_splice($potentialMatches, $i, 1);
						$potentialMatches_len--;
						$i--;
					}
				}
			}
		}
		return $matches;
	}

	function isElementValuesEqual( $elementValue1, $elementValue2 ){

		if( $elementValue1 === $elementValue2 ){
			return true;
		}
		if( is_string($elementValue1) || is_string($elementValue2) ) {
			return false;
		}

		if( $elementValue1 instanceof %BOOTCODE%_Less_Tree_Attribute ){

			if( $elementValue1->op !== $elementValue2->op || $elementValue1->key !== $elementValue2->key ){
				return false;
			}

			if( !$elementValue1->value || !$elementValue2->value ){
				if( $elementValue1->value || $elementValue2->value ) {
					return false;
				}
				return true;
			}
			$elementValue1 = ($elementValue1->value->value ? $elementValue1->value->value : $elementValue1->value );
			$elementValue2 = ($elementValue2->value->value ? $elementValue2->value->value : $elementValue2->value );
			return $elementValue1 === $elementValue2;
		}

		$elementValue1 = $elementValue1->value;
		if( $elementValue1 instanceof %BOOTCODE%_Less_Tree_Selector ){
			$elementValue2 = $elementValue2->value;
			if( !($elementValue2 instanceof %BOOTCODE%_Less_Tree_Selector) || $elementValue1->elements_len !== $elementValue2->elements_len ){
				return false;
			}
			for( $i = 0; $i < $elementValue1->elements_len; $i++ ){
				if( $elementValue1->elements[$i]->combinator->value !== $elementValue2->elements[$i]->combinator->value ){
					if( $i !== 0 || ($elementValue1->elements[$i]->combinator->value || ' ') !== ($elementValue2->elements[$i]->combinator->value || ' ') ){
						return false;
					}
				}
				if( !$this->isElementValuesEqual($elementValue1->elements[$i]->value, $elementValue2->elements[$i]->value) ){
					return false;
				}
			}
			return true;
		}

		return false;
	}

	function extendSelector($matches, $selectorPath, $replacementSelector){

		//for a set of matches, replace each match with the replacement selector

		$currentSelectorPathIndex = 0;
		$currentSelectorPathElementIndex = 0;
		$path = array();
		$selectorPath_len = count($selectorPath);

		for($matchIndex = 0, $matches_len = count($matches); $matchIndex < $matches_len; $matchIndex++ ){


			$match = $matches[$matchIndex];
			$selector = $selectorPath[ $match['pathIndex'] ];
			$firstElement = new %BOOTCODE%_Less_Tree_Element(
				$match['initialCombinator'],
				$replacementSelector->elements[0]->value,
				$replacementSelector->elements[0]->index,
				$replacementSelector->elements[0]->currentFileInfo
			);

			if( $match['pathIndex'] > $currentSelectorPathIndex && $currentSelectorPathElementIndex > 0 ){
				$last_path = end($path);
				$last_path->elements = array_merge( $last_path->elements, array_slice( $selectorPath[$currentSelectorPathIndex]->elements, $currentSelectorPathElementIndex));
				$currentSelectorPathElementIndex = 0;
				$currentSelectorPathIndex++;
			}

			$newElements = array_merge(
				array_slice($selector->elements, $currentSelectorPathElementIndex, ($match['index'] - $currentSelectorPathElementIndex) ) // last parameter of array_slice is different than the last parameter of javascript's slice
				, array($firstElement)
				, array_slice($replacementSelector->elements,1)
				);

			if( $currentSelectorPathIndex === $match['pathIndex'] && $matchIndex > 0 ){
				$last_key = count($path)-1;
				$path[$last_key]->elements = array_merge($path[$last_key]->elements,$newElements);
			}else{
				$path = array_merge( $path, array_slice( $selectorPath, $currentSelectorPathIndex, $match['pathIndex'] ));
				$path[] = new %BOOTCODE%_Less_Tree_Selector( $newElements );
			}

			$currentSelectorPathIndex = $match['endPathIndex'];
			$currentSelectorPathElementIndex = $match['endPathElementIndex'];
			if( $currentSelectorPathElementIndex >= count($selectorPath[$currentSelectorPathIndex]->elements) ){
				$currentSelectorPathElementIndex = 0;
				$currentSelectorPathIndex++;
			}
		}

		if( $currentSelectorPathIndex < $selectorPath_len && $currentSelectorPathElementIndex > 0 ){
			$last_path = end($path);
			$last_path->elements = array_merge( $last_path->elements, array_slice($selectorPath[$currentSelectorPathIndex]->elements, $currentSelectorPathElementIndex));
			$currentSelectorPathIndex++;
		}

		$slice_len = $selectorPath_len - $currentSelectorPathIndex;
		$path = array_merge($path, array_slice($selectorPath, $currentSelectorPathIndex, $slice_len));

		return $path;
	}


	function visitMedia( $mediaNode ){
		$newAllExtends = array_merge( $mediaNode->allExtends, end($this->allExtendsStack) );
		$this->allExtendsStack[] = $this->doExtendChaining($newAllExtends, $mediaNode->allExtends);
	}

	function visitMediaOut( $mediaNode ){
		array_pop( $this->allExtendsStack );
	}

	function visitDirective( $directiveNode ){
		$newAllExtends = array_merge( $directiveNode->allExtends, end($this->allExtendsStack) );
		$this->allExtendsStack[] = $this->doExtendChaining($newAllExtends, $directiveNode->allExtends);
	}

	function visitDirectiveOut( $directiveNode ){
		array_pop($this->allExtendsStack);
	}

}

class %BOOTCODE%_Less_toCSSVisitor extends %BOOTCODE%_Less_visitor_replacing{

	function __construct($env){
		$this->_env = $env;
		parent::__construct();
	}

	function run( $root ){
		return $this->visitObj($root);
	}

	function visitRule( $ruleNode ){
		if( $ruleNode->variable ){
			return array();
		}
		return $ruleNode;
	}

	function visitMixinDefinition( $mixinNode ){
		return array();
	}

	function visitExtend( $extendNode ){
		return array();
	}

	function visitComment( $commentNode ){
		if( $commentNode->isSilent( $this->_env) ){
			return array();
		}
		return $commentNode;
	}

	function visitMedia( $mediaNode, &$visitDeeper ){
		$mediaNode->accept($this);
		$visitDeeper = false;

		if( !$mediaNode->rules ){
			return array();
		}
		return $mediaNode;
	}

	function visitDirective( $directiveNode ){
		if( isset($directiveNode->currentFileInfo['reference']) && (!property_exists($directiveNode,'isReferenced') || !$directiveNode->isReferenced) ){
			return array();
		}
		if( $directiveNode->name === '@charset' ){
			// Only output the debug info together with subsequent @charset definitions
			// a comment (or @media statement) before the actual @charset directive would
			// be considered illegal css as it has to be on the first line
			if( isset($this->charset) && $this->charset ){

				//if( $directiveNode->debugInfo ){
				//	$comment = new %BOOTCODE%_Less_Tree_Comment('/* ' . str_replace("\n",'',$directiveNode->toCSS($this->_env))." */\n");
				//	$comment->debugInfo = $directiveNode->debugInfo;
				//	return $this->visit($comment);
				//}


				return array();
			}
			$this->charset = true;
		}
		return $directiveNode;
	}

	function checkPropertiesInRoot( $rules ){
		foreach($rules as $ruleNode){
			if( $ruleNode instanceof %BOOTCODE%_Less_Tree_Rule && !$ruleNode->variable ){
				$msg = "properties must be inside selector blocks, they cannot be in the root. Index ".$ruleNode->index.($ruleNode->currentFileInfo ? (' Filename: '.$ruleNode->currentFileInfo['filename']) : null);
				throw new %BOOTCODE%_Less_CompilerException($msg);
			}
		}
	}

	function visitRuleset( $rulesetNode, &$visitDeeper ){

		$visitDeeper = false;
		$rulesets = array();
		if( $rulesetNode->firstRoot ){
			$this->checkPropertiesInRoot( $rulesetNode->rules );
		}
		if( !$rulesetNode->root ){

			$paths = array();
			foreach($rulesetNode->paths as $p){
				if( $p[0]->elements[0]->combinator->value === ' ' ){
					$p[0]->elements[0]->combinator = new %BOOTCODE%_Less_Tree_Combinator('');
				}

				foreach($p as $pi){
					if( $pi->getIsReferenced() && $pi->getIsOutput() ){
						$paths[] = $p;
						break;
					}
				}
			}

			$rulesetNode->paths = $paths;

			// Compile rules and rulesets
			$nodeRuleCnt = count($rulesetNode->rules);
			for( $i = 0; $i < $nodeRuleCnt; ){
				$rule = $rulesetNode->rules[$i];

				if( property_exists($rule,'rules') ){
					// visit because we are moving them out from being a child
					$rulesets[] = $this->visitObj($rule);
					array_splice($rulesetNode->rules,$i,1);
					$nodeRuleCnt--;
					continue;
				}
				$i++;
			}


			// accept the visitor to remove rules and refactor itself
			// then we can decide now whether we want it or not
			if( $nodeRuleCnt > 0 ){
				$rulesetNode->accept($this);

				if( $rulesetNode->rules ){

					if( count($rulesetNode->rules) >  1 ){
						$this->_mergeRules( $rulesetNode->rules );
						$this->_removeDuplicateRules( $rulesetNode->rules );
					}

					// now decide whether we keep the ruleset
					if( $rulesetNode->paths ){
						//array_unshift($rulesets, $rulesetNode);
						array_splice($rulesets,0,0,array($rulesetNode));
					}
				}

			}

		}else{
			$rulesetNode->accept( $this );
			if( $rulesetNode->firstRoot || $rulesetNode->rules ){
				return $rulesetNode;
				//array_unshift($rulesets, $rulesetNode);
			}
			return $rulesets;
		}

		if( count($rulesets) === 1 ){
			return $rulesets[0];
		}
		return $rulesets;
	}

	function _removeDuplicateRules( &$rules ){
		// remove duplicates
		$ruleCache = array();
		for( $i = count($rules)-1; $i >= 0 ; $i-- ){
			$rule = $rules[$i];
			if( $rule instanceof %BOOTCODE%_Less_Tree_Rule ){
				if( !isset($ruleCache[$rule->name]) ){
					$ruleCache[$rule->name] = $rule;
				}else{
					$ruleList =& $ruleCache[$rule->name];
					if( $ruleList instanceof %BOOTCODE%_Less_Tree_Rule ){
						$ruleList = $ruleCache[$rule->name] = array( $ruleCache[$rule->name]->toCSS($this->_env) );
					}
					$ruleCSS = $rule->toCSS($this->_env);
					if( array_search($ruleCSS,$ruleList) !== false ){
						array_splice($rules,$i,1);
					}else{
						$ruleList[] = $ruleCSS;
					}
				}
			}
		}
	}

	function _mergeRules( &$rules ){
		$groups = array();

		$rules_len = count($rules);
		for( $i = 0; $i < $rules_len; $i++ ){
			$rule = $rules[$i];

			if( ($rule instanceof %BOOTCODE%_Less_Tree_Rule) && $rule->merge ){

				$key = $rule->name;
				if( $rule->important ){
					$key .= ',!';
				}

				if( !isset($groups[$key]) ){
					$groups[$key] = array();
					$parts =& $groups[$key];
				}else{
					array_splice($rules, $i--, 1);
					$rules_len--;
				}

				$parts[] = $rule;
			}
		}

		foreach($groups as $parts){

			if( count($parts) > 1 ){
				$rule = $parts[0];

				$values = array();
				foreach($parts as $p){
					$values[] = $p->value;
				}

				$rule->value = new %BOOTCODE%_Less_Tree_Value( $values );
			}
		}
	}
}



class %BOOTCODE%_Less_visitor{

	var $methods = array();
	var $_visitFnCache = array();

	function __construct(){
		$this->_visitFnCache = get_class_methods(get_class($this));
		$this->_visitFnCache = array_flip($this->_visitFnCache);
	}

	function visitObj( $node ){

		$funcName = 'visit'.$node->type;
		if( isset($this->_visitFnCache[$funcName]) ){

			$visitDeeper = true;
			$this->$funcName( $node, $visitDeeper );

			if( $visitDeeper ){
				$node->accept($this);
			}

			$funcName = $funcName . "Out";
			if( isset($this->_visitFnCache[$funcName]) ){
				$this->$funcName( $node );
			}

		}else{
			$node->accept($this);
		}

		return $node;
	}

	function visitArray( $nodes ){

		array_map( array($this,'visitObj'), $nodes);
		return $nodes;
	}
}


class %BOOTCODE%_Less_visitor_replacing extends %BOOTCODE%_Less_visitor{

	function visitObj( $node ){

		$funcName = 'visit'.$node->type;
		if( isset($this->_visitFnCache[$funcName]) ){

			$visitDeeper = true;
			$node = $this->$funcName( $node, $visitDeeper );

			if( $visitDeeper && is_object($node) ){
				$node->accept($this);
			}

			$funcName = $funcName . "Out";
			if( isset($this->_visitFnCache[$funcName]) ){
				$this->$funcName( $node );
			}

		}else{
			$node->accept($this);
		}

		return $node;
	}

	function visitArray( $nodes ){

		$newNodes = array();
		foreach($nodes as $node){
			$evald = $this->visitObj($node);
			if( is_array($evald) ){
				self::flatten($evald,$newNodes);
			}else{
				$newNodes[] = $evald;
			}
		}
		return $newNodes;
	}

	function flatten( $arr, &$out ){

		foreach($arr as $item){
			if( !is_array($item) ){
				$out[] = $item;
				continue;
			}

			foreach($item as $nestedItem){
				if( is_array($nestedItem) ){
					self::flatten( $nestedItem, $out);
				}else{
					$out[] = $nestedItem;
				}
			}
		}

		return $out;
	}

}





class %BOOTCODE%_Less_CompilerException extends Exception {

	private $filename;

	public function __construct($message = null, $code = 0, Exception $previous = null, $filename = null ){
		parent::__construct($message, $code);
		$this->filename = $filename;
	}

	public function getFilename() {
		return $this->filename;
	}

	public function __toString() {
		return $this->message . " (" . $this->filename . ")";
	}
}



class %BOOTCODE%_Less_ParserException extends Exception{

}
