<?php
/**
 * @package		Foundry
 * @copyright	Copyright (C) 2010 - 2013 Stack Ideas Sdn Bhd. All rights reserved.
 * @license		GNU/GPL, see LICENSE.php
 *
 * Foundry is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 * See COPYRIGHT.php for copyright notices and details.
 */

defined('_JEXEC') or die('Restricted access');

require_once(JPATH_ROOT . '/media/foundry/$FOUNDRY_VERSION/joomla/framework.php');

jimport('joomla.filesystem.folder');
jimport('joomla.filesystem.file');

class %BOOTCODE%_FoundryBaseConfiguration {

	public $fullName;
	public $shortName;
	public $path;
	public $uri;
	public $cdn;
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
		$this->environment = JRequest::getString($this->shortName . '_env' , $this->environment, 'GET');
		$this->mode        = JRequest::getString($this->shortName . '_mode', $this->mode       , 'GET');

		// Explicitly set mode to uncompressed when
		// under development mode.
		if ($this->environment=='development') {
			$this->mode = 'uncompressed';
		}

		switch($this->mode) {

			case 'compressed':
			default:
				$this->extension = '.min.js';
				break;

			case 'uncompressed':
				$this->extension = '.js';
				break;
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
		$app = JFactory::getApplication();
		$document = JFactory::getDocument();
		$isAdmin = $app->isAdmin();

		// Do not attach if document type is not html.
		if ($document->getType() != 'html') return;

		if ($this->inline) {

			// Load configuration script inline
			// This is used together with JS compressor plugins that
			// defers the loading of configuration scripts.
			// Configuration scripts CANNOT be deferred because
			// configuration scripts provide bootloaders and
			// abstract component which inline scripts rely upon.
			$this->loadInline();

		} else {

			// Load configuration script first
			$this->load();
		}

		// Prefer CDN over site uri
		$uri = $this->enableCdn ? $this->cdn : $this->uri;

		// Additional scripts uses addCustomTag because
		// we want to fill in defer & async attribute so
		// they can load & execute without page blocking.
		foreach ($this->scripts as $i => $script) {
			$scriptPath = $uri . '/scripts/' . $script . $this->extension;
			$scriptTag  = $this->createScriptTag($scriptPath);
			$document->addCustomTag($scriptTag);
		}
	}

	public function load()
	{
		// This is cached so it doesn't always write to file.
		$script = $this->write();

		// If unable to write to file, e.g. file permissions issue.
		// then load the configuration script inline.
		if ($script->failed) {

			$this->loadInline();
		} else {

			// Add to the very top of document head.
			$document = JFactory::getDocument();
			$document->addScript($script->url);
		}

		return $script;
	}

	public function loadInline()
	{
		$document = JFactory::getDocument();

		$contents = $this->export();
		$document->addCustomTag('<script>' . $contents . '</script>');
	}

	public function write()
	{
		$configPath = $this->path . '/config/';
		$configUri  = $this->uri  . '/config/';

		// Prefer CDN
		if ($this->enableCdn) {
			$configUri = $this->cdn . '/config/';
		}

		$script = new stdClass();
		$script->id     = $this->id();
		$script->file   = $configPath . $script->id . '.js';
		$script->url    = $configUri  . $script->id . '.js';
		$script->data   = $configPath . $script->id . '.json';
		$script->failed = false;

		// Create config folder if it doesn't exist
		if (!JFolder::exists($configPath)) {
			JFolder::create($configPath);
		}

		// Write config file
		if (!JFile::exists($script->file)) {

			$contents = $this->export();

			if (!JFile::write($script->file, $contents)) {
				$script->failed = true;
			}

			// Also write cache data
			$data = $this->data();
			$jsonData = json_encode($data);

			JFile::write($script->data, $jsonData);
		}

		return $script;
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
		$this->cdn           = (defined($NS.'MEDIA_CDN') ? constant($NS.'MEDIA_CDN') : null);
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

			// @TODO: Automatically switch to remote source when
			// under static mode + full Foundry is not installed.
			if ($this->environment=="static") {
				// $this->foundry->source = 'remote';
			}

			// @TODO: Switch environment back to static if full foundry doesn't exists.

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
			"environment"   => $this->environment,
			"source"        => $this->source,
			"mode"          => $this->mode,
			"baseUrl"       => $this->baseUrl,
			"version"       => $this->version
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

		// Attach Foundry configuration & scripts
		$this->foundry->inline = $this->inline;
		$this->foundry->enableCdn = $this->enableCdn;
		$this->foundry->passiveCdn = $this->passiveCdn;
		$this->foundry->attach();

		// Attach component configuration & scripts
		parent::attach();

		// And lastly an ajax token ;)
		$document = JFactory::getDocument();
		$document->addCustomTag('<meta property="' . strtolower($this->fullName) . ':token" content="' . $this->token . '" />');
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
		$this->cdn  = (defined('%BOOTCODE%_FOUNDRY_CDN') ? %BOOTCODE%_FOUNDRY_CDN : null);

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
		$this->mode = JRequest::getString('fd_mode', $this->mode, 'GET');

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
					'bootstrap',
					'responsive',
					'utils',
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
				// Note: Foundry CDN is not working yet.
				$this->uri = %BOOTCODE%_FOUNDRY_CDN;
				break;
		}
	}

	public function toArray()
	{
		$this->update();

		$app    = JFactory::getApplication();
		$config = JFactory::getConfig();

		$appendTitle	= '';

		if( $config->get( 'sitename_pagetitles' ) )
		{
			$appendTitle	= $config->get( 'sitename_pagetitles' ) == 1 ? 'before' : 'after';
		}

		$isAdmin = $app->isAdmin();

		$data = array(
			"environment"   => $this->environment,
			"source"        => $this->source,
			"mode"          => $this->mode,
			"path"          => $this->uri,
			"cdn"           => $this->cdn,
			"extension"     => $this->extension,
			"cdnPath"       => (defined('%BOOTCODE%_FOUNDRY_JOOMLA_CDN') ? %BOOTCODE%_FOUNDRY_JOOMLA_CDN : null),
			"rootPath"      => %BOOTCODE%_FOUNDRY_JOOMLA_URI,
			"basePath"      => %BOOTCODE%_FOUNDRY_JOOMLA_URI . ($isAdmin ? '/administrator' : ''),
			"indexUrl"      => %BOOTCODE%_FOUNDRY_JOOMLA_URI . ($isAdmin ? '/administrator/index.php' : '/index.php'),
			"joomla"        => array(
				"location"  => ($isAdmin ? "admin" : "site"),
				"version"   => (string) JVERSION,
				"debug"     => (bool) $config->get('debug'),
				"appendTitle"	=> $appendTitle,
				"sitename"		=> $config->get( 'sitename' )
			),
			"locale"        => array(
				"lang"      => JFactory::getLanguage()->getTag()
			)
		);

		// Prefer CDN over site
		if ($this->enableCdn) {
			$data['path'] = $this->cdn;
		}

		return $data;
	}

	public function attach()
	{
		if (self::$attached) return;

		parent::attach();

		self::$attached = true;
	}
}
