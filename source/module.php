<?php

class %BOOTCODE%_FoundryModule
{
	public $name      = null;
	public $type      = null;
	public $adapter   = null;
	public $compiler  = null;
	public $added    = false;

	private $manifest = null;
	private $data     = null;

	public function __construct($compiler, $adapterName, $moduleName, $moduleType)
	{
		$this->name     = $moduleName;
		$this->type     = $moduleType;
		$this->adapter  = $adapterName;
		$this->compiler = $compiler;
	}

	private function getAdapter() {

		return $this->compiler->getAdapter($this->adapter);
	}

	public function getData() {

		if (!empty($this->data)) {
			return $this->data;
		}

		$adapterMethod = 'get' . ucfirst($this->type);

		$this->data = $this->getAdapter()->$adapterMethod($this->name);

		return $this->data;
	}

	public function getManifest() {

		if (!empty($this->manifest)) {
			return $this->manifest;
		}

		$this->manifest = $this->getAdapter()->getManifest($this->name);

		return $this->manifest;
	}
}
