<?php
/** 
  *  Wallet class
*/

class Wallet{
	private $balance;
	private $address;
	private $currency;
	private $exchange;
	private $min;

	function __construct(&$exchange, $currency='BTC', $balance = 0.0, $address=''){
		if ((DEBUG & DEBUG_WALLET) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "function wallet::__construct(\$exchange, $currency, $balance, $address)");

		$this->exchange = &$exchange;
		$this->balance = $balance;
		$this->address = is_null($address)?'':$address;
		$this->currency = $currency;
		$this->min = 0;

		switch($this->exchange->name()){
			case 'poloniex':
				switch($this->currency){
					case 'ETC':
						$this->min = 1;
					case 'ETH':
						$this->min = 0.5;
				}
		}
	}

	/**
	@retval string
	ISO code of current currency
	*/
	public function &currency(){
		return $this->currency;
	}

	/**
	@retval number
	Balance
	*/
	public function &balance(){
		return $this->balance;
	}

	/**
	@retval string
	The address of this wallet
	*/
	public function &address(){
		return $this->address;
	}

	/**
	@retval number
	Minimum deposit allowed by this wallet
	*/
	public function &min(){
		return $this->min;
	}
	
	/**
	@param string
	The target currency
	@retval float
	The value
	*/
	
	public function convert($target_currency = 'BTC'){
		if ((DEBUG & DEBUG_WALLET) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
			syslog(LOG_INFO|LOG_LOCAL1, "function wallet::convert($target_currency)");

		$value = $this->balance;

		if ((strcasecmp($target_currency, $this->currency)) and (bccomp($value, 0) > 0)){
			// Different currency
			$_base_market_currencies = $this->exchange->base_market_currencies();
			if ((array_key_exists($this->currency, $_base_market_currencies)) and (array_key_exists($target_currency, $_base_market_currencies))){
				// Both are base market, need to decide what to do
				if ($_base_market_currencies[$this->currency] < $_base_market_currencies[$target_currency]){
					$_pair = $this->exchange->build_pair_string($target_currency, $this->currency);
					if (is_object($_r = $this->exchange->get_rate($_pair))){
						$hb = $_r->highest_bid();
						$value = bcmul($this->balance, $hb, EXCHANGE_ROUND_DECIMALS * 2);
					}
					else
						$value = false;
				}
				elseif ($_base_market_currencies[$this->currency] == $_base_market_currencies[$target_currency]){
					$_pair1 = $this->exchange->build_pair_string($this->currency, 'BTC');
					$_r1 = $this->exchange->get_rate($_pair1);
					$_pair2 = $this->exchange->build_pair_string('BTC', $target_currency);
					$_r2 = $this->exchange->get_rate($_pair2);
					if ((is_object($_r1)) and (is_object($_r2))){
						$hb1 = $_r1->highest_bid();
						$_value1 = bcmul($this->balance, $hb1, EXCHANGE_ROUND_DECIMALS * 2);
						$hb2 = $_r2->highest_bid();
						$value = bcmul($_value1, $hb2, EXCHANGE_ROUND_DECIMALS * 2);
					}
					else
						$value = false;
				}
				else{
					$_pair = $this->exchange->build_pair_string($this->currency, $target_currency);
					if (is_object($_r = $this->exchange->get_rate($_pair))){
						$la = $_r->lowest_ask();
						$value = bcdiv($this->balance, $la, EXCHANGE_ROUND_DECIMALS * 2);
					}
					else
						$value = false;
				}
			}
			elseif (array_key_exists($this->currency, $this->exchange->base_market_currencies())){
				// Buy
				$_pair = $this->exchange->build_pair_string($this->currency, $target_currency);
				if (is_object($_r = $this->exchange->get_rate($_pair))){
					$la = $_r->lowest_ask();
					$value = bcdiv($this->balance, $la, EXCHANGE_ROUND_DECIMALS * 2);
				}
				else
					$value = false;
			}
			elseif (array_key_exists($target_currency, $this->exchange->base_market_currencies())){
				// Sell
				$_pair = $this->exchange->build_pair_string($target_currency, $this->currency);
				if (is_object($_r = $this->exchange->get_rate($_pair))){
					$hb = $_r->highest_bid();
					$value = bcmul($this->balance, $hb, EXCHANGE_ROUND_DECIMALS * 2);
				}
				else
					$value = false;
			}
			else{
				// TODO: Indirect conversion, think later how to do it
				// Make XXX_BTC, BTC_YYY
				$_pair1 = $this->exchange->build_pair_string($this->currency, 'BTC');
				$_pair2 = $this->exchange->build_pair_string('BTC', $target_currency);
				$_r1 = $this->exchange->get_rate($_pair1);
				$_r2 = $this->exchange->get_rate($_pair2);
				if ((is_object($_r1)) and (is_object($_r2))){
					$hb1 = $_r1->highest_bid();
					$_value1 = bcmul($this->balance, $hb1, EXCHANGE_ROUND_DECIMALS * 2);
					$hb2 = $_r2->highest_bid();
					$value = bcmul($_value1, $hb2, EXCHANGE_ROUND_DECIMALS * 2);
				}
				else{
					$value = false;
				}
			}
		}
		
		if ((DEBUG & DEBUG_WALLET) && (DEBUG & DEBUG_TRACE_FUNCTIONS_OUTPUT))
			syslog(LOG_INFO|LOG_LOCAL1, "function wallet::convert($target_currency) = $value");

		return $value;
	}
}
