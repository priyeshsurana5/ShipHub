@extends('admin.layouts.admin_master')
@section('title', 'Awaiting Shipment')
@section('content')
<style>
    /* Optional custom CSS for sidebar */
    .sidebar {
        background-color: #f8f9fa;
    }
    .sidebar .form-select,
    .sidebar .form-control {
        border-color: #dee2e6;
        transition: border-color 0.2s ease;
    }
    .sidebar .form-select:focus,
    .sidebar .form-control:focus {
        border-color: #007bff;
        box-shadow: 0 0 5px rgba(0, 123, 255, 0.3);
    }
    .sidebar .input-group-text {
        display: none;
    }
    .sidebar #rateDisplay {
        font-weight: bold;
        color: #28a745;
    }
    .toolbar {
        display: flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 10px;
    }
    .toolbar .btn {
        font-size: 13px;
        padding: 6px 10px;
    }
    .filter-row {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 10px;
        font-size: 13px;
    }
    .shipment-table {
        width: 100% !important;
        border-collapse: collapse;
        background: white;
        font-size: 13px;
    }
    .shipment-table th, .shipment-table td {
        padding: 8px 10px;
        border-bottom: 1px solid #ddd;
    }
    .shipment-table thead {
        background: #f3f4f6;
        font-weight: bold;
    }
    .sidebar {
        background: #f8f9fa;
        padding: 10px;
        width: 100%;
        min-height: 200px;
    }
    .sidebar label {
        display: block;
        margin-bottom: 5px;
    }
    .sidebar .form-group {
        margin-bottom: 10px;
    }
    .sidebar .btn {
        width: 100%;
    }
    #tableCol {
        overflow-x: auto;
    }
    /* Modal styles */
    .modal.left .modal-dialog,
    .modal.right .modal-dialog {
        position: fixed;
        margin: auto;
        width: 400px;
        height: 100%;
        transform: translate3d(0%, 0, 0);
    }
    .modal.left .modal-content,
    .modal.right .modal-content {
        height: 100%;
        overflow-y: auto;
    }
    .modal.left .modal-body,
    .modal.right .modal-body {
        padding: 15px 15px 80px;
    }
    .modal.right.fade .modal-dialog {
        right: -400px;
        transition: opacity 0.3s linear, right 0.3s ease-out;
    }
    .modal.right.fade.show .modal-dialog {
        right: 0;
    }
    .modal-content {
        border-radius: 0;
        border: none;
    }
    .modal-header {
        border-bottom: 1px solid #eee;
        background-color: #f8f9fa;
    }
</style>

<div class="p-3">
    <!-- Page Title -->
   <div class="page-title d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Awaiting Shipment</h5>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#rightModal">
        <i class="bi bi-filter"></i> Filters
    </button>
</div>


    <!-- Right Sidebar Modal -->
    <div class="modal right fade" id="rightModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Filters</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Marketplace -->
                    <div class="mb-3">
                        <label class="form-label">Marketplace</label>
                        <select class="form-select" id="filterMarketplace">
                            <option value="">-- Select Marketplace --</option>
                            <option value="amazon">Amazon</option>
                            <option value="ebay">eBay</option>
                            <option value="walmart">Walmart</option>
                        </select>
                    </div>

                    <!-- Date Range -->
                    <div class="mb-3">
                        <label class="form-label">From Date</label>
                        <input type="date" class="form-control" id="filterFromDate">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">To Date</label>
                        <input type="date" class="form-control" id="filterToDate">
                    </div>

                    <!-- Status -->
                  <!--   <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" id="filterStatus">
                            <option value="">-- Select Status --</option>
                            <option value="pending">Pending</option>
                            <option value="processing">Processing</option>
                            <option value="shipped">Shipped</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div> -->

                    <!-- Buttons -->
                    <div class="d-flex justify-content-between">
                        <button type="button" class="btn btn-secondary" id="resetFilters">Reset</button>
                        <button type="button" class="btn btn-primary" id="applyFilters">Apply Filters</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-md-9 col-12" id="tableCol">
            <table id="shipmentTable" class="shipment-table table table-bordered nowrap">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="selectAll"></th>
                        <th>Marketplace</th>
                        <th>Order #</th>
                        <th>Age</th>
                        <th>Order Date</th>
                        <th>Notes</th>
                        <th>Gift</th>
                        <th>Item SKU</th>
                        <th>Item Name</th>
                        <th>Batch</th>
                        <th>Recipient</th>
                        <th>Quantity</th>
                        <th>Order Total</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <div class="col-md-3 col-12" id="sidebarCol">
            <div class="sidebar border">
                <h6>Sales Channels</h6>
                <hr>
                <div class="sidebar border p-3 text-center">
                    <h6>No row selected</h6>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('script')
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>

<script>
$(document).ready(function() {
    const shippingServices = @json($services);
    let table = $('#shipmentTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '{{ route("orders.shipped.data") }}',
            data: function(d) {
                d.channels = $('.sales-channel-filter:checked').map(function() {
                    return this.value;
                }).get();
                d.marketplace = $('#filterMarketplace').val();
                d.from_date = $('#filterFromDate').val();
                d.to_date = $('#filterToDate').val();
                d.status = $('#filterStatus').val();
            }
        },
        columns: [
            {
                data: 'id',
                render: function(data) {
                    return `<input type="checkbox" class="order-checkbox" value="${data}">`;
                },
                orderable: false,
                searchable: false
            },
           {
            data: 'marketplace',
            render: function(data, type, row) {
                if (data === 'amazon') {
                    return '<span class="badge bg-success">Amazon</span>';
                } else if (data === 'reverb') {
                    return '<span class="badge bg-primary">Reverb</span>';
                } else {
                    return '<span class="badge bg-secondary">' + data.charAt(0).toUpperCase() + data.slice(1) + '</span>';
                }
            }
            },
            { data: 'order_number' },
            { data: 'order_age' },
            {
                data: 'order_date',
                render: function(data) {
                    if (!data) return '';
                    let d = new Date(data);
                    return d.toLocaleDateString('en-GB');
                }
            },
            { data: 'notes' },
            {
                data: 'is_gift',
                render: function(data) {
                    return data ? 'Yes' : 'No';
                }
            },
            { data: 'sku' },
            {
                data: 'product_name',
                render: function(data) {
                    if (!data) return '';
                    var maxLength = 30;
                    var displayText = data.length > maxLength ? data.substr(0, maxLength) + '...' : data;
                    return '<span title="' + data + '">' + displayText + '</span>';
                }
            },
            { data: 'batch' },
            { data: 'recipient_name' },
            { data: 'quantity' },
            { data: 'order_total', render: $.fn.dataTable.render.number(',', '.', 2, '$') }
        ],
        order: [[3, 'desc']],
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100]
    });

    // Filter handling
    $('#applyFilters').on('click', function() {
        $('#rightModal').modal('hide');
        table.ajax.reload();
    });

    $('#resetFilters').on('click', function() {
        $('#filterMarketplace').val('');
        $('#filterFromDate').val('');
        $('#filterToDate').val('');
        $('#filterStatus').val('');
        $('#rightModal').modal('hide');
        table.ajax.reload();
    });

    $('#selectAll').on('change', function() {
        $('.order-checkbox', table.rows().nodes()).prop('checked', this.checked);
        updateSidebar();
    });

    $('#showSidebar').on('change', function() {
        if (this.checked) {
            $('#sidebarCol').show();
            $('#tableCol').removeClass('col-md-12').addClass('col-md-9');
        } else {
            $('#sidebarCol').hide();
            $('#tableCol').removeClass('col-md-9').addClass('col-md-12');
        }
        setTimeout(function() {
            table.columns.adjust().draw();
        }, 200);
    });

    $('#shipmentTable').on('change', '.order-checkbox', function() {
        updateSidebar();
    });

    function updateSidebar() {
        let selectedOrders = $('.order-checkbox:checked');

        if (selectedOrders.length === 0) {
            $('#sidebarCol .sidebar').html(`
                <div class="sidebar border p-3 text-center">
                    <h6>No row selected</h6>
                </div>
            `);
            return;
        }

        if (selectedOrders.length === 1) {
            let rowData = table.row($(selectedOrders[0]).closest('tr')).data();
            let sidebarContent = `
                <div class="sidebar border p-3">
                    <div class="mb-3">
                        <label class="form-label">Order #:</label>
                        <span class="form-control-plaintext">${rowData.order_number || ''}</span>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Weight:</label>
                        <div class="input-group">
                            <input type="number" id="pkgWeightLb" value="${rowData.weight || '20'}" min="0" class="form-control w-25"> lb
                            <input type="number" id="pkgWeightOz" value="${rowData.weight_oz || '0'}" min="0" max="15" class="form-control w-25"> oz
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="shippingService" class="form-label">Service:</label>
                        <select id="shippingService" data-order-id="${rowData.id}" class="form-select">
                            ${Object.keys(shippingServices).map(carrier => `
                                <optgroup label="${carrier}">
                                    ${shippingServices[carrier].map(service => `
                                        <option value="${service.service_code}" 
                                            ${rowData.shipping_service === service.service_code ? 'selected' : ''}>
                                            ${service.display_name}
                                        </option>
                                    `).join('')}
                                </optgroup>
                            `).join('')}
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="packageType" class="form-label">Package:</label>
                        <select id="packageType" class="form-select">
                            <option value="YOUR_PACKAGING" ${rowData.package_type === 'YOUR_PACKAGING' ? 'selected' : ''}>Your Packaging</option>
                            <option value="FEDEX_BOX" ${rowData.package_type === 'FEDEX_BOX' ? 'selected' : ''}>FedEx Box</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Size:</label>
                        <div class="input-group">
                            <input type="number" id="pkgLength" value="${rowData.length || '8'}" min="0" class="form-control w-25"> L x
                            <input type="number" id="pkgWidth" value="${rowData.width || '6'}" min="0" class="form-control w-25"> W x
                            <input type="number" id="pkgHeight" value="${rowData.height || '2'}" min="0" class="form-control w-25"> H (in)
                        </div>
                    </div>
                    <div class="mb-3">
                        <strong>Estimated Rate:</strong> <span id="rateDisplay" class="ms-2">-</span>
                    </div>
                    <button class="btn btn-success btn-sm">Create + Print Label</button>
                </div>
            `;
            $('#sidebarCol .sidebar').html(sidebarContent);
            fetchShippingRate(rowData);
        } else {
            let sidebarContent = `
                <div class="sidebar border p-3">
                    <h6>${selectedOrders.length} rows selected</h6>
                    <div class="mb-3">
                        <label class="form-label">Weight:</label>
                        <div class="input-group">
                            <input type="number" id="pkgWeightLb" value="20" min="0" class="form-control w-25"> lb
                            <input type="number" id="pkgWeightOz" value="0" min="0" max="15" class="form-control w-25"> oz
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="shippingService" class="form-label">Service:</label>
                        <select id="shippingService" class="form-select">
                            ${Object.keys(shippingServices).map(carrier => `
                                <optgroup label="${carrier}">
                                    ${shippingServices[carrier].map(service => `
                                        <option value="${service.service_code}">
                                            ${service.display_name}
                                        </option>
                                    `).join('')}
                                </optgroup>
                            `).join('')}
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="packageType" class="form-label">Package:</label>
                        <select id="packageType" class="form-select">
                            <option value="YOUR_PACKAGING">Your Packaging</option>
                            <option value="FEDEX_BOX">FedEx Box</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Size:</label>
                        <div class="input-group">
                            <input type="number" id="pkgLength" value="8" min="0" class="form-control w-25"> L x
                            <input type="number" id="pkgWidth" value="6" min="0" class="form-control w-25"> W x
                            <input type="number" id="pkgHeight" value="2" min="0" class="form-control w-25"> H (in)
                        </div>
                    </div>
                    <div class="mb-3">
                        <strong>Estimated Rate:</strong> <span id="rateDisplay" class="ms-2">-</span>
                    </div>
                    <button class="btn btn-success btn-sm mt-2" id="bulkCreateLabels">Create + Print Labels</button>
                </div>
            `;
            $('#sidebarCol .sidebar').html(sidebarContent);
            if (selectedOrders.length > 0) {
                let rowData = table.row($(selectedOrders[0]).closest('tr')).data();
                fetchShippingRate(rowData);
            }
        }
    }

    $('#sidebarCol').on('change', '#shippingService', function() {
        let orderId = $(this).data('order-id');
        let rowData = table.rows().data().toArray().find(o => o.id == orderId);
        if (rowData) {
            fetchShippingRate(rowData);
        }
    });

    function fetchShippingRate(rowData) {
        let selectedOrderValues = $('.order-checkbox:checked').map(function() {
            let rowData = table.row($(this).closest('tr')).data();
            return {
                order_number: rowData.order_number, 
                id: rowData.id
            };
        }).get();
        let payload = {
            order_number: rowData.order_number,
            weight_lb: $('#pkgWeightLb').val() || rowData.weight || 20,
            weight_oz: $('#pkgWeightOz').val() || rowData.weight_oz || 0,
            service_code: $('#shippingService').val() || 'FEDEX_GROUND',
            length: $('#pkgLength').val() || rowData.length || 8,
            width: $('#pkgWidth').val() || rowData.width || 6,
            height: $('#pkgHeight').val() || rowData.height || 2,
            package_type: $('#packageType').val() || 'YOUR_PACKAGING',
            from_postal_code: rowData.from_postal_code || '90210',
            from_country_code: rowData.from_country_code || 'US',
            to_postal_code: rowData.to_postal_code || '10001',
            to_country_code: rowData.to_country_code || 'US',
            residential: false,
            selectedOrderValues: selectedOrderValues,
            _token: '{{ csrf_token() }}'
        };

        $.ajax({
            url: '{{ route("orders.get.rate") }}',
            type: 'POST',
            data: payload,
            success: function(response) {
                if (response.success) {
                    $('#rateDisplay').text(`$${response.rate}`);
                } else {
                    $('#rateDisplay').text('Error: ' + (response.message || 'Failed to fetch rate'));
                }
            },
            error: function(xhr) {
                $('#rateDisplay').text('API Error');
                console.error(xhr.responseText);
            }
        });
    }

    $('#sidebarCol').on('click', '.btn-success', function(e) {
        e.preventDefault();
        let selectedOrders = $('.order-checkbox:checked').map(function() {
            return this.value;
        }).get();

        if (selectedOrders.length === 0) {
            alert('Please select at least one order to create and print labels.');
            return;
        }

        $.ajax({
            url: '{{ route("orders.create.print.labels") }}',
            type: 'POST',
            data: {
                order_ids: selectedOrders, 
                weight_lb: $('#pkgWeightLb').val(),
                weight_oz: $('#pkgWeightOz').val(),
                service_code: $('#shippingService').val(),
                length: $('#pkgLength').val(),
                width: $('#pkgWidth').val(),
                height: $('#pkgHeight').val(),
                package_type: $('#packageType').val(),
                _token: '{{ csrf_token() }}'
            },
            success: function(response) {
                if (response.success) {
                    if (response.label_url) {
                        let printWindow = window.open(response.label_url, '_blank');
                        printWindow.onload = function() {
                            printWindow.print();
                        };
                    }
                    alert('Label created successfully!');
                    table.ajax.reload();
                } else {
                    alert('Error: ' + (response.message || 'Failed to create label.'));
                }
            },
            error: function(xhr) {
                alert('An error occurred while creating the label. Please try again.');
                console.error(xhr.responseText);
            }
        });
    });
});
</script>
@endsection