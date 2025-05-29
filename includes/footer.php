<?php
// includes/footer.php
?>

    </div> <!-- Close content div -->
    </div> <!-- Close container div -->
    <footer class="footer">
        <p>© <?php echo date("Y"); ?> Rahela Solutions LTD.</p>
    </footer>
</body>
</html>

<style>
.footer {
    width: 100%;
    text-align: center;
    padding: 20px;
    background-color: #f5f5f5;
    border-top: 1px solid #ddd;
    position: relative;
    bottom: 0;
    left: 0;
    font-size: 14px;
    color: #666;
    margin-top: 20px;
}

/* Ensure the container and content allow the footer to sit at the bottom */
.container {
    display: flex;
    min-height: 100vh;
    flex-direction: row;
}

.content {
    flex: 1;
    padding: 20px;
    box-sizing: border-box;
}

/* Ensure the sidebar doesn't interfere with the footer */
.sidebar {
    width: 200px;
    background-color: #2e7d32;
    padding: 20px;
    box-sizing: border-box;
}

@media (max-width: 768px) {
    .container {
        flex-direction: column;
    }

    .sidebar {
        width: 100%;
    }

    .footer {
        margin-top: 20px;
    }
}
</style>