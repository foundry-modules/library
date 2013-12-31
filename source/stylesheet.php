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

	const STOP_ON_FIRST_OCCURENCE = true;

	public function __construct($ns='', $workspace=array(), $location) {

		$this->ns = $ns;
		$this->workspace = array_merge($this->workspace, $workspace);
		$this->location = $location;
	}

	public function folder($name) {

		$NS  = $this->ns . '_';
		$workspace = $this->workspace;

		switch ($name) {

			case 'user':
				$folder = constant($NS . 'USER_THEMES') . '/' . $this->location . '/' . $workspace[$this->location];
				break;

			case 'override':
				$administator = ($this->location=='admin') ? 'administrator/' : '';
				$component = ($this->location=='module') ? $this->workspace['module'] : constant($NS . 'COMPONENT_NAME');
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

		// When passing in an object.
		// $this->file(array('location'=>'override', 'type'=>'css'));
		if (is_array($filename)) {
			extract($filename);
		}

		// When passing in type or filename + type pair.
		// $this->file('css') returns 'path_to_location/style.css'
		// $this->file('photos', 'css') returns 'path_to_location/photos.css'
		if (is_null($type)) {
			$type = $filename;
			$filename = 'style';
		}

		// Get path to current folder
		$location = empty($location) ? $this->location : $location;
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
			case 'json'
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

	public function compile($section, $options=array()) {

		$compiler = new %BOOTCODE%_Stylesheet_Compiler($this);
		$task = $compiler->run($section, $options);
		return $task;
	}

	public function minify($section, $options=array()) {

		$minifier = new %BOOTCODE%_Stylesheet_Minifier($this);
		$task = $minifier->run($section, $options);
		return $task;
	}

	// $mode = fast | cache | full
	public function build($mode='cache', $options=array()) {

		$builder = new %BOOTCODE%_Stylesheet_Builder($this);
		$task = $builder->run($mode, $options);
		return $task;
	}

	public function manifest() {

		static $manifestContent;

		// Manifest content loaded before, just return it.
		if (isset($manifestContent)) return $manifestContent;

		$manifestFile = $this->file('manifest');

		// No manifest file found.
		if (!JFile::exists($manifestFile)) return null;

		// Read manifest file.
		$manifestData = JFile::read($manifestFile);

		// Manifest could not be read.
		if (!$manifestData) return null;

		// Parse manifest data
		$manifestContent = json_decode($manifestData);

		return $manifestContent;
	}

	public function override() {

		if (empty($this->_override)) {
			$this->_override = new self($this->ns, $this->workspace, 'override');
		}

		return $this->_override;
	}

	public function hasOverride() {

		static $hasOverride;

		if (!isset($override)) {
			$overrideFile = $this->file({'location' => 'override', 'type' => 'css'});
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

		// If there is no manifest file,
		// assume folder has simple stylesheets.
		if (empty($manifest)) {
			$manifest['style'] = array();
		}

		// Determine the type of stylesheet to attach
		$type = $minified ? 'css' : 'minified';
		$uris = array();

		foreach ($manifest as $filename => $sections) {

			// Get stylesheet uri.
			$uri = $this->uri($filename, $type);
			$uris[] = $uri;

			// Stop because this stylesheet
			// has been attached.
			if (self::$attached[$uri]) return;

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

			if (!isset($files[$filename]) {
				$files[$filename] = null;
			}
		}

		// Go through each file to look for changes
		foreach ($files as $filename => $timestamp) {

			// For fast change detection. Used by hasChanges().
			if ($stopOnFirstOccurence && $modified) break;

			// Get file path
			$file = $this->file($this->filename, 'css');
			$state = self::FILE_STATUS_UNCHANGED;

			// If the file does not exist anymore
			if (!JFile::exists($file)) {

				// If the file still exist in the manifest,
				// then this file is missing.
				if (isset($manifest[$filename])) {
					$task->report("Missing file '$file'.");
					$state = self::FILE_STATUS_REMOVED;

				// Else this file has been removed.
				} else {
					$task->report("Deleted file '$file'.");
					$$state = self::FILE_STATUS_MISSING;
				}

			} else {

				// Retrieve file's modified time
				$modifiedTime = filemtime($file);

				// Skip and generate a warning if unable to retrieve timestamp
				if ($modifiedTime===false) {
					$task->report("Unable to get modified time for '$file'.");
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
			if (isset($status[$state]) {
				$status[$state] = 0;
			}
			$status[$state]++;

			// Flag to indicate this stylesheet is modified
			if (!$modified && $state!==0) {
				$modified = true;
			}
		}

		// If there are no changes in this stylesheet, report it.
		if (!$hasChanges) {
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

		$task = $this->changes(self::STOP_ON_FIRST_OCCURENCE);

		// Unable to detect changes
		if ($task->failed) return null;

		return $task->result->modified > 0;
	}

	public function purge() {

		// Create compile task object.
		$task = new %BOOTCODE%_Foundry_Stylesheet_Task("Purge stylesheet cache & log files");

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
