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

		$this->exchange = $exchange;
		$this->balance = $balance;
		$this->address = is_null($address)?'':$address;
		$this->currency = $currency;
		$this->min = 0;

		switch($this->exchange->name()){
			case 'poloniex':
				switch($this->currency){
					case 'ETC':
					case 'ETH':
						$this->min = 1;
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
}
