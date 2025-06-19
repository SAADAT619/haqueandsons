<?php
// models/Buy.php

class Buy {
    private $db;

    public function __construct() {
        $this->db = new Database(); // Assuming you have a Database class
    }

    public function addBuy($data) {
        $this->db->query("INSERT INTO buys (seller_id, product_id, quantity, price, paid_amount, due_amount, buy_date, payment_method, invoice_number) VALUES (:seller_id, :product_id, :quantity, :price, :paid_amount, :due_amount, :buy_date, :payment_method, :invoice_number)");

        $this->db->bind(':seller_id', $data['seller_id']);
        $this->db->bind(':product_id', $data['product_id']);
        $this->db->bind(':quantity', $data['quantity']);
        $this->db->bind(':price', $data['price']);
        $this->db->bind(':paid_amount', $data['paid_amount']);
        $this->db->bind(':due_amount', $data['due_amount']);
        $this->db->bind(':buy_date', $data['buy_date']);
        $this->db->bind(':payment_method', isset($data['payment_method']) ? $data['payment_method'] : null);
        $this->db->bind(':invoice_number', $data['invoice_number']); // Assuming you're generating an invoice number

        return $this->db->execute();
    }

    // ... your other methods ...
}
?>