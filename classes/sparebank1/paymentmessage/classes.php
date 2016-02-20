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
	public $bank_name;
}
