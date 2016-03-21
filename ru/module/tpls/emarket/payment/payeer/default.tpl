<?php
$FORMS = Array();

$FORMS['form_block'] = <<<END

<form action="%payeer_url%" method="get">	
	<input type="hidden" name="m_shop" value="%payeer_shop%" />
	<input type="hidden" name="m_orderid" value="%payeer_orderid%" />
	<input type="hidden" name="m_amount" value="%payeer_amount%" />
	<input type="hidden" name="m_curr" value="%payeer_curr%" />
	<input type="hidden" name="m_desc" value="%payeer_desc%" />
	<input type="hidden" name="m_sign" value="%payeer_sign%" />
</form>
END;

?>