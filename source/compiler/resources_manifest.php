<?php 

defined( '_JEXEC' ) or die( 'Unauthorized Access' );

$_manifest = array();

foreach($deps as $componentName => $component) {

	// Skip foundry
	if ($componentName=='Foundry') continue;

	$_deps = array('adapter' => $componentName);

	$types = array('template', 'view', 'language');

	foreach($types as $type) {

		if (empty($component[$type])) continue;

		$_deps[$type] = array();

		$modules = $component[$type];

		foreach ($modules as $module) {
			$_deps[$type][] = $module->name;
		}
	}

	$_manifest[] = $_deps;
}

$json = new Services_JSON();

echo $json->encode($_manifest);