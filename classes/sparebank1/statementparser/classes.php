<?php

class Sparebank1AccountStatementDocument {
    // This should extend the Sparebank1Document class, but we currently don't have the same parser.
    // extends Sparebank1Document {

    public $accountstatement_num;
    public $account_id;

    /**
     * @var String The account number. Format: 1234.12.12345
     */
    public $account_num;

    /**
     * @var int Unix time 00:00 on the start date for the account statement
     */
    public $accountstatement_start;

    /**
     * @var int Unix time 00:00 on the end date for the account statement
     */
    public $accountstatement_end;

    /**
     * @var $account_type String The account type or account name in the bank database
     */
    public $account_type;

    /**
     * @var Sparebank1AccountStatementTransaction[]
     */
    public $transactions;

    /**
     * @var int Internal control amount for the balance out.
     */
    protected $control_amount;

    /**
     * @var int Account balance at start of period. In øre.
     */
    public $accountstatement_balance_in;

    /**
     * @var int Account balance at end of period. In øre.
     */
    public $accountstatement_balance_out;
}

class Sparebank1AccountStatementTransaction {

    /**
     * @var String Your bank account id if accountsTransaction was set.
     */
    public $bankaccount_id;

    /**
     * @var String Payment description. Usually contains from name, and value date. Can contain your internal payment message.
     */
    public $description;

    /**
     * @var int Unix time
     */
    public $interest_date;

    /**
     * @var float Amount in NOK.
     */
    public $amount;

    /**
     * @var int Unix time
     */
    public $payment_date;

    /**
     * @var String If payment type was detected. E.g. "NETTGIRO FRA", "NETTGIRO TIL", "LØNN", etc
     */
    public $type;

    /**
     * @var String Contains a reference number, if the statement contained one. E.g. 1234 1234567, 1234 *1234567 or 123456 or something.
     */
    public $reference_number;
}