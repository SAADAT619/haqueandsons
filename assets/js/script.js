// assets/js/script.js

// Function to fetch data via AJAX
function fetchData(url, callback) {
    const xhr = new XMLHttpRequest();
    xhr.onload = function() {
        if (this.status >= 200 && this.status < 300) {
            try {
                callback(JSON.parse(xhr.response));
            } catch (error) {
                console.error("Error parsing JSON:", error);
                callback({ error: "Invalid JSON response" }); // Handle JSON parsing errors
            }
        } else {
            console.error('Error fetching data:', xhr.status, xhr.statusText);
            callback({ error: 'Failed to fetch data' });
        }
    };
    xhr.onerror = function() {
        console.error('Network error occurred.');
        callback({ error: 'Network error' });
    };
    xhr.open('GET', url);
    xhr.send();
}

// Function to get seller details via AJAX (for buy.php)
function getSellerDetails(sellerId) {
    if (!sellerId) {
        document.getElementById('seller_details').style.display = 'none';
        return; // Exit if sellerId is empty
    }

    fetchData('get_seller_details.php?id=' + sellerId, function(response) {
        if (response.error) {
            alert(response.error);
            document.getElementById('seller_details').style.display = 'none';
        } else {
            document.getElementById('seller_name').innerHTML = response.name;
            document.getElementById('seller_phone').innerHTML = response.phone;
            document.getElementById('seller_address').innerHTML = response.address;
            document.getElementById('seller_details').style.display = 'block';
        }
    });
}

// Function to calculate total (for buy.php and sell.php)
function calculateTotal() {
    const quantity = parseFloat(document.getElementById('quantity').value) || 0;
    const price = parseFloat(document.getElementById('price').value) || 0;
    const total = quantity * price;
    document.getElementById('total').innerHTML = total.toFixed(2);
    calculateDue(); // Recalculate due whenever total changes
}

// Function to calculate due (for buy.php and sell.php)
function calculateDue() {
    const total = parseFloat(document.getElementById('total').innerHTML) || 0;
    const paid = parseFloat(document.getElementById('paid').value) || 0;
    const due = total - paid;
    document.getElementById('due').innerHTML = due.toFixed(2);
}

// Function to get customer details via AJAX (for sell.php)
function getCustomerDetails(customerId) {
    if (!customerId) {
        document.getElementById('customer_details').style.display = 'none';
        return; // Exit if customerId is empty
    }
    fetchData('get_customer_details.php?id=' + customerId, function(response) {
        if (response.error) {
            alert(response.error);
            document.getElementById('customer_details').style.display = 'none';
        } else {
            document.getElementById('customer_name').innerHTML = response.name;
            document.getElementById('customer_phone').innerHTML = response.phone;
            document.getElementById('customer_address').innerHTML = response.address;
            document.getElementById('customer_details').style.display = 'block';
        }
    });
}

// Function to edit product (for manage_products.php)
function editProduct(id, category_id, name, price, quantity) {
    document.getElementById('edit_product_form').style.display = 'block';
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_category_id').value = category_id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_price').value = price;
    document.getElementById('edit_quantity').value = quantity;
}