<?php
if (!function_exists('bcabs')) {
	function bcabs($number){
		return preg_replace('/^\-+/', '', $number);
	}
}
