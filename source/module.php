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

class FoundryModule
{
	public $name      = null;
	public $type      = null;
	public $adapter   = null;
	public $compiler  = null;
	public $loaded    = false;

	private $manifest = null;
	private $data     = null;

	private $_adapter = null;

	public function __construct($compiler, $moduleName, $moduleType, )
	{
		$this->_adapter = $this->compiler->getAdapter($this->adapter);

		$module->name     = $moduleName;
		$module->type     = $moduleType;
		$module->adapter  = $adapterName;
		$module->compiler = $this;		

		$adapter->initModule($module);
	}

	public function getData() {

		if (!empty($this->data)) {
			return $this->data;
		}

		$this->data = $this->_adapter->$adapterMethod($this->name);

		return $this->data;
	}

	public function getManifest() {

		if (!empty($this->manifest) {
			return $this->manifest;
		}

		$this->manifest = $this->_adapter->getManifest($this->name);

		return $this->manifest;
	}
}
