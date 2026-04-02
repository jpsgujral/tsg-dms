    </div><!-- /content-area -->
</div><!-- /main-content -->

<!-- Scripts -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
<!-- DataTables core + Responsive extension -->
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

<script>
/* ── DataTables ─────────────────────────────────────────── */
$(document).ready(function () {
    if ($('.datatable').length) {
        $('.datatable').DataTable({
            responsive: true,
            pageLength: 25,
            language: {
                search:     '<i class="bi bi-search me-1"></i>',
                searchPlaceholder: 'Search...',
                emptyTable: 'No records found',
                paginate: {
                    previous: '<i class="bi bi-chevron-left"></i>',
                    next:     '<i class="bi bi-chevron-right"></i>'
                }
            },
            dom: "<'row mb-2'<'col-sm-6'l><'col-sm-6'f>>" +
                 "<'row'<'col-12'tr>>" +
                 "<'row mt-2'<'col-sm-5'i><'col-sm-7'p>>",
        });
    }

    /* Bootstrap tooltips */
    $('[data-bs-toggle="tooltip"]').each(function () {
        new bootstrap.Tooltip(this);
    });
});

/* ── Sidebar toggle ─────────────────────────────────────── */
function openSidebar() {
    document.getElementById('sidebar').classList.add('open');
    document.getElementById('sidebarOverlay').classList.add('show');
    document.body.style.overflow = 'hidden';   // prevent body scroll on mobile
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('show');
    document.body.style.overflow = '';
}
function toggleSidebar() {
    const sb = document.getElementById('sidebar');
    sb.classList.contains('open') ? closeSidebar() : openSidebar();
}

/* Close sidebar when a nav link is clicked on mobile */
document.querySelectorAll('.sidebar .nav-link').forEach(function (link) {
    link.addEventListener('click', function () {
        if (window.innerWidth < 992) closeSidebar();
    });
});

/* Close sidebar on resize to desktop */
window.addEventListener('resize', function () {
    if (window.innerWidth >= 992) closeSidebar();
});

/* ── Confirm delete ─────────────────────────────────────── */
function confirmDelete(id, url) {
    if (confirm('Delete this record?\nThis action cannot be undone.')) {
        window.location.href = url + '?delete=' + id;
    }
}
</script>
</body>
</html>
<?php ob_end_flush(); ?>
