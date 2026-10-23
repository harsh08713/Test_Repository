<?php

use App\Modules\Sales\Http\Controllers\QuotationPdfController;
use App\Modules\Sales\Http\Controllers\SalesInvoicePdfController;
use App\Modules\Sales\Http\Controllers\SalesOrderPdfController;
use App\Modules\Sales\Http\Livewire\Invoices;
use App\Modules\Sales\Http\Livewire\Orders;
use App\Modules\Sales\Http\Livewire\Quotations;
use App\Modules\Sales\Http\Livewire\Returns;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('sales')->name('sales.')->group(function () {
    // Fixed paths (make) come before {quotation}; an edit parameter is not named like a form property.
    Route::livewire('/quotations', Quotations\QuotationList::class)->name('quotations.index');
    Route::livewire('/quotations/make/deal/{deal}', Quotations\QuotationForm::class)->whereNumber('deal')->name('quotations.from-deal');
    Route::livewire('/quotations/make/inquiry/{inquiry}', Quotations\QuotationForm::class)->whereNumber('inquiry')->name('quotations.from-inquiry');
    Route::livewire('/quotations/{quotation}', Quotations\QuotationShow::class)->whereNumber('quotation')->name('quotations.show');
    Route::livewire('/quotations/{editing}/edit', Quotations\QuotationForm::class)->whereNumber('editing')->name('quotations.edit');
    Route::get('/quotations/{quotation}/pdf', QuotationPdfController::class)->whereNumber('quotation')->name('quotations.pdf');

    // Sales orders: made from a quotation, from a deal, or directly; the fixed "make" paths come before {order}.
    Route::livewire('/orders', Orders\SalesOrderList::class)->name('orders.index');
    Route::livewire('/orders/make/quotation/{quotation}', Orders\SalesOrderForm::class)->whereNumber('quotation')->name('orders.from-quotation');
    Route::livewire('/orders/make/deal/{deal}', Orders\SalesOrderForm::class)->whereNumber('deal')->name('orders.from-deal');
    Route::livewire('/orders/make', Orders\SalesOrderForm::class)->name('orders.direct');
    Route::livewire('/orders/{order}', Orders\SalesOrderShow::class)->whereNumber('order')->name('orders.show');
    Route::livewire('/orders/{editing}/edit', Orders\SalesOrderForm::class)->whereNumber('editing')->name('orders.edit');
    Route::get('/orders/{order}/pdf', SalesOrderPdfController::class)->whereNumber('order')->name('orders.pdf');

    // Sales invoices: made by the system from an approved order; only read and downloaded here.
    Route::livewire('/invoices', Invoices\SalesInvoiceList::class)->name('invoices.index');
    Route::livewire('/invoices/{invoice}', Invoices\SalesInvoiceShow::class)->whereNumber('invoice')->name('invoices.show');
    // Returns and credit notes against an issued invoice; the fixed "make" path comes before {return}.
    Route::livewire('/returns', Returns\SalesReturnList::class)->name('returns.index');
    Route::livewire('/returns/make/{invoice}', Returns\SalesReturnForm::class)->whereNumber('invoice')->name('returns.make');
    Route::livewire('/returns/{salesReturn}', Returns\SalesReturnShow::class)->whereNumber('salesReturn')->name('returns.show');
    Route::livewire('/returns/{editing}/edit', Returns\SalesReturnForm::class)->whereNumber('editing')->name('returns.edit');

    Route::get('/invoices/{invoice}/pdf', SalesInvoicePdfController::class)->whereNumber('invoice')->name('invoices.pdf');
});
