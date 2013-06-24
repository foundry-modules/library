<?php 

defined( '_JEXEC' ) or die( 'Unauthorized Access' );

foreach($deps as $componentName => $component) {

	// Skip foundry
	if ($componentName=='Foundry') continue;	

	echo 'dispatch("' . $componentName . '").to(function($){' . "\n";

	// 4. Templates
	if (!empty($component['template'])) {

		$templates = $component['template'];

		echo '$.require.template.loader(' . $this->getJSONData($templates) . ');' . "\n";
	}

	// 5. Views
	if (!empty($component['view'])) {

		$views = $component['view'];

		echo '$.require.template.loader(' . $this->getJSONData($views) . ');' . "\n";
	}

	// 6. Languages
	if (!empty($component['language'])) {

		$languages = $component['language'];

		echo '$.require.language.loader(' . $this->getJSONData($languages) . ');' . "\n";
	}

	echo '});' . "\n";
}