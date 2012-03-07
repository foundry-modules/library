<?php
/**
 * @package		Foundry
 * @copyright	Copyright (C) 2012 StackIdeas Private Limited. All rights reserved.
 * @license		GNU/GPL, see LICENSE.php
 *
 * Foundry is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 * See COPYRIGHT.php for copyright notices and details.
 */

defined('_JEXEC') or die('Restricted access');

static $loaded	= false;

if( !$loaded ) {

	$doc = JFactory::getDocument();

	$version = "$FOUNDRY_VERSION";

	$environment = JRequest::getString( 'foundry_environment', 'development', 'GET' );

	$foundryPath = rtrim(JURI::root(), '/') . '/media/foundry/' . $version . '/';

	switch ($environment) {

		case 'production':

			$scriptPath = $foundryPath . 'scripts/';

			$scripts = array(
				'foundry'
			);

			break;

		case 'development':

			$scriptPath = $foundryPath . 'scripts_/';

			$scripts = array(
				'dispatch',
				'abstractComponent',
				'jquery',
				'utils',
				'uri',
				'module',
				'script',
				'stylesheet',
				'language',
				'template',
				'require',
				'component'
			);

			break;
	}

	foreach ($scripts as $i=>$script) {

		$doc->addScript($scriptPath . $script . '.js');
	}

	ob_start();
?>

dispatch
	.to("$FOUNDRY_NAMESPACE Bootstrap")
	.at(function($, manifest) {

		$.rootPath    = '<?php echo JURI::root(); ?>';
		$.indexUrl    = '<?php echo JURI::base() . "index.php"; ?>';
		$.path        = '<?php echo $foundryPath; ?>';
		$.scriptPath  = '<?php echo $scriptPath; ?>';
		$.environment = '<?php echo $environment ?>';

		// Make sure core plugins are installed first
		dispatch("$FOUNDRY_NAMESPACE")
			.containing($)
			.onlyTo("$FOUNDRY_NAMESPACE Core Plugins");
	});

<?php
	$contents = ob_get_contents();
	ob_end_clean();

	$doc->addScriptDeclaration($contents);
}
?>
