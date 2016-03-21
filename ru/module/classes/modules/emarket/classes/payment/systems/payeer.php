<?php
class payeerPayment extends payment 
{
	public function validate()
	{
		return true;
	}

	public static function getOrderId() 
	{
		return (int) getRequest('m_orderid');
	}

	public function process($template = null)
	{	
		$this->order->order();
		
		$param = array();
		$param['payeer_url'] 	= $this->object->payeer_url;
		$param['payeer_shop'] 	= $this->object->payeer_shop;
		$param['payeer_orderid'] = $this->order->getId();
		$param['payeer_amount'] = number_format($this->order->getActualPrice(), 2, '.', '');
		$param['payeer_desc']	= base64_encode('Оплата заказа №' . $param['payeer_orderid']);
		
		$m_curr = strtoupper(mainConfiguration::getInstance()->get('system', 'default-currency'));

		$param['payeer_curr'] = ($m_curr == 'RUR' ? 'RUB' : $m_curr);
		
		$m_key = $this->object->payeer_key;

		$arHash = array(
			$param['payeer_shop'],
			$param['payeer_orderid'],
			$param['payeer_amount'],
			$param['payeer_curr'],
			$param['payeer_desc'],
			$m_key
		);
		
		$param['payeer_sign'] = strtoupper(hash('sha256', implode(":", $arHash)));
		
		$this->order->setPaymentStatus('initialized');
		
		$FORMS = Array();
		$FORMS = "<form action=" . $param['payeer_url'] . " method='get' name='form_payeer'>
			<input type='hidden' name='m_shop' value=" . $param['payeer_shop'] . " />
			<input type='hidden' name='m_orderid' value=" . $param['payeer_orderid'] . " />
			<input type='hidden' name='m_amount' value=" . $param['payeer_amount'] . " />
			<input type='hidden' name='m_curr' value=" . $param['payeer_curr'] . " />
			<input type='hidden' name='m_desc' value=" . $param['payeer_desc'] . " />
			<input type='hidden' name='m_sign' value=" . $param['payeer_sign'] . " />
		</form>
		<script type='text/javascript'>document.form_payeer.submit();</script>";

		echo $FORMS; 
		
		list($templateString) = def_module::loadTemplates("emarket/payment/payeer/" . $template, "form_block");
		return def_module::parseTemplate($templateString, $param);
    }

    public function poll() 
	{
		$buffer = outputBuffer::current();
		$buffer->clear();
		$buffer->contentType("text/plain");
		$m_operation_id = getRequest('m_operation_id');
		$m_sign = getRequest('m_sign');
		
		if (isset($m_operation_id) && isset($m_sign))
		{
			$err = false;
			$message = '';
			
			// запись логов
			
			$log_text = 
				"--------------------------------------------------------\n" .
				"operation id		" . getRequest('m_operation_id') . "\n" .
				"operation ps		" . getRequest('m_operation_ps') . "\n" .
				"operation date		" . getRequest('m_operation_date') . "\n" .
				"operation pay date	" . getRequest('m_operation_pay_date') . "\n" .
				"shop				" . getRequest('m_shop') . "\n" .
				"order id			" . getRequest('m_orderid') . "\n" .
				"amount				" . getRequest('m_amount') . "\n" .
				"currency			" . getRequest('m_curr') . "\n" .
				"description		" . base64_decode(getRequest('m_desc')) . "\n" .
				"status				" . getRequest('m_status') . "\n" .
				"sign				" . getRequest('m_sign') . "\n\n";
			
			$log_file = $this->object->payeer_log;
			
			if (!empty($log_file))
			{
				file_put_contents($_SERVER['DOCUMENT_ROOT'] . $log_file, $log_text, FILE_APPEND);
			}
			
			// проверка цифровой подписи и ip

			$sign_hash = strtoupper(hash('sha256', implode(":", array(
				getRequest('m_operation_id'),
				getRequest('m_operation_ps'),
				getRequest('m_operation_date'),
				getRequest('m_operation_pay_date'),
				getRequest('m_shop'),
				getRequest('m_orderid'),
				getRequest('m_amount'),
				getRequest('m_curr'),
				getRequest('m_desc'),
				getRequest('m_status'),
				$this->object->payeer_key
			))));
			
			$valid_ip = true;
			$sIP = str_replace(' ', '', $this->object->payeer_ipfilter);
			
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

			if (getRequest('m_sign') != $sign_hash)
			{
				$message .= " - Не совпадают цифровые подписи\n";
				$err = true;
			}
			
			if (!$err)
			{
				// загрузка заказа
				
				$currency = strtoupper(mainConfiguration::getInstance()->get('system', 'default-currency'));
				$order_curr = ($currency == 'RUR') ? 'RUB' : $currency;
				$order_amount = number_format($this->order->getActualPrice(), 2, '.', '');
				
				// проверка суммы и валюты
			
				if (getRequest('m_amount') != $order_amount)
				{
					$message .= " - Неправильная сумма\n";
					$err = true;
				}

				if (getRequest('m_curr') != $order_curr)
				{
					$message .= " - Неправильная валюта\n";
					$err = true;
				}
				
				// проверка статуса
				
				if (!$err)
				{
					switch (getRequest('m_status'))
					{
						case 'success':
							$this->order->setPaymentStatus('accepted');
							$this->order->payment_document_num = getRequest('m_orderid');
							break;
							
						default:
							$message .= " - Cтатус платежа не является success\n";
							$this->order->setPaymentStatus('declined');
							$err = true;
							break;
					}
				}
			}
			
			if ($err)
			{
				$to = $this->object->payeer_emailerr;

				if (!empty($to))
				{
					$message = "Не удалось провести платёж через систему Payeer по следующим причинам:\n\n" . $message . "\n" . $log_text;
					$headers = "From: no-reply@" . $_SERVER['HTTP_HOST'] . "\r\n" . 
					"Content-type: text/plain; charset=utf-8 \r\n";
					mail($to, 'Ошибка оплаты', $message, $headers);
				}
				
				$buffer->push(getRequest('m_orderid') . '|error');
			}
			else
			{
				$buffer->push(getRequest('m_orderid') . '|success');
			}
		}
		
		$buffer->end();
		return false;
	}
};
?>