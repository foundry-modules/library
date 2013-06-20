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

require_once('constants.php');

require_once(FOUNDRY_LIB . '/json.php');
require_once(FOUNDRY_LIB . '/jsmin.php');
require_once(FOUNDRY_LIB . '/cssmin.php');

jimport( 'joomla.filesystem.file' );

class FoundryCompiler
{
	private $modules  = array();
	private $adapters = array();

	public function __construct()
	{
	}

	public function getAdapter($adapterName='foundry')
	{
		// If the adapter has been loaded, just return it.
		if (!empty($this->adapters[$adapterName])) {
			return $this->adapters[$adapterName];
		}

		// Try to get the adapter class
		$adapterClass = 'FoundryCompiler_' . ucfirst($adapterName);

		if (!class_exists($adapterClass)) {

			// If the adapter class does not exist, try to load it.
			$adapterFile = JPATH_ROOT . '/administrator/components/com_' . $adapterName . '/foundry.php';

			// If the adapter file is missing, stop.
			if (!file_exists($file)) {
				return null;
			}

			require_once($adapterFile);
		}

		// Create an instance of the adapter
		$this->adapters[$adapterName] = new $adapterClass($this);

		return $this->adapters[$adapterName];
	}

	public function getModule($moduleName, $moduleType='script', $adapterName='foundry')
	{
		$adapter = $this->getAdapter($adapterName);

		// Create module instance
		$module = $adapter->createModule($moduleName, $moduleType, $adapterName);

		// Create adapter entry
		if (!array_key_exists($module->adapter, $this->modules)) {
			$this->modules[$module->adapter] = array();
		}

		// Create module type entry
		if (!array_key_exists($module->type, $this->modules[$module->adapter]) {
			$this->modules[$module->adapter][$module->type] = array();
		}

		// Create module entry
		if (!array_key_exists($module->name, $this->modules[$module->adapter][$module->type]) {

			// Store a reference to the module instance
			$this->modules[$module->adapter][$module->type][$module->name] = $module;

		} else {

			// Discard previously created module for existing one
			$module = $this->modules[$module->adapter][$module->type][$module->name];
		}

		return $module;
	}

	private function getDependencies($manifest, $deps=array())
	{
		$manifests = (is_object($manifest)) ? array($manifest) : $manifest;

		foreach($manifests as $manifest) {

			$adapterName = (empty($manifest->adapter)) ? 'default' : $manifest->adapter;
			$adapter = $this->getAdapter($adapterName);

			foreach($manifest as $moduleType => $moduleName)
			{
				// Create module entry
				$module = $this->getModule(
					$moduleName,
					$moduleType,
					$adapterName
				);

				if (!$module->loaded) {

					// Add it to the dependency tree
					if (!array_key_exists($module->type, $deps)) {
						$deps[$module->type][] = array();
					};

					$deps[$module->type][] = $module

					if ($module->type=='script') {

						// Crawl into module's dependencies
						$this->getDependencies($module->getManifest(), &$deps)						
					}
				}
			}
		}

		return $deps;
	}
	/**
	 * Minifies javascript
	 *
	 * @since	1.0
	 * @access	public
	 * @param	string
	 * @return	
	 */
	public function minifyjs( $contents )
	{
		return JSMinPlus::minify( $contents );
	}

	/**
	 * uglifies css codes
	 *
	 * @since	1.0
	 * @access	public
	 * @param	string
	 * @return	
	 */
	public function minifycss( $contents )
	{
		return CssMin::minify( $contents );
	}
}