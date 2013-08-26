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

class FoundryConfiguration {

	public $environment = 'optimized',
	public $source      = 'local';
	public $mode        = 'compressed';
	public $path        = FOUNDRY_URI;
	public $extension   = '.min.js';
	public $async       = false;
	public $defer       = false;
	public $scripts     = array();

	public function __construct()
	{
		// Allow url overrides
		$this->environment = JRequest::getString('fd_env' , $this->environment, 'GET');
		$this->source      = JRequest::getString('fd_src' , $this->source     , 'GET');
		$this->mode        = JRequest::getString('fd_mode', $this->mode       , 'GET');
	
		switch ($this->environment) {

			case 'static':
				// Does not load anything as foundry.js
				// is included within component script file.
				break;

			case 'optimized':
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
				$this->path = FOUNDRY_CDN;
				break;
		}

		switch($this->mode) {
			case 'uncompressed':
				$this->extension = '.min.js';
		}
	}

	public function id()
	{
		return md5(serialize($this->toArray()));
	}

	public function toArray()
	{
		$app = JFactory::getApplication();
		$config = JFactory::getConfig();

		$config = array(
			"environment"   => $this->environment,
			"source"        => $this->source,
			"mode"          => $this->mode,
			"path"          => $this->path,
			"extension"     => $this->extension,
			"rootPath"      => JURI::root(),
			"indexUrl"      => JURI::root() . (($app->isAdmin()) ? 'administrator/index.php' : 'index.php'),
			"joomla"        => array(
				"version"   => floatval(JVERSION),
				"debug"     => $config->get('debug')
			)
			"locale"        => array(
				"lang"      => JFactory::getLanguage()->getTag()
			)
		);

		return $config;
	}

	public function toJSON()
	{
		$json = new Services_JSON();
		$config = $this->toArray();
		return $json->encode($config);
	}

	public function attach()
	{
		static $loaded = false;

		if ($loaded) return;

		$document = JFactory::getDocument();

		// Load configuration script first
		$script = $this->load();

		// Additional scripts uses addCustomTag because
		// we want to fill in defer & async attribute so
		// they can load & execute without page blocking.
		foreach ($this->scripts as $i=>$script) {
			$scriptPath = $this->path . '/scripts/' . $script . $this->extension;
			$scriptTag  = '<script' . (($this->defer) ? '' : ' defer') . (($this->async) ? '' : ' async') . ' src="' . $scriptPath . '"></script>';
			$document->addCustomTag($scriptTag);
		}

		$loaded = true;
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
	}

	public function write()
	{
		$id = $this->id();

		$script = array(
			"id"     => $id,
			"file"   => FOUNDRY_PATH . '/config/' . $id . '.js',
			"url"    => FOUNDRY_URI  . '/config/' . $id . '.js',
			"failed" => false
		);

		if (!JFile::exists($script->file)) {

			$contents = $this->export();

			if (!JFile::write($script->file, $contents)) {
				$script->failed = true;
			}
		}

		return $script;
	}

	public function export()
	{
		ob_start();
		include(FOUNDRY_CLASSES . '/configuration/config.php');
		$contents = ob_get_contents();
		ob_end_clean();

		return $contents;
	}

	public function purge()
	{
		// TODO: Remove existing scripts in FOUNDRY_PATH . '/config'?
		// Delete folder? Recreate folder?
	}
}