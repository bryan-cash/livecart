<?php

include_once('unittest/UTStandalone.php');

include_once('PaymentTest.php');
include_once('../method/AuthorizeNet.php');

class TestAuthorizeNet extends PaymentTest
{
	function testAuthorization()
	{
		
		$payment = new AuthorizeNet($this->details);
		
		$payment->authorizeAndCapture();
		
		//return $details;
	}	
}

?>