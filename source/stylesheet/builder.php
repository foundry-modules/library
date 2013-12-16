<?php

require_once(%BOOTCODE%_FOUNDRY_CLASSES . '/stylesheet/analyzer.php');
require_once(%BOOTCODE%_FOUNDRY_CLASSES . '/stylesheet/task.php');

class %BOOTCODE%_Stylesheet_Builder {

	public $stylesheet = null;

	public function __construct($stylesheet) {

		$this->stylesheet = $stylesheet;
	}

	public function run($in) {

		// Create compile task object.
		$task = new %BOOTCODE%_Foundry_Stylesheet_Task();

		// Get main stylesheet file.
		$stylesheet = $this->file('stylesheet');

		// Stop if main stylesheet file is missing.
		if (!JFile::exists($task->stylesheet)) {
			return $task->reject('Missing main stylesheet file "' . $compile->stylesheet . '".');
		}

		// Get content of main stylesheet file.
		$content = JFile::read($stylesheet);

		// Stop if unable to read the main stylesheet file.
		if ($content===false) {
			return $task->reject('Unable to read main stylesheet file "' . $compile->stylesheet . '".');
		}

		// Get list of sections from stylesheet content.
		$compile->sections = %BOOTCODE%_Stylesheet_Analyzer::sections($content);

		// Stop if there are no sections to compile.
		if (count($compile->sections) < 1) {
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
			}
		}

		// Fast compile
		// If minify is on, join minified stylesheets together.
		// If minify is off, nothing to do.
		if ($options['$minify']) {

			// This holds the content of the "style.min.css" file.
			$content = '';

			foreach ($sections as $section) {

				// $subtask = $this->loadSection($section);
				// Store subtask
				// $task->subtasks[] = $subtask;
				// if ($task->failed) continue;

				$sectionFile = $this->file($section, 'minified');

				if (!JFile::exists($sectionFile)) {
					return $task->report('Missing minified section file "' . $sectionFile . '".');
				}

				$sectionContent = JFile::read($file);

				if ($sectionContent===false) {
					return $task->reject('Unable to read minified section file "' . $sectionFile . '".');
				}
			}

			// Write to 'style.min.css'
			JFile::write($this->file('minified'), $content);
		}


		// Generate log file
		$log = $this->file('log');

		if (!JFile::write($log, $task->toJSON())) {
			$task->report('Unable to write log file "' . $logFile . '".', 'warn');
		}

		return $task;
	}
}