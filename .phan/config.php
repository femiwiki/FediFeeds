<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

// Translate is optional, and its classes are used only while it is loaded
$cfg['directory_list'] = array_merge( $cfg['directory_list'], [ '../../extensions/Translate' ] );
$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'], [ '../../extensions/Translate' ] );

// Phan's strict checks, which mediawiki-phan-config leaves off (femiwiki/femiwiki#705)
$cfg['strict_method_checking'] = true;
$cfg['strict_object_checking'] = true;
$cfg['strict_param_checking'] = true;
$cfg['strict_property_checking'] = true;
$cfg['strict_return_checking'] = true;

return $cfg;
