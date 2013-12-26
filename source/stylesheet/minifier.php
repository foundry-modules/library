<?php

require_once(%BOOTCODE%_FOUNDRY_LIB . '/cssmin.php');
require_once(%BOOTCODE%_FOUNDRY_CLASSES . '/stylesheet/task.php');

class %BOOTCODE%_Stylesheet_Minifier extends %BOOTCODE%_CSSMin {

	private $stylesheet;

	public function __construct($stylesheet) {

		$this->stylesheet = $stylesheet;
	}

	public function run($section, $options=array()) {

		// Create new task
		$this->task = new %BOOTCODE%_Stylesheet_Task("Minify section '$section'");
		$task = $this->task;

		// Get paths
		$in   = $this->stylesheet->file($section, 'css');
		$out  = $this->stylesheet->file($section, 'minified');
		$root = dirname($out);

		// Check if css file exists.
		if (!JFile::exists($in)) {
			return $task->reject("Missing css file '$in'.");
		}

		// Check if folder is writable.
		if (!is_writable($root)) {
			return $task->reject("Unable to write files inside the folder '$root'.");
		}

		// Check if css file is writable.
		if (JFile::exists($out) && !is_writable($out)) {
			return $task->reject("Unable to write css file '$out'.");
		}

		$content = JFile::read($in);

		if ($content===false) {
			return $task->reject("Unable to read css file '$in'.");
		}

		$minifiedContent = null;

		try {
			$minifiedContent = $this->compress($content);
		} catch (Exception $exception) {
			$task->reject("An error occured while minifying section '$section'.");
			$task->report($exception->getMessage(), 'error');
			return $task;
		}

		if (!JFile::write($out, $minifiedContent)) {
			return $task->reject("An error occured while writing minified file '$out'.");
		}

		return $task->resolve();
	}
}