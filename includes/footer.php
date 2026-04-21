        </main>
    </div>
</div>
    <script src="/assets/js/main.js"></script>
<script>
function filterTable(inputId, tableId) {
    var input = document.getElementById(inputId);
    var filter = input.value.toLowerCase();
    var rows = document.querySelectorAll('#' + tableId + ' tbody tr');
    rows.forEach(function(row) {
        row.style.display = row.textContent.toLowerCase().includes(filter) ? '' : 'none';
    });
}
</script>
</body>
</html>
