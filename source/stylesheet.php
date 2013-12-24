<?php

require_once(%BOOTCODE%_FOUNDRY_CLASSES . '/stylesheet/compiler.php');
require_once(%BOOTCODE%_FOUNDRY_CLASSES . '/stylesheet/compressor.php');
require_once(%BOOTCODE%_FOUNDRY_CLASSES . '/stylesheet/builder.php');

jimport('joomla.filesystem.folder');
jimport('joomla.filesystem.file');
jimport('joomla.filesystem.path');

class %BOOTCODE%_Stylesheet {

	private $ns = null;

	public $workspace = array(
		'site'       => null,
		'site_base'  => null,
		'admin'      => null,
		'admin_base' => null,
		'module'     => null
	);

	public $location;

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
				$folder = $this->folder($this->location);
				break;

			default:
				$folder = null;
				break;
		}

		// Ensure consistency across platforms.
		$folder = JPath::clean($folder, '/');

		return $folder;
	}

	public function file($filename, $type=null) {

		// Normalize arguments
		if (is_null($type)) {
			$type = $filename;
			$filename = 'style';
		}

		// Get path to current folder
		$file = $this->folder($this->location) . '/' . $filename;

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

		$NS  = $this->ns . '_';

		$path = $this->file($filename, $type);
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

	public function compress($section, $options=array()) {

		$compressor = new %BOOTCODE%_Stylesheet_Compressor($this);
		$task = $compile->run($section);
		return $task;
	}

	// $mode = fast | cache | full
	public function build($mode='cache', $options=array()) {

		$builder = new %BOOTCODE%_Stylesheet_Builder($this);
		$task = $builder->run($mode);
		return $task;
	}

	public function purge() {
		// TODO: Purging
	}
}
