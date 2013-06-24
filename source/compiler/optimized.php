<?php 

defined( '_JEXEC' ) or die( 'Unauthorized Access' );

foreach($deps as $componentName => $component) {

	// Skip foundry
	if ($componentName=='foundry') continue;

	// 1. Predefine dependencies

	// 1.1 Predefine scripts
	if (!empty($component['script'])) {

		$scripts = $component['script'];

		echo '$.module(' . $this->getNames($scripts) . ');';
	}

	// 1.2 Predefine templates
	if (!empty($component['template'])) {

		$templates = $component['template'];

		echo '$.require.template.loader(' . $this->getNames($templates) . ');\n'
	}

	// 1.3 Predefine languages
	if (!empty($component['languages'])) {

		$languages = $component['language'];

		echo '$.require.language.loader(' . $this->getNames($languages) . ');\n'
	}

	// 2. Stylesheets
	if (!empty($component['stylesheet'])) {

		$stylesheets = $component['stylesheet'];

		echo '(function(){';
		echo 'var stylesheetNames = ' . $this->getNames($stylesheets) . ';';
		echo 'var state = ($.stylesheet(' . $this->generateStylesheetData($stylesheets) . '})) ? "resolve" : "reject";';
		echo '$.each(stylesheetNames, function(i, stylesheet){ $.require.stylesheet.loader(stylesheet)[state](); });'
		echo '})();'
	}	
}

foreach($deps as $componentName => $component) {

	// Skip foundry
	if ($componentName=='foundry') continue;	

	// 3. Scripts
	if (!empty($scripts)) {

		echo $this->getData($scripts);
	}
}