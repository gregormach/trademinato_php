<?php
define ('DEBUG_TRACE_FUNCTIONS', 		0b00000001);
define ('DEBUG_TRACE_FUNCTIONS_OUTPUT', 	0b00000010);
define ('DEBUG_EXCHANGE', 			0b00000100);
define ('DEBUG_RATE', 				0b00001000);
define ('DEBUG_WALLET', 			0b00010000);

define ('RATE_REFRESH_MY_TRADES',		0b00000001);
define ('RATE_REFRESH_ORDERS',			0b00000010);
define ('RATE_REFRESH_TICKER',			0b00000100);
define ('RATE_REFRESH_ALL',			0b11111111);

define ('ACTION_SELL',				0b00000000);
define ('ACTION_BUY',				0b00000001);
define ('ACTION_TRADE',				0b00000010);
define ('ACTION_RESEARCH',			0b00000100);

define ('EXIT_SUCESSFUL',					0b00000000);
define ('EXIT_SUCESSFUL_OPENED_ORDER',				0b00000001);
define ('EXIT_SUCESSFUL_DEBAG',					0b00000010);
define ('EXIT_SUCESSFUL_INCREASE_SEQUENCE',			0b00000100);
define ('EXIT_FAIL',						0b10000000);
define ('EXIT_FAIL_EXCHANGE',					0b10000001);
define ('EXIT_FAIL_TRADEMINATOR_CONNECTION',			0b10000011);
define ('EXIT_FAIL_REQUIREMENTS',				0b10000100);

define ('DIRECTOR_COMMAND_LINE', 'timeout --preserve-status 45 php client/trader.php -f client/config.yaml');
//define ('DIRECTOR_COMMAND_LINE', 'php client/trader.php -f client/config.yaml');

define ('EXCHANGE_EMA_ALGORITHM_HIT',			0b00000001);
define ('EXCHANGE_MACD_ALGORITHM_HIT',			0b00000010);
define ('EXCHANGE_MAXMIN_ALGORITHM_HIT',		0b00000100);
define ('EXCHANGE_BB_ALGORITHM_HIT',			0b00001000);


define ('EXCHANGE_LOAD_WALLETS',			0b00000001);
define ('EXCHANGE_LOAD_RATES',				0b00000010);

define ('TRENDING_NEUTRAL',				0b00000000);
define ('TRENDING_UP',					0b00000001);
define ('TRENDING_DOWN',				0b00000010);

if (!defined('CURL_HTTP_VERSION_2_0')) {
	define('CURL_HTTP_VERSION_2_0', 3);
}
