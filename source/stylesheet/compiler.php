<?php

require_once(%BOOTCODE%_FOUNDRY_LIB . '/less.php');
require_once(%BOOTCODE%_FOUNDRY_CLASSES . '/stylesheet/task.php');

class %BOOTCODE%_Stylesheet_Compiler extends %BOOTCODE%_Less_Parser {

	private $stylesheet;
	public $task;

	protected static $defaultOptions = array(
		'force' => false,
		'compress' => true
	);

	protected static $locations = array(
		'user',
		'site',
		'site_base',
		'admin',
		'admin_base',
		'module',
		'media',
		'component',
		'foundry',
		'global'
	);

	protected static $importOrdering = array(
		'site' => array(
			'user',
			'site',
			'site_base',
			'component',
			'global'
		),

		'admin' => array(
			'user',
			'admin',
			'admin_base',
			'component',
			'global'
		),

		'module' => array(
			'module',
			'component',
			'global'
		)
	);

	// TODO: Restrict importing of less files within the allowed directories.
	public $allowedDir = array();

	public function __construct($stylesheet, $options=array()) {

		$this->stylesheet = $stylesheet;

		// Normalize options
		$this->options = array_merge(self::$defaultOptions, $options);

		parent::__construct($this->options);
	}

	public function run($section) {

		// Create new task
		$this->task = new %BOOTCODE%_Stylesheet_Task("Compile section '$section'");
		$task = $this->task;

		// Set current instance as default parser
		// so that it is accessible by all child parsers.
		FD40_Less_Parser::$instance = $this;

		// Get current stylesheet location
		$currentLocation = $this->stylesheet->location;

		// Get paths
		$in    = $this->stylesheet->file($section, 'less');
		$out   = $this->stylesheet->file($section, 'css');
		$cache = $this->stylesheet->folder('cache');
		$root  = dirname($out);

		// Check if less file exists.
		if (!JFile::exists($in)) {
			return $task->reject("Missing less file '$in'.");
		}

		// Check if folder is writable.
		if (!is_writable($root)) {
			return $task->reject("Unable to write files inside the folder '$root'.");
		}

		// Check if css file is writable.
		if (JFile::exists($out) && !is_writable($out)) {
			return $task->reject("Unable to write css file '$out'.");
		}

		// Check if cache folder exists
		if (!JFolder::exists($cache)) {

			$task->report("Creating cache folder '$cache'.", 'info');

			// Stop if unable to create cache folder.
			if (!JFolder::create($cache)) {
				return $task->reject("Unable to create cache folder '$cache'.");
			}
		}

		// If we're force compiling, don't check cache.
		if (!$this->options['force']) {

			// Determine if cache is unchanged
			%BOOTCODE%_Less_Cache::$cache_dir = $cache;
			$compiled = %BOOTCODE%_Less_Cache::Get(array($in => $root));

			// If this stylesheet has been compiled before,
			// and there are no changes in this stylsheet.
			if ($compiled) {

				// Check if the stylesheet file exists
				if (!JFile::exists($out)) {

					// If the stylsheet file does not exist,
					// copy over from the cache file.
					if (!JFile::copy($compiled, $out)) {
						return $task->reject("Unable to copy from cache to css file '$out'.");
					}
				}

				$task->report('There are no changes in this stylesheet.', 'info');
				return $task->resolve();
			}
		}

		// Generate location variables
		$variables = '';

		foreach (self::$locations as $location) {
			$path = $this->stylesheet->folder($location);
			// $variables .= '@' . $location . ': \'file://' . $path . '\';';
			$variables .= '@' . $location . ':\'' . $this->stylesheet->relative($path, $root) . '\';';
			$variables .= '@' . $location . '_uri: \'' . $this->stylesheet->relative($path, $root) . '\';';
		}

		$this->parse($variables);

		// Generate import directories
		$importDir = array();
		$site_root = $this->stylesheet->folder('root');

		foreach (self::$importOrdering[$currentLocation] as $location) {
			$path = $this->stylesheet->folder($location);
			$uri_root = str_replace($site_root, '', $path);
			$importDir[$path] = $uri_root;
		}

		// Set import directories
		$this->SetImportDirs($importDir);

		// Compile less stylesheet.
		try {

			$this->SetCacheDir($cache);
			$this->parseFile($in, $root);
			$css = $this->getCss();
		} catch (Exception $exception) {
			$task->report($exception->getMessage() . ' (' . $exception->getFilename() . ')', 'error');
			return $task->reject('An error occured while compiling less file.');
		}

		if (!isset($css)) {

			return $task->reject('An error occured while executing compiler.');
		} else {

			// Write stylesheet file.
			if (!JFile::write($out, $css)) {
				return $task->reject("An error occured while writing css file '$out'.");
			}

			// Write log file.
			$logFile = $this->stylesheet->file($section, 'log');
			$logContent = $task->toJSON();

			if (!JFile::write($logFile, $logContent)) {
				$task->report("An error occured while writing log file '$log'", 'warn');
			}
		}

		return $task->resolve();
	}
}