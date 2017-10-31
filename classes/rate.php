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

	function __construct(&$exchange, $from, $to, $last, $lowest_ask, $highest_bid, $percent_change, $base_volume, $quote_volume, $is_frozen, $high_24hr, $low_24hr){
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "function rate::__construct(\$exchange, $from, $to, $last, $lowest_ask, $highest_bid, $percent_change, $base_volume, $quote_volume, $is_frozen, $high_24hr, $low_24hr)");
		$this->exchange = $exchange;
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
	}

	public function get_historic_data($start,$end){
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function &rate::get_historic_data($start,$end)");
		$answer = $this->exchange->get_historic_data($this->pair,$start,$end,$this->period);
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS_OUTPUT))
			syslog(LOG_INFO|LOG_LOCAL1, "public function &rate::get_historic_data($start,$end) = ".print_r($answer, true));
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
			$start = $end - 604800;
		}
		else{
		      if ($start >= $end){
				$start = $end - 604800;
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
			$max = 0; $min = 999999999; $nReg=count($this->historial)-1;
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
					$start = $end - 604800;
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
			$this->base_volume_market_share = bcdiv($this->base_volume, $max);
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
		return $this->book;
	}

	private function exponential_key_average($period, $base_key, $index){
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "private function rate::exponential_key_average($period, $base_key, $index)");

		$i = 1; $key = "$base_key$period"; $nkey = "normallized_$key";
		foreach ($this->historial as &$h){	// Last element is most recent
			if ($i == 1){
				$h[$key] = $h[$index]; // number_format($h[$index], EXCHANGE_ROUND_DECIMALS, ".", "");
				$h[$nkey] = 1;
			}
			else{
				$a = bcdiv(2, bcadd(1, $period), EXCHANGE_ROUND_DECIMALS * 2);
				$h[$key] = bcadd(bcmul($a,  $h[$index], EXCHANGE_ROUND_DECIMALS * 2), bcmul(bcsub(1, $a, EXCHANGE_ROUND_DECIMALS * 2), $previews[$key], EXCHANGE_ROUND_DECIMALS * 1));
				if (bccomp($h[$index], 0) > 0){
					$h[$nkey] = bcdiv($h[$key], $h[$index]);
				}
				else{
					$h[$nkey] = 1;
				}
			}
			$previews = $h; $i++;
		}

		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS_OUTPUT))
			syslog(LOG_INFO|LOG_LOCAL1, "private function rate::exponential_key_average($period, $base_key, $index)");
	}

	/**
	@param integer
	The number of period for the ema
	@retval float
	Returns the EMA
	*/
	public function ema($period){
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::ema($period)");

		$this->exponential_key_average($period, "ema", "close");

		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS_OUTPUT))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::ema($period) = ".print_r($this->historial, true));
		return $this->historial;
	}

	/**
	@retval float
	Returns the TR
	*/
	public function tr(){
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::tr()");

		$i = 1; $key = "tr";
		foreach ($this->historial as &$h){	// Last element is most rescent
			if ($i == 1){
				$previous_close = $h["low"];
			}
			else{
				$previous_close = $previous["close"];
			}
			$tr1 = bcsub($h["high"], $h["low"]);
			$tr2 = bcsub($h["high"], $previous_close);
			$tr3 = bcsub($previous_close, $h["low"]);
			$previous = $h; $i++;
			$h[$key] = bcmax($tr1, $tr2, $tr3);
		}

		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS_OUTPUT))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::tr() = ".print_r($this->historial, true));
		return $this->historial;
	}

	/**
	@retval float
	Returns the Gain & Average Gain
	*/
	private function gain($period = 14){
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "private function rate::gain()");

		$i = 1; $agkey = "average_gain$period"; $alkey = "average_loss$period";
		foreach ($this->historial as &$h){	// Last element is most rescent

			if ($i < $period){
				$k = $i;
			}
			else{
				$k = $period;
			}
			if ($i == 1){			// First element needs default values
				$h[$alkey] = 0;
				$h[$agkey] = $h["close"];
				$h["loss"] = 0;
				$h["gain"] = $h["close"];
			}
			else{
				$delta = bcsub($h["close"], $p["close"]);
				if (bccomp($delta, 0) < 0){	// Loss
					$h["loss"] = bcabs($delta);
					$h["gain"] = 0;
				}
				else{
					$h["loss"] = 0;
					$h["gain"] = $delta;
				}
				$k1 = $k - 1;
				$h[$alkey] = bcdiv(bcadd(bcmul($p[$alkey], $k1, EXCHANGE_ROUND_DECIMALS * 2), $h["loss"], EXCHANGE_ROUND_DECIMALS * 2), $k, EXCHANGE_ROUND_DECIMALS * 2);
				$h[$agkey] = bcdiv(bcadd(bcmul($p[$agkey], $k1, EXCHANGE_ROUND_DECIMALS * 2), $h["gain"], EXCHANGE_ROUND_DECIMALS * 2), $k, EXCHANGE_ROUND_DECIMALS * 2);
			}
			$p = $h; $i++;
		}

		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS_OUTPUT))
			syslog(LOG_INFO|LOG_LOCAL1, "private function rate::gain() = ".print_r($this->historial, true));
		return $this->historial;
	}

	/**
	@param integer
	The number of period for the rsi
	@retval float
	Returns the RSI
	*/
	public function rsi($period = 14){
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::rsi($period)");

		$this->gain($period);
		//$this->exponential_key_average($period, "average_gain" , "gain");
		//$this->exponential_key_average($period, "average_loss" , "loss");

		$key="rsi$period";
		foreach ($this->historial as &$h){	// Last element is most rescent
			if ($h["average_loss$period"] > 0){
				$rs = bcdiv($h["average_gain$period"], $h["average_loss$period"], EXCHANGE_ROUND_DECIMALS * 2);
				$h[$key] = bcsub(100, bcdiv(100, bcadd(1, $rs )));
			}
			else{
				$h[$key] = 100;
			}
		}

		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS_OUTPUT))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::rsi($period) = ".print_r($this->historial, true));
		return $this->historial;
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

		$this->ema($short_period);
		$this->ema($long_period);
		$macdkey = "macd".$short_period."_".$long_period;
		$skey = "ema$short_period";
		$lkey = "ema$long_period";
		$sigkey = $macdkey."_signal";
		$dkey = $sigkey.$signal_period.'_delta';
		foreach ($this->historial as &$h){
			$h[$macdkey] = bcsub($h[$skey], $h[$lkey]);
		}

		$this->exponential_key_average($signal_period, $sigkey, $macdkey);

		foreach ($this->historial as &$h){
			$h[$dkey] = bcsub($h[$macdkey], $h[$sigkey.$signal_period]);
		}
	}

	/**
	@param integer
	The number of period for the atr
	@retval float
	Returns the ATR
	*/
	public function atr($period = 14){
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::atr($period)");

		$this->tr(); // TODO: Find a way to save this call if TR has been called already
		$this->exponential_key_average($period, "atr", "tr");

		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS_OUTPUT))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::atr($period) = ".print_r($this->historial, true));
		return $this->historial;
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

		$buffer = array(); $i = 0; $key = 'sma_'.$index.$period;
		foreach ($this->historial as &$h){	// Last element is most rescent
			array_push($buffer, $h[$index]);
			if (count($buffer) > $period){
				array_shift($buffer);
			}
			$sum = 0;
			foreach ($buffer as $b){
				$sum = bcadd($sum, $b, EXCHANGE_ROUND_DECIMALS * 2);
			}
			$h[$key] = bcdiv($sum, count($buffer), EXCHANGE_ROUND_DECIMALS * 2);
		}

		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS_OUTPUT))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::sma($period, $index) = ".print_r($this->historial, true));
		return $this->historial;
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

		$this->sma($period, 'close');
		$buffer = array(); $i = 0; $keyhbb = 'bb_high'.$period.'_'.$stddev; $keylbb = 'bb_low'.$period.'_'.$stddev;
		$bb_qz = 'bb_bw'.$period.'_'.$stddev; $keystd = 'stddev'.$period;
		foreach ($this->historial as &$h){	// Last element is most rescent
				array_push($buffer, $h['close']);
				if (count($buffer) > $period){
					array_shift($buffer);
				}
				if (count($buffer) > 1){
					$std = stats_standard_deviation($buffer, true);
					$h[$keystd] = $std;
					$h[$keyhbb] = bcadd($h["sma_close".$period], bcmul($stddev, $std, EXCHANGE_ROUND_DECIMALS * 2));
					$h[$keylbb] = bcsub($h["sma_close".$period], bcmul($stddev, $std, EXCHANGE_ROUND_DECIMALS * 2));
					$h[$bb_qz] = bcdiv(bcsub($h[$keyhbb], $h[$keylbb], EXCHANGE_ROUND_DECIMALS * 2), $h["sma_close".$period]);
				}
		}

		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS_OUTPUT))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::bb($period, $stddev) = ".print_r($this->historial, true));
		return $this->historial;
	}

	/**
	@param integer
	The number of period for the Simple Moving Average
	@param string
	Key to use
	*/
	public function min_max($index = 'close'){
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::min_max($index)");

		$i = 0;
		$minkey = "min_".$index;
		$maxkey = "max_".$index;
		$stepsminkey = 'steps_'.$minkey;
		$stepsmaxkey = 'steps_'.$maxkey;
		foreach ($this->historial as &$h){	// Last element is most rescent
			if ($i == 0){
				$h[$minkey] = $h[$index];
				$h[$maxkey] = $h[$index];
				$h[$stepsminkey] = 0;
				$h[$stepsmaxkey] = 0;
			}
			else{
				$h[$minkey] = bcmin($h[$index], $_last[$minkey]);
				$h[$maxkey] = bcmax($h[$index], $_last[$maxkey]);

				if (bccomp($h[$index], $h[$maxkey]) == -1){
					$h[$stepsmaxkey] = $_last[$stepsmaxkey] + 1;
				}
				else{
					$h[$stepsmaxkey] = 0;
				}

				if (bccomp($h[$index], $h[$minkey]) == 1){
					$h[$stepsminkey] = $_last[$stepsminkey] + 1;
				}
				else{
					$h[$stepsminkey] = 0;
				}

			}
			$i++; $_last = $h;
		}
		if ((DEBUG & DEBUG_RATE) && (DEBUG & DEBUG_TRACE_FUNCTIONS_OUTPUT))
			syslog(LOG_INFO|LOG_LOCAL1, "public function rate::min_max($index) = ".print_r($this->historial, true));
		return $this->historial;
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
}
