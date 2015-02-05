<?php

require_once(%BOOTCODE%_FOUNDRY_CLASSES . '/module.php');
require_once(%BOOTCODE%_FOUNDRY_LIB . '/cssmin.php');
require_once(%BOOTCODE%_FOUNDRY_LIB . '/closure.php');

jimport('joomla.filesystem.folder');
jimport('joomla.filesystem.file');

class %BOOTCODE%_FoundryBaseConfiguration {

	public $fullName;
	public $shortName;
	public $path;
	public $uri;
	public $file;

	public $environment = 'static';
	public $source      = 'local';
	public $mode        = 'compressed';
	public $extension   = '.min.js';

	public $cdn;
	public $enableCdn  = false;
	public $passiveCdn = false;

	public $scripts    = array();
	public $async      = true;
	public $defer      = true;
	public $inline     = false;

	static $bootloader = false;

	public function __construct()
	{
		$this->update();
	}

	public function update()
	{
		$app = JFactory::getApplication();
		$isAdmin = $app->isAdmin();

		// Disable CDN when running in backend
		if ($isAdmin) {
			$this->enableCdn = false;
		}

		// Allow url overrides
		$this->environment = $app->input->get($this->shortName . '_env', $this->environment, 'string');
		$this->mode = $app->input->get($this->shortName . '_mode', $this->mode, 'string');

		// Explicitly set mode to uncompressed when
		// under development mode.
		if ($this->environment=='development') {
			$this->mode = 'uncompressed';
		}

		if ($this->mode == 'compressed') {
			$this->extension = '.min.js';
		}

		if ($this->mode == 'uncompressed') {
			$this->extension = '.js';
		}

	}

	public function id()
	{
		return md5(serialize($this->data()));
	}

	public function data()
	{
		$data = $this->toArray();
		$data["modified"] = filemtime($this->file);
		$data["foundry_version"] = "$FOUNDRY_LONG_VERSION";

		return $data;
	}

	public function toArray()
	{
		// Note: Extended class furnish this with proper data.
		return array();
	}

	public function toJSON()
	{
		$config = $this->toArray();
		return json_encode($config);
	}

	public function createScriptTag($path)
	{
		return '<script' . (($this->defer) ? ' defer' : '') . (($this->async) ? ' async' : '') . ' src="' . $path . '"></script>';
	}

	public function attach()
	{
		$doc = JFactory::getDocument();

		if ($doc->getType() != 'html'){
			return;	
		} 

		// Load custom bootloader
		$this->loadBootloader();

		// Prefer CDN over site uri
		$uri = $this->enableCdn && !$this->passiveCdn ? $this->cdn : $this->uri;

		// Additional scripts uses addCustomTag because
		// we want to fill in defer & async attribute so
		// they can load & execute without page blocking.
		foreach ($this->scripts as $i => $script) {
			$scriptPath = $uri . '/scripts/' . $script . $this->extension;
			$scriptTag  = $this->createScriptTag($scriptPath);
			$doc->addCustomTag($scriptTag);
		}
	}

	/**
	 * Loads the bootloader
	 *
	 * @since	5.0
	 * @access	public
	 * @param	string
	 * @return	
	 */
	public function loadBootloader()
	{
		if (!self::$bootloader) {
			// Attach the bootloader 
			$doc = JFactory::getDocument();
			$bootloader = FD50_FOUNDRY_URI . '/scripts/bootloader.js';

			$doc->addScript($bootloader);

			self::$bootloader = true;
		}

	}

	public function export()
	{
		$this->update();

		ob_start();

		include($this->file);

		$contents = ob_get_contents();

		ob_end_clean();

		return $contents;
	}

	public function purge()
	{
		$this->update();

		$configPath = $this->path . '/config';

		if (!JFolder::exists($configPath)) return;

        $files = JFolder::files($configPath, '.', true, true);

		foreach($files as $file) {

			$state = JFile::delete( $file );
		}

		return true;
	}
}

class %BOOTCODE%_FoundryComponentConfiguration extends %BOOTCODE%_FoundryBaseConfiguration {

	static $components = array();

	public $foundry;

	public $namespace;
	public $componentName;
	public $baseUrl;
	public $version;
	public $token;
	public $options = array();

	public function __construct()
	{
		$this->foundry = %BOOTCODE%_FoundryConfiguration::getInstance();

		$NS = $this->namespace . '_';

		$this->fullName      = constant($NS.'CLASS_NAME');
		$this->componentName = constant($NS.'COMPONENT_NAME');
		$this->path          = constant($NS.'MEDIA');
		$this->uri           = constant($NS.'MEDIA_URI');
		$this->cdn           = (defined($NS.'MEDIA_CDN') ? constant($NS.'MEDIA_CDN') : '');
		$this->passiveCdn    = (defined($NS.'PASSIVE_CDN') ? constant($NS.'PASSIVE_CDN') : false);

		$this->file = $this->path . '/config.php';

		self::$components[] = $this;

		parent::__construct();
	}

	public function update()
	{
		parent::update();

		// If this is the first time we're attaching a component
		if (count(self::$components)==1) {

			// Automatically reflect environment & mode settings on Foundry
			// unless it is explicitly overriden via url.
			$this->foundry->environment = $this->environment;
			$this->foundry->mode        = $this->mode;

		// If we're attaching a secondary component
		} else {

			// and the secondary component is running under static mode
			if ($this->environment='static') {

				// If the environment of the primary component is static,
				// it should load under optimized mode, else it should
				// just follow the environment of the primary component.
				$primaryComponent   = self::$components[0];
				$primaryEnvironment = $primaryComponent->environment;

				$this->environment = ($primaryEnvironment=='static') ? 'optimized' : $primaryEnvironment;
			}
		}
	}

	public function toArray()
	{
		$this->update();

		$options = array(
			"environment" => $this->environment,
			"source"      => $this->source,
			"mode"        => $this->mode,
			"baseUrl"     => $this->baseUrl,
			"version"     => $this->version
		);

		// Use script & style path from CDN.
		if ($this->enableCdn) {
			$options["scriptPath"] = $this->cdn . '/scripts';
			$options["stylePath"]  = $this->cdn . '/styles';
		}

		$data = array_merge_recursive($options, $this->options);

		return $data;
	}

	public function attach()
	{
		// Update configuration
		$this->update();

		// Attach the meta tag on the page
		$doc = JFactory::getDocument();
		$options = array($this->mode, $this->version, $this->baseUrl, $this->cdn, $this->token);

		$doc->addCustomTag('<meta name="FD50:' . $this->fullName . '" content="' . implode(',', $options) . '" />');

		// Attach Foundry configuration & scripts
		$this->foundry->inline = $this->inline;
		$this->foundry->enableCdn = $this->enableCdn;
		$this->foundry->passiveCdn = $this->passiveCdn;
		$this->foundry->attach();

		// Attach component configuration & scripts
		$app = JFactory::getApplication();
		$this->mode = $app->input->get('fd_mode', $this->mode, 'string');

		// Attach component configuration & scripts
		parent::attach();
	}

	public function purge()
	{
		$this->foundry->purge();

		return parent::purge();
	}
}

class %BOOTCODE%_FoundryConfiguration extends %BOOTCODE%_FoundryBaseConfiguration {

	static $attached = false;

	public function __construct()
	{
		$this->environment = 'optimized';
		$this->path = %BOOTCODE%_FOUNDRY_PATH;
		$this->uri  = %BOOTCODE%_FOUNDRY_URI;
		$this->file = %BOOTCODE%_FOUNDRY_CLASSES . '/configuration/config.php';
		$this->cdn  = (defined('%BOOTCODE%_FOUNDRY_CDN') ? %BOOTCODE%_FOUNDRY_CDN : '');

		parent::__construct();
	}

	public static function getInstance()
	{
		static $instance = null;

		if (is_null($instance)) {
			$instance = new self();
		}

		return $instance;
	}

	public function update()
	{
		parent::update();

		// Allow url overrides
		$app = JFactory::getApplication();
		$this->mode = $app->input->get('fd_mode', $this->mode, 'string');

		switch ($this->environment) {

			case 'static':
				// Does not load anything as foundry.js
				// is included within component script file.
				$this->scripts = array();
				break;

			case 'optimized':
			default:
				// Loads a single "foundry.js"
				// containing all core foundry files.
				$this->scripts = array(
					'foundry'
				);
				break;

			case 'development':
				$this->async = false;
				$this->defer = false;
				// Load core foundry files separately.
				$this->scripts = array(
					'jquery',
					'lodash',
					'bootstrap3',
					'responsive',
					'utils',
					'storage',
					'uri',
					'mvc',
					'joomla',
					'module',
					'script',
					'stylesheet',
					'language',
					'template',
					'require',
					'iframe-transport',
					'server',
					'component'
				);
				break;
		}

		switch ($this->source) {

			case 'remote':
				// Note: Foundry hosted is not working yet.
				$this->uri = %BOOTCODE%_FOUNDRY_HOSTED;
				break;
		}
	}

	public function toArray()
	{
		$this->update();

		$app    = JFactory::getApplication();
		$config = JFactory::getConfig();

		$appendTitle = '';

		if ($config->get('sitename_pagetitles')) {
			$appendTitle = $config->get( 'sitename_pagetitles' ) == 1 ? 'before' : 'after';
		}

		$isAdmin = $app->isAdmin();

		$data = array(
			"environment"   => $this->environment,
			"mode"          => $this->mode,
			"path"          => $this->uri,
			"cdn"           => $this->cdn,
			"extension"     => $this->extension,
			"cdnPath"       => (defined('FD50_FOUNDRY_JOOMLA_CDN') ? FD50_FOUNDRY_JOOMLA_CDN : ''),
			"rootPath"      => FD50_FOUNDRY_JOOMLA_URI,
			"basePath"      => FD50_FOUNDRY_JOOMLA_URI . ($isAdmin ? '/administrator' : ''),
			"indexUrl"      => FD50_FOUNDRY_JOOMLA_URI . ($isAdmin ? '/administrator/index.php' : '/index.php'),
			"joomla.location" => ($isAdmin ? "admin" : "site"),
			"joomla.version" => (string) JVERSION,
			"joomla.debug"       => (bool) $config->get('debug'),
			"joomla.appendTitle" => $appendTitle,
			"joomla.sitename" => $config->get('sitename'),
			"locale" => JFactory::getLanguage()->getTag()
		);

		// Prefer CDN over site
		if ($this->enableCdn) {
			$data['path'] = $this->cdn;
		}

		return $data;
	}

	public function attach()
	{
		if (self::$attached) {
			return;
		}

		$doc = JFactory::getDocument();
		$options = $this->toArray();

		$doc->addCustomTag('<meta name="FD50" content="' . implode(',', $options) . '" />');

		parent::attach();

		self::$attached = true;
	}
}
