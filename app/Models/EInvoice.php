<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EInvoice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'approval_date' => 'date',
            'excise_amount' => 'decimal:2',
            'vat_taxable_amount' => 'decimal:2',
            'non_vat_taxable_amount' => 'decimal:2',
            'vat_exempt_amount' => 'decimal:2',
            'zero_rated_vat_amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'road_tax' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }
}
