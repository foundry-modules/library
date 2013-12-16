<?php

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

			$name = $module->name;

 			if ($type=="view") {
				$name = str_replace(strtolower($componentName) . '/', '', $name);
			}

			$_deps[$type][] = $name;
		}
	}

	$_manifest[] = $_deps;
}
echo json_encode($_manifest);