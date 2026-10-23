<?php

use App\Modules\Finance\Http\Livewire\BudgetForm;
use App\Modules\Finance\Http\Livewire\BudgetList;
use App\Modules\Finance\Http\Livewire\BudgetShow;
use App\Modules\Finance\Http\Livewire\CostCenterList;
use App\Modules\Finance\Http\Livewire\ProfitabilityReport;
use App\Modules\Finance\Http\Livewire\VarianceReport;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('finance')->name('finance.')->group(function () {
    Route::livewire('/cost-centres', CostCenterList::class)->name('cost-centers');
    Route::livewire('/budgets', BudgetList::class)->name('budgets.index');
    Route::livewire('/budgets/create', BudgetForm::class)->name('budgets.create');
    Route::livewire('/budgets/{budget}', BudgetShow::class)->whereNumber('budget')->name('budgets.show');
    Route::livewire('/budgets/{editing}/edit', BudgetForm::class)->whereNumber('editing')->name('budgets.edit');
    Route::livewire('/variance', VarianceReport::class)->name('variance');
    Route::livewire('/profitability', ProfitabilityReport::class)->name('profitability');
});
