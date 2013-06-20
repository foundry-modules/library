<?php 

defined( '_JEXEC' ) or die( 'Unauthorized Access' );

foreach($deps as $componentName => $component) {

	// 4. Templates
	if (!empty($component['template'])) {

		$templates = $component['template'];

		echo '$.require.template.loader(' . $this->getJSONData($templates) . ');\n'
	}

	// 5. Templates
	if (!empty($component['language'])) {

		$languages = $component['language'];

		echo '$.require.language.loader(' . $this->getJSONData($languages) . ');\n'
	}
}