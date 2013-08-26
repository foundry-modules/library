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

include(FOUNDRY_PATH . '/scripts/dispatch'          . $config->extension);
include(FOUNDRY_PATH . '/scripts/abstractComponent' . $config->extension);
?>

Dispatch
	.to("Foundry/$FOUNDRY_VERSION Configuration")
	.at(function($) {
		$.initialize("<?php echo $config->toJSON(); ?>");
	});