<?php
	// FINAL TESTED CODE - Created by Compcentral

	// NOTE: currency pairs are reverse of what most exchanges use...
	//       For instance, instead of XPM_BTC, use BTC_XPM

	class poloniex {
		protected $api_key;
		protected $api_secret;
		protected $trading_api_key;
		protected $trading_api_secret;
		protected $trading_url = "https://poloniex.com/tradingApi";
		protected $public_url = "https://poloniex.com/public";

		public function __construct(){
			$a = func_get_args();
			$i = func_num_args();
			if (method_exists($this,$f='__construct'.$i)) {
				call_user_func_array(array($this,$f),$a);
			}
			else{
				throw new Exception('Incorrect number of parameters, 0, 2 or 4 only.');
			}
		}

		public function __construct0() {
			$this->api_key = null;
			$this->api_secret = null;
			$this->trading_api_key = null;
			$this->trading_api_secret = null;
		}

		public function __construct2($api_key, $api_secret) {
			$this->api_key = $api_key;
			$this->api_secret = $api_secret;
			$this->trading_api_key = $api_key;
			$this->trading_api_secret = $api_secret;
		}

		public function __construct4($api_key, $api_secret, $trading_api_key, $trading_api_secret) {
			$this->api_key = $api_key;
			$this->api_secret = $api_secret;
			$this->trading_api_key = $trading_api_key;
			$this->trading_api_secret = $trading_api_secret;
		}

		private function &getCh(){
			static $ch;
			return  $ch;
		}

		private function query(array $req = array()) {
			if ((DEBUG & DEBUG_EXCHANGE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
				syslog(LOG_INFO|LOG_LOCAL1, "private function poloniex::query(".print_r($req, true).")");
			// API settings
			$key = $this->api_key;
			$secret = $this->api_secret;

			// generate a nonce to avoid problems with 32bit systems
			$mt = explode(' ', microtime());
			$req['nonce'] = $mt[1].substr($mt[0], 2, 6);

			// generate the POST data string
			$post_data = http_build_query($req, '', '&');
			$sign = hash_hmac('sha512', $post_data, $secret);

			// generate the extra headers
			$headers = array(
				'Key: '.$key,
				'Sign: '.$sign,
			);

			// curl handle (initialize if required)
			$ch = & $this->getCh();
			if (is_null($ch)) {
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_USERAGENT,
					'Mozilla/4.0 (compatible; Poloniex PHP bot; '.php_uname('a').'; PHP/'.phpversion().')'
				);
			}
			curl_setopt($ch, CURLOPT_URL, $this->trading_url);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
			curl_setopt($ch, CURLOPT_TIMEOUT, 20000);
			curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);

			usleep(200);
			// run the query
			$res = curl_exec($ch);

			if ($res === false) throw new Exception('Curl error: '.curl_error($ch));
			//echo $res;
			$dec = json_decode($res, true, 512, JSON_BIGINT_AS_STRING);
			if (!$dec){
				//throw new Exception('Invalid data: '.$res);
				return false;
			}else{
				return $dec;
			}
		}

		protected function retrieveJSON($URL) {
			// TODO: Use cURL
			$ch = & $this->getCh();
			if (is_null($ch)) {
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_HEADER, false);
				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
				curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
				curl_setopt($ch, CURLOPT_ENCODING, '');
				curl_setopt($ch, CURLOPT_USERAGENT,
					'Mozilla/4.0 (compatible; Poloniex PHP bot; '.php_uname('a').'; PHP/'.phpversion().')'
				);
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
				curl_setopt($ch, CURLOPT_AUTOREFERER, true);
				curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
				curl_setopt($ch, CURLOPT_TIMEOUT, 20000);
			}
			curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
			curl_setopt($ch, CURLOPT_URL, $URL);
			usleep(200);
			$feed = curl_exec($ch);

			if ($feed === false) throw new Exception('Curl error: '.curl_error($ch));
/*
			$opts = array('http' =>
						array(
							'method'  => 'GET',
							'timeout' => 20
						),
					'ssl' =>
						array(
							'verify_peer' => false,
							'verify_peer_name' => false,
							'ciphers' => 'HIGH:TLSv1.2:TLSv1.1:TLSv1.0:!SSLv3:!SSLv2'
    						)
			);
			$context = stream_context_create($opts);
			$feed = file_get_contents($URL, false, $context);
*/
//			syslog(LOG_INFO|LOG_LOCAL1, 'private function poloniex::retrieveJSON() URL: '. $URL . '=' . $feed);
			$json = json_decode($feed, true, 1024, JSON_BIGINT_AS_STRING);
//			syslog(LOG_INFO|LOG_LOCAL1, 'private function poloniex::retrieveJSON() JSON: '. print_r($json, true));
			return $json;
		}

		public function get_balances() {
			return $this->query(
				array(
					'command' => 'returnBalances'
				)
			);
		}

		public function get_addresses() {
			return $this->query(
				array(
					'command' => 'returnDepositAddresses'
				)
			);
		}
		
		public function get_open_orders($pair = 'all') {		
			return $this->query( 
				array(
					'command' => 'returnOpenOrders',
					'currencyPair' => strtoupper($pair)
				)
			);
		}
		
		public function get_my_trade_history($pair='all', $start = null, $end = null) {
			if ((DEBUG & DEBUG_EXCHANGE) && (DEBUG & DEBUG_TRACE_FUNCTIONS))
				syslog(LOG_INFO|LOG_LOCAL1, "public function poloniex::get_my_trade_history($pair, $start, $end)");
			if (is_null($end)){
				$end = time();
			}
			if (is_null($start)){ 
				return $this->query(
					array(
						'command' => 'returnTradeHistory',
						'currencyPair' => strtoupper($pair)
					)
				);
			}
			else{
				return $this->query(
					array(
						'command' => 'returnTradeHistory',
						'currencyPair' => strtoupper($pair),
						'start' => $start,
						'end' => $end
					)
				);
			}
		}
		
		public function buy($pair, $rate, $amount) {
			return $this->query( 
				array(
					'command' => 'buy',	
					'currencyPair' => strtoupper($pair),
					'rate' => $rate,
					'amount' => $amount
				)
			);
		}

		public function sell($pair, $rate, $amount) {
			return $this->query(
				array(
					'command' => 'sell',
					'currencyPair' => strtoupper($pair),
					'rate' => $rate,
					'amount' => $amount
				)
			);
		}

		public function cancel_order($pair, $order_number) {
			return $this->query( 
				array(
					'command' => 'cancelOrder',	
					'currencyPair' => strtoupper($pair),
					'orderNumber' => $order_number
				)
			);
		}

		public function withdraw($currency, $amount, $address) {
			return $this->query(
				array(
					'command' => 'withdraw',
					'currency' => strtoupper($currency),
					'amount' => $amount,
					'address' => $address
				)
			);
		}

		public function get_trade_history($pair) {
			$trades = $this->retrieveJSON($this->public_url.'?command=returnTradeHistory&currencyPair='.strtoupper($pair));
			return $trades;
		}

		public function get_order_book($pair = 'all') {
			$orders = $this->retrieveJSON($this->public_url.'?command=returnOrderBook&currencyPair='.strtoupper($pair));
			return $orders;
		}

		public function get_volume() {
			$volume = $this->retrieveJSON($this->public_url.'?command=return24hVolume');
			return $volume;
		}

		public function get_ticker($pair = "ALL") {
			$pair = strtoupper($pair);
			$prices = $this->retrieveJSON($this->public_url.'?command=returnTicker');
			if($pair == "ALL"){
				return $prices;
			}else{
				if(isset($prices[$pair])){
					return $prices[$pair];
				}else{
					return array();
				}
			}
		}
		
		public function get_trading_pairs() {
			$tickers = $this->retrieveJSON($this->public_url.'?command=returnTicker');
			return array_keys($tickers);
		}
		
		public function get_total_btc_balance() {
			$balances = $this->get_balances();
			$prices = $this->get_ticker();
			
			$tot_btc = 0;
			
			foreach($balances as $coin => $amount){
				$pair = "BTC_".strtoupper($coin);
			
				// convert coin balances to btc value
				if($amount > 0){
					if($coin != "BTC"){
						$tot_btc += $amount * $prices[$pair];
					}else{
						$tot_btc += $amount;
					}
				}

				// process open orders as well
				if($coin != "BTC"){
					$open_orders = $this->get_open_orders($pair);
					foreach($open_orders as $order){
						if($order['type'] == 'buy'){
							$tot_btc += $order['total'];
						}elseif($order['type'] == 'sell'){
							$tot_btc += $order['amount'] * $prices[$pair];
						}
					}
				}
			}

			return $tot_btc;
		}
		
		public function get_chart_data($pair,$start,$end,$period) {
			$tickers = $this->retrieveJSON($this->public_url."?command=returnChartData&currencyPair=$pair&start=$start&end=$end&period=$period");
			return $tickers;
		}
		
	}
