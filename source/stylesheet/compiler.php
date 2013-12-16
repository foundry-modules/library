<?php

class %BOOTCODE%_Stylesheet_Compiler extends %BOOTCODE%_lessc {

	// TODO: Restrict importing of less files within the allowed directories.
	public $allowedDir = array();

	public function __construct() {

		$this->task = %BOOTCODE%_Stylesheet_Task();
	}

	private $defaultOptions = array(
		'force' => false,
		'variables' => array(),
		'importDir' => array()
	);

	public function run($options=array()) {

		$task = $this->task;

		// Normalize options
		$options = array_merge($this->defaultOptions, $options);

		// Get paths
		$in = $options['in'];
		$out = $options['out'];
		$root = dirname($out);

		// Check if less file exists
		if (!JFile::exists($in)) {
			return $task->reject('Missing less file "' . $in . '".');
		}

		// Check if folder is writable
		if (!is_writable($root)) {
			return $task->reject('Unable to write files inside the folder "' . $root . '".');
		}

		// Check if css file is writable
		if (JFile::exists($out) && !is_writable($out)) {
			return $task->reject('Unable to write css file "' . $out . '".');
		}

		// Prepare cache
		$cacheFile = $options['cache'];
		$cacheBefore = null;

		// Check if cache file is writable
		if (JFile::exists($cacheFile) && !is_writable($cacheFile)) {
			return $task->reject('Unable to write cache file "' . $out . '".');
		}

		// If there is an existing cache file
		if (JFile::exists($cacheFile)) {

			// Get contents of cache file
			$content = JFile::read($cacheFile);

			if ($content===false) {
				$task->report('Unable to read existing cache file "' . $cacheFile . '".', 'info');
			} else {
				$cacheBefore = json_decode($content);
			}
		}

		// Set compiler options
		$this->setVariables($options['variables']);
		$this->setImportDir($options['importDir']);

		// Compile less stylesheet
		try {
			$cacheAfter = $this->cachedCompileFile((empty($cacheBefore) ? $in : $cacheBefore), $options['force']);
		} catch (Exception $exception) {
			$task->reject('An error occured while compiling less file.');
			$task->report($exception->getMessage(), 'error');
			return $task;
		}

		// Stop if compiler did not return an array object.
		if (!is_array($cacheAfter)) {
			return $task->reject('Incompatible less cache structure or invalid input file was provided.');
		}

		// Determine if there are changes in this stylesheet
		if (empty($cacheBefore) || $cacheAfter['updated'] > $cacheBefore['updated']) {

			// Write stylesheet file
			if (!JFile::write($out, $cacheContent)) {
				return $task->reject('An error occured writing css file "' . $out . '".');
			}

			// Write cache file.
			if (!JFile::write($cacheFile, $cacheAfter)) {
				return $task->reject('An error occured writing cache file "' . $cacheFile '".');
			}

		// If there are no changes, skip writing stylesheet & cache file.
		} else {

			$task->report('Nothing has changed in this stylesheet.', 'info');
		}

		return $task->resolve();
	}

	public function makeParser($name) {

		// Thia makes tracing broken less files a lot easier.
		$this->report('Parsing "' . $name '".', 'info');

		return parent::makeParser($name);
	}

	public function findImport($name) {

		// Adds support for absolute paths
		if (substr($url, 0, 7)=="file://") {
			$full = substr($url, 7);
			// TODO: Restrict importing of less files within the allowed directories.
			if ($this->fileExists($file = $full.'.less') || $this->fileExists($file = $full)) {
				return $file;
			}
		}

		return parent::findImport($name);
	}
}