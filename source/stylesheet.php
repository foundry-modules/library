<?php

require_once(%BOOTCODE%_FOUNDRY_CLASSES . '/stylesheet/compiler.php');
require_once(%BOOTCODE%_FOUNDRY_CLASSES . '/stylesheet/minifier.php');
require_once(%BOOTCODE%_FOUNDRY_CLASSES . '/stylesheet/builder.php');

jimport('joomla.filesystem.folder');
jimport('joomla.filesystem.file');
jimport('joomla.filesystem.path');

class %BOOTCODE%_Stylesheet {

	private $ns = null;

	private $_override;

	public $workspace = array(
		'site'       => null,
		'site_base'  => null,
		'admin'      => null,
		'admin_base' => null,
		'module'     => null,
		'override'   => null
	);

	public $location;

	static $attached = array();

	const FILE_STATUS_NEW       = -1;
	const FILE_STATUS_UNCHANGED = 0;
	const FILE_STATUS_MODIFIED  = 1;
	const FILE_STATUS_MISSING   = 2;
	const FILE_STATUS_REMOVED   = 3;
	const FILE_STATUS_UNKNOWN   = 4;

	public function __construct($ns='', $workspace=array(), $location) {

		$this->ns = $ns;
		$this->workspace = array_merge($this->workspace, $workspace);
		$this->location = $location;
	}

	public function folder($name='current') {

		$NS  = $this->ns . '_';
		$workspace = $this->workspace;

		switch ($name) {

			case 'user':
				$folder = constant($NS . 'USER_THEMES') . '/' . $this->location . '/' . $workspace[$this->location];
				break;

			case 'override':
				$administrator = ($this->location=='admin') ? 'administrator/' : '';
				$component = ($this->location=='module') ? $this->workspace['module'] : constant($NS . 'COMPONENT_NAME');
				$template = $this->workspace['override'];
				$folder = constant($NS . 'JOOMLA') . "$administrator/templates/$template/html/$component/styles";
				break;

			case 'site':
				$folder = constant($NS . 'SITE_THEMES') . '/' . $workspace['site'] . '/styles';
				break;

			case 'site_base':
				$folder = constant($NS . 'SITE_THEMES') . '/' . $workspace['site_base'] . '/styles';
				break;

			case 'admin':
				$folder = constant($NS . 'ADMIN_THEMES') . '/' . $workspace['admin'] . '/styles';
				break;

			case 'admin_base':
				$folder = constant($NS . 'ADMIN_THEMES') . '/' . $workspace['admin_base'] . '/styles';
				break;

			case 'module':
				$folder = constant($NS . 'JOOMLA_MODULES');
				if (!empty($workspace['module'])) {
					$folder .= '/' . $workspace['module'] . '/styles';
				}
				break;

			case 'media':
				$folder = constant($NS . 'MEDIA');
				break;

			case 'component':
				$folder = constant($NS . 'MEDIA') . '/styles';
				break;

			case 'foundry':
				$folder = constant($NS . 'FOUNDRY');
				break;

			case 'global':
				$folder = constant($NS . 'FOUNDRY') . '/styles';
				break;

			case 'root':
				$folder = constant($NS . 'JOOMLA');
				break;

			case 'cache':
				$folder = $this->folder('current') . '/cache';
				break;

			case 'current':
			default:
				$folder = $this->folder($this->location);
				break;
		}

		// Ensure consistency across platforms.
		$folder = JPath::clean($folder, '/');

		return $folder;
	}

	public function file($filename, $type=null) {

		// Default file options
		$defaultOptions = array(
			'location' => $this->location,
			'filename' => 'style',
			'type' => $type,
			'seek' => false
		);

		// Current options
		$options = array();

		// When passing in an object.
		// $this->file(array('location'=>'override', 'type'=>'css'));
		if (is_array($filename)) {
			$options = $filename;

		// When passing in type or filename + type pair.
		// $this->file('css') returns 'path_to_location/style.css'
		// $this->file('photos', 'css') returns 'path_to_location/photos.css'
		} else {
			$numargs = func_num_args();
			if ($numargs===1) $options['type'] = $filename;
			if ($numargs===2) $options['filename'] = $filename;
		}

		// Extract options as variables
		$options = array_merge($defaultOptions, $options);
		extract($options);

		// If we should seek for the file according
		// to the list of import ordering locations.
		if ($seek) {

			// Get list of import ordering locations
			$locations = %BOOTCODE%_Stylesheet_Compiler::importOrdering($this->location);

			// Go through each of the location
			foreach ($locations as $location) {

				$file = $this->file(array(
					'location' => $location,
					'filename' => $filename,
					'type' => $type
				));

				// and return if the file exists
				if (JFile::exists($file)) return $file;
			}

			// If file could not be found, return false
			return false;
		}

		// Construct filename without extension
		$file = $this->folder($location) . '/' . $filename;

		switch ($type) {

			case 'worksheet':
			case 'less':
				$file .= '.less';
				break;

			case 'stylesheet':
			case 'css':
				$file .= '.css';
				break;

			case 'minified':
				$file .= '.min.css';
				break;

			case 'manifest':
			case 'json':
				$file .= '.json';
				break;

			case 'fallback':
				$file .= '.default.css';
				break;

			case 'config':
			case 'xml':
				$file .= '.xml';
				break;

			case 'log';
				$file .= '.log';
				break;

			case 'cache':
				$file .= '.cache';
				break;

			case 'variables':
				$file .= '/variables.less';
				break;
		}

		return $file;
	}

	public function uri($filename, $type=null) {

		$path = is_array($filename) ?
					$this->file($filename) :
					$this->file($filename, $type);

		// This may be false if it involved seeking.
		if ($path===false) return false;

		$NS = $this->ns . '_';
		$root = constant($NS . 'JOOMLA');
		$root_uri = constant($NS . 'JOOMLA_URI');

		if (strpos($path, $root)===0) {
			$path = substr_replace($path, '', 0, strlen($root));
		}

		return $root_uri . $path;
	}

	public function relative($dest, $root='', $dir_sep='/') {

		$root = explode($dir_sep, $root);
		$dest = explode($dir_sep, $dest);
		$path = '.';
		$fix = '';
		$diff = 0;

		for ($i = -1; ++$i < max(($rC = count($root)), ($dC = count($dest)));) {

			if (isset($root[$i]) and isset($dest[$i])) {

				if ($diff) {
					$path .= $dir_sep. '..';
					$fix .= $dir_sep. $dest[$i];
					continue;
				}

				if ($root[$i] != $dest[$i]) {
					$diff = 1;
					$path .= $dir_sep. '..';
					$fix .= $dir_sep. $dest[$i];
					continue;
				}

			} elseif (!isset($root[$i]) and isset($dest[$i])) {

				for($j = $i-1; ++$j < $dC;) {
					$fix .= $dir_sep. $dest[$j];
				}
				break;

			} elseif (isset($root[$i]) and !isset($dest[$i])) {

				for($j = $i-1; ++$j < $rC;) {
					$fix = $dir_sep. '..'. $fix;
				}
				break;
			}
		}

		return $path . $fix;
	}

	public function compiler() {

		static $compiler;

		if (!isset($compiler)) {
			$compiler = new %BOOTCODE%_Stylesheet_Compiler($this);
		}

		return $compiler;
	}

	public function minifier() {

		static $minifier;

		if (!isset($minifier)) {
			$minifier = new %BOOTCODE%_Stylesheet_Minifier($this);
		}

		return $minifier;
	}


	public function builder() {

		static $builder;

		if (!isset($builder)) {
			$builder = new %BOOTCODE%_Stylesheet_Builder($this);
		}

		return $builder;
	}

	public function compile($section, $options=array()) {

		$compiler = $this->compiler();
		$task = $compiler->run($section, $options);
		return $task;
	}

	public function minify($section, $options=array()) {

		$minifier = $this->minifier();
		$task = $minifier->run($section, $options);
		return $task;
	}

	// $mode = fast | cache | full
	public function build($preset='cache', $options=array()) {

		$builder = $this->builder();
		$task = $builder->run($preset, $options);
		return $task;
	}

	public function manifest() {

		static $manifestContent;

		// Manifest content loaded before, just return it.
		if (isset($manifestContent)) return $manifestContent;

		$manifestFile = $this->file('manifest');

		// If manifest file exists,
		if (JFile::exists($manifestFile)) {

			// read manifest file,
			$manifestData = JFile::read($manifestFile);

			// and parse manifest data.
			$manifestContent = json_decode($manifestData, true);
		}

		// If no manifest file found or manifest could not be parsed, assume simple stylesheet.
		// Simple stylesheet does not contain sections, the bare minimum is a single "style.css" file.
		// If it has a "style.less" file, then this less file is considered the source stylesheet where "style.css" is compiled from, else "style.css" is considered the source stylesheet.
		if (!is_array($manifestContent)) {
			$manifestContent = array('style' => array('style'));
		}

		return $manifestContent;
	}

	public function sections() {

		static $sections;

		if (isset($sections)) return $sections;

		// Get manifest
		$manifest = $this->manifest();

		// Merge all sections in a single array
		$sections = array();
		foreach ($manifest as $group => $_sections) {
			$sections = array_merge($sections, $_sections);
		}

		// Remove duplicates
		$sections = array_unique($sections);

		return $sections;
	}

	public function override() {

		if (empty($this->_override)) {
			$this->_override = new self($this->ns, $this->workspace, 'override');
		}

		return $this->_override;
	}

	public function overrides() {

		static $overrides;

		if (isset($overrides)) return $overrides;

		// Prepare keywords for path building.
		$NS  = $this->ns . '_';
		$administrator = ($this->location=='admin') ? 'administrator/' : '';
		$component = ($this->location=='module') ? $this->workspace['module'] : constant($NS . 'COMPONENT_NAME');

		// Determine path for Joomla template folder because frontend and backend is different.
		$templateFolder = constant($NS . 'JOOMLA') . "$administrator/templates";

		// Get a list of template folders.
		$templates = JFolder::folders($templateFolder);

		// Go through each template folder to see if there is a stylesheet override.
		$overrides = array();
		foreach ($templates as $template) {

			$overrideFolder = "$templateFolder/$template/html/$component/styles";

			// If override folder exists, add to override list.
			if (JFolder::exists($overrideFolder)) {
				$overrides[] = $template;
			}
		}

		return $overrides;
	}

	public function hasOverride() {

		static $hasOverride;

		if (!isset($override)) {
			$overrideFile = $this->file(array('location' => 'override', 'type' => 'css'));
			$hasOverride = JFile::exists($overrideFile);
		}

		return $hasOverride;
	}

	public function attach($minified=true, $allowOverride=true) {

		$document = JFactory::getDocument();
		$app = JFactory::getApplication();

		// If this stylesheet has overridem
		if ($allowOverride && $this->hasOverride()) {

			// get override stylesheet instance,
			$override = $this->override();

			// and let override stylesheet attach itself.
			return $override->attach();
		}

		// Load manifest file.
		$manifest = $this->manifest();

		// Determine the type of stylesheet to attach
		$type = $minified ? 'minified' : 'css';
		$uris = array();

		foreach ($manifest as $group => $sections) {

			// Get stylesheet uri.
			$uri = $this->uri($group, $type);
			$uris[] = $uri;

			// Stop because this stylesheet
			// has been attached.
			if (isset(self::$attached[$uri])) return;

			// Attach to document head.
			$document->addStyleSheet($uri);

			// Remember this stylesheet so
			// we won't reattach it again.
			self::$attached[$uri] = true;
		}

		return $uris;
	}

	public function changes($fast=false) {

		static $result;

		$key = (string) $fast;

		if (!isset($result)) $result = array();

		// Return cached result if possible
		if (isset($result[$key])) return $result[$key];

		$task = new %BOOTCODE%_Foundry_Stylesheet_Task('Detect changes in stylesheets');

		$cacheFile = $this->stylesheet->file('cache');
		$cache = null;

		if (!JFile::exists($cacheFile)) {

			$cacheData = JFile::read($cacheFile);

			if (!$cacheData) {
				$task->report("No cache file found at '$cacheFile'.", %BOOTCODE%_Foundry_Stylesheet_Task::MESSAGE_INFO);
				return $task->reject();
			}

			$cache = json_decode($cacheData);
		}

		if (!is_array($cache)) {
			$task->report("Incompatible style cache structure or invalid cache file was provided at '$cacheFile'.");
			return $task->reject();
		}

		// Result dataset
		$changes  = array();
		$status   = array();
		$modified = false;

		// Get cache to detect missing or modified file.
		$files = $cache->files;

		// Get sections to detect new or deleted files.
		$sections = $this->sections();

		foreach ($sections as $section) {

			$filename = $section . '.css';

			if (!isset($files[$filename])) {
				$files[$filename] = null;
			}
		}

		// Go through each file to look for changes
		foreach ($files as $filename => $timestamp) {

			// For fast change detection. Used by hasChanges().
			if ($fast && $modified) break;

			// Get file path
			$file = $this->file($this->filename, 'css');
			$state = self::FILE_STATUS_UNCHANGED;

			// If the file does not exist anymore
			if (!JFile::exists($file)) {

				// If the file still exist in the manifest,
				// then this file is missing.
				if (isset($sections[$filename])) {
					$task->report("Missing file '$file'.");
					$state = self::FILE_STATUS_MISSING;

				// Else this file has been removed.
				} else {
					$task->report("Removed file '$file'.");
					$$state = self::FILE_STATUS_REMOVED;
				}

			} else {

				// Retrieve file's modified time
				$modifiedTime = @filemtime($file);

				// Skip and generate a warning if unable to retrieve timestamp
				if ($modifiedTime===false) {
					$task->report("Unknown modified time for '$file'.");
					$$state = self::FILE_STATUS_UNKNOWN;
				}

				// File is new
				if (is_null($timestamp)) {
					$task->report("New file found '$file'.");
					$state = self::FILE_STATUS_NEW;

				// File is modified
				} elseif ($timestamp < $modifiedTime) {
					$task->report("Modified file found '$file'.");
					$state = self::FILE_STATUS_MODIFIED;
				}
			}

			// Add to change list
			$changes[$file] = $state;

			// Increase state count
			if (isset($status[$state])) {
				$status[$state] = 0;
			}
			$status[$state]++;

			// Flag to indicate this stylesheet is modified
			if (!$modified && $state!==0) {
				$modified = true;
			}
		}

		// If there are no changes in this stylesheet, report it.
		if (!$modified) {
			$task->report('There are no changes in this stylesheet.');
		}

		$task->result = (object) array(
			'status'   => $status,
			'changes'  => $changes,
			'modified' => $modified
		);

		$result[$key] = $result;

		return $task->resolve();
	}

	public function hasChanges() {

		$task = $this->changes(true);

		// Unable to detect changes
		if ($task->failed) return null;

		return $task->result->modified;
	}

	public function purge() {

		// Create compile task object.
		$task = new %BOOTCODE%_Stylesheet_Task("Purge stylesheet cache & log files");

		// Get a list of all cache & log files in this folder.
		$files = JFolder::files($this->folder(), '(.cache|.log)$', true, true);

		// Go through each of the manifest files.
		foreach ($files as $file) {

			if (JFile::delete($file)) {
				$task->report("Deleted file '$file'.");
			} else {
				$task->report("Unable to delete '$file'.");
			}
		}

		return $task->resolve();
	}
}
