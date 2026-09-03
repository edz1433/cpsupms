import './bootstrap';
import Swal from 'sweetalert2';
import 'sweetalert2/dist/sweetalert2.min.css';
import TomSelect from 'tom-select';
import 'tom-select/dist/css/tom-select.css';

window.Swal = Swal;

const searchableSelectSelector = 'select:not([data-no-search])';

window.initializeSearchableSelects = function (root = document) {
    const selects = [];

    if (root instanceof HTMLSelectElement && root.matches(searchableSelectSelector)) {
        selects.push(root);
    }

    root.querySelectorAll?.(searchableSelectSelector).forEach(function (select) {
        selects.push(select);
    });

    selects.forEach(function (select) {
        if (select.tomselect) {
            return;
        }

        new TomSelect(select, {
            allowEmptyOption: true,
            closeAfterSelect: true,
            create: false,
            maxOptions: null,
            sortField: [{ field: '$order' }],
            plugins: ['dropdown_input'],
            placeholder: select.dataset.placeholder
                || (select.dataset.employeeSearch !== undefined ? 'Search employee...' : 'Search options...'),
        });
    });
};

window.initializeSearchableSelects();

new MutationObserver(function (mutations) {
    mutations.forEach(function (mutation) {
        mutation.addedNodes.forEach(function (node) {
            if (node instanceof Element) {
                window.initializeSearchableSelects(node);
            }
        });
    });
}).observe(document.documentElement, { childList: true, subtree: true });

document.addEventListener('submit', function (event) {
    const form = event.target;

    if (!form.matches('[data-confirm-delete]') || form.dataset.confirmed === '1') {
        return;
    }

    event.preventDefault();

    Swal.fire({
        title: form.dataset.confirmTitle || 'Delete this record?',
        text: form.dataset.confirmText || 'This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: form.dataset.confirmButton || 'Delete',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#667085',
        reverseButtons: true,
        focusCancel: true,
    }).then(function (result) {
        if (result.isConfirmed) {
            form.dataset.confirmed = '1';
            form.submit();
        }
    });
});
