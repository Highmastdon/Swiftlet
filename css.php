<?php
/**
 * @package Swiftlet
 * @copyright 2009 ElbertF http://elbertf.com
 * @license http://www.gnu.org/licenses/gpl-2.0.txt GNU Public License
 */

$contrSetup = array(
	'rootPath'   => './',
	'pageTitle'  => 'CSS Parser',
	'standAlone' => TRUE
	);

require($contrSetup['rootPath'] . '_model/init.php');

/*
 * Parse CSS files so we can use variables
 */
if ( !empty($model->GET_raw['files']) )
{
	$css = '';

	foreach ( explode(',', $model->GET_raw['files']) as $filename )
	{
		if ( is_file($file = $contr->viewPath . $filename) )
		{
			$css .= '/* ' . $filename . ' */' . "\n\n" . trim(file_get_contents($file)) . "\n\n";
		}
	}

	preg_match('/@variables \{([^}]+)\}\s*/s', $css, $m);

	if ( isset($m[1]) )
	{
		foreach ( explode(';', trim($m[1])) as $pair )
		{
			if ( strstr($pair, ':') )
			{
				list($k, $v) = explode(':', $pair);

				$css = trim(str_replace($m[0], '', str_replace('var(' . trim($k) . ')', trim($v), $css)));
			}
		}
	}
}

header('Content-type: text/css');

echo $css;

$model->end();
