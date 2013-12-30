<?php

require_once(%BOOTCODE%_FOUNDRY_CLASSES . '/stylesheet/analyzer.php');
require_once(%BOOTCODE%_FOUNDRY_CLASSES . '/stylesheet/task.php');

class %BOOTCODE%_Stylesheet_Builder {

	public $stylesheet = null;

	protected static $defaultOptions = array(
		'force' => false
	);

	public function __construct($stylesheet) {

		$this->stylesheet = $stylesheet;
	}

	public function run($mode='cache', $options=array()) {

		// Create compile task object.
		$this->task = new %BOOTCODE%_Foundry_Stylesheet_Task("Build stylesheet");
		$task = $this->task;

		// Normalize options
		$options = array_merge(self::$defaultOptions, $options);

		// Get main stylesheet file.
		$in = $this->stylesheet->file('css');

		// Stop if main stylesheet file is missing.
		if (!JFile::exists($in)) {
			return $task->reject("Missing main stylesheet file '$in'.");
		}

		// Get content of main stylesheet file.
		$content = JFile::read($in);

		// Stop if unable to read the main stylesheet file.
		if ($content===false) {
			return $task->reject("Unable to read main stylesheet file '$in'.");
		}

		// Get list of sections from stylesheet content.
		$sections = %BOOTCODE%_Stylesheet_Analyzer::sections($content);

		// Stop if there are no sections to compile.
		if (count($sections) < 1) {
			return $task->reject('Unable to retrieve stylesheet sections.');
		}

		// Get compiler options.
		$options['force'] = $mode==='full';

		// Cache compile / Full compile.
		// Go through every stylesheet section and compile if necessary.
		if ($mode!=='fast') {

			// Full compile forces every section to be
			// recompiled whether or not they were modified.
			foreach ($sections as $section) {

				// Compile section
				$subtask = $this->stylesheet->compile($section, $options);

				// Store subtask
				$task->subtasks[] = $subtask;

				// Stop building if one of the subtask failed.
				if ($subtask->failed) {
					return $task->reject("An error occured while compiling section '$section'.");
				}
			}
		}

		// Fast compile
		// If minify is on, join minified stylesheets together.
		// If minify is off, nothing to do.
		if ($options['$minify']) {

			// Detect changes in minified stylesheets.
			$detectTask = $this->detectChanges();
			$task->subtasks[] = $detectTask;

			// If there is a changes in minified stylesheets,
			if ($joinTask->result===true) {

				// Join minified stylesheets together.
				$joinTask = $this->joinMinifiedTask($sections);
				$task->subtasks[] = $joinTask;
			}
		}

		// Generate log file
		$logFile = $this->file('log');
		$logContent = $task->toJSON();

		if (!JFile::write($logFile, $logContent)) {
			$task->report("Unable to write log file '$logFile'.");
		}

		return $task->resolve();
	}

	public function detectChanges() {

		$task = new %BOOTCODE%_Foundry_Stylesheet_Task('Detect changes in minified stylesheets');
		$task->result = false;

		$cacheFile = $this->stylesheet->file('cache');
		$cache = null;

		if (!JFile::exists($cacheFile)) {

			$cacheContent = JFile::read($cacheFile);

			if ($cacheContent===false) {
				$task->report("Unable to read existing cache file '$cacheFile'.", %BOOTCODE%_Foundry_Stylesheet_Task::MESSAGE_INFO);
			} else {
				$cache = json_decode($cacheContent);
			}
		}

		if (!is_array($cache)) {
			$task->report('Incompatible style cache structure or invalid cache file was provided.');
			$task->result = true;
			return $task->reject();
		}

		$files = $cache->files;
		foreach ($files as $file => $timestamp) {

			// If the file does not exist anymore, template has changed.
			if (!JFile::exists($file)) {
				$task->report("Missing file '$file'.");
				$task->result = true;
				return $task->reject();
			}

			// Retrieve file's modified time
			$modified = filemtime($file);

			// Skip and generate a warning if unable to retrieve timestamp
			if ($modified===false) {
				$task->report("Unable to get modified time for '$file'.");
				continue;
			}

			if ($timestamp < $modified) {
				$task->report("Modified file found '$file'.");
				return $task->resolve();
			}
		}

		$task->report('There are no changes in minified stylesheets.');

		return $task->resolve();
	}

	public function joinMinifiedFiles($sections) {

		$task = new %BOOTCODE%_Foundry_Stylesheet_Task('Join minified stylesheets');

		$content = '';

		foreach ($sections as $section) {

			$sectionFile = $this->stylesheet->file($section, 'minified');

			if (!JFile::exists($sectionFile)) {
				return $task->reject("Missing minified section file '$sectionFile'.");
			}

			$sectionContent = JFile::read($file);

			if ($sectionContent===false) {
				return $task->reject("Unable to read minified section file '$sectionFile'.");
			}

			$content .= $sectionContent;
		}

		$blocks = %BOOTCODE%_Stylesheet_Analyzer::split($content);
		$files  = array();

		foreach ($blocks as $i => $block) {

			$filename = 'style' . (($i > 0) ? $i : '');

			// Write to 'style.min.css'
			$minifiedFile = $this->stylesheet->file($filename, 'minified');
			$files[] = $file;

			if (!JFile::write($minifiedFile, $block)) {
				return $task->reject("An error occured while writing minified file '$file'.");
			}
		}

		// Write file list to 'style.list'
		$listFile = $this->stylesheet->file('list');
		$list = json_encode($files);

		if (!JFile::write($listFile, $list)) {
			return $task->reject("An error occured while writing file list '$file'.");
		}

		return $task->resolve();
	}
}