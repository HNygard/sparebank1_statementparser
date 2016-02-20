<?php

class Sparebank1Pdf {
	private $documents = array();
	public function addDocument(Sparebank1Document $document) {
		$this->documents[] = $document;
	}
	public function getDocuments() {
		return $documents;
	}
}

class Sparebank1Document {
	public $document_date;
	public $page_number;
	public $bank_name;
	public $bank_org_number;
	public $bank_account_owner;

	public $customer_id;
	public $customer_name;
	public $customer_email;
}

class Sparebank1PaymentOverviewDocument extends Sparebank1Document {
	public $bank_account_number;
	private $paymentMessages = array();
	public function addPaymentMessage(Sparebank1PaymentMessage $payment) {
		$this->paymentMessages[] = $payment;
	}
}
class Sparebank1PaymentMessage {
	public $payment_value_date;
	public $payment_amount;
	public $payment_message;
	public $payment_msg_date;
	public $payment_bank_ref;
	public $payment_from;
	public $payment_to;
	public $payment_from_bank_account;
}
class Sparebank1PaymentReceiptDocument extends Sparebank1Document {
	public $bank_account_number;
	public $content;
}
class Sparebank1RejectedPaymentDocument extends Sparebank1Document {
	public $content;
}
