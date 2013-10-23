<?php
/**
 * @package		Foundry
 * @copyright	Copyright (C) 2010 - 2013 Stack Ideas Sdn Bhd. All rights reserved.
 * @license		GNU/GPL, see LICENSE.php
 *
 * Foundry is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 * See COPYRIGHT.php for copyright notices and details.
 */

defined('_JEXEC') or die('Restricted access');

define('FOUNDRY_JOOMLA_PATH', JPATH_ROOT);
define('FOUNDRY_JOOMLA_URI' , rtrim( JURI::root(), '/')) ;
define('FOUNDRY_MEDIA_PATH' , FOUNDRY_JOOMLA_PATH . '/media');
define('FOUNDRY_MEDIA_URI'  , FOUNDRY_JOOMLA_URI . '/media');

define('FOUNDRY_BOOTLOADER', '$FOUNDRY_BOOTLOADER');
define('FOUNDRY_VERSION'   , '$FOUNDRY_VERSION');
define('FOUNDRY_PATH'      , FOUNDRY_JOOMLA_PATH . '/media/foundry/' . FOUNDRY_VERSION);
define('FOUNDRY_URI'       , rtrim(JURI::root(), '/') . '/media/foundry/' . FOUNDRY_VERSION);
define('FOUNDRY_CDN'       , 'http://foundry.stackideas.com/' .  FOUNDRY_VERSION);
define('FOUNDRY_CLASSES'   , FOUNDRY_PATH . '/joomla');
define('FOUNDRY_LIB'       , FOUNDRY_PATH . '/libraries');