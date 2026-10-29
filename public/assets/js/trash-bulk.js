document.addEventListener('DOMContentLoaded', function () {
    const checkAll = document.getElementById('check-all');
    const itemCheckboxes = document.querySelectorAll('.item-checkbox');
    const bulkContainer = document.getElementById('bulk-action-container');
    
    // Ambil elemen baru
    const btnRestore = document.getElementById('btn-bulk-restore');
    const btnDelete = document.getElementById('btn-bulk-delete');
    const countText = document.getElementById('bulk-count-text');

    if(!checkAll || !bulkContainer) return;

    const postUrl = bulkContainer.dataset.url;
    const csrfToken = bulkContainer.dataset.token;

    function toggleBulkContainer() {
        const checkedCount = document.querySelectorAll('.item-checkbox:checked').length;
        if (checkedCount > 0) {
            bulkContainer.style.display = 'flex';
            if(countText) countText.textContent = checkedCount + ' dipilih';
        } else {
            bulkContainer.style.display = 'none';
        }
    }

    checkAll.addEventListener('change', function () {
        itemCheckboxes.forEach(cb => cb.checked = checkAll.checked);
        toggleBulkContainer();
    });

    itemCheckboxes.forEach(cb => cb.addEventListener('change', toggleBulkContainer));

    // Fungsi utama untuk memproses AJAX
    function handleBulkAction(action, btnElement) {
        const selectedIds = Array.from(document.querySelectorAll('.item-checkbox:checked')).map(cb => cb.value);
        if (selectedIds.length === 0) return;

        const confirmText = action === 'force_delete'
            ? `Yakin MENGHAPUS PERMANEN ${selectedIds.length} barang?\nSemua foto fisik dan riwayat akan hilang.`
            : `Yakin memulihkan ${selectedIds.length} barang?`;

        if (confirm(confirmText)) {
            // Animasi loading sederhana (mematikan tombol sementara)
            const originalHtml = btnElement.innerHTML;
            btnElement.disabled = true;
            btnElement.style.opacity = '0.5';

            fetch(postUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({ action: action, item_ids: selectedIds })
            })
            .then(res => res.json())
            .then(data => {
                alert(data.message);
                if (data.success) location.reload();
            })
            .catch(err => {
                console.error('Error:', err);
                alert('Terjadi kesalahan saat menghubungi server.');
            })
            .finally(() => {
                btnElement.disabled = false;
                btnElement.style.opacity = '1';
                btnElement.innerHTML = originalHtml;
            });
        }
    }

    // Pasangkan event klik ke masing-masing tombol
    if(btnRestore) {
        btnRestore.addEventListener('click', () => handleBulkAction('restore', btnRestore));
    }
    
    if(btnDelete) {
        btnDelete.addEventListener('click', () => handleBulkAction('force_delete', btnDelete));
    }
});