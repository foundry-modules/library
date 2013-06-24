<?php 

defined( '_JEXEC' ) or die( 'Unauthorized Access' );

foreach($deps as $componentName => $component) {

	// Skip foundry
	if ($componentName=='Foundry') continue;

	echo 'dispatch("' . $componentName . '").to(function($){' . "\n";

	// 1. Predefine dependencies

	// 1.1 Predefine scripts
	if (!empty($component['script'])) {

		$scripts = $component['script'];

		echo '$.module(' . $this->getNames($scripts) . ');' . "\n";
	}

	// 1.2 Predefine templates
	if (!empty($component['template'])) {

		$templates = $component['template'];

		echo '$.require.template.loader(' . $this->getNames($templates) . ');' . "\n";
	}

	// 1.3 Predefine languages
	if (!empty($component['language'])) {

		$languages = $component['language'];

		echo '$.require.language.loader(' . $this->getNames($languages) . ');' . "\n";
	}

	// 2. Stylesheets
	if (!empty($component['stylesheet'])) {

		$stylesheets = $component['stylesheet'];

		echo '(function(){' . "\n";
		echo 'var stylesheetNames = ' . $this->getNames($stylesheets) . ';' . "\n";
		echo 'var state = ($.stylesheet(' . $this->getStylesheetData($stylesheets) . ')) ? "resolve" : "reject";' . "\n";
		echo '$.each(stylesheetNames, function(i, stylesheet){ $.require.stylesheet.loader(stylesheet)[state](); });' . "\n";
		echo '})();' . "\n";
	}

	echo '});' . "\n";
}

foreach($deps as $componentName => $component) {

	// Skip foundry
	if ($componentName=='Foundry') continue;	

	echo 'dispatch("' . $componentName . '").to(function($){' . "\n";

	// 3. Scripts
	if (!empty($scripts)) {

		echo $this->getData($scripts);
	}

	echo '});' . "\n";
}