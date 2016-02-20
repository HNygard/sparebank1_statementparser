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
}
class Sparebank1PaymentReceiptDocument extends Sparebank1Document {
	public $bank_account_number;
	public $content;
}
