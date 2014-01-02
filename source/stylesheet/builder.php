<?php

require_once(%BOOTCODE%_FOUNDRY_CLASSES . '/stylesheet/analyzer.php');
require_once(%BOOTCODE%_FOUNDRY_CLASSES . '/stylesheet/task.php');

class %BOOTCODE%_Stylesheet_Builder {

	public $stylesheet = null;

	protected static $defaultOptions = array(

		'compile' => array(
			'enabled' => true,
			'force' => false
		),

		'minify' => array(
			'enabled' => true
		),

		'build' => array(
			'enabled' => true,
			'target' => array(
				'mode' => 'index'
			),
			'minified_target' => array(
				'mode' => 'join'
			)
		),
	);

	protected static $presets = array(

		// Generate index & join minified stylesheets.
		// Ideal when you want to pick up CSS changes on page refresh.
		// Suitable when running under static mode.
		'fast' => array(
			'compile' => array(
				'enabled' => false
			),
			'minify' => array(
				'enabled' => false
			)
		),

		// Cache compile, minify stylesheet, generate index and join minified stylesheet.
		// Ideal when you want to pick up LESS changes on page refresh when loading minified stylesheets.
		// Suitable for testing when running under static/optimized mode.
		'cache' => array(),

		// Cache compile & generate index.
		// Ideal when you want to pick up LESS changes on page refresh.
		// Suitable for development when running under development mode.
		'development' => array(

			'minify' => array(
				'enabled' => false
			),

			'build' => array(
				'minified_target' => array(
					'mode' => 'skip'
				)
			)
		),

		// Full compile, minify stylesheet, generate index and join minified stylesheets.
		// Ideal when packaging theme stylesheets.
		// Suitable for building.
		'full' => array(
			'compile' => array(
				'force' => true
			)
		)
	);

	public function __construct($stylesheet) {

		$this->stylesheet = $stylesheet;
	}

	public function run($preset='cache', $options=array()) {

		// Create compile task object.
		$this->task = new %BOOTCODE%_Stylesheet_Task("Build stylesheet");
		$task = $this->task;

		// Normalize options
		$options = array_merge_recursive(self::$defaultOptions, self::$presets[$preset], $options);

		// Get manifest file.
		$manifest = $this->stylesheet->manifest();

		foreach ($manifest as $group => $sections) {

			// If we need to compile,
			$compileOptions = $options['compile'];

			if ($compileOptions['enabled']) {

				// then compile all sections for this group.
				$subtask = $this->compileGroup($group);
				$task->subtasks[] = $subtask;

				// If failed, stop.
				if ($subtask->failed) {
					$task->reject();
					break;
				}
			}

			// If we need to minify,
			$minifyOptions = $options['minify'];

			if ($minifyOptions['enabled']) {

				// then minify all sections for this group.
				$subtask = $this->minifyGroup($group, $minifyOptions);
				$task->subtasks[] = $subtask;

				// If failed, stop.
				if ($subtask->failed) {
					$task->reject();
					break;
				}
			}

			// If we need to build,
			$buildOptions = $options['build'];

			if ($buildOptions['enabled']) {

				// then build this group.
				$subtask = $this->buildGroup($group, $buildOptions);
				$task->subtasks[] = $subtask;

				// If failed, stop.
				if ($subtask->failed) {
					$task->reject();
					break;
				}
			}
		}

		// Generate cache file
		$sections = $this->stylesheet->sections();
		$files = array();
		$cache = array();

		// Collect modified time for every section's css file
		foreach($sections as $section) {

			$file = $this->stylesheet->file($section, 'css');
			$filename = basename($file);
			$modifiedTime = filemtime($file);

			// Skip unreadable file
			if ($modifiedTime===false) {
				$task->report("Unable to get modified time for '$file'");
				continue;
			}

			$files[$filename] = $modifiedTime;
		}

		// Build cache data
		$cache['files'] = $files;

		// Generate cache file
		$cacheFile = $this->stylesheet->file('cache');
		$cacheContent = json_encode($cache);

		if (!JFile::write($cacheFile, $cacheContent)) {
			$task->report("Unable to write cache file '$cacheFile'");
		}

		// Generate log file
		$logFile = $this->stylesheet->file('log');
		$logContent = $task->toJSON();

		if (!JFile::write($logFile, $logContent)) {
			$task->report("Unable to write log file '$logFile'.");
		}

		return $task->resolve();
	}

	public function compileGroup($group, $options=array()) {

		$task = new %BOOTCODE%_Stylesheet_Task("Compile all sections for group '$group'");

		// Get manifest
		$manifest = $this->stylesheet->manifest();

		// Stop if group does not exist in stylesheet manifest.
		if (!isset($manifest['group'])) {
			return $task->reject("Group '$group' does not exist in stylesheet manifest.");
		}

		// Get sections
		$sections = $manifest['group'];

		// Stop if there are no sections.
		if (count($sections) < 1) {
			return $task->reject("No available sections to compile.");
		}

		foreach ($sections as $section) {

			// Compile section
			$subtask = $this->stylesheet->compile($section, $options);
			$task->subtasks[] = $subtask;

			// Stop if section could not be compiled.
			if ($subtask->failed) {
				return $task->reject("An error occured while compiling section '$section'.");
			}
		}

		return $task->resolve();
	}

	public function minifyGroup($group, $options=array()) {

		$task = new %BOOTCODE%_Stylesheet_Task("Minify all sections for group '$group'");

		// Get manifest
		$manifest = $this->stylesheet->manifest();

		// Stop if group does not exist in stylesheet manifest.
		if (!isset($manifest['group'])) {
			return $task->reject("Group '$group' does not exist in stylesheet manifest.");
		}

		// Get sections
		$sections = $manifest['group'];

		// Stop if there are no sections.
		if (count($sections) < 1) {
			return $task->reject("No available sections to compile.");
		}

		foreach ($sections as $section) {

			// Compile section
			$subtask = $this->stylesheet->minify($section, $options);
			$task->subtasks[] = $subtask;

			// Stop if section could not be minified.
			if ($subtask->failed) {
				return $task->reject("An error occured while compiling section '$section'.");
			}
		}
	}

	public function buildGroup($group, $options=array()) {

		$task = new %BOOTCODE%_Stylesheet_Task("Building group '$group'");

		// Get manifest
		$manifest = $this->stylesheet->manifest();

		// Stop if group does not exist in stylesheet manifest.
		if (!isset($manifest['group'])) {
			return $task->reject("Group '$group' does not exist in stylesheet manifest.");
		}

		// Get sections
		$sections = $manifest['group'];

		// Stop if there are no sections.
		if (count($sections) < 1) {
			return $task->reject("No available sections to minify.");
		}

		// If this is a simple stylesheet, just minify stylesheet.
		if ($group=='style' && $sections[0]=='style') {

			$subtask = $this->stylesheet->minify('style');
			$task->subtasks[] = $subtask;

			// Stop if minifying stylesheet fail.
			if ($subtask->failed) {
				return $task->reject();
			}

		} else {

			// Write target.
			$type = 'css';
			$mode = $options['target']['mode'];

			$subtask = $this->writeTarget($group, $type, $mode);
			$task->subtasks[] = $subtask;

			// Stop if writing target failed.
			if ($subtask->failed) {
				return $task->reject();
			}

			// Write minified target.
			$type = 'minified';
			$mode = $options['minified_target']['mode'];

			$subtask = $this->writeTarget($group, $type, $mode);
			$task->subtasks[] = $subtask;

			// Stop if writing minified target failed.
			if ($subtask->failed) {
				return $task->reject();
			}
		}

		return $task->resolve();
	}

	public function writeTarget($group, $type, $mode) {

		$task = new %BOOTCODE%_Stylesheet_Task("Writing $type target for '$group'.");

		$file = $this->stylesheet->file($group, $type);
		$content = '';

		switch ($options['minified_target']['mode']) {

			case 'index':
				$subtask = $this->generateIndex($sections, $type);
				$task->subtasks[] = $subtask;

				if ($subtask->failed) {
					return $task->reject();
				}

				$content = $task->result;
				break;

			case 'join':
				$subtask = $this->joinFiles($sections, $type);
				$task->subtasks[] = $subtask;

				if ($subtask->failed) {
					return $task->reject();
				}

				$content = $task->result;
				break;

			case 'skip':
			default:
				$task->report('Nothing to do.', 'info');
				return $task;
		}

		if (!JFile::write($file, $content)) {
			return $task->reject("Unable to write to file '$file'");
		}

		return $task->resolve();
	}

	public function generateIndex($sections=array(), $type='css') {

		$task = new %BOOTCODE%_Stylesheet_Task("Generate index for $type sections");

		$index = '';
		foreach ($sections as $section) {
			$filename = basename($this->stylesheet->file($section, $type));
			$index .= "@import '$filename'\n";
		}

		$task->result = $index;

		return $task->resolve();
	}

	public function joinFiles($sections=array(), $type='css') {

		$task = new %BOOTCODE%_Stylesheet_Task("Join $type sections");

		$content = '';

		foreach ($sections as $section) {

			$sectionFile = $this->stylesheet->file($section, $type);

			if (!JFile::exists($sectionFile)) {
				return $task->reject("Missing minified section file '$sectionFile'.");
			}

			$sectionContent = JFile::read($file);

			if ($sectionContent===false) {
				return $task->reject("Unable to read minified section file '$sectionFile'.");
			}

			$content .= $sectionContent;
		}

		$task->result = $content;

		return $task->resolve();
	}
}