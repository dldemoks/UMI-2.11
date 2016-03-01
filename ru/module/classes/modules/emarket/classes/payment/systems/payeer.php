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
		
		$m_curr = strtoupper( mainConfiguration::getInstance()->get('system', 'default-currency') );
		
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
		
		if (isset($_POST["m_operation_id"]) && isset($_POST["m_sign"]))
		{
			$m_key = $this->object->payeer_key;
			
			$arHash = array($_POST['m_operation_id'],
					$_POST['m_operation_ps'],
					$_POST['m_operation_date'],
					$_POST['m_operation_pay_date'],
					$_POST['m_shop'],
					$_POST['m_orderid'],
					$_POST['m_amount'],
					$_POST['m_curr'],
					$_POST['m_desc'],
					$_POST['m_status'],
					$m_key);
					
			$sign_hash = strtoupper(hash('sha256', implode(":", $arHash)));
			
			// проверка принадлежности ip списку доверенных ip
			
			$list_ip_str = str_replace(' ', '', $this->object->payeer_ipfilter);
			
			if (!empty($list_ip_str)) 
			{
				$list_ip = explode(',', $list_ip_str);
				$this_ip = $_SERVER['REMOTE_ADDR'];
				$this_ip_field = explode('.', $this_ip);
				$list_ip_field = array();
				$i = 0;
				$valid_ip = FALSE;
				foreach ($list_ip as $ip)
				{
					$ip_field[$i] = explode('.', $ip);
					if ((($this_ip_field[0] ==  $ip_field[$i][0]) or ($ip_field[$i][0] == '*')) and
						(($this_ip_field[1] ==  $ip_field[$i][1]) or ($ip_field[$i][1] == '*')) and
						(($this_ip_field[2] ==  $ip_field[$i][2]) or ($ip_field[$i][2] == '*')) and
						(($this_ip_field[3] ==  $ip_field[$i][3]) or ($ip_field[$i][3] == '*')))
						{
							$valid_ip = TRUE;
							break;
						}
					$i++;
				}
			}
			else
			{
				$valid_ip = TRUE;
			}
		
			$log_text = 
				"--------------------------------------------------------\n".
				"operation id		" . $_POST["m_operation_id"] . "\n".
				"operation ps		" . $_POST["m_operation_ps"] . "\n".
				"operation date		" . $_POST["m_operation_date"] . "\n".
				"operation pay date	" . $_POST["m_operation_pay_date"] . "\n".
				"shop				" . $_POST["m_shop"] . "\n".
				"order id			" . $_POST["m_orderid"] . "\n".
				"amount				" . $_POST["m_amount"] . "\n".
				"currency			" . $_POST["m_curr"] . "\n".
				"description		" . base64_decode($_POST["m_desc"]) . "\n".
				"status				" . $_POST["m_status"] . "\n".
				"sign				" . $_POST["m_sign"] . "\n\n";

			if (!empty($this->object->payeer_log))
			{
				file_put_contents($_SERVER['DOCUMENT_ROOT'] . $this->object->payeer_log, $log_text, FILE_APPEND);
			}
			
			if ($_POST["m_sign"] != $sign_hash)
			{
				$to = $this->object->payeer_emailerr;
				
				if (!empty($to))
				{
					$subject = "Ошибка оплаты";
					$message = "Не удалось провести платёж через систему Payeer по следующим причинам:\n\n";
					$message .= " - Не совпадают цифровые подписи\n";
					$message .= "\n" . $log_text;
					$headers = "From: no-reply@" . $_SERVER['HTTP_SERVER'] . "\r\nContent-type: text/plain; charset=utf-8 \r\n";
					mail($to, $subject, $message, $headers);
				}
				
				$buffer->push($_POST['m_orderid'] . '|error');
				$buffer->end();
				return false;
			}
			
			if ($_POST['m_status'] == "success" && $valid_ip)
			{
				$this->order->setPaymentStatus('accepted');
				$this->order->payment_document_num = $_POST['m_orderid'];
				$response = $_POST['m_orderid'] . "|success";
			}
			else
			{
				$to = $this->object->payeer_emailerr;
				
				if (!empty($to))
				{
					$subject = "Ошибка оплаты";
					$message = "Не удалось провести платёж через систему Payeer по следующим причинам:\n\n";
					
					if ($_POST['m_status'] != "success")
					{
						$message .= " - Cтатус платежа не является success\n";
					}
					
					if (!$valid_ip)
					{
						$message .= " - ip-адрес сервера не является доверенным\n";
						$message .= "   доверенные ip: " . $this->object->payeer_ipfilter . "\n";
						$message .= "   ip текущего сервера: " . $_SERVER['REMOTE_ADDR'] . "\n";
					}
					
					$message .= "\n" . $log_text;
					$headers = "From: no-reply@" . $_SERVER['HTTP_SERVER'] . "\r\nContent-type: text/plain; charset=utf-8 \r\n";
					mail($to, $subject, $message, $headers);
				}
		
				$this->order->setPaymentStatus('declined');
				$response = $_POST['m_orderid'] . "|error";
			}

			$buffer->push($response);
		}
		
		$buffer->end();
	}
};
?>