<?php
/**
 * @package		Foundry
 * @copyright	Copyright (C) 2012 StackIdeas Private Limited. All rights reserved.
 * @license		GNU/GPL, see LICENSE.php
 *
 * Foundry is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 * See COPYRIGHT.php for copyright notices and details.
 */

defined('_JEXEC') or die('Restricted access');

require_once('constants.php');
require_once(FOUNDRY_LIB . '/json.php');

class FoundryBaseConfiguration {

	static $attached = false;

	public $fullName;
	public $shortName;
	public $path;
	public $uri;
	public $file;

	public $environment = 'static';
	public $source      = 'local';
	public $mode        = 'compressed';
	public $extension  = '.min.js';

	public $scripts    = array();
	public $async       = true;
	public $defer       = true;	

	public function __construct()
	{
		$this->update();
	}	

	public function update()
	{
		// Allow url overrides
		$this->environment = JRequest::getString($this->shortName . '_env' , $this->environment, 'GET');
		$this->mode        = JRequest::getString($this->shortName . '_mode', $this->mode       , 'GET');

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

		return $data;
	}

	public function toArray()
	{
		return array();
	}

	public function toJSON()
	{
		$json = new Services_JSON();
		$config = $this->toArray();
		return $json->encode($config);
	}

	public function attach()
	{
		$document = JFactory::getDocument();

		// Load configuration script first
		$script = $this->load();

		// Additional scripts uses addCustomTag because
		// we want to fill in defer & async attribute so
		// they can load & execute without page blocking.
		foreach ($this->scripts as $i=>$script) {
			$scriptPath = $this->uri . '/scripts/' . $script . $this->extension;
			$scriptTag  = '<script' . (($this->defer) ? ' defer' : '') . (($this->async) ? ' async' : '') . ' src="' . $scriptPath . '"></script>';
			$document->addCustomTag($scriptTag);
		}
	}

	public function load()
	{
		$document = JFactory::getDocument();

		// This is cached so it doesn't always write to file.
		$script = $this->write();

		// If unable to write to file, e.g. file permissions issue.
		// Just dump the entire script on the head.
		if ($script->failed) {
			$contents = $this->export();
			$document->addCustomTag('<script>' . $contents . '</script>');
		} else {
			// Add to the very top of document head.
			$document->addScript($script->url);
		}

		return $script;
	}	

	public function write()
	{
		$script = new stdClass();
		$script->id     = $this->id();
		$script->file   = $this->path . '/config/' . $script->id . '.js';
		$script->url    = $this->uri  . '/config/' . $script->id . '.js';
		$script->data   = $this->path . '/config/' . $script->id . '.json';
		$script->failed = false;

		if (!JFile::exists($script->file)) {

			$contents = $this->export();

			if (!JFile::write($script->file, $contents)) {
				$script->failed = true;
			}

			// Also write cache data
			$json = new Services_JSON();
			$data = $this->data();
			JFile::write($script->data, $json->encode($data));
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

        $files = JFolder::files($this->path . '/config/', '.', true, true);

		foreach($files as $file) {

			$state = JFile::delete( $file );
		}

		return true;
	}
}

class FoundryComponentConfiguration extends FoundryBaseConfiguration {

	public $foundry;
	
	public $componentName;
	public $baseUrl;
	public $version;
	public $token;
	
	public function __construct()
	{
		$this->foundry = new FoundryConfiguration();

		$this->componentName = 'com_' . strtolower($this->fullName);
		$this->path = FOUNDRY_MEDIA_PATH . '/' . $this->componentName;
		$this->uri  = FOUNDRY_MEDIA_URI  . '/' . $this->componentName;

		$this->file = $this->path . '/config.php';

		parent::__construct();
	}

	public function update()
	{
		parent::update();

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
	}	

	public function toArray()
	{
		$this->update();

		$data = array(
			"environment"   => $this->environment,
			"source"        => $this->source,
			"mode"          => $this->mode,
			"baseUrl"       => $this->baseUrl,
			"version"       => $this->version
		);

		return $data;
	}

	public function export()
	{
		$data = "";

		// Include Foundry configuration
	    // if we're running under static mode
		if ($this->environment=='static') {
			$data .= $this->foundry->export();
		}		

		$data .= parent::export();

		return $data;
	}

	public function attach()
	{
		$document = JFactory::getDocument();

		// Load Foundry configuration if we're not under static mode
		if ($this->environment!=='static') {
			$this->foundry->attach();
		}

		parent::attach();

		// And lastly an ajax token ;)
		$document->addCustomTag('<script>' . $this->fullName . '.token = "' . $this->token . '";</script>');
	}

	public function purge()
	{
		$this->foundry->purge();

		return parent::purge();
	}
}

class FoundryConfiguration extends FoundryBaseConfiguration {

	public function __construct()
	{
		$this->fullName    = "EasySocial";
		$this->shortName   = "es";

		$this->environment = 'optimized';
		$this->path = FOUNDRY_PATH;
		$this->uri  = FOUNDRY_URI;
		$this->file = FOUNDRY_CLASSES . '/configuration/config.php';
		
		parent::__construct();
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
				break;

			case 'optimized':
			default:
				$this->async = true;
				$this->defer = true;			
				// Loads a single "foundry.js"
				// containing all core foundry files.
				$this->scripts = array(
					'foundry'
				);
				break;

			case 'development':
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
				$this->uri = FOUNDRY_CDN;
				break;
		}
	}

	public function toArray()
	{
		$this->update();

		$app    = JFactory::getApplication();
		$config = JFactory::getConfig();

		$data = array(
			"environment"   => $this->environment,
			"source"        => $this->source,
			"mode"          => $this->mode,
			"path"          => $this->uri,
			"extension"     => $this->extension,
			"rootPath"      => rtrim(JURI::root(), '/'),
			"indexUrl"      => JURI::root() . (($app->isAdmin()) ? 'administrator/index.php' : 'index.php'),
			"joomla"        => array(
				"version"   => floatval(JVERSION),
				"debug"     => (bool) $config->get('debug')
			),
			"locale"        => array(
				"lang"      => JFactory::getLanguage()->getTag()
			)
		);

		return $data;
	}

	public function attach()
	{
		if (self::$attached) return;

		parent::attach();
		
		self::$attached = true;		
	}	
}
