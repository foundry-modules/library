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

if (!$loaded) {

	$app = JFactory::getApplication();
	$doc = JFactory::getDocument();
	$jConfig = JFactory::getConfig();

	$foundry_version = "$FOUNDRY_VERSION";


	// FOUNDRY ENVIRONMENT ---------------------------------------//
	// If no foundry_environment is set, default to optimized.
	if (empty($foundry_environment)) {
		$foundry_environment = 'optimized';
	}

	// Allow foundry_environment to be overriden via url
	$foundry_environment = JRequest::getString('fd_env', $foundry_environment, 'GET');


	// FOUNDRY SOURCE -------------------------------------------//
	// If no foundry_source is set, default to local.
	// Component should explicitly set this to remote when
	// running under static mode.
	if (empty($foundry_source)) {
		$foundry_source = 'local';
	}

	// Allow foundry_source to be overriden via url
	$foundry_source = JRequest::getString('fd_src', $foundry_source, 'GET');


	// FOUNDRY MODE ---------------------------------------------//
	// If no foundry_mode is set, default to compressed.
	if (empty($foundry_mode)) {
		$foundry_mode = 'compressed';
	}

	// Allow foundry_source to be overriden via url
	$foundry_mode = JRequest::getString('fd_mode', $foundry_mode, 'GET');	

	// FOUNDRY PATH ---------------------------------------------//
	// If no foundry_path is set, default to local or remote.
	if (empty($foundry_path)) {

		switch ($foundry_source) {
			case 'remote':
				// TODO: Set up Foundry CDN server.
				$foundry_path = '';
				break;

			case 'local':
				$foundry_path = rtrim(JURI::root(), '/') . '/media/foundry/' . $foundry_version . '/';
				break;
		}
	}

	$scripts = array();

	// Load Foundry scripts in header
	switch ($foundry_environment) {

		case 'static':
			// Does not load anything as foundry.js
			// is included within component script file.
			$scripts = array();
			break;

		case 'optimized':
			// Loads a single "foundry.js"
			// containing all core foundry files.
			$scripts = array(
				'foundry'
			);
			break;

		case 'development':
			// Load core foundry files separately.
			$scripts = array(
				'dispatch',
				'abstractComponent',
				'jquery',
				'lodash',
				'bootstrap',
				'responsive',
				'utils',
				'uri',
				'mvc',
				'joomla',
				'module',
				'script',
				'stylesheet',
				'language',
				'template',
				'require',
				'iframe-transport',
				'server',
				'component'
			);
			break;
	}

	foreach ($scripts as $i=>$script) {
		$doc->addScript($foundry_path . 'scripts/' . $script . (($foundry_mode=='uncompressed') ? '.js' : '.min.js'));
	}

	ob_start();
?>

Dispatch
	.to("$FOUNDRY_NAMESPACE Configuration")
	.at(function($, manifest) {

		<?php if ($foundry_environment=="development"): ?>
		window.F = $;
		<?php endif; ?>

		$.rootPath      = '<?php echo JURI::root(); ?>';
		$.indexUrl      = '<?php echo JURI::root() . (($app->isAdmin()) ? 'administrator/index.php' : 'index.php') ?>';
		$.path          = '<?php echo $foundry_path; ?>';
		$.source        = '<?php echo $foundry_source; ?>';
		$.environment   = '<?php echo $foundry_environment; ?>';
		$.mode          = '<?php echo $foundry_mode; ?>';
		$.joomlaVersion = <?php echo floatval(JVERSION); ?>;
		$.joomlaDebug   = <?php echo $jConfig->get('debug'); ?>;
		$.locale = {
			lang: '<?php echo JFactory::getLanguage()->getTag(); ?>'
		};

		// Make sure core plugins are installed first
		Dispatch("$FOUNDRY_NAMESPACE")
			.containing($)
			.onlyTo("$FOUNDRY_NAMESPACE Core Plugins");
	});

<?php
	$contents = ob_get_contents();
	ob_end_clean();

	$doc->addScriptDeclaration($contents);
}
?>
