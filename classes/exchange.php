<?php
require_once "config.php";
include_once "wallet.php";
include_once "rate.php";
require_once "exchanges/poloniex.php";

/**
  *  Exchange class
*/


class Exchange {
	/**
	@var private $make_comission
	*/
	private $make_comission;
	private $take_comission;
	private $memcached;
	protected $wallets;
	protected $rates;
	protected $name;
	protected $api;
	protected $api_key;
	protected $api_secret;
	protected $trading_api_key;
	protected $trading_api_secret;
	protected $rules;
	protected $orders;
	protected $candlestick_period;
	protected $only_public;
	protected $config;

	/**
	@param $exchange_name
	The name of the exchange. A class with that name should exist in the exchanges/ directory
	@param $api_key
	API key from the exchange
	@param $api_secret
	Secret key from the exchange

	*/

	public function __construct($exchange_name, $exchange_config){
		if ((DEBUG & DEBUG_EXCHANGE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function exchange::__construct($exchange_name, ". print_r($exchange_config, true).")");

		if (class_exists('Memcached')){
			$this->memcached = new Memcached();
			$this->memcached->addServer(MEMCACHED_SERVER,MEMCACHED_PORT);
		}
		else{
			$this->memcached = null;
			syslog(LOG_INFO|LOG_LOCAL1, "Memcached class not found");
		}
		$this->config = $exchange_config;
		$this->name = $exchange_name;
		$this->wallets = array();
		$this->rates = array();
		$this->rules = array();
		$this->orders = array();
		$this->api_key = $exchange_config['exchanges'][$this->name]['api_key'];
		$this->api_secret = array_key_exists('secret_key', $exchange_config['exchanges'][$this->name])?$exchange_config['exchanges'][$this->name]['secret_key']:null;
		$this->candlestick_period = array_key_exists('candlestick_period', $exchange_config['exchanges'][$this->name])?$exchange_config['exchanges'][$this->name]['candlestick_period']:900;

		if (class_exists($exchange_name)){
			if (!array_key_exists('api_key', $exchange_config['exchanges'][$this->name]) or !array_key_exists('secret_key', $exchange_config['exchanges'][$this->name])){
				// If one of this is missing we assume public mode only
				$this->only_public = true;
				$this->api = new $exchange_name();
			}
			else{
				$this->only_public = false;
				if (!array_key_exists('trading_api_key', $exchange_config['exchanges'][$this->name]) or !array_key_exists('trading_secret_key', $exchange_config['exchanges'][$this->name]) or is_null($exchange_config['exchanges'][$this->name]['trading_api_key']) or is_null($exchange_config['exchanges'][$this->name]['trading_secret_key'])){
					// If tradit_*_key is missing, then we only use one key
					$this->trading_api_key = $this->api_key;
					$this->trading_api_secret = $this->api_secret;

					$this->api = new $exchange_name($this->api_key, $this->api_secret);
	            }
	            else{
					$this->trading_api_key = $exchange_config['exchanges'][$this->name]['trading_api_key'];
					$this->trading_api_secret = $exchange_config['exchanges'][$this->name]['trading_api_secret'];

					$this->api = new $exchange_name($this->api_key, $this->api_secret, $this->trading_api_key, $this->trading_secret_key);
				}
			}

			switch ($exchange_name) {
				case "poloniex":
					// We work on worst case scenario possible
					$this->make_comission = 0.0015;
					$this->take_comission = 0.0025;
					break;
			}

			if (!$this->only_public){
				$this->get_wallets();
			}

			if (isset($exchange_config['exchanges'][$exchange_name]['pair'])){
				$this->get_rates($exchange_config['exchanges'][$exchange_name]['pair']);
			}
			else{
				$this->get_rates();
			}
		}
		else{
			$this->make_comission = (double) 0.0;
			$this->take_comission = (double) 0.0;
			$this->wallets = array();
			$this->name = '';
			$this->api = null;
			throw new Exception('Exchangge class '.$exchange_name.' does not exist.');
		}
	}

	/**
	@retval array
	An array of currencies from-to.
	*/
	public function get_config(){
		return $this->config;
	}

	/**
	@param string pair
	string pair from exchange

	@retval array
	An array of currencies from-to.
	*/
	public function &get_pairs($pair_string){
		switch($this->name){
			case "poloniex":
					$split = '_';
				break;
			default:
				$split = '-';
		}

		$pair_array = explode($split, $pair_string, 2);
		return $pair_array;
	}

	/**
	@param string from
	@param string to

	@retval string
	Pair strilg
	*/

	public function build_pair_string($from, $to){
		switch($this->name){
			case "poloniex":
					$split = '_';
				break;
			default:
				$split = '-';
		}

		return $from.$split.$to;
	}

	/**
	@retval object
	An array of Rates object of all the rating information available in the exchange
	*/
	public function &get_wallet($currency){
		if ((DEBUG & DEBUG_EXCHANGE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function &exchange::get_wallet($currency)");

		$_wallet = null;
		foreach ($this->wallets as $w){
				if ($w->currency() == $currency){
					$_wallet = $w;
					break;
				}
		}

		return $_wallet;
	}

	/**
	@retval array
	An array of Wallet object of all the wallets available in that exchange.
	*/
	private function &get_wallets(){
		if ((DEBUG & DEBUG_EXCHANGE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "private function &exchange::get_wallets()");

		if ($this->only_public){
			throw new Exception('This exchange is in public mode.');
			return array();
		}
		else {
			$_wallets = $this->api->get_balances();
			$_addresses = $this->api->get_addresses();
			$this->wallets = array();

			foreach ($_wallets as $c => $v){
				$this->wallets[$c] = new Wallet($this, $c, $v, array_key_exists($c, $_addresses)?$_addresses[$c]:null);
			}
		return $this->wallets;
		}
	}

	/**
	@retval array
	An array of Rates object of all the rating information available in the exchange
	*/
	public function &get_rates($pair = null){
		if ((DEBUG & DEBUG_EXCHANGE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function &exchange::get_rates($pair)");

		unset($this->rates);
		$this->rates = array();

		$key = "exchange[".$this->name."]:ticker";
		if (!is_object($this->memcached)){
			$_pairs = $this->api->get_ticker();
		}
		else{
			if (!($_pairs = $this->memcached->get($key))){
				syslog(LOG_INFO|LOG_LOCAL1, "Ticker NOT found in Memcached: $key");
				$_pairs = $this->api->get_ticker();
				if (count($_pairs)){
					if ($this->memcached->set($key, serialize($_pairs), MEMCACHED_TICKER_TTL))
						syslog(LOG_INFO|LOG_LOCAL1, "Ticker SET in Memcached: $key");
				}
				else{
					throw new Exception('Exchangge class '.$exchange_name.' does not return a ticker.');
				}
			}
			else{
				syslog(LOG_INFO|LOG_LOCAL1, "Ticker found in Memcached");
				$_pairs = unserialize($_pairs);
			}
		}

		$_max_base_volume_market_share = array();

		foreach ($_pairs as $p => $d){
			if ((is_null($pair)) || (!is_null($pair) && $pair == $p)){
				$last = $d['last'];
				$lowest_ask = $d['lowestAsk'];
				$highest_bid = $d['highestBid'];
				$percent_change = $d['percentChange'];
				$base_volume = $d['baseVolume'];
				$quote_volume = $d['quoteVolume'];
				$is_frozen = $d['isFrozen'];
				$high_24hr = $d['high24hr'];
				$low_24hr = $d['low24hr'];
				list($from, $to) = $this->get_pairs($p);
				if (!array_key_exists($p,$_max_base_volume_market_share) || ($_max_base_volume_market_share[$p] < $base_volume)){
					$_max_base_volume_market_share[$p] = $base_volume;
				}
				$this->rates[$p] = new Rate($this, $from, $to, $last, $lowest_ask, $highest_bid, $percent_change, $base_volume, $quote_volume, $is_frozen, $high_24hr, $low_24hr);
			}
		}

		foreach ($this->rates as $r){
			$k = $r->from().'_'.$r->to();
			$r->set_market_share($_max_base_volume_market_share[$k]);
		}

		return $this->rates;
	}

	/**
	@retval object
	An array of Rates object of all the rating information available in the exchange
	*/
	public function &get_rate($pair){
		if ((DEBUG & DEBUG_EXCHANGE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function &exchange::get_rate($pair)");
		$this->get_rates($pair);
		$_rate = null;
		foreach ($this->rates as $r){
			if ($r->pair() == $pair){
				$_rate = $r;
				break;
			}
		}

		return $_rate;
	}

	/**
	@retval array
	An array of Wallet object of all the wallets available in that exchange.
	*/
	public function get_orders($pair='all'){
		if ((DEBUG & DEBUG_EXCHANGE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function exchange::get_orders($pair)");

		$key = "exchange[".$this->name."]:orders[$pair]";
		if (!is_object($this->memcached)){
			$_orders = $this->api()->get_order_book($pair);
		}
		else{
			if (!($_orders = $this->memcached->get($key))){
				syslog(LOG_INFO|LOG_LOCAL1, "Orders NOT found in Memcached: $key");
				$_orders = $this->api()->get_order_book($pair);
				if (count($_orders)){
					if ($this->memcached->set($key, serialize($_orders), MEMCACHED_ORDERS_TTL))
						syslog(LOG_INFO|LOG_LOCAL1, "Orders SET in Memcached: $key");
				}
				else{
					throw new Exception('Exchangge class '.$exchange_name.' does not return orders.');
				}
			}
			else{
				syslog(LOG_INFO|LOG_LOCAL1, "Orders found in Memcached");
				$_orders = unserialize($_orders);
			}

		}

		$this->orders = $_orders;
		return ($this->orders);
	}

	/**
	@retval array
	An array of Wallet object of all the wallets available in that exchange.
	*/
	public function get_my_orders($pair='all'){
		if ((DEBUG & DEBUG_EXCHANGE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function exchange::get_my_orders($pair)");

		if ($this->only_public){
			throw new Exception('This exchange is in public mode.');
			return array();
		}

		$key = "exchange[".$this->name."]:myorders[$pair]";
		if (!is_object($this->memcached)){
			$_orders = $this->api()->get_open_orders($pair);
		}
		else{
			if (!($_orders = $this->memcached->get($key))){
				syslog(LOG_INFO|LOG_LOCAL1, "My orders NOT found in Memcached: $key");
				$_orders = $this->api()->get_open_orders($pair);
				if (count($_orders)){
					if ($this->memcached->set($key, serialize($_orders), MEMCACHED_ORDERS_TTL))
						syslog(LOG_INFO|LOG_LOCAL1, "My orders SET in Memcached: $key");
				}
				else{
					throw new Exception('Exchangge class '.$exchange_name.' does not return orders.');
				}
			}
			else{
				syslog(LOG_INFO|LOG_LOCAL1, "My orders found in Memcached");
				$_orders = unserialize($_orders);
			}

		}

		return ($this->orders = $_orders);
	}

	/**
	@retval string
	The name of the exchange
	*/
	public function name(){
		return $this->name;
	}

	/**
	@retval array
	The native object of the exchange
	*/
	public function &api(){
		return $this->api;
	}

	/**
	@retval array
	All the rates
	*/
	public function &rates(){
		return $this->rates;
	}

	/**
	@retval array
	All the wallets
	*/
	public function &wallets(){
		if ($this->only_public){
			throw new Exception('This exchange is in public mode.');
			return array();
		}
		else{
			return $this->wallets;
		}
	}

	/**
	@param string $currency
	the ISO code of the currency that is looked for
	@retval object Wallet
	The wallet object corresponding to that currency
	*/
	public function &wallet($currency){
		if ($this->only_public){
			throw new Exception('This exchange is in public mode.');
			return null;
		}
		else{
			$result = null;
			foreach ($this->wallets as &$w){
				if ($w->currency() == $currency){
					$result = &$w;
					break;
				}
			}

			return $result;
		}
	}

	/**
	@retval string
	Comission
	*/
	public function &make_comission(){
		return $this->make_comission;
	}

	/**
	@retval string
	Comission
	*/
	public function &take_comission(){
		return $this->take_comission;
	}

	/**
	@retval string
	API key
	*/
	public function &api_key(){
		return $this->api_key;
	}

	/**
	@retval string
	Secret key
	*/
	public function &api_secret(){
		return $this->api_secret;
	}

	/**
	@retval boolean
	isPublic?
	*/
	public function is_public(){
		return $this->only_public;
	}

	/**
	@retval array
	*/
	public function get_historic_data($pair, $start, $end, $period){
		if ((DEBUG & DEBUG_EXCHANGE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function exchange::get_historic_data($pair, $start, $end, $period)");
		$_h = $this->api->get_chart_data($pair,$start,$end,$period);
		if (is_array($_h) and count($_h)){
			foreach ($_h as &$h){
				$h['high'] = number_format($h['high'], EXCHANGE_ROUND_DECIMALS, ".", "");
				$h['low'] = number_format($h['low'], EXCHANGE_ROUND_DECIMALS, ".", "");
				$h['open'] = number_format($h['open'], EXCHANGE_ROUND_DECIMALS, ".", "");
				$h['close'] = number_format($h['close'], EXCHANGE_ROUND_DECIMALS, ".", "");
				$h['volume'] = number_format($h['volume'], EXCHANGE_ROUND_DECIMALS, ".", "");
				$h['quoteVolume'] = number_format($h['quoteVolume'], EXCHANGE_ROUND_DECIMALS, ".", "");
				$h['weightedAverage'] = number_format($h['weightedAverage'], EXCHANGE_ROUND_DECIMALS, ".", "");
			}
		}

		if ((DEBUG & DEBUG_EXCHANGE) && (DEBUG & DEBUG_TRACE_FUNCTIONS_OUTPUT))
			syslog(LOG_INFO|LOG_LOCAL1, "public function exchange::get_historic_data($pair, $start, $end, $period) = ".print_r($_h,true));
		return $_h;
	}

	/**
	@param string
	@param integer
	@param integer
	@retval array
	*/
	public function get_my_trade_history($pair='all', $end = null, $start = null){
		if ((DEBUG & DEBUG_EXCHANGE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function exchange::get_my_trade_history($pair, $end, $start)");
		return $this->api->get_my_trade_history($pair, $start, $end);
	}

	/**
	@param string
	Pair to buy
	@param float
	Rate to buy
	@param float
	Amount to buy
	@retval array
	*/
	public function buy($pair, $rate, $amount){
		if ((DEBUG & DEBUG_EXCHANGE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function exchange::buy($pair, $rate, $amount)");
		return $this->api->buy($pair, $rate, $amount);
	}

	/**
	@param string
	Pair to sell
	@param float
	Rate to sell
	@param float
	Amount to sell
	@retval array
	*/
	public function sell($pair, $rate, $amount){
		if ((DEBUG & DEBUG_EXCHANGE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function exchange::sell($pair, $rate, $amount)");
		return $this->api->sell($pair, $rate, $amount);
	}

	/**
	@param string
	Pair to affect
	@param int
	Order number
	@retval array
	*/
	public function cancel_order($pair, $order_number) {
		if ((DEBUG & DEBUG_EXCHANGE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "public function exchange::cancel_order($pair, $order_number)");
		return $this->api->cancel_order($pair, $order_number);
	}
}
