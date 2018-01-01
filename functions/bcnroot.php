<?php
function bcnroot($num, $n) {
	if ($n<1) return 0; // we want positive exponents
	if ($num<=0) return 0; // we want positive numbers
	if ($num<2) return 1; // n-th root of 1 or 2 give 1

	// g is our guess number
	$g=2;

	// while (g^n < num) g=g*2
	while (bccomp(bcpow($g,$n),$num)==-1) {
		$g=bcmul($g,"2");
	}
	// if (g^n==num) num is a power of 2, we're lucky, end of job
	if (bccomp(bcpow($g,$n),$num)==0) {
		return $g;
	}

	// if we're here num wasn't a power of 2 :(
	$og=$g; // og means original guess and here is our upper bound
	$g=bcdiv($g,"2"); // g is set to be our lower bound
	$step=bcdiv(bcsub($og,$g),"2"); // step is the half of upper bound - lower bound
	$g=bcadd($g,$step); // we start at lower bound + step , basically in the middle of our interval

	// while step!=1

	while (bccomp($step,"1")==1) {
		$guess=bcpow($g,$n);
		$step=bcdiv($step,"2");
		$comp=bccomp($guess,$num); // compare our guess with real number
		if ($comp==-1) { // if guess is lower we add the new step
			$g=bcadd($g,$step);
		} else if ($comp==1) { // if guess is higher we sub the new step
			$g=bcsub($g,$step);
		} else { // if guess is exactly the num we're done, we return the value
            		return $g;
        	}
    	}

	// whatever happened, g is the closest guess we can make so return it
	return $g;
}
