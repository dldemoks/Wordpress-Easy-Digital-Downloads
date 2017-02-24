<?php
/*
Plugin Name: Payeer
Plugin URL: https://payeer.com/ru/modules/
Description: Модуль оплаты Payeer для плагина Easy Digital Downloads
Version: 1.0
Author: Payeer
Author URI: https://payeer.com
Contributors: Payeer
*/

function edd_listen_for_payeer(){
	
	global $edd_options;
	
	if (isset($_GET['edd-gateway']) && $_GET['edd-gateway'] == 'payeer' && $_GET['type'] == 'success')
	{
		do_action('edd_success_payeer');
	}
	else if (isset($_GET['edd-gateway']) && $_GET['edd-gateway'] == 'payeer' && $_GET['type'] == 'fail')
	{
		do_action('edd_fail_payeer');
	}
	else if (isset($_GET['edd-gateway']) && $_GET['edd-gateway'] == 'payeer' && $_GET['type'] == 'status')
	{
		do_action('edd_verify_payeer');
	}
}
add_action('init', 'edd_listen_for_payeer');

function edd_process_payeer() {
	
	global $edd_options;
	
	if (isset($_POST["m_operation_id"]) && isset($_POST["m_sign"]))
	{
		$err = false;
		$message = '';

		// запись логов

		$log_text = 
			"--------------------------------------------------------\n" .
			"operation id       " . $_POST['m_operation_id'] . "\n" .
			"operation ps       " . $_POST['m_operation_ps'] . "\n" .
			"operation date     " . $_POST['m_operation_date'] . "\n" .
			"operation pay date " . $_POST['m_operation_pay_date'] . "\n" .
			"shop               " . $_POST['m_shop'] . "\n" .
			"order id           " . $_POST['m_orderid'] . "\n" .
			"amount             " . $_POST['m_amount'] . "\n" .
			"currency           " . $_POST['m_curr'] . "\n" .
			"description        " . base64_decode($_POST['m_desc']) . "\n" .
			"status             " . $_POST['m_status'] . "\n" .
			"sign               " . $_POST['m_sign'] . "\n\n";

		$log_file = $edd_options['log_file'];

		if (!empty($log_file))
		{
			file_put_contents($_SERVER['DOCUMENT_ROOT'] . $log_file, $log_text, FILE_APPEND);
		}
		
		// проверка цифровой подписи и ip

		$sign_hash = strtoupper(hash('sha256', implode(":", array(
			$_POST['m_operation_id'],
			$_POST['m_operation_ps'],
			$_POST['m_operation_date'],
			$_POST['m_operation_pay_date'],
			$_POST['m_shop'],
			$_POST['m_orderid'],
			$_POST['m_amount'],
			$_POST['m_curr'],
			$_POST['m_desc'],
			$_POST['m_status'],
			$edd_options['secret_key']
		))));
		
		$valid_ip = true;
		$sIP = str_replace(' ', '', $edd_options['ip_filter']);
		
		if (!empty($sIP))
		{
			$arrIP = explode('.', $_SERVER['REMOTE_ADDR']);
			if (!preg_match('/(^|,)(' . $arrIP[0] . '|\*{1})(\.)' .
			'(' . $arrIP[1] . '|\*{1})(\.)' .
			'(' . $arrIP[2] . '|\*{1})(\.)' .
			'(' . $arrIP[3] . '|\*{1})($|,)/', $sIP))
			{
				$valid_ip = false;
			}
		}
		
		if (!$valid_ip)
		{
			$message .= " - ip-адрес сервера не является доверенным\n" .
			"   доверенные ip: " . $sIP . "\n" .
			"   ip текущего сервера: " . $_SERVER['REMOTE_ADDR'] . "\n";
			$err = true;
		}

		if ($_POST['m_sign'] != $sign_hash)
		{
			$message .= " - не совпадают цифровые подписи\n";
			$err = true;
		}
	
		if (!$err)
		{
			// загрузка заказа
			
			$order = get_post_meta($_POST['m_orderid'], '_edd_payment_meta', true);
			$order_curr = ($order['currency'] == 'RUR') ? 'RUB' : $order['currency'];
			$order_amount = number_format(edd_get_payment_amount($_POST['m_orderid']), 2, '.', '');
	
			// проверка суммы и валюты
		
			if ($_POST['m_amount'] != $order_amount)
			{
				$message .= " - неправильная сумма\n";
				$err = true;
			}

			if ($_POST['m_curr'] != $order_curr)
			{
				$message .= " - неправильная валюта\n";
				$err = true;
			}
			
			// проверка статуса
			
			if (!$err)
			{
				switch ($_POST['m_status'])
				{
					case 'success':
					
						if ($order['date'] == '')
						{
							edd_update_payment_status($_POST['m_orderid'], 'complete');
						}

						break;
						
					default:
					
						edd_update_payment_status($_POST['m_orderid'], 'failed');
						$message .= " - статус платежа не является success\n";
						$err = true;
						
						break;
				}
			}
		}
		
		if ($err)
		{
			$to = $edd_options['admin_email'];

			if (!empty($to))
			{
				$message = "Не удалось провести платёж через систему Payeer по следующим причинам:\n\n" . $message . "\n" . $log_text;
				$headers = "From: no-reply@" . $_SERVER['HTTP_HOST'] . "\r\n" . 
				"Content-type: text/plain; charset=utf-8 \r\n";
				mail($to, 'Ошибка оплаты', $message, $headers);
			}
			
			echo $_POST['m_orderid'] . '|error';
		}
		else
		{
			echo $_POST['m_orderid'] . '|success';
		}
	}
	
	exit;
}
add_action('edd_verify_payeer', 'edd_process_payeer');


function edd_successlink_payeer() {
	
	global $edd_options;
	
	$return_url = add_query_arg('payment-confirmation', 'payeer', get_permalink($edd_options['success_page']));
	wp_redirect($return_url);
	exit;
}
add_action('edd_success_payeer', 'edd_successlink_payeer');

function edd_faillink_payeer() {
	
	global $edd_options;
	
	$return_url = add_query_arg( 'payment-confirmation', 'payeer', get_permalink($edd_options['failure_page']));
	wp_redirect( $return_url );
	exit;
}
add_action('edd_fail_payeer', 'edd_faillink_payeer');

function pw_edd_payeer_settings($settings) {

	$payeer_gateway_settings = array(
		'payeer' => array(
			'id' => 'payeer',
			'name' => '<strong>' . __('Payeer настройки', 'edd') . '</strong>',
			'desc' => __('Настройки платежного модуля Payeer', 'edd'),
			'type' => 'header'
		),
		'merchant_url' => array(
			'id' => 'merchant_url',
			'name' => __('URL мерчанта', 'edd'),
			'desc' => __('URL для оплаты в системе Payeer. По умолчанию, https://payeer.com/merchant/', 'edd'),
			'type' => 'text',
			'size' => 'regular'
		),
		'merchant_id' => array(
			'id' => 'merchant_id',
			'name' => __('Идентификатор магазина', 'edd'),
			'desc' => __('Идентификатор магазина, зарегистрированного в платежной системе Payeer', 'edd'),
			'type' => 'text',
			'size' => 'regular'
		),
		'secret_key' => array(
			'id' => 'secret_key',
			'name' => __('Секретный ключ', 'edd'),
			'desc' => __('Должен совпадать с секретным ключем, указанным в личном кабинете Payeer', 'edd'),
			'type' => 'text',
			'size' => 'regular'
		),
		'ip_filter' => array(
			'id' => 'ip_filter',
			'name' => __('IP фильтр входящих запросов', 'edd'),
			'desc' => __('Список доверенных IP с поддержкой маски (Например: 123.456.78.90, 123.456.*.*)', 'edd'),
			'type' => 'text',
			'size' => 'regular'
		),
		'admin_email' => array(
			'id' => 'admin_email',
			'name' => __('E-mail для оповещения об ошибках', 'edd'),
			'desc' => __('E-mail администратора для оповещения об ошибках оплаты', 'edd'),
			'type' => 'text',
			'size' => 'regular'
		),
		'log_file' => array(
			'id' => 'log_file',
			'name' => __('Путь к журналу транзакций', 'edd'),
			'desc' => __('Путь к файлу, где будет сохраняться вся история оплаты в Payeer (например, /payeer_orders.log)', 'edd'),
			'type' => 'text',
			'size' => 'regular'
		)
	);
 
	return array_merge($settings, $payeer_gateway_settings);	
}
add_filter('edd_settings_gateways', 'pw_edd_payeer_settings');

function pw_edd_register_payeer_gateway($gateways) {
	$gateways['payeer'] = array('admin_label' => 'Payeer', 'checkout_label' => 'Payeer');
	return $gateways;
}
add_filter('edd_payment_gateways', 'pw_edd_register_payeer_gateway');

function pw_edd_payeer_cc_form() {
	return;
}
add_action('edd_payeer_cc_form', 'pw_edd_payeer_cc_form');

function pw_edd_process_payeer_payment($purchase_data) {
	
	global $edd_options;
	
	$payment_data = array( 
		'price' => $purchase_data['price'], 
		'date' => $purchase_data['date'], 
		'user_email' => $purchase_data['user_email'],
		'purchase_key' => $purchase_data['purchase_key'],
		'currency' => $edd_options['currency'],
		'downloads' => $purchase_data['downloads'],
		'user_info' => $purchase_data['user_info'],
		'cart_details' => $purchase_data['cart_details'],
		'status' => 'pending'
	);

	$m_orderid = edd_insert_payment($payment_data);
	
	if ($m_orderid)
	{
		$m_url = $edd_options['merchant_url'];
		$m_shop = $edd_options['merchant_id'];
		$m_amount = number_format($purchase_data['price'], 2, '.', '');
		$m_curr = strtoupper($edd_options['currency']);
		$m_curr = ($m_curr == 'RUR') ? 'RUB' : $m_curr;
		$m_desc = base64_encode(edd_get_purchase_summary($purchase_data, false));
		$m_key = $edd_options['secret_key'];
		
		$arHash = array(
			$m_shop,
			$m_orderid,
			$m_amount,
			$m_curr,
			$m_desc,
			$m_key
		);
		
		$sign = strtoupper(hash('sha256', implode(':', $arHash)));

		$m_url .= '?' . http_build_query(array(
			'm_shop' => $m_shop,
			'm_orderid' => $m_orderid,
			'm_amount' => $m_amount,
			'm_curr' => $m_curr,
			'm_desc' => $m_desc,
			'm_sign' => $sign,
		));
		
		edd_empty_cart();
		wp_redirect($m_url);
		exit;
	}
	else
	{
		edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
	}
}
add_action('edd_gateway_payeer', 'pw_edd_process_payeer_payment');