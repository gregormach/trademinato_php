<?php
// http://php.net/manual/es/function.bccomp.php
if (!function_exists('bcmax')) {
	function bcmax() {
		$args = func_get_args();
		if (count($args)==0) return false;
		$max = $args[0];
		foreach($args as $value) {
			if (bccomp($value, $max)==1) {
				$max = $value;
			}
		}
		return $max;
	}

	function bcmin() {
		$args = func_get_args();
		if (count($args)==0) return false;
		$min = $args[0];
		foreach($args as $value) {
			if (bccomp($min, $value)==1) {
				$min = $value;
			}
		}
		return $min;
	}
}
