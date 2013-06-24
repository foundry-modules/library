<?php

defined( '_JEXEC' ) or die( 'Unauthorized Access' );

/*
	Static compilation
	------------------
	[component.static.js]
	1. Foundry (foundry.js)
	2. Templates
	3. Stylesheets
	4. Predefine Scripts
	5. Scripts
	6. Continue to "Optimized compilation".

	Optimized compilation
	---------------------
	1. Predefine ALL component dependencies
	   * Scripts
	   * Templates (incl. views)
	   * Languages
	2. Stylesheets
	3. Scripts

	Extras
	------
	[component.extras.js]
	This is the failsafe extras file.
	1. Templates
	2. Views
	3. Languages

	[component.extras.json --> component.extras.%hash%.js]
	This is so we can quickly construct a "template x language" hash.
	1. Manifest for component templates & languages.
*/

// FOUNDRY

if ($compileMode=='static') {

	// 1. Foundry (foundry.js)
	echo $this->getFoundry();

	if (!empty($deps['foundry'])) {

		$foundry = $deps['foundry'];

		// 2. Templates
		if (!empty($foundry['template'])) {

			$templates = $deps['foundry']['template'];

			echo '$.require.template.loader(' . $this->getJSONData($templates) . ');' . "\n";
		}

		// 3. Stylesheets
		if (!empty($foundry['stylesheet'])) {

			$stylesheets = $foundry['stylesheet'];

			echo '(function(){' . "\n";
			echo 'var stylesheetNames = ' . $this->getNames($stylesheets) . ';' . "\n";
			echo 'var state = ($.stylesheet(' . $this->getStylesheetData($stylesheets, $minify) . ')) ? "resolve" : "reject";' . "\n";
			echo '$.each(stylesheetNames, function(i, stylesheet){ $.require.stylesheet.loader(stylesheet)[state](); });' . "\n";
			echo '})();' . "\n";
		}

		// 4. Predefine scripts
		if (!empty($foundry['script'])) {

			$scripts = $foundry['script'];

			echo '$.module(' . $this->getNames($scripts) . ');' . "\n";

			// 5. Scripts
			echo $this->getData($scripts);
		}
	}
}

include('optimized.php');