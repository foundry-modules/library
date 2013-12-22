<?php

require_once(%BOOTCODE%_FOUNDRY_LIB . '/lessc.php');
require_once(%BOOTCODE%_FOUNDRY_CLASSES . '/stylesheet/task.php');

class %BOOTCODE%_Stylesheet_Compiler extends %BOOTCODE%_lessc {

	private $stylesheet;
	private $task;

	protected static $defaultOptions = array(
		'force' => false
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

	public function __construct($stylesheet) {

		$this->stylesheet = $stylesheet;
	}

	public function run($section, $options=array()) {

		// Create new task
		$this->task = new %BOOTCODE%_Stylesheet_Task("Compile section '$section'");
		$task = $this->task;

		// Normalize options
		$options = array_merge(self::$defaultOptions, $options);

		// Get current stylesheet location
		$currentLocation = $this->stylesheet->location;

		// Get paths
		$in   = $this->stylesheet->file($section, 'less');
		$out  = $this->stylesheet->file($section, 'css');
		$root = dirname($out);

		// Check if less file exists.
		if (!JFile::exists($in)) {
			return $task->reject('Missing less file "' . $in . '".');
		}

		// Check if folder is writable.
		if (!is_writable($root)) {
			return $task->reject('Unable to write files inside the folder "' . $root . '".');
		}

		// Check if css file is writable.
		if (JFile::exists($out) && !is_writable($out)) {
			return $task->reject('Unable to write css file "' . $out . '".');
		}

		// Prepare cache.
		$cache = $this->stylesheet->file($section, 'cache');
		$cacheBefore = null;

		// Check if cache file is writable.
		if (JFile::exists($cache) && !is_writable($cache)) {
			return $task->reject('Unable to write cache file "' . $out . '".');
		}

		// If there is an existing cache file,
		if (JFile::exists($cache)) {

			// get contents of cache file.
			$content = JFile::read($cache);

			if ($content===false) {
				$task->report('Unable to read existing cache file "' . $cache . '".', 'info');
			} else {
				$cacheBefore = json_decode($content);
			}
		}

		// Generate location variables
		$variables = array();

		foreach (self::$locations as $location) {
			$path = $this->stylesheet->folder($location);
			$variables[$location] = "'" . 'file://' . $path . "'";
			$variables[$location . '_uri'] = "'" . $this->stylesheet->relative($path, $root) . "'";
		}

		// Set variables
		$this->setVariables($variables);

		// Generate import directories
		$importDir = array();

		foreach (self::$importOrdering[$currentLocation] as $location) {
			$importDir[] = $this->stylesheet->folder($location);
		}

		// Set import directories
		$this->setImportDir($importDir);

		// Compile less stylesheet.
		try {
			$cacheAfter = $this->cachedCompile((empty($cacheBefore) ? $in : $cacheBefore), $options['force']);
		} catch (Exception $exception) {
			$task->report($exception->getMessage(), 'error');
			$task->reject("An error occured while compiling less file.");
			return $task;
		}

		// Stop if compiler did not return an array object.
		if (!is_array($cacheAfter)) {
			return $task->reject("Incompatible less cache structure or invalid input file was provided.");
		}

		// Determine if there are changes in this stylesheet.
		if (empty($cacheBefore) || $cacheAfter['updated'] > $cacheBefore['updated']) {

			// Write stylesheet file.
			if (!JFile::write($out, $cacheContent)) {
				return $task->reject("An error occured while writing css file '$out'.");
			}

			// Write cache file.
			if (!JFile::write($cacheFile, $cacheAfter)) {
				return $task->reject("An error occured while writing cache file '$cacheFile'.");
			}

			// Write log file.
			$log = $this->stylesheet->file($section, 'log');
			if (!JFile::write($log, $task->toJSON())) {
				$task->report("An error occured while writing log file '$logFile'", 'warn');
			}

		// If there are no changes, skip writing stylesheet & cache file.
		} else {

			$task->report("There are no changes in this stylesheet.", 'info');
		}

		return $task->resolve();
	}

	public function makeParser($name) {

		// Thia makes tracing broken less files a lot easier.
		$this->task->report("Parsing '$name'.", 'info');

		return parent::makeParser($name);
	}

	public function findImport($name) {

		// Adds support for absolute paths
		if (substr($name, 0, 7)=="file://") {
			$full = substr($name, 7);
			// TODO: Restrict importing of less files within the allowed directories.
			if ($this->fileExists($file = $full.'.less') || $this->fileExists($file = $full)) {
				return $file;
			}
		}

		return parent::findImport($name);
	}
}