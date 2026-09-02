<?php
declare(strict_types=1);

return [
    'search' => [
        'contacts' => ['method' => 'GET', 'path' => '/v1/contacts', 'scope' => 'lexware:read', 'maxSize' => 250, 'parameters' => ['email','name','number','customer','vendor','page','size','sort'], 'minLength' => ['email' => 3, 'name' => 3]],
        'articles' => ['method' => 'GET', 'path' => '/v1/articles', 'scope' => 'lexware:read', 'maxSize' => 250, 'parameters' => ['articleNumber','gtin','type','page','size','sort'], 'enums' => ['type' => ['PRODUCT','SERVICE']]],
        'vouchers' => ['method' => 'GET', 'path' => '/v1/voucherlist', 'scope' => 'lexware:read', 'maxSize' => 250, 'required' => ['voucherType','voucherStatus'], 'parameters' => ['voucherType','voucherStatus','archived','contactId','voucherDateFrom','voucherDateTo','createdDateFrom','createdDateTo','updatedDateFrom','updatedDateTo','voucherNumber','page','size','sort'], 'enumLists' => [
            'voucherType' => ['any','salesinvoice','salescreditnote','purchaseinvoice','purchasecreditnote','invoice','creditnote','quotation','orderconfirmation','deliverynote','dunning','downpaymentinvoice'],
            'voucherStatus' => ['any','draft','open','overdue','paid','paidoff','voided','transferred','sepadebit','accepted','rejected','unchecked'],
        ]],
    ],
    'get' => [
        'contact' => ['method' => 'GET', 'path' => '/v1/contacts/{id}', 'scope' => 'lexware:read', 'id' => true],
        'article' => ['method' => 'GET', 'path' => '/v1/articles/{id}', 'scope' => 'lexware:read', 'id' => true],
        'voucher' => ['method' => 'GET', 'path' => '/v1/vouchers/{id}', 'scope' => 'lexware:read', 'id' => true],
        'invoice' => ['method' => 'GET', 'path' => '/v1/invoices/{id}', 'scope' => 'lexware:read', 'id' => true],
        'credit_note' => ['method' => 'GET', 'path' => '/v1/credit-notes/{id}', 'scope' => 'lexware:read', 'id' => true],
        'quotation' => ['method' => 'GET', 'path' => '/v1/quotations/{id}', 'scope' => 'lexware:read', 'id' => true],
        'order_confirmation' => ['method' => 'GET', 'path' => '/v1/order-confirmations/{id}', 'scope' => 'lexware:read', 'id' => true],
        'delivery_note' => ['method' => 'GET', 'path' => '/v1/delivery-notes/{id}', 'scope' => 'lexware:read', 'id' => true],
        'dunning' => ['method' => 'GET', 'path' => '/v1/dunnings/{id}', 'scope' => 'lexware:read', 'id' => true],
        'down_payment_invoice' => ['method' => 'GET', 'path' => '/v1/down-payment-invoices/{id}', 'scope' => 'lexware:read', 'id' => true],
        'payment' => ['method' => 'GET', 'path' => '/v1/payments/{id}', 'scope' => 'lexware:read', 'id' => true],
        'file_status' => ['method' => 'GET', 'path' => '/v1/files/{id}/status', 'scope' => 'lexware:read', 'id' => true],
        'posting_categories' => ['method' => 'GET', 'path' => '/v1/posting-categories', 'scope' => 'lexware:read'],
        'payment_conditions' => ['method' => 'GET', 'path' => '/v1/payment-conditions', 'scope' => 'lexware:read'],
        'countries' => ['method' => 'GET', 'path' => '/v1/countries', 'scope' => 'lexware:read'],
        'print_layouts' => ['method' => 'GET', 'path' => '/v1/print-layouts', 'scope' => 'lexware:read'],
        'profile' => ['method' => 'GET', 'path' => '/v1/profile', 'scope' => 'lexware:read'],
        'invoice_file' => ['method' => 'GET', 'path' => '/v1/invoices/{id}/file', 'scope' => 'lexware:read', 'id' => true, 'binary' => true],
        'credit_note_file' => ['method' => 'GET', 'path' => '/v1/credit-notes/{id}/file', 'scope' => 'lexware:read', 'id' => true, 'binary' => true],
        'file' => ['method' => 'GET', 'path' => '/v1/files/{id}', 'scope' => 'lexware:read', 'id' => true, 'binary' => true],
    ],
    'write' => [
        'contact_create' => ['method' => 'POST', 'path' => '/v1/contacts', 'contact' => true, 'create' => true, 'fields' => ['version','roles','company','person','addresses','xRechnung','emailAddresses','phoneNumbers','note'], 'required' => ['version','roles']],
        'contact_update' => ['method' => 'PUT', 'path' => '/v1/contacts/{id}', 'contact' => true, 'id' => true, 'fields' => ['version','roles','company','person','addresses','xRechnung','emailAddresses','phoneNumbers','note'], 'required' => ['version','roles']],
        'article_create' => ['method' => 'POST', 'path' => '/v1/articles', 'article' => true, 'fields' => ['title','description','type','articleNumber','gtin','note','unitName','price'], 'required' => ['title','type','unitName','price'], 'enums' => ['type' => ['PRODUCT','SERVICE']]],
        'article_update' => ['method' => 'PUT', 'path' => '/v1/articles/{id}', 'article' => true, 'id' => true, 'fields' => ['title','description','type','articleNumber','gtin','note','unitName','price','version'], 'required' => ['title','type','unitName','price','version'], 'enums' => ['type' => ['PRODUCT','SERVICE']]],
        'voucher_create' => ['method' => 'POST', 'path' => '/v1/vouchers', 'voucher' => true, 'fields' => ['type','voucherStatus','voucherNumber','voucherDate','shippingDate','dueDate','totalGrossAmount','totalTaxAmount','taxType','useCollectiveContact','contactId','remark','voucherItems','files','version'], 'required' => ['type','voucherStatus','taxType'], 'enums' => ['type' => ['salesinvoice','salescreditnote','purchaseinvoice','purchasecreditnote'], 'voucherStatus' => ['unchecked'], 'taxType' => ['gross']]],
        'voucher_update' => ['method' => 'PUT', 'path' => '/v1/vouchers/{id}', 'voucher' => true, 'id' => true, 'preserveFiles' => true, 'fields' => ['type','voucherStatus','voucherNumber','voucherDate','shippingDate','dueDate','totalGrossAmount','totalTaxAmount','taxType','useCollectiveContact','contactId','remark','voucherItems','files','version'], 'required' => ['type','taxType','version'], 'enums' => ['type' => ['salesinvoice','salescreditnote','purchaseinvoice','purchasecreditnote'], 'voucherStatus' => ['open','unchecked'], 'taxType' => ['net','gross']]],
        'invoice_draft_create' => ['method' => 'POST', 'path' => '/v1/invoices', 'sales' => true, 'invoice' => true, 'fields' => ['voucherDate','address','lineItems','totalPrice','taxConditions','paymentConditions','shippingConditions','title','introduction','remark','deliveryTerms','printLayoutId','language','xRechnung'], 'required' => ['voucherDate','address','lineItems','totalPrice','taxConditions','shippingConditions']],
        'invoice_draft_pursue' => ['method' => 'POST', 'path' => '/v1/invoices', 'sales' => true, 'invoice' => true, 'preceding' => true, 'fields' => ['voucherDate','address','lineItems','totalPrice','taxConditions','paymentConditions','shippingConditions','title','introduction','remark','deliveryTerms','printLayoutId','language','xRechnung','precedingSalesVoucherId'], 'required' => ['voucherDate','address','lineItems','totalPrice','taxConditions','shippingConditions','precedingSalesVoucherId']],
        'credit_note_draft_create' => ['method' => 'POST', 'path' => '/v1/credit-notes', 'sales' => true, 'fields' => ['voucherDate','address','lineItems','totalPrice','taxConditions','title','introduction','remark','printLayoutId','language'], 'required' => ['voucherDate','address','lineItems','totalPrice','taxConditions']],
        'credit_note_draft_pursue' => ['method' => 'POST', 'path' => '/v1/credit-notes', 'sales' => true, 'preceding' => true, 'fields' => ['voucherDate','address','lineItems','totalPrice','taxConditions','title','introduction','remark','printLayoutId','language','precedingSalesVoucherId'], 'required' => ['voucherDate','address','lineItems','totalPrice','taxConditions','precedingSalesVoucherId']],
    ],
    'finalize' => [
        'voucher_book' => ['method' => 'PUT', 'path' => '/v1/vouchers/{id}', 'voucher' => true, 'id' => true, 'preserveFiles' => true, 'fields' => ['type','voucherStatus','voucherNumber','voucherDate','shippingDate','dueDate','totalGrossAmount','totalTaxAmount','taxType','useCollectiveContact','contactId','remark','voucherItems','files','version'], 'required' => ['type','voucherStatus','taxType','version'], 'enums' => ['type' => ['salesinvoice','salescreditnote','purchaseinvoice','purchasecreditnote'], 'voucherStatus' => ['open'], 'taxType' => ['net','gross']]],
        'invoice_create' => ['method' => 'POST', 'path' => '/v1/invoices', 'sales' => true, 'invoice' => true, 'finalize' => true, 'fields' => ['voucherDate','address','lineItems','totalPrice','taxConditions','paymentConditions','shippingConditions','title','introduction','remark','deliveryTerms','printLayoutId','language','xRechnung'], 'required' => ['voucherDate','address','lineItems','totalPrice','taxConditions','shippingConditions']],
        'invoice_pursue' => ['method' => 'POST', 'path' => '/v1/invoices', 'sales' => true, 'invoice' => true, 'finalize' => true, 'preceding' => true, 'fields' => ['voucherDate','address','lineItems','totalPrice','taxConditions','paymentConditions','shippingConditions','title','introduction','remark','deliveryTerms','printLayoutId','language','xRechnung','precedingSalesVoucherId'], 'required' => ['voucherDate','address','lineItems','totalPrice','taxConditions','shippingConditions','precedingSalesVoucherId']],
        'credit_note_create' => ['method' => 'POST', 'path' => '/v1/credit-notes', 'sales' => true, 'finalize' => true, 'fields' => ['voucherDate','address','lineItems','totalPrice','taxConditions','title','introduction','remark','printLayoutId','language'], 'required' => ['voucherDate','address','lineItems','totalPrice','taxConditions']],
        'credit_note_pursue' => ['method' => 'POST', 'path' => '/v1/credit-notes', 'sales' => true, 'finalize' => true, 'preceding' => true, 'fields' => ['voucherDate','address','lineItems','totalPrice','taxConditions','title','introduction','remark','printLayoutId','language','precedingSalesVoucherId'], 'required' => ['voucherDate','address','lineItems','totalPrice','taxConditions','precedingSalesVoucherId']],
    ],
    'delete' => [
        'article_delete' => ['method' => 'DELETE', 'path' => '/v1/articles/{id}', 'id' => true],
        'voucher_file_remove' => ['method' => 'PUT', 'path' => '/v1/vouchers/{id}', 'id' => true, 'special' => true],
    ],
    'salesFields' => ['voucherDate','address','lineItems','totalPrice','taxConditions','paymentConditions','shippingConditions','title','introduction','remark','deliveryTerms','printLayoutId','language','xRechnung'],
];
