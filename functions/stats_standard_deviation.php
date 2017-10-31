<?php
if (!function_exists('stats_standard_deviation')) {
	/**
		* This user-land implementation follows the implementation quite strictly;
		* it does not attempt to improve the code or algorithm in any way. It will
		* raise a warning if you have fewer than 2 values in your array, just like
		* the extension does (although as an E_USER_WARNING, not E_WARNING).
		*
		* @param array $a
		* @param bool $sample [optional] Defaults to false
		* @return float|bool The standard deviation or false on error.
		*/
	function stats_standard_deviation(array $a, $sample = false) {
		$n = count($a);
		if ($n === 0) {
			trigger_error("The array has zero elements", E_USER_WARNING);
			return false;
		}
		if ($sample && $n === 1) {
			trigger_error("The array has only 1 element", E_USER_WARNING);
			return false;
		}
		// $mean = array_sum($a) / $n;
		$aa = 0.0;
		foreach ($a as $val){
			$aa = bcadd($val, $aa);
		}
		$mean = bcdiv($aa, $n);
		$carry = 0.0;
		foreach ($a as $val) {
			// $d = ((double) $val) - $mean;
			$d = bcsub($val, $mean);
			// $carry += $d * $d;
			$carry = bcadd($carry, bcpow($d, 2, EXCHANGE_ROUND_DECIMALS * 2), EXCHANGE_ROUND_DECIMALS * 2);
		};
		if ($sample) {
			--$n;
		}
		return bcsqrt(bcdiv($carry, $n, EXCHANGE_ROUND_DECIMALS * 2));
	}
}
