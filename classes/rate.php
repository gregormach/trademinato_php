<?php
$server_path = dirname(__FILE__);
set_include_path(get_include_path() . PATH_SEPARATOR . $server_path . '/../' . PATH_SEPARATOR . $server_path . '/');

require_once ('config.php');
require_once ('resources/functions/stats_standard_deviation.php');
require_once ('resources/functions/bcmax_min.php');

/**
  *  Rate class. A rate class contains all information about the currency valuation against another
*/

class Rate{
	private $exchange;
	private $from;
	private $to;
	private $last;
	private $lowest_ask;
	private $highest_bid;
	private $percent_change;
	private $base_volume;
	private $quote_volume;
	private $is_frozen;
	private $high_24hr;
	private $low_24hr;
	private $historial;
	private $resistance;
	private $resistance0;
	private $support;
	private $support0;
	private $pair;
	private $period;
	private $my_trades;
	private $base_volume_market_share;
	private $breaking_point;				// Paying / (got - fee) ??
	private $book;
	private $spread;
	private $percent_wise;

	function __construct(&$exchange, $from, $to, $last, $lowest_ask, $highest_bid, $percent_change, $base_volume, $quote_volume, $is_frozen, $high_24hr, $low_24hr){
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "function rate::__construct(\$exchange, $from, $to, $last, $lowest_ask, $highest_bid, $percent_change, $base_volume, $quote_volume, $is_frozen, $high_24hr, $low_24hr)");
		$this->exchange = &$exchange;
		$this->from = $from;
		$this->to = $to;
		$this->last = $last;
		$this->lowest_ask = $lowest_ask;
		$this->highest_bid = $highest_bid;
		$this->percent_change = $percent_change;
		$this->base_volume = $base_volume;
		$this->quote_volume = $quote_volume;
		$this->is_frozen = $is_frozen;
		$this->high_24hr = $high_24hr;
		$this->low_24hr = $low_24hr;
		$this->historial = array();
		$this->resistance = (double) 0.0;
		$this->support = (double) 0.0;
		$this->pair = $this->exchange->build_pair_string($from,$to);
		$this->period = 900;
		$this->my_trades = array();
		$this->refresh();
		$this->base_volume_market_share = 0;
		$this->book = array();
		$this->spread = bcsub($lowest_ask, $highest_bid);
		$this->percent_wise = bcdiv($this->spread, $lowest_ask, EXCHANGE_ROUND_DECIMALS * 2);
	}

	public function get_historic_data($start, $end){
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function &rate::get_historic_data($start, $end)");
		$answer = $this->exchange->get_historic_data($this->pair, $start, $end, $this->period);
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS_OUTPUT))
			syslog(LOG_INFO|LOG_LOCAL1, "public function &rate::get_historic_data($start, $end) = ".print_r($answer, true));
		return $answer;
	}

	/**
	@param number $period
	number in seconds, 300, 900, 1800, 7200 or 14400
	@param number $end
	timestamp of the ending date, can not be greater than now
	@param number $start
	timestamp of the starting date, can not be greater or equal than end
	@retval object Wallet
	The wallet object corresponding to that currency
	*/
	public function refresh($period = 900, $end = null, $start = null, $elements = RATE_REFRESH_ALL){
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::refresh($period, $end, $start, $elements)");
		date_default_timezone_set('GMT');
		$date = new DateTime();
		if (is_null($end)){
			$end = $date->getTimestamp();
		}
		else{
			if ($end > $date->getTimestamp()){
~				$end = $date->getTimestamp();
			}
		}

		if (is_null($start)){
			$start = $end - 1296000;
		}
		else{
		      if ($start >= $end){
				$start = $end - 1296000;
		      }
		}

		switch($period){
			case 300:
			case 900:
			case 1800:
			case 7200:
			case 14400:
				$this->period = $period;
				break;
			default:
				$period = 900;
				$this->period = 900;
		}

		/* TODO: Be sure we get at least 30 samples */
		if ($elements & RATE_REFRESH_TICKER){
			$this->historial = $this->get_historic_data($start, $end);
			$max = 0; $min = 999999999; $nReg = count($this->historial);
			for($i = 0 ; $i < $nReg; $i++){
				$h = $this->historial[$i];
				$max = bcmax($max, $h['high']);
				$min = bcmin($min, $h['low']);
			}

			$this->resistance0 = $max;
			$this->support0 = $min;
			$five = bcmul(bcsub($max, $min), 0.05);
			$this->resistance = bcsub($max, $five);
			$this->support = bcadd($min, $five);
		}

		if (!$this->exchange->is_public()){
			if ($elements & RATE_REFRESH_MY_TRADES){
				$this->get_my_trade_history();
			}
		}

		if ($elements & RATE_REFRESH_ORDERS){
			$this->book = $this->get_orders();
		}
	}

	/**
	@retval array
	All the historical information
	*/
	public function get_my_trade_history($end = null, $start = null){
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::get_my_trade_history($end,$start)");

		if ($this->exchange->is_public()){
			throw new Exception('This exchange is in public mode.');
			return array();
		}
		else{
			$date = new DateTime();
			if (is_null($end)){
				$end = $date->getTimestamp();
			}
			else{
				if ($end > $date->getTimestamp()){
					$end = $date->getTimestamp();
				}
			}

			if (is_null($start)){
				$start = $end - 1296000; // 15 days in seconds
			}
			else{
			      if ($start >= $end){
					$start = $end - 1296000;
			      }
			}

			$this->my_trades = $this->exchange->get_my_trade_history($this->pair, $end, $start);
			if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS_OUTPUT))
				syslog(LOG_INFO|LOG_LOCAL1, "public function rate::get_my_trade_history($end,$start) = ".print_r($this->myTrades,true));
		}

		return $this->my_trades;
	}

	/**
	@retval array
	All the historical information
	*/
	public function &historial(){
		if (count($this->historial) == 0){
			$this->refresh();
		}
		return $this->historial;
	}

	/**
	@retval string
	The ISO code of the destination currency
	*/
	public function &to(){
		return $this->to;
	}

	/**
	@retval string
	The ISO code of the origin currency
	*/
	public function &from(){
		return $this->from;
	}

	/**
	@retval string
	The pair XXX_YYY
	*/
	public function &pair(){
		return $this->pair;
	}

	/**
	@retval number
	The resistance point calculated from the current historial
	*/
	public function &resistance(){
		return $this->resistance;
	}

	/**
	@retval number
	The support point calculated from the current historial
	*/
	public function &support(){
		return $this->support;
	}

	/**
	@retval boolean
	Returns if this currency is considered liquid
	*/
	public function is_liquid(){
		return ($this->lowest_ask <= $this->highest_bid)?true:false;		// This is only one of many conditions to determine if it is or not
	}

	/**
	@retval number
	Last rate price
	*/
	public function last(){
		return $this->last;
	}

	/**
	@retval number
	Last rate price
	*/
	public function percent_change(){
		return $this->percent_change;
	}

	/**
	@retval number
	Highest Bid
	*/
	public function highest_bid(){
		return $this->highest_bid;
	}

	/**
	@retval number
	Lowest Ask
	*/
	public function lowest_ask(){
		return $this->lowest_ask;
	}

	/**
	@retval array
	Past trades
	*/
	public function my_trades(){
		if (count($this->my_trades) == 0){
			$this->get_my_trade_history();
		}
		return $this->my_trades;
	}

	/**
	@param number $maxShare
	100% value
	@retval object
	This
	*/
	public function &set_market_share($max){
		if (bccomp($max, 0) > 0){
			$this->base_volume_market_share = bcdiv($this->base_volume, $max, EXCHANGE_ROUND_DECIMALS * 2);
		}
		return $this;
	}

	/**
	@retval number
	Percentage of Volume Market Share
	*/
	public function &base_volume_market_share(){
		return $this->base_volume_market_share;
	}

	/**
	@retval number
	Percentage of Volume Market Share
	*/
	public function &get_market_share(){
		return $this->base_volume_market_share;
	}

	/**
	@param float
	@retval boolean
	Returns if price is equal or bellow the percentage band
	*/
	public function &is_bellow_percentage_band($percentage){
		$one = ($this->resistance0 - $this->support0) / 100;
		$c = $this->last();
		$r = ($c <= ($this->support0 + $one * $percentage))?true:false;
		return $r;
	}

	/**
	@retval array
	array of historial
	*/
	public function get_historial(){
		return $this->historial;
	}

	/**
	@retval integer
	*/
	public function period(){
		return $this->period;
	}

	/**
	@retval integer
	*/
	public function base_volume(){
		return $this->base_volume;
	}

	/**
	@retval array
	*/
	public function book(){
		$this->book = $this->get_orders();
		return $this->book;
	}

	/**
	@retval float
	*/
	public function spread(){
		$this->book = $this->get_orders();
		return $this->spread;
	}

	/**
	@retval float
	*/
	public function percent_wise(){
		$this->book = $this->get_orders();
		return $this->percent_wise;
	}

	private function rename_key($oldkey, $newkey){
		foreach ($this->historial as &$h){
			$h[$newkey] = $h[$oldkey];
			unset($h[$oldkey]);
		}
	}

	public function normallize($key = 'close', $index = 'close'){
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::normallize($key, $index)");

		$nkey = 'normallized('.$key.','.$index.')';
		$t = end($this->historial);
		if (!array_key_exists($nkey, $t)){

			foreach ($this->historial as &$h){	// Last element is the most recent
				if (bccomp($h[$index], 0) > 0){
					$h[$nkey] = bcdiv($h[$key], $h[$index], EXCHANGE_ROUND_DECIMALS * 2);
				}
				else{
					$h[$nkey] = 0;
				}
			}
		}

		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS_OUTPUT))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::normallize($key, $index)");

		return $nkey;
	}

	public function ema($period = 2, $index = 'close'){
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::ema($period, $index)");

		$t = end($this->historial);
		$key = 'ema('.$period.','.$index.')';
		if (!array_key_exists($key, $t)){
			$i = 1;
			//K = 2 ÷(N + 1)
			$a = bcdiv(2, bcadd(1, $period));
			foreach ($this->historial as &$h){	// Last element is the most recent
				if ($i == 1){
					$h[$key] = number_format($h[$index], EXCHANGE_ROUND_DECIMALS, '.', '');
				}
				else{
					//EMA [today] = (Price [today] x K) + (EMA [yesterday] x (1 – K))
					$h[$key] = bcadd(bcmul($a,  $h[$index], EXCHANGE_ROUND_DECIMALS * 2), bcmul(bcsub(1, $a, EXCHANGE_ROUND_DECIMALS * 2), $p[$key], EXCHANGE_ROUND_DECIMALS * 2), EXCHANGE_ROUND_DECIMALS * 2);
				}
				$p = $h; $i++;
			}
		}
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS_OUTPUT))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::ema($period, $base_key, $index)");

		return $key;
	}

	/**
	@retval string
	Returns the Typical Price
	*/
	public function tp(){
		$key = 'tp()';
		$t = end($this->historial);

		if (!array_key_exists($key, $t)){
			foreach ($this->historial as &$h){	// Last element is the most rescent
				$t = bcadd($h['high'], $h['low']);
				$t = bcadd($t, $h['close']);
				$h[$key] = bcdiv($t, 3);
			}
		}
		return $key;
	}

	/**
	@retval array
	Returns the TR
	*/
	public function tr(){
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::tr()");

		$i = 1; $key = 'tr()';
		$t = end($this->historial);
		if (!array_key_exists($key, $t)){
			foreach ($this->historial as &$h){	// Last element is the most rescent
				if ($i == 1){
					$previous_close = $h['low'];
				}
				else{
					$previous_close = $p['close'];
				}
				$tr1 = bcsub($h['high'], $h['low']);	
				$tr2 = bcabs(bcsub($h['high'], $previous_close));
				$tr3 = bcabs(bcsub($previous_close, $h['low']));
				$p = $h; $i++;
				$h[$key] = bcmax($tr1, $tr2, $tr3);
			}
		}

		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS_OUTPUT))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::tr() = ".print_r($this->historial, true));

		return $key;
	}

	/**
	@retval array
	Returns the +DM and -DM
	*/
	public function dm(){
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::dm()");

		$mkey = '-dm()'; $pkey = '+dm()';
		$t = end($this->historial);

		if (!array_key_exists($mkey, $t) or !array_key_exists($pkey, $t)){
			$i = 1;
			foreach ($this->historial as &$h){	// Last element is the most rescent
				if ($i == 1){
					$h[$pkey] = $h['close'];
					$h[$mkey] = $h['close'];
				}
				else{
					$upmove = bcsub($h['high'], $p['high']);
					$downmove = bcsub($p['low'], $h['low']);
					if ((bccomp($upmove, $downmove) > 0) and (bccomp($upmove, 0) > 0)){
						$h[$pkey] = $upmove;
					}
					else{
						$h[$pkey] = 0;
					}

					if ((bccomp($downmove, $upmove) > 0) and (bccomp($downmove, 0) > 0)){
						$h[$mkey] = $downmove;
					}
					else{
						$h[$mkey] = 0;
					}
				}
				$p = $h; $i++;
			}
		}

		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS_OUTPUT))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::dm() = ".print_r($this->historial, true));

		$keys = array($mkey, $pkey);
		return $keys;
	}

	/**
	@retval float
	Returns the Gain & Average Gain
	*/
	private function gain($period = 14){
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "private function rate::gain()");

		$agkey = 'average_gain('.$period.')'; $alkey = 'average_loss('.$period.')';
		$t = end($this->historial);

		if (!array_key_exists($agkey, $t) or !array_key_exists($alkey, $t)){
			$i = 1; $k = 1; 
			foreach ($this->historial as &$h){	// Last element is most the recent

				if ($i < $period){
					$k = $i;
				}
				else{
					$k = $period;
				}
				if ($i == 1){			// First element needs default values
					$h[$alkey] = $h['close'];
					$h[$agkey] = 0;
					$h['loss'] = 0;
					$h['gain'] = 0;
				}
				else{
					$delta = bcsub($h['close'], $p['close']);
					if (bccomp($delta, 0) < 0){	// Loss
						$h['loss'] = bcabs(number_format($delta, EXCHANGE_ROUND_DECIMALS, '.', ''));
						$h['gain'] = 0;
					}
					else{
						$h['loss'] = 0;
						$h['gain'] = number_format($delta, EXCHANGE_ROUND_DECIMALS, '.', '');
					}
					// Could be smma
					$k1 = $k - 1;
					$h[$alkey] = bcdiv(bcadd(bcmul($p[$alkey], $k1, EXCHANGE_ROUND_DECIMALS * 2), $h['loss'], EXCHANGE_ROUND_DECIMALS * 2), $k, EXCHANGE_ROUND_DECIMALS * 2);
					$h[$agkey] = bcdiv(bcadd(bcmul($p[$agkey], $k1, EXCHANGE_ROUND_DECIMALS * 2), $h['gain'], EXCHANGE_ROUND_DECIMALS * 2), $k, EXCHANGE_ROUND_DECIMALS * 2);
				}
				$p = $h; $i++;
			}
		}
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS_OUTPUT))
			syslog(LOG_INFO|LOG_LOCAL1, "private function rate::gain() = ".print_r($this->historial, true));

		$keys = array('gain','loss',$alkey,$agkey);
		return $keys;
	}

	/**
	@param integer
	The number of period for the rsi
	@retval float
	Returns the RSI
	*/
	public function &rsi($period = 14){
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::rsi($period)");

		$key = 'rsi('.$period.')';
		$t = end($this->historial);

		if (!array_key_exists($key, $t)){
			$this->gain($period);
			$gain_key = $this->ema($period, 'gain'); // $gain_key = 'ema('.$period.',gain)';
			$loss_key = $this->ema($period, 'loss'); // $loss_key = 'ema('.$period.',loss)';

			foreach ($this->historial as &$h){	// Last element is most rescent
				if (bccomp($h[$loss_key], 0, EXCHANGE_ROUND_DECIMALS * 2) > 0){
					$rs = bcdiv($h[$gain_key], $h[$loss_key], EXCHANGE_ROUND_DECIMALS * 2);
					//RSI = (100 – (100 / (1 + RS)))
					$h[$key] = bcsub(100, bcdiv(100, bcadd(1, $rs, EXCHANGE_ROUND_DECIMALS * 2), EXCHANGE_ROUND_DECIMALS * 2), 2);
				}
				else{
					$h[$key] = 100;
				}
			}
		}

		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS_OUTPUT))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::rsi($period) = ".print_r($this->historial, true));

		return $key;
	}

	/**
	@param integer
	The number of the short period
	@param integer
	The number of the long period
	*/
	public function macd($short_period = 12, $long_period = 26, $signal_period = 9){
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::macd($short_period, $long_period, $signal_period)");

		$t = end($this->historial);
		$macdkey = 'macd('.$short_period.','.$long_period.','.$signal_period.')';
		$sigkey = 'signal('.$macdkey.')';
		if (!array_key_exists($macdkey, $t)){
			$skey = $this->ema($short_period, 'close'); // $skey = 'ema('.$short_period.',close)';
			$lkey = $this->ema($long_period, 'close'); // $lkey = 'ema('.$long_period.',close)';

			foreach ($this->historial as &$h){
				$h[$macdkey] = bcsub($h[$skey], $h[$lkey]);
			}
		}

		$emasigkey = $this->ema($signal_period, $macdkey); // $emasigkey = 'ema('.$signal_period.','.$macdkey.')';
		$this->rename_key($emasigkey, $sigkey);
		$dkey = 'delta('.$macdkey.','.$sigkey.')';

		if (!array_key_exists($dkey, $t)){
			foreach ($this->historial as &$h){
				$h[$dkey] = bcsub($h[$macdkey], $h[$sigkey]);
			}
		}
		$keys = array($macdkey, $sigkey, $dkey);
		return $keys;
	}

	/**
	@param integer
	The number of period for the atr
	@retval string
	Returns the ATR
	*/
	public function atr($period = 14){
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::atr($period)");

		$t = end($this->historial);
		$key = 'atr('.$period.')';

		if (!array_key_exists($key, $t)){
			$tr_key = $this->tr(); // TODO: Find a way to save this call if TR has been called already
			$okey = $this->ema($period,$tr_key); // $okey = 'ema('.$period.',tr)';

			// Rename ema(period,tr) new key into atr one
			$this->rename_key($okey, $key);
		}

		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS_OUTPUT))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::atr($period) = ".print_r($this->historial, true));
		return $key;
	}

	/**
	@param integer
	The number of period for the ADX
	@retval float
	Returns the ADX
	*/
	public function adx($period = 14){
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::adx($period)");

		$adx_key = 'adx('.$period.')';
		$dx_key = 'dx()';
		$pkey = '+di('.$period.')';
		$mkey = '-di('.$period.')';
		$t = end($this->historial);
		if (!array_key_exists($mkey, $t) or !array_key_exists($pkey, $t) or !array_key_exists($adx_key, $t) or !array_key_exists($dx_key, $t)){
			$tr_key = $this->tr(); // TODO: Find a way to save this call if TR has been called already
			list($minus_dm_key, $plus_dm_key) = $this->dm(); // TODO: Find a way to save this call if DM has been called already
			$sma_dmp_key = $this->sma($period, $plus_dm_key); // $sma_dmp_key = 'sma('.$period.',+dm)';
			$sma_dmm_key = $this->sma($period, $minus_dm_key); // $sma_dmm_key = 'sma('.$period.',-dm)';
			$sma_tr_key = $this->sma($period, $tr_key); // $sma_tr_key = 'sma('.$period.',tr)';
			$tpkey = "t$pkey"; $tmkey = "t$mkey";

			foreach ($this->historial as &$h){
				$h[$tpkey] = bcabs(bcdiv($h[$sma_dmp_key], $h[$sma_tr_key], EXCHANGE_ROUND_DECIMALS * 2));
				$h[$tmkey] = bcabs(bcdiv($h[$sma_dmm_key], $h[$sma_tr_key], EXCHANGE_ROUND_DECIMALS * 2));
				$h[$pkey] = bcmul(100, $h[$tpkey], EXCHANGE_ROUND_DECIMALS * 2);
				$h[$mkey] = bcmul(100, $h[$tmkey], EXCHANGE_ROUND_DECIMALS * 2);
				unset($h[$tpkey], $h[$tmkey]);
			}

			foreach ($this->historial as &$h){
				$sub = bcsub($h[$pkey], $h[$mkey]);
				$add = bcadd($h[$pkey], $h[$mkey]);
				if (bccomp($add, 0)){
					$h[$dx_key] = bcabs(bcdiv($sub, $add, EXCHANGE_ROUND_DECIMALS * 2));
				}
				else{
					$h[$dx_key] = 0;
				}
			}
	//		$this->exponential_key_average($period, $key, $tkey);
			$ttkey = $this->ema($period, $dx_key); // $ttkey = 'ema('.$period.','.$tkey.')';
			foreach ($this->historial as &$h){
				$h[$ttkey] = bcmul(100, $h[$ttkey]);
			}

			$this->rename_key($ttkey, $adx_key);
		}
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS_OUTPUT))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::adx($period) = ".print_r($this->historial, true));
		$keys = array($adx_key, $dx_key, $mkey, $pkey);
		return $keys;
	}

	/**
	@param integer
	The number of period for the Smoothing Moving Average
	@param string
	Key to use
    // https://mahifx.com/mfxtrade/indicators/smoothed-moving-average-smma
    // The first value for the Smoothed Moving Average is calculated as a Simple Moving Average (SMA):
    // 
    // SUM1=SUM (CLOSE, N)
    // 
    // SMMA1 = SUM1/ N
    // 
    // The second and subsequent moving averages are calculated according to this formula:
    // 
    // SMMA (i) = (SUM1 – SMMA1+CLOSE (i))/ N
    // 
    // Where:
    // 
    // SUM1 – is the total sum of closing prices for N periods;
    // SMMA1 – is the smoothed moving average of the first bar;
    // SMMA (i) – is the smoothed moving average of the current bar (except the first one);
    // CLOSE (i) – is the current closing price;
    // N – is the smoothing period.	
	*/
	public function smma($period = 20, $index = 'close'){
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::smma($period, $index)");
		$key = 'smma('.$period.','.$index.')';
		$t = end($this->historial);

		if (!array_key_exists($key, $t)){
			$sma_key = $this->sma($period, $index);
			$buffer = array(); $i = 0; 
			$k = $period - 1;
			foreach ($this->historial as &$h){	// Last element is the most rescent
	/*			array_push($buffer, $h[$index]);
				if (count($buffer) > $period){
					array_shift($buffer);
				}
				$sum = 0;
				foreach ($buffer as $b){
					$sum = bcadd($sum, $b, EXCHANGE_ROUND_DECIMALS * 2);
				}

				if ($i == 1){
					$h[$key] = $h['sma_'.$index.$period];
					$smma1 = $h[$key];
				}
				else{
					$a = bcsub($sum, $smma1);
					$b = bcadd($a, $h[$index]);
					$h[$key] = bcdiv($b, $period, EXCHANGE_ROUND_DECIMALS * 2);
				}
	*/
				if ($i == 1){
					$h[$key] = $h[$sma_key];
				}
				else{
					$h[$key] = bcdiv(bcadd(bcmul($p[$key], $k), $h[$index]), $period, EXCHANGE_ROUND_DECIMALS * 2);
				}
				$p = $h; $i++;
			}
		}

		return $key;
	}


	/**
	@param integer
	The number of period for the Simple Moving Average
	@param string
	Key to use
	*/
	public function sma($period = 20, $index = 'close'){
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::sma($period, $index)"); 

		$key = 'sma('.$period.','.$index.')';
		$t = end($this->historial);

		if (!array_key_exists($key, $t)){
			$buffer = array(); $i = 0;
			foreach ($this->historial as &$h){	// Last element is the most rescent
				array_push($buffer, $h[$index]);
				if (count($buffer) > $period){
					array_shift($buffer);
				}
				$sum = 0;
				foreach ($buffer as $b){
					$sum = bcadd($sum, $b, EXCHANGE_ROUND_DECIMALS * 2);
				}
				$h[$key] = bcdiv($sum, $period, EXCHANGE_ROUND_DECIMALS * 2);
			}
		}

		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS_OUTPUT))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::sma($period, $index) = ".print_r($this->historial, true));
		return $key;
	}

	/**
	@param integer
	The number of short period for the Awesome Oscillator
	@param integer
	The number of long period for the Awesome Oscillator
	*/
	public function ao($short = 5, $long = 34){
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::ao($short, $long)"); 

		$t = end($this->historial);
		$key = 'ao('.$short.','.$long.')';
		$midkey = 'average(high,low)';
		$key_color = 'ao_color('.$short.','.$long.')';
		if (!array_key_exists($key, $t) or !array_key_exists($midkey, $t) or !array_key_exists($key_color, $t)){
			foreach ($this->historial as &$h){	// Last element is the most rescent
				$h[$midkey] = bcdiv(bcadd($h['high'], $h['low']), 2, EXCHANGE_ROUND_DECIMALS * 2);
			}

			$sma_short_key = $this->sma($short, $midkey);
			$sma_long_key = $this->sma($long, $midkey);

			$i = 1;
			foreach ($this->historial as &$h){	// Last element is the most rescent
				$h[$key] = bcsub($h[$sma_short_key], $h[$sma_long_key]);
				if ($i == 1){
					$h[$key_color] = 'green';
				}
				else{
					if (bccomp($h[$key], $p[$key]) > -1){
						$h[$key_color] = 'green';
					}
					else{
						$h[$key_color] = 'red';
					}
				}
				$p = $h; $i++;
			}
		}

		$keys = array($key, $key_color, $midkey);
		return $keys;
	}

	/**
	@param integer
	The number of period for the Acellerator
	*/
	public function ac($period = 5){
	if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::ac($period)");

		$key = 'ac('.$period.')';
		$t = end($this->historial);

		if (!array_key_exists($key, $t)){
			list($key_ao, $key_ao_color, $midkey) = $this->ao($period, 34);
			$smakey = $this->sma($period, $key_ao);
			foreach ($this->historial as &$h){	// Last element is the most rescent
				$h[$key] = bcsub($h[$key_ao], $h[$smakey]);
			}
		}

		return $key;
	}

	/**
	@param integer
	The number of period for the Bollinger Band
	@param integer
	The number of standard deviation for the Bollinger Bad
	*/
	public function bb($period = 20, $stddev = 2){
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::bb($period, $stddev)");

		$t = end($this->historial);
		$keyhbb = 'bb_high('.$period.','.$stddev.')';
		$keylbb = 'bb_low('.$period.','.$stddev.')';
		$bb_qz = 'bb_bw('.$period.','.$stddev.')';
		$keystd = 'stddev('.$period.')';
		$sma_key = $this->sma($period, 'close');
		if (!array_key_exists($keyhbb, $t) or !array_key_exists($keylbb, $t) or !array_key_exists($bb_qz, $t) or !array_key_exists($keystd, $t)){
			$buffer = array(); $i = 0;
			foreach ($this->historial as &$h){	// Last element is the most rescent
				array_push($buffer, $h['close']);
				if (count($buffer) > $period){
					array_shift($buffer);
				}
				if (count($buffer) > 1){
					$std = stats_standard_deviation($buffer, true);
					$h[$keystd] = $std;
					$h[$keyhbb] = bcadd($h[$sma_key], bcmul($stddev, $std, EXCHANGE_ROUND_DECIMALS * 2));
					$h[$keylbb] = bcsub($h[$sma_key], bcmul($stddev, $std, EXCHANGE_ROUND_DECIMALS * 2));
					$h[$bb_qz] = bcdiv(bcsub($h[$keyhbb], $h[$keylbb], EXCHANGE_ROUND_DECIMALS * 2), $h[$sma_key], EXCHANGE_ROUND_DECIMALS * 2);
				}
			}
		}

		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS_OUTPUT))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::bb($period, $stddev) = ".print_r($this->historial, true));
		$keys = array($keylbb, $keyhbb, $bb_qz, $keystd, $sma_key);
		return $keys;
	}

	/**
	@param string
	The number of period for the Simple Moving Average
	@param integer
	Key to use
	*/
	public function greater_smaller($period = 20, $index = 'close', $compare = 'open'){
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::greater_smaller($period,$index,$compare)");

		$greater_key = 'greater('.$period.','.$index.','.$compare.')';
		$smaller_key = 'smaller('.$period.','.$index.','.$compare.')';
		$compare_key = 'compare('.$index.','.$compare.')';
		$t = end($this->historial);

		if (!array_key_exists($greater_key, $t) or !array_key_exists($smaller_key, $t) or !array_key_exists($compare_key, $t)){
			$buffer = array(); $i = 0;

			foreach ($this->historial as &$h){	// Last element is the most rescent
				$h[$compare_key] = bccomp($h[$index], $h[$compare]);
				array_push($buffer, $h[$compare_key]);

				if (count($buffer) > $period){
					array_shift($buffer);
				}

				$h[$smaller_key] = 0;
				$h[$greater_key] = 0;

				if (count($buffer) > 1){
					$array_count = array_count_values($buffer);
					if (array_key_exists('-1', $array_count)){
						$h[$smaller_key] += $array_count['-1'];
					}

					if (array_key_exists('0', $array_count)){
						$h[$greater_key] += $array_count['0'];
					}

					if (array_key_exists('1', $array_count)){
						$h[$greater_key] += $array_count['1'];
					}
				}
			}
		}

		$keys = array($compare_key, $smaller_key, $greater_key);
		return $keys;
	}

	/**
	@param integer
	The number of period for the Simple Moving Average
	@param string
	Key to use
	*/
	public function min_max($index = 'close', $decimals = EXCHANGE_ROUND_DECIMALS){
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::min_max($index)");

		$c = round(count($this->historial)/2, 0);
		$minkey = 'min('.$c.','.$index.')';
		$maxkey = 'max('.$c.','.$index.')';
		$stepsminkey = 'steps('.$minkey.')';
		$stepsmaxkey = 'steps('.$maxkey.')';
		$t = end($this->historial);
		if (!array_key_exists($minkey, $t) or !array_key_exists($maxkey, $t) or !array_key_exists($stepsminkey, $t) or !array_key_exists($stepsmaxkey, $t)){
			$i = 0;
			foreach ($this->historial as &$h){	// Last element is the most rescent
				if ($i < $c){
				}
				elseif ($i == $c){
					$h[$minkey] = number_format($h[$index], $decimals, '.', '');
					$h[$maxkey] = number_format($h[$index], $decimals, '.', '');
					$h[$stepsminkey] = 0;
					$h[$stepsmaxkey] = 0;
				}
				else{
					bcscale($decimals);
					$h[$minkey] = number_format(bcmin($h[$index], $p[$minkey]), $decimals, '.', '');
					$h[$maxkey] = number_format(bcmax($h[$index], $p[$maxkey]), $decimals, '.', '');
					bcscale(EXCHANGE_ROUND_DECIMALS);
					$h[$stepsmaxkey] = 0;
					$h[$stepsminkey] = 0;

					if (bccomp($h[$index], $h[$maxkey], $decimals) == -1){
						$h[$stepsmaxkey] = $p[$stepsmaxkey] + 1;
					}

					if (bccomp($h[$index], $h[$minkey], $decimals) == 1){
						$h[$stepsminkey] = $p[$stepsminkey] + 1;
					}

				}
				$i++; $p = $h;
			}
		}
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS_OUTPUT))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::min_max($index) = ".print_r($this->historial, true));

		$keys = array($minkey, $maxkey, $stepsmaxkey, $stepsmaxkey);
		return $keys;
	}

	/**
	@param float
	@retval boolean
	*/
	public function buy($rate, $amount){
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::buy($rate, $amount)");
		$result = $this->exchange->buy($this->pair, $rate, $amount);
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS_OUTPUT))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::buy($rate, $amount) = " .  print_r($result,true));
		return $result;
	}

	/**
	@param float
	@retval boolean
	*/
	public function sell($rate, $amount){
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::sell($rate, $amount)");
		$result = $this->exchange->sell($this->pair, $rate, $amount);
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS_OUTPUT))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::sell($rate, $amount) = " .  print_r($result,true));
		return $result;
	}

	/**
	@retval array
	*/
	public function &get_orders(){
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function &rate::get_orders()");
		$result = $this->exchange->get_orders($this->pair);
		if (is_array($this->book) && count($this->book)){
			$this->highest_bid = $this->book['bids'][0][0];
			$this->lowest_ask = $this->book['asks'][0][0];
			$this->spread = bcsub($this->lowest_ask, $this->highest_bid);
			$this->percent_wise = bcdiv($this->spread, $this->lowest_ask, EXCHANGE_ROUND_DECIMALS * 2);
		}
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS_OUTPUT))
			syslog(LOG_INFO|LOG_LOCAL1, "public function &rate::get_orders() = " . print_r($result,true));
		return $result;
	}

	/**
	@retval array
	*/
	public function &get_my_orders(){
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function &rate::get_my_orders()");
		$result = $this->exchange->get_my_orders($this->pair);
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS_OUTPUT))
			syslog(LOG_INFO|LOG_LOCAL1, "public function &rate::get_my_orders() = " . print_r($result,true));
		return $result;
	}

	/**
	@param int
	Order number
	@retval array
	*/
	public function cancel_order($order_number) {
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::cancel_order($order_number)");
		$result = $this->exchange->cancel_order($this->pair, $order_number);
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS_OUTPUT))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::cancel_order($order_number) = " . print_r($result,true));
		return $result;
	}

	/**
	@param string
	The pair
	@retval boolean
	true if it exists
	*/
	
	public function exists($pair){
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public static function rate::exists($pair)");
			
		$key = 'exchange['.$this->name.']:ticker';
		if (!is_object($this->exchange->memcached())){
			$_pairs = $this->exchange->api->get_ticker();
		}
		else{
			$_pairs = $this->exchange->memcached()->get($key);
			if ($this->exchange->memcached()->getResultCode() != Memcached::RES_SUCCESS){
				if (DEBUG)
					syslog(LOG_INFO|LOG_LOCAL1, "Ticker NOT found in Memcached: $key");
				$_pairs = $this->exchange->api->get_ticker();
				if (is_array($_pairs)){
					$this->exchange->memcached()->set($key, serialize($_pairs), MEMCACHED_TICKER_TTL);
					if ((DEBUG) && ($this->memcached->getResultCode() == Memcached::RES_NOTSTORED)){
						syslog(LOG_INFO|LOG_LOCAL1, "Ticker SET in Memcached: $key");
					}
				}
				else{
					throw new Exception('Exchangge class '.$this->name.' does not return a ticker.');
				}
			}
			else{
				if (DEBUG){
					syslog(LOG_INFO|LOG_LOCAL1, 'Ticker found in Memcached '.$this->exchange->memcached()->getResultCode());
				}
				$_pairs = unserialize($_pairs);
			}
		}
		
		// we have all the pairs, now we are looking for them
		$answer = array_key_exists($pair, $_pairs);	
		
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS_OUTPUT))
			syslog(LOG_INFO|LOG_LOCAL1, "public static function rate::exists($pair)");

		return $answer;
	}
}
