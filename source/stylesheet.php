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

		$compressor = new %BOOTCODE%_Stylesheet_Minifier($this);
		$task = $compressor->run($section, $options);
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

	public function attach($minified=true) {

		$document = JFactory::getDocument();
		$app = JFactory::getApplication();

		// If this stylesheet has overridem
		if ($this->hasOverride()) {

			// get override stylesheet instance,
			$override = $this->override();

			// and let override stylesheet attach itself.
			return $override->attach();
		}

		// Load manifest file.
		$manifest = $this->manifest();

		// Fallback if unable to load manifest file
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
