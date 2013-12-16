<?php

require_once(%BOOTCODE%_FOUNDRY_LIB . '/cssmin.php');

class %BOOTCODE%_Stylesheet_Compressor extends %BOOTCODE%_Libraries_CSSMin {

	private $stylesheet;

	public function __construct($stylesheet) {

		$this->stylesheet = $stylesheet;
	}
}